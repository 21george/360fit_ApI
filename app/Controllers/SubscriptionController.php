<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{StripeService, JwtService, EmailService};
use MongoDB\BSON\ObjectId;

class SubscriptionController
{
    private const TIER_LIMITS = [
        'free'     => 3,
        'pro'      => 50,
        'business' => 999999,
    ];

    /**
     * GET /subscription
     * Returns the current coach's subscription status.
     */
    public function status(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);

        if (!$coach) Response::error('Coach not found', 404);

        $tier   = $coach['subscription_tier'] ?? 'none';
        $status = $coach['subscription_status'] ?? 'none';
        $limit  = self::TIER_LIMITS[$tier] ?? self::TIER_LIMITS['free'];

        // Count active, non-deleted, non-blocked clients
        $clientCount = Database::collection('clients')->countDocuments([
            'coach_id'    => $coachId,
            'active'      => true,
            'is_blocked'  => ['$ne' => true],
            'deleted_at'  => ['$exists' => false],
        ]);

        $period = $coach['subscription_period'] ?? 'monthly';
        $formatDate = static function ($value): ?string {
            if ($value instanceof \MongoDB\BSON\UTCDateTime) {
                return $value->toDateTime()->format(DATE_ATOM);
            }
            return is_string($value) ? $value : null;
        };

        $trialStartsAt = $formatDate($coach['trial_starts_at'] ?? null);
        $trialEndsAt   = $formatDate($coach['trial_ends_at']   ?? null);
        $periodEnd     = $formatDate($coach['subscription_period_end'] ?? null);

        // If subscription_period_end is missing but trial_ends_at exists, use trial end
        // as the period end (they represent the same moment for trial subscriptions).
        if (!$periodEnd && $trialEndsAt) {
            $periodEnd = $trialEndsAt;
        }

        $amountCents = $coach['subscription_price_cents'] ?? null;
        $amount = is_numeric($amountCents) ? round(((int) $amountCents) / 100, 2) : null;
        $currency = $coach['subscription_currency'] ?? null;
        $providerAvailable = StripeService::isConfigured();

        // next_payment_date only makes sense for active or trialing subscriptions.
        $nextPaymentDate = in_array($status, ['active', 'trialing'], true)
            ? $periodEnd
            : null;

        Response::success([
            'tier'                   => $tier,
            'status'                 => $status,
            'period'                 => $period,
            'client_limit'           => $limit,
            'client_count'           => $clientCount,
            'trial_starts_at'        => $trialStartsAt,
            'trial_ends_at'          => $trialEndsAt,
            'current_period_end'     => $periodEnd,
            'next_payment_date'      => $nextPaymentDate,
            'amount'                 => $amount,
            'currency'               => $currency,
            'provider_available'     => $providerAvailable,
            'cancel_at_period_end'   => $coach['cancel_at_period_end'] ?? false,
        ]);
    }

    /**
     * GET /subscription/billing-information
     * Returns the authenticated coach's persisted billing contact and tax details.
     */
    public function billingInformation(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $billing = $coach['billing_information'] ?? [];
        Response::success([
            'first_name'  => $billing['first_name'] ?? $coach['name'] ?? null,
            'last_name'   => $billing['last_name'] ?? $coach['surname'] ?? null,
            'company'     => $billing['company'] ?? null,
            'address'     => $billing['address'] ?? $coach['address'] ?? null,
            'postal_code' => $billing['postal_code'] ?? $coach['postal_code'] ?? null,
            'city'        => $billing['city'] ?? $coach['city'] ?? null,
            'country'     => $billing['country'] ?? $coach['country'] ?? null,
            'vat_id'      => $billing['vat_id'] ?? null,
            'email'       => $billing['email'] ?? $coach['email'] ?? null,
            'phone'       => $billing['phone'] ?? $coach['phone'] ?? null,
        ]);
    }

    /**
     * PUT /subscription/billing-information
     * Updates only non-sensitive billing contact and tax details for the authenticated coach.
     */
    public function updateBillingInformation(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $body = Request::body();
        $allowed = [
            'first_name', 'last_name', 'company', 'address', 'postal_code',
            'city', 'country', 'vat_id', 'email', 'phone',
        ];
        $billing = array_filter(
            $body,
            fn(string $key): bool => in_array($key, $allowed, true),
            ARRAY_FILTER_USE_KEY
        );

        if (array_key_exists('email', $billing) && $billing['email'] !== ''
            && filter_var($billing['email'], FILTER_VALIDATE_EMAIL) === false) {
            Response::error('Billing email must be valid', 422);
        }
        if (array_key_exists('country', $billing) && $billing['country'] !== ''
            && !preg_match('/^[A-Za-z]{2}$/', (string) $billing['country'])) {
            Response::error('Country must be a two-letter ISO code', 422);
        }

        $existing = $coach['billing_information'] ?? [];
        $billing = array_map(
            static fn($value) => is_string($value) ? trim($value) : $value,
            array_merge($existing, $billing)
        );

        try {
            if (!empty($coach['stripe_customer_id']) && StripeService::isConfigured()) {
                $stripe = new StripeService();
                $stripe->updateCustomerBillingDetails(
                    $coach['stripe_customer_id'],
                    $billing,
                    (string) $coachId
                );
            }
        } catch (\RuntimeException $e) {
            Response::error('Failed to update billing information with payment provider', 502);
        }

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => [
                'billing_information' => $billing,
                'updated_at' => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        Response::success($billing, 'Billing information updated');
    }

    /**
     * POST /subscription/setup-trial
     * Step 1: Create a Stripe customer + SetupIntent client_secret for the
     * payment-method collection form (Stripe Elements). Called during signup
     * (setup token auth) before the card details are entered.
     */
    public function setupTrial(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['plan_tier' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $tier = $body['plan_tier'];
        if (!in_array($tier, ['pro', 'business'])) {
            Response::error('Invalid tier. Choose pro or business', 422);
        }

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $subscriptionStatus = $coach['subscription_status'] ?? 'none';
        if ($subscriptionStatus !== 'pending' && $subscriptionStatus !== 'none') {
            Response::error(
                'Subscription already configured (status: ' . $subscriptionStatus . ').',
                409
            );
        }

        try {
            $stripe = new StripeService();
        } catch (\RuntimeException) {
            Response::error('Payment processing is not configured.', 503);
        }

        $period  = $body['plan_period'] ?? $body['period'] ?? 'monthly';

        // Create or reuse Stripe customer
        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            $customer   = $stripe->createCustomer($coach['email'], $coach['name'], (string) $coachId);
            $customerId = $customer['id'];
            Database::collection('coaches')->updateOne(
                ['_id' => $coachId],
                ['$set' => ['stripe_customer_id' => $customerId]]
            );
        }

        // Store selected tier + period for confirm step
        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => [
                'pending_subscription_tier'   => $tier,
                'pending_subscription_period' => $period,
                'updated_at'                  => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        $setupIntent = $stripe->createTrialSetupIntent($customerId, (string) $coachId);

        Response::success([
            'client_secret' => $setupIntent['client_secret'],
            'customer_id'   => $customerId,
        ], 'SetupIntent created');
    }

    /**
     * POST /subscription/confirm-trial
     * Step 2: Called after Stripe.js confirmCardSetup succeeds on the frontend.
     * Creates a 30-day trial subscription using the validated payment method.
     */
    public function confirmTrial(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['payment_method_id' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $paymentMethodId = $body['payment_method_id'];

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $tier       = $coach['pending_subscription_tier'] ?? null;
        $period     = $coach['pending_subscription_period'] ?? 'monthly';
        $customerId = $coach['stripe_customer_id'] ?? null;

        if (!$tier || !$customerId) {
            Response::error('Setup session expired — please restart signup', 422);
        }

        try {
            $stripe = new StripeService();
        } catch (\RuntimeException) {
            Response::error('Payment processing is not configured.', 503);
        }

        $priceId   = $stripe->getPriceIdForTierAndPeriod($tier, $period);
        $trialDays = (int) ($_ENV['STRIPE_TRIAL_DAYS'] ?? 30);

        try {
            $sub = $stripe->createTrialSubscription(
                $customerId,
                $priceId,
                $paymentMethodId,
                (string) $coachId,
                $trialDays
            );
        } catch (\RuntimeException $e) {
            Response::error('Failed to create trial subscription: ' . $e->getMessage(), 422);
        }

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => $this->subscriptionSnapshot($sub, $tier, $period),
            '$unset' => [
                'pending_subscription_tier'   => '',
                'pending_subscription_period' => '',
            ]]
        );

        // Send trial-start email
        $trialEndDate = !empty($sub['trial_end'])
            ? (new \DateTime())->setTimestamp((int) $sub['trial_end'])->format('F j, Y')
            : '';
        EmailService::sendTrialStarted(
            $coach['email'],
            $coach['name'],
            $tier,
            $trialEndDate
        );

        // Generate dashboard JWT
        $payload = [
            'sub'   => (string) $coachId,
            'role'  => 'coach',
            'name'  => $coach['name'],
            'email' => $coach['email'],
        ];
        $accessToken  = JwtService::generateAccessToken($payload);
        $refreshToken = JwtService::generateRefreshToken($payload);

        $isSecure = ($_ENV['APP_ENV'] ?? '') === 'production'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        setcookie('access_token', $accessToken, [
            'expires'  => time() + 900,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        setcookie('refresh_token', $refreshToken, [
            'expires'  => time() + 2592000,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        Response::success([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'coach'         => [
                'id'                  => (string) $coachId,
                'name'                => $coach['name'],
                'email'               => $coach['email'],
                'language'            => $coach['language'] ?? 'en',
                'subscription_status' => 'trialing',
                'subscription_tier'   => $tier,
            ],
            'tier'          => $tier,
            'trial_ends_at' => isset($sub['trial_end']) ? gmdate(DATE_ATOM, (int) $sub['trial_end']) : null,
            'redirect'      => '/dashboard',
        ], 'Trial started');
    }

    /**
     * POST /subscription/select-plan
     */
    public function selectPlan(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['plan_tier' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $tier = $body['plan_tier'];
        if (!in_array($tier, ['free', 'pro', 'business'])) {
            Response::error('Invalid tier. Choose free, pro, or business', 422);
        }

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $subscriptionStatus = $coach['subscription_status'] ?? 'none';
        if ($subscriptionStatus !== 'pending' && $subscriptionStatus !== 'none') {
            Response::error(
                'Subscription already configured (status: ' . $subscriptionStatus . ').',
                409
            );
        }

        if ($tier === 'free') {
            // Activate free tier immediately
            Database::collection('coaches')->updateOne(
                ['_id' => $coachId],
                ['$set' => [
                    'subscription_tier'   => 'free',
                    'subscription_period' => 'monthly',
                    'subscription_status' => 'active',
                    'updated_at'          => new \MongoDB\BSON\UTCDateTime(),
                ]]
            );

            // Generate full JWT tokens for dashboard access
            $payload = [
                'sub'   => (string) $coachId,
                'role'  => 'coach',
                'name'  => $coach['name'],
                'email' => $coach['email'],
            ];

            $accessToken  = JwtService::generateAccessToken($payload);
            $refreshToken = JwtService::generateRefreshToken($payload);

            // Set auth cookies directly (avoids reflection on private AuthController method)
            $isSecure = ($_ENV['APP_ENV'] ?? '') === 'production'
                || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

            setcookie('access_token', $accessToken, [
                'expires'  => time() + 900,
                'path'     => '/',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            setcookie('refresh_token', $refreshToken, [
                'expires'  => time() + 2592000,
                'path'     => '/',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);

            Response::success([
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
                'coach'         => [
                    'id'                  => (string) $coachId,
                    'name'                => $coach['name'],
                    'email'               => $coach['email'],
                    'language'            => $coach['language'] ?? 'en',
                    'subscription_status' => 'active',
                    'subscription_tier'   => 'free',
                ],
                'tier'          => 'free',
                'redirect'      => '/dashboard',
            ], 'Free plan activated');
        }

        // For paid tiers: create Stripe customer and checkout session
        try {
            $stripe = new StripeService();
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }
        $period  = $body['plan_period'] ?? $body['period'] ?? 'monthly';
        $priceId = $stripe->getPriceIdForTierAndPeriod($tier, $period);

        if (empty($priceId)) {
            Response::error('Stripe price not configured for this tier and period', 500);
        }

        // Create Stripe customer
        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            $customer = $stripe->createCustomer($coach['email'], $coach['name'], (string) $coachId);
            $customerId = $customer['id'];
            Database::collection('coaches')->updateOne(
                ['_id' => $coachId],
                ['$set' => ['stripe_customer_id' => $customerId]]
            );
        }

        // Store selected tier + period temporarily for webhook to use
        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => [
                'pending_subscription_tier'   => $tier,
                'pending_subscription_period' => $period,
                'updated_at'                  => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        // Create checkout session with signup-specific URLs
        $session = $stripe->createSignupCheckoutSession($customerId, $priceId, (string) $coachId);

        Response::success([
            'checkout_url' => $session['url'],
            'tier'         => $tier,
        ], 'Ready for checkout');
    }

    /**
     * POST /subscription/checkout
     * Create a Stripe Checkout session for upgrading.
     */
    public function checkout(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['tier' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $tier = $body['tier'];
        if (!in_array($tier, ['pro', 'business'])) {
            Response::error('Invalid tier. Choose pro or business', 422);
        }

        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        try {
            $stripe = new StripeService();
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }
        $period  = $body['period'] ?? 'monthly';
        $priceId = $stripe->getPriceIdForTierAndPeriod($tier, $period);

        if (empty($priceId)) {
            Response::error('Stripe price not configured for this tier and period', 500);
        }

        // Create or reuse Stripe customer
        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            $customer   = $stripe->createCustomer($coach['email'], $coach['name'], (string) $coachId);
            $customerId = $customer['id'];
            Database::collection('coaches')->updateOne(
                ['_id' => $coachId],
                ['$set' => ['stripe_customer_id' => $customerId]]
            );
        }

        // Store pending tier+period for webhook handlers
        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => [
                'pending_subscription_tier'   => $tier,
                'pending_subscription_period' => $period,
                'updated_at'                  => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        // Check if this is for signup flow (pending subscription) or upgrade flow
        $isSignupFlow = ($coach['subscription_status'] ?? '') === 'pending';

        if ($isSignupFlow) {
            // Use signup-specific checkout with proper redirect URLs
            $session = $stripe->createSignupCheckoutSession($customerId, $priceId, (string) $coachId);
        } else {
            $session = $stripe->createCheckoutSession($customerId, $priceId, (string) $coachId);
        }

        Response::success(['checkout_url' => $session['url']], 'Checkout session created');
    }

    /**
     * POST /subscription/portal
     * Create a Stripe Customer Portal session for managing billing.
     */
    public function portal(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            Response::error('No active subscription to manage', 400);
        }

        try {
            $stripe  = new StripeService();
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }
        $session = $stripe->createPortalSession($customerId);

        Response::success(['portal_url' => $session['url']], 'Portal session created');
    }

    /**
     * POST /subscription/cancel
     * Cancel at end of current period.
     */
    public function cancel(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $subId = $coach['stripe_subscription_id'] ?? null;
        if (!$subId) {
            Response::error('No active subscription', 400);
        }

        try {
            $stripe = new StripeService();
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }
        $stripe->cancelSubscription($subId);

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => ['cancel_at_period_end' => true, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]]
        );

        Response::success(null, 'Subscription will cancel at end of billing period');
    }

    /**
     * POST /subscription/webhook
     * Handle Stripe webhook events.
     */
    public function webhook(array $params): void
    {
        $payload   = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $stripe = new StripeService();
            $event  = $stripe->verifyWebhookSignature($payload, $sigHeader);
        } catch (\RuntimeException $e) {
            Response::error('Webhook verification failed', 400);
        }

        $eventId = $event['id'] ?? null;
        if (!$eventId) {
            Response::error('Webhook event ID missing', 400);
        }

        // ── Idempotency: check whether this event has already been
        //    processed. We record the receipt only AFTER successful
        //    handling so that a post-receipt failure leaves the event
        //    un-receipted and Stripe's retry can re-process it. A
        //    `status` field tracks the lifecycle so partial work is
        //    observable.
        $existing = Database::collection('stripe_webhook_events')->findOne(
            ['event_id' => $eventId]
        );
        if ($existing && ($existing['status'] ?? '') === 'processed') {
            Response::success(null, 'Webhook already processed');
        }

        // Reserve the event with status=processing so a concurrent
        // delivery sees it as in-flight. If this insert races, the
        // unique index on event_id makes one of the two fail — the
        // loser returns "already processing" and Stripe retries later.
        try {
            Database::collection('stripe_webhook_events')->updateOne(
                ['event_id' => $eventId],
                ['$setOnInsert' => [
                    'event_id'   => $eventId,
                    'type'       => $event['type'] ?? 'unknown',
                    'received_at'=> new \MongoDB\BSON\UTCDateTime(),
                    'status'     => 'processing',
                ]],
                ['upsert' => true]
            );
        } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
            // Duplicate key → another worker is already processing.
            Response::success(null, 'Webhook already being processed');
        }

        $type = $event['type'] ?? '';
        $data = $event['data']['object'] ?? '';
        if (!is_array($data)) {
            $data = [];
        }

        $handlerError = null;

        try {
            switch ($type) {
                case 'checkout.session.completed':
                    $this->handleCheckoutCompleted($data);
                    break;

                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($data);
                    break;

                case 'customer.subscription.deleted':
                    $this->handleSubscriptionDeleted($data);
                    break;

                case 'customer.subscription.trial_will_end':
                    $this->handleTrialWillEnd($data);
                    break;

                case 'invoice.payment_succeeded':
                    $this->handleInvoicePaymentSucceeded($data);
                    break;

                case 'invoice.payment_failed':
                    $this->handlePaymentFailed($data);
                    break;

                default:
                    error_log("[webhook] Unhandled Stripe event type: {$type}");
                    break;
            }
        } catch (\Throwable $e) {
            $handlerError = $e;
            error_log("[webhook] Handler failed for event {$eventId}: " . $e->getMessage());
        }

        // Mark processed on success, or failed on error (so Stripe retries).
        Database::collection('stripe_webhook_events')->updateOne(
            ['event_id' => $eventId],
            ['$set' => [
                'status'      => $handlerError ? 'failed' : 'processed',
                'processed_at'=> new \MongoDB\BSON\UTCDateTime(),
                'error'       => $handlerError ? $handlerError->getMessage() : null,
            ]]
        );

        if ($handlerError) {
            Response::error('Webhook handler failed', 500);
        }

        Response::success(null, 'Webhook processed');
    }

    private function handleCheckoutCompleted(array $session): void
    {
        $coachId        = $session['metadata']['coach_id'] ?? null;
        $subscriptionId = $session['subscription'] ?? null;
        $customerId     = $session['customer'] ?? null;
        $pendingTier    = $session['metadata']['pending_tier'] ?? null;

        if (!$coachId || !$subscriptionId) return;

        // Fetch coach to get pending period
        $coachDoc = Database::collection('coaches')->findOne(['_id' => new ObjectId($coachId)]);
        $pendingPeriod = $coachDoc['pending_subscription_period'] ?? null;

        // Fetch the subscription to determine tier + period
        $stripe = new StripeService();
        $sub    = $stripe->getSubscription($subscriptionId);
        $priceId = $sub['items']['data'][0]['price']['id'] ?? '';
        $tier   = $this->tierFromPriceId($priceId);
        $period = $pendingPeriod ?: $this->periodFromPriceId($priceId);

        // Use pending_tier from metadata as fallback
        if ($pendingTier && in_array($pendingTier, ['pro', 'business'])) {
            $tier = $pendingTier;
        }

        // Determine subscription status - convert 'pending' to 'active'/'trialing'
        $status = $sub['status'] ?? 'active';
        if ($status === 'incomplete') {
            $status = 'trialing'; // Stripe sets incomplete for trials before first payment
        }

        $snapshot = $this->subscriptionSnapshot($sub, $tier, $period);
        $snapshot['stripe_customer_id'] = $customerId;
        $snapshot['subscription_status'] = $status;
        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => $snapshot,
            '$unset' => [
                'pending_subscription_tier'   => '',
                'pending_subscription_period' => '',
            ]]
        );
    }

    private function handleSubscriptionUpdated(array $sub): void
    {
        $coachId = $sub['metadata']['coach_id'] ?? null;
        if (!$coachId) return;

        $priceId = $sub['items']['data'][0]['price']['id'] ?? '';
        $tier   = $this->tierFromPriceId($priceId);
        $period = $this->periodFromPriceId($priceId);

        $update = $this->subscriptionSnapshot($sub, $tier, $period);

        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => $update]
        );
    }

    private function handleTrialWillEnd(array $sub): void
    {
        $coachId = $sub['metadata']['coach_id'] ?? null;
        if (!$coachId) return;

        $coach = Database::collection('coaches')->findOne(['_id' => new ObjectId($coachId)]);
        if (!$coach) return;

        $trialEndTs  = $sub['trial_end'] ?? null;
        $trialEndStr = $trialEndTs
            ? (new \DateTime())->setTimestamp((int) $trialEndTs)->format('F j, Y')
            : '';

        // Fire reminder email
        EmailService::sendTrialEndingReminder($coach['email'], $coach['name'], $trialEndStr);
    }

    private function handleInvoicePaymentSucceeded(array $invoice): void
    {
        $customerId = $invoice['customer'] ?? null;
        if (!$customerId) return;

        // Only act on subscription invoices (skip one-off charges)
        if (empty($invoice['subscription'])) return;

        $coach = Database::collection('coaches')->findOne(['stripe_customer_id' => $customerId]);
        if (!$coach) return;

        $coachId = (string) $coach['_id'];
        $wasTrialing = ($coach['subscription_status'] ?? '') === 'trialing';

        Database::collection('coaches')->updateOne(
            ['stripe_customer_id' => $customerId],
            ['$set' => [
                'subscription_status' => 'active',
                'trial_ends_at'       => null,
                'updated_at'          => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        // Send conversion email when trial converts to paid
        if ($wasTrialing) {
            $tier = $coach['subscription_tier'] ?? 'pro';
            EmailService::sendTrialConverted($coach['email'], $coach['name'], $tier);
        }
    }

    private function handleSubscriptionDeleted(array $sub): void
    {
        $coachId = $sub['metadata']['coach_id'] ?? null;
        if (!$coachId) return;

        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => [
                'subscription_tier'      => 'free',
                'subscription_period'    => 'monthly',
                'subscription_status'    => 'cancelled',
                'subscription_price_cents' => null,
                'subscription_currency'  => null,
                'cancel_at_period_end'   => false,
                'last_stripe_sync_at'    => new \MongoDB\BSON\UTCDateTime(),
                'last_stripe_sync_status' => 'success',
                'updated_at'             => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );
    }

    private function handlePaymentFailed(array $invoice): void
    {
        $customerId = $invoice['customer'] ?? null;
        if (!$customerId) return;

        $coach = Database::collection('coaches')->findOne(['stripe_customer_id' => $customerId]);
        if (!$coach) return;

        Database::collection('coaches')->updateOne(
            ['stripe_customer_id' => $customerId],
            ['$set' => [
                'subscription_status' => 'past_due',
                'failed_payment_attempts' => ($coach['failed_payment_attempts'] ?? 0) + 1,
                'failed_payment_last_attempt_at' => new \MongoDB\BSON\UTCDateTime(),
                'updated_at'          => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );
    }

    private function periodFromPriceId(string $priceId): string
    {
        $periods = ['monthly', 'quarterly', 'semi_annual', 'annual'];
        foreach ($periods as $period) {
            $proKey      = 'STRIPE_PRICE_PRO_' . strtoupper($period);
            $businessKey = 'STRIPE_PRICE_BUSINESS_' . strtoupper($period);
            if ($priceId === ($_ENV[$proKey] ?? ''))      return $period;
            if ($priceId === ($_ENV[$businessKey] ?? '')) return $period;
        }
        // Fall back to generic tier prices
        if ($priceId === ($_ENV['STRIPE_PRICE_PRO'] ?? ''))      return 'monthly';
        if ($priceId === ($_ENV['STRIPE_PRICE_BUSINESS'] ?? '')) return 'monthly';
        return 'monthly';
    }

    /**
     * Converts a verified Stripe subscription into the local read model.
     * Webhooks are authoritative; this keeps billing reads available during
     * transient Stripe API outages without storing sensitive card data.
     */
    private function subscriptionSnapshot(array $subscription, string $tier, string $period): array
    {
        $price = $subscription['items']['data'][0]['price'] ?? [];
        $toDate = static fn($timestamp): ?\MongoDB\BSON\UTCDateTime =>
            is_numeric($timestamp) ? new \MongoDB\BSON\UTCDateTime((int) $timestamp * 1000) : null;
        $defaultPaymentMethod = $subscription['default_payment_method'] ?? null;

        return [
            'stripe_subscription_id' => $subscription['id'] ?? null,
            'subscription_tier' => $tier,
            'subscription_period' => $period,
            'subscription_status' => $subscription['status'] ?? 'active',
            'subscription_price_cents' => isset($price['unit_amount']) ? (int) $price['unit_amount'] : null,
            'subscription_currency' => $price['currency'] ?? null,
            'trial_starts_at' => $toDate($subscription['trial_start'] ?? null),
            'trial_ends_at' => $toDate($subscription['trial_end'] ?? null),
            'subscription_period_end' => $toDate($subscription['current_period_end'] ?? null),
            'subscription_started_at' => $toDate($subscription['start_date'] ?? null),
            'default_payment_method' => is_array($defaultPaymentMethod)
                ? ($defaultPaymentMethod['id'] ?? null)
                : $defaultPaymentMethod,
            'cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
            'last_stripe_sync_at' => new \MongoDB\BSON\UTCDateTime(),
            'last_stripe_sync_status' => 'success',
            'updated_at' => new \MongoDB\BSON\UTCDateTime(),
        ];
    }

    /**
     * Get the client limit for a given tier.
     */
    public static function getClientLimit(string $tier): int
    {
        return self::TIER_LIMITS[$tier] ?? self::TIER_LIMITS['free'];
    }

    /**
     * GET /subscription/invoices
     * List invoices for the authenticated coach from Stripe.
     */
    public function invoices(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        // No Stripe key configured — return empty list, don't crash
        if (!StripeService::isConfigured()) {
            Response::success([]);
        }

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            Response::success([]);
        }

        try {
            $stripe   = new StripeService();
            $response = $stripe->listInvoices($customerId);
            $invoices = $response['data'] ?? [];

            $formatted = array_map(function ($inv) {
                $amountCents = $inv['amount_paid'] ?? ($inv['amount_due'] ?? 0);
                $created = $inv['created'] ?? null;

                // Stripe sometimes returns null for number on draft/pending invoices.
                // Fallback to a readable generated number so the UI never shows blank.
                $number = $inv['number'] ?? null;
                if (empty($number)) {
                    $number = '#' . strtoupper(substr($inv['id'], -8));
                }

                return [
                    'id'                 => $inv['id'],
                    'number'             => $number,
                    'amount'             => $amountCents > 0 ? round($amountCents / 100, 2) : 0,
                    'currency'           => $inv['currency'] ?? 'usd',
                    'status'             => $inv['status'] ?? 'unknown',
                    'date'               => $created ? (new \DateTime())->setTimestamp((int) $created)->format('c') : null,
                    'period_start'       => $inv['period_start'] ?? null,
                    'period_end'         => $inv['period_end'] ?? null,
                    'hosted_invoice_url' => $inv['hosted_invoice_url'] ?? null,
                    'pdf_url'            => $inv['invoice_pdf'] ?? null,
                    'description'        => $inv['lines']['data'][0]['description'] ?? $inv['description'] ?? null,
                    'last4'              => $inv['charge']['payment_method_details']['card']['last4']
                                            ?? $inv['payment_intent']['charges']['data'][0]['payment_method_details']['card']['last4']
                                            ?? null,
                ];
            }, $invoices);

            Response::success($formatted);
        } catch (\RuntimeException $e) {
            error_log('Stripe invoices error: ' . $e->getMessage());
            Response::error('Failed to retrieve invoices from payment provider', 502);
        }
    }

    /**
     * GET /subscription/invoices/:id/download
     * Get download URL for a specific invoice.
     */
    public function downloadInvoice(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            Response::error('No invoices found', 404);
        }

        try {
            $stripe  = new StripeService();
            $invoice = $stripe->getInvoice($params['id']);

            // Verify invoice belongs to this customer
            if (($invoice['customer'] ?? '') !== $customerId) {
                Response::error('Invoice not found', 404);
            }

            Response::success([
                'download_url'       => $invoice['invoice_pdf'] ?? null,
                'hosted_invoice_url' => $invoice['hosted_invoice_url'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                Response::error('Invoice not found', 404);
            }
            Response::error('Failed to retrieve invoice', 500);
        }
    }

    /**
     * POST /subscription/upgrade
     * Immediately upgrade or downgrade a subscription via Stripe.
     */
    public function upgrade(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['tier' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $newTier = $body['tier'];
        if (!in_array($newTier, ['pro', 'business'])) {
            Response::error('Invalid tier. Choose pro or business', 422);
        }

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $subId = $coach['stripe_subscription_id'] ?? null;
        if (!$subId) {
            Response::error('No active subscription to upgrade', 400);
        }

        try {
            $stripe  = new StripeService();
            $period  = $body['period'] ?? 'monthly';
            $priceId = $stripe->getPriceIdForTierAndPeriod($newTier, $period);

            if (empty($priceId)) {
                Response::error('Stripe price not configured for this tier', 500);
            }

            $stripe->updateSubscription($subId, $priceId);

            $limit = self::TIER_LIMITS[$newTier] ?? self::TIER_LIMITS['free'];

            Database::collection('coaches')->updateOne(
                ['_id' => $coachId],
                ['$set' => [
                    'subscription_tier'        => $newTier,
                    'subscription_period'      => $period,
                    'max_clients'              => $limit,
                    'cancel_at_period_end'     => false,
                    'updated_at'               => new \MongoDB\BSON\UTCDateTime(),
                ]]
            );

            Response::success([
                'new_tier'   => $newTier,
                'new_period' => $period,
            ], 'Subscription updated successfully');
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }
    }

    /**
     * GET /payment-methods
     * List saved payment methods for the authenticated coach.
     */
    public function listPaymentMethods(array $params): void
    {
        $coachId    = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach      = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        // No Stripe key configured — return empty list, don't crash
        if (!StripeService::isConfigured()) {
            Response::success(['payment_methods' => []]);
        }

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            Response::success(['payment_methods' => []]);
        }

        try {
            $stripe   = new StripeService();
            $methods  = $stripe->listPaymentMethods($customerId);
            $default  = $coach['default_payment_method'] ?? null;

            $formatted = array_map(fn($pm) => [
                'id'         => $pm['id'],
                'brand'      => $pm['card']['brand'] ?? null,
                'last4'      => $pm['card']['last4'] ?? null,
                'exp_month'  => $pm['card']['exp_month'] ?? null,
                'exp_year'   => $pm['card']['exp_year'] ?? null,
                'is_default' => $pm['id'] === $default,
            ], $methods['data'] ?? []);

            Response::success(['payment_methods' => $formatted]);
        } catch (\RuntimeException $e) {
            error_log('Stripe payment methods error: ' . $e->getMessage());
            Response::error('Failed to retrieve payment methods from payment provider', 502);
        }
    }

    /**
     * POST /payment-methods/setup-intent
     * Create a SetupIntent so the frontend can collect a new payment method.
     */
    public function createSetupIntent(array $params): void
    {
        $coachId    = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach      = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        try {
            $stripe     = new StripeService();

            // Ensure Stripe customer exists
            $customerId = $coach['stripe_customer_id'] ?? null;
            if (!$customerId) {
                $customer   = $stripe->createCustomer($coach['email'], $coach['name'], (string) $coachId);
                $customerId = $customer['id'];
                Database::collection('coaches')->updateOne(
                    ['_id' => $coachId],
                    ['$set' => ['stripe_customer_id' => $customerId]]
                );
            }

            $intent = $stripe->createSetupIntent($customerId);

            Response::success([
                'client_secret'  => $intent['client_secret'],
                'setup_intent_id'=> $intent['id'],
            ]);
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }
    }

    /**
     * POST /payment-methods
     * Attach a PaymentMethod (collected by Stripe.js) to the coach.
     */
    public function addPaymentMethod(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['payment_method_id' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            Response::error('No Stripe customer found. Please start a checkout first.', 400);
        }

        try {
            $stripe  = new StripeService();
            $pmId    = trim($body['payment_method_id']);
            $stripe->attachPaymentMethod($pmId, $customerId);

            $isDefault = !empty($body['is_default']);
            if ($isDefault) {
                $stripe->setDefaultPaymentMethod($customerId, $pmId);
                Database::collection('coaches')->updateOne(
                    ['_id' => $coachId],
                    ['$set' => ['default_payment_method' => $pmId, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]]
                );
            }

            Response::success(['payment_method_id' => $pmId], 'Payment method added', 201);
        } catch (\RuntimeException $e) {
            Response::error('Failed to add payment method: ' . $e->getMessage(), 400);
        }
    }

    /**
     * DELETE /payment-methods/:id
     * Detach a PaymentMethod from the coach.
     */
    public function deletePaymentMethod(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $pmId    = $params['id'] ?? '';

        if (empty($pmId)) Response::error('Payment method ID required', 400);

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            Response::error('No Stripe customer found for this account.', 400);
        }

        try {
            $stripe = new StripeService();

            // ── IDOR guard: verify the PaymentMethod belongs to this coach's
            //    Stripe customer before detaching. Without this, a coach could
            //    attempt to detach an arbitrary pm_* ID belonging to another
            //    coach. Stripe would normally reject cross-customer detach,
            //    but we enforce it server-side so the attempt never reaches
            //    Stripe and the caller gets a clear 403.
            $pms = $stripe->listPaymentMethods($customerId);
            $ownedIds = array_column(
                array_map(fn(array $pm): array => [
                    'id'       => $pm['id'] ?? '',
                    'customer' => $pm['customer'] ?? '',
                ], $pms['data'] ?? []),
                'id'
            );

            if (!in_array($pmId, $ownedIds, true)) {
                Response::error('Payment method not found on this account.', 403);
            }

            $stripe->detachPaymentMethod($pmId);

            // Clear default if it was the default method
            if (($coach['default_payment_method'] ?? '') === $pmId) {
                Database::collection('coaches')->updateOne(
                    ['_id' => $coachId],
                    ['$unset' => ['default_payment_method' => ''], '$set' => ['updated_at' => new \MongoDB\BSON\UTCDateTime()]]
                );
            }

            Response::success(null, 'Payment method removed');
        } catch (\RuntimeException $e) {
            Response::error('Failed to remove payment method', 400);
        }
    }

    /**
     * POST /payment-methods/:id/default
     * Set a PaymentMethod as the default for the coach.
     */
    public function setDefaultPaymentMethod(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $pmId    = $params['id'] ?? '';

        if (empty($pmId)) Response::error('Payment method ID required', 400);

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) Response::error('No Stripe customer found', 400);

        try {
            $stripe = new StripeService();
            $stripe->setDefaultPaymentMethod($customerId, $pmId);

            Database::collection('coaches')->updateOne(
                ['_id' => $coachId],
                ['$set' => ['default_payment_method' => $pmId, 'updated_at' => new \MongoDB\BSON\UTCDateTime()]]
            );

            Response::success(null, 'Default payment method updated');
        } catch (\RuntimeException $e) {
            Response::error('Failed to update default payment method', 400);
        }
    }
}
