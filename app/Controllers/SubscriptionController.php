<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{StripeService, JwtService};
use MongoDB\BSON\ObjectId;

class SubscriptionController
{
    private const TIER_LIMITS = [
        'none'     => null,
        'free'     => null,
        'pro'      => null,
        'business' => null,
    ];

    /**
     * GET /subscription
     * Returns the current coach's subscription status.
     */
    public function status(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);

        if (!$coach) Response::error('Coach not found', 404);

        $tier   = $coach['subscription_tier'] ?? 'free';
        $status = $coach['subscription_status'] ?? 'none';
        $limit  = self::TIER_LIMITS[$tier] ?? self::TIER_LIMITS['free'];
        $period = $coach['subscription_period'] ?? 'monthly';

        $clientCount = Database::collection('clients')->countDocuments([
            'coach_id' => $coachId,
            'active'   => true,
        ]);

        $trialEndsAt = isset($coach['trial_ends_at']) ? (string) $coach['trial_ends_at'] : null;
        $periodEnd   = isset($coach['subscription_period_end']) ? (string) $coach['subscription_period_end'] : null;
        $nextPaymentDate = ($status === 'trialing' && $trialEndsAt) ? $trialEndsAt : $periodEnd;

        Response::success([
            'tier'             => $tier,
            'status'           => $status,
            'period'           => $period,
            'client_limit'     => $limit,
            'client_count'     => $clientCount,
            'trial_ends_at'    => $trialEndsAt,
            'current_period_end' => $periodEnd,
            'next_payment_date' => $nextPaymentDate,
            'cancel_at_period_end' => $coach['cancel_at_period_end'] ?? false,
            'stripe_customer_id'   => $coach['stripe_customer_id'] ?? null,
        ]);
    }

    /**
     * POST /subscription/select-plan
     * Select a subscription plan during signup (setup token auth).
     */
    public function selectPlan(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['plan_tier' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $tier = $body['plan_tier'];
        if (!in_array($tier, ['pro', 'business'])) {
            Response::error('Invalid tier. Choose pro or business', 422);
        }

        $period = $body['plan_period'] ?? 'monthly';
        if (!in_array($period, ['monthly', 'quarterly', 'semi_annual', 'annual'])) {
            $period = 'monthly';
        }

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        // Verify still in pending state
        if (($coach['subscription_status'] ?? '') !== 'pending') {
            Response::error('Subscription already configured', 409);
        }

        if ($tier === 'free') {
            // Activate free tier immediately
            Database::collection('coaches')->updateOne(
                ['_id' => $coachId],
                ['$set' => [
                    'subscription_tier'   => 'free',
                    'subscription_status' => 'active',
                    'subscription_period' => 'monthly',
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
                'tier'          => 'free',
                'redirect'      => '/dashboard',
            ], 'Free plan activated');
            return;
        }

        // For paid tiers: create Stripe customer and checkout session
        try {
            $stripe = new StripeService();
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }
        $priceId = $stripe->getPriceIdForTier($tier, $period);

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

        // Store selected tier and period temporarily for webhook to use
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
            'period'       => $period,
        ], 'Ready for checkout');
    }

    /**
     * POST /subscription/checkout
     * Create a Stripe Checkout session for upgrading.
     */
    public function checkout(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['tier' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $tier = $body['tier'];
        if (!in_array($tier, ['pro', 'business'])) {
            Response::error('Invalid tier. Choose pro or business', 422);
        }

        $period = $body['period'] ?? 'monthly';
        if (!in_array($period, ['monthly', 'quarterly', 'semi_annual', 'annual'])) {
            $period = 'monthly';
        }

        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        try {
            $stripe = new StripeService();
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }
        $priceId = $stripe->getPriceIdForTier($tier, $period);

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

        // Check if this is for signup flow (pending subscription) or upgrade flow
        $isSignupFlow = ($coach['subscription_status'] ?? '') === 'pending';

        if ($isSignupFlow) {
            // Use signup-specific checkout with proper redirect URLs
            $session = $stripe->createSignupCheckoutSession($customerId, $priceId, (string) $coachId);
            Response::success(['checkout_url' => $session['url']], 'Checkout session created');
        } else {
            // Use embedded checkout for in-app upgrades
            $session = $stripe->createEmbeddedCheckoutSession($customerId, $priceId, (string) $coachId);
            Response::success([
                'client_secret' => $session['client_secret'],
                'session_id'    => $session['id'],
            ], 'Checkout session created');
        }
    }

    /**
     * POST /subscription/portal
     * Create a Stripe Customer Portal session for managing billing.
     */
    public function portal(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        try {
            $stripe  = new StripeService();
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            $customer   = $stripe->createCustomer($coach['email'], $coach['name'], (string) $coachId);
            $customerId = $customer['id'];
            Database::collection('coaches')->updateOne(
                ['_id' => $coachId],
                ['$set' => ['stripe_customer_id' => $customerId]]
            );
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
        $coachId = new ObjectId($params['_auth']['sub']);
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
     * POST /subscription/upgrade
     * Upgrade or downgrade an existing active subscription to a new tier.
     * Uses Stripe subscription update with proration — no checkout redirect needed.
     */
    public function upgrade(array $params): void
    {
        $body    = Request::body();
        $newTier = trim($body['tier'] ?? '');
        $newPeriod = trim($body['period'] ?? 'monthly');

        if (!in_array($newTier, ['pro', 'business'], true)) {
            Response::error('Invalid tier. Use "pro" or "business".', 400);
        }

        if (!in_array($newPeriod, ['monthly', 'quarterly', 'semi_annual', 'annual'], true)) {
            $newPeriod = 'monthly';
        }

        $coachId = new ObjectId($params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) {
            Response::error('Coach not found', 404);
        }

        $currentTier   = $coach['subscription_tier']   ?? 'free';
        $currentPeriod = $coach['subscription_period'] ?? 'monthly';
        $currentStatus = $coach['subscription_status'] ?? 'none';
        $subId         = $coach['stripe_subscription_id'] ?? null;

        if ($currentTier === $newTier && $currentPeriod === $newPeriod) {
            Response::error('You are already on this plan and billing period.', 400);
        }

        if (!in_array($currentStatus, ['active', 'trialing'], true) || !$subId) {
            Response::error('No active subscription to upgrade. Please use checkout instead.', 400);
        }

        try {
            $stripe   = new StripeService();
            $priceId  = $stripe->getPriceIdForTier($newTier, $newPeriod);

            if (empty($priceId)) {
                Response::error("Price not configured for tier: {$newTier}, period: {$newPeriod}", 503);
            }

            $stripe->updateSubscription($subId, $priceId);
        } catch (\RuntimeException $e) {
            Response::error('Failed to update subscription: ' . $e->getMessage(), 503);
        }

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => [
                'subscription_tier'   => $newTier,
                'subscription_period' => $newPeriod,
                'updated_at'          => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );

        Response::success(['new_tier' => $newTier, 'new_period' => $newPeriod], 'Plan updated successfully');
    }

    /**
     * GET /subscription/invoices
     * Return a list of paid Stripe invoices for the authenticated coach.
     */
    public function invoices(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            Response::json(['data' => [], 'total' => 0]);
            return;
        }

        try {
            $stripe   = new StripeService();
            $response = $stripe->listInvoices($customerId, 20);
            $invoices = $response['data'] ?? [];

            $formatted = array_map(fn($inv) => [
                'id'                  => $inv['id'],
                'number'              => $inv['number'] ?? ('INV-' . substr($inv['id'], -6)),
                'amount'              => $inv['amount_paid'] / 100,
                'currency'            => strtoupper($inv['currency']),
                'status'              => $inv['status'],
                'date'                => $inv['created'],
                'pdf_url'             => $inv['invoice_pdf'] ?? null,
                'hosted_invoice_url'  => $inv['hosted_invoice_url'] ?? null,
                'description'         => $inv['lines']['data'][0]['description'] ?? null,
                'last4'               => $inv['charge']['payment_method_details']['card']['last4'] ?? null,
            ], $invoices);

            Response::json(['data' => $formatted, 'total' => count($formatted)]);
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured.', 503);
        }
    }

    /**
     * GET /subscription/invoices/:id/download
     * Return a download URL for a specific Stripe invoice.
     */
    public function downloadInvoice(array $params): void
    {
        $coachId   = new ObjectId($params['_auth']['sub']);
        $invoiceId = $params['id'] ?? '';
        if (!$invoiceId) {
            Response::error('Invoice ID required', 400);
        }

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            Response::error('No billing account found', 400);
        }

        try {
            $stripe = new StripeService();
            $invoice = $stripe->retrieveInvoice($invoiceId);

            if (($invoice['customer'] ?? '') !== $customerId) {
                Response::error('Invoice not found', 404);
            }

            Response::success([
                'download_url' => $invoice['invoice_pdf'] ?? null,
                'hosted_invoice_url' => $invoice['hosted_invoice_url'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            Response::error('Failed to retrieve invoice: ' . $e->getMessage(), 503);
        }
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
            return;
        }

        $type = $event['type'] ?? '';
        $data = $event['data']['object'] ?? [];

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

            case 'invoice.payment_failed':
                $this->handlePaymentFailed($data);
                break;
        }

        Response::success(null, 'Webhook processed');
    }

    private function handleCheckoutCompleted(array $session): void
    {
        $coachId       = $session['metadata']['coach_id'] ?? null;
        $subscriptionId = $session['subscription'] ?? null;
        $customerId    = $session['customer'] ?? null;
        $pendingTier    = $session['metadata']['pending_tier'] ?? null;

        if (!$coachId || !$subscriptionId) return;

        // Fetch the subscription to determine tier
        $stripe = new StripeService();
        $sub    = $stripe->getSubscription($subscriptionId);
        $tier   = $this->tierFromPriceId($sub['items']['data'][0]['price']['id'] ?? '');
        $period = $this->periodFromPriceId($sub['items']['data'][0]['price']['id'] ?? '');

        // Use pending_tier from metadata as fallback
        if ($pendingTier && in_array($pendingTier, ['pro', 'business'])) {
            $tier = $pendingTier;
        }

        // Determine subscription status - convert 'incomplete' to 'active'/'trialing'
        $status = $sub['status'] ?? 'active';
        if ($status === 'incomplete') {
            $status = 'trialing'; // Stripe sets incomplete for trials before first payment
        }

        $set = [
            'stripe_customer_id'     => $customerId,
            'stripe_subscription_id' => $subscriptionId,
            'subscription_tier'      => $tier,
            'subscription_status'    => $status,
            'subscription_period'    => $period,
            'subscription_period_end' => new \MongoDB\BSON\UTCDateTime(($sub['current_period_end'] ?? time()) * 1000),
            'cancel_at_period_end'   => false,
            'updated_at'             => new \MongoDB\BSON\UTCDateTime(),
        ];

        if (!empty($sub['trial_end'])) {
            $set['trial_ends_at'] = new \MongoDB\BSON\UTCDateTime((int) $sub['trial_end'] * 1000);
        }

        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => $set]
        );
    }

    private function handleSubscriptionUpdated(array $sub): void
    {
        $coachId = $sub['metadata']['coach_id'] ?? null;
        if (!$coachId) return;

        $tier = $this->tierFromPriceId($sub['items']['data'][0]['price']['id'] ?? '');
        $period = $this->periodFromPriceId($sub['items']['data'][0]['price']['id'] ?? '');

        $set = [
            'subscription_tier'       => $tier,
            'subscription_period'     => $period,
            'subscription_status'     => $sub['status'] ?? 'active',
            'subscription_period_end' => new \MongoDB\BSON\UTCDateTime(($sub['current_period_end'] ?? time()) * 1000),
            'cancel_at_period_end'    => $sub['cancel_at_period_end'] ?? false,
            'updated_at'              => new \MongoDB\BSON\UTCDateTime(),
        ];

        if (!empty($sub['trial_end'])) {
            $set['trial_ends_at'] = new \MongoDB\BSON\UTCDateTime((int) $sub['trial_end'] * 1000);
        }

        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => $set]
        );
    }

    private function handleSubscriptionDeleted(array $sub): void
    {
        $coachId = $sub['metadata']['coach_id'] ?? null;
        if (!$coachId) return;

        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => [
                'subscription_tier'      => 'none',
                'subscription_status'      => 'cancelled',
                'subscription_period'      => 'monthly',
                'cancel_at_period_end'     => false,
                'updated_at'               => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );
    }

    private function handlePaymentFailed(array $invoice): void
    {
        $customerId = $invoice['customer'] ?? null;
        if (!$customerId) return;

        Database::collection('coaches')->updateOne(
            ['stripe_customer_id' => $customerId],
            ['$set' => [
                'subscription_status' => 'past_due',
                'updated_at'          => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );
    }

    private function tierFromPriceId(string $priceId): string
    {
        $proIds = array_filter([
            $_ENV['STRIPE_PRICE_PRO_MONTHLY']     ?? $_ENV['STRIPE_PRICE_PRO'] ?? '',
            $_ENV['STRIPE_PRICE_PRO_QUARTERLY']    ?? '',
            $_ENV['STRIPE_PRICE_PRO_SEMI_ANNUAL']  ?? '',
            $_ENV['STRIPE_PRICE_PRO_ANNUAL']       ?? '',
        ]);
        $businessIds = array_filter([
            $_ENV['STRIPE_PRICE_BUSINESS_MONTHLY']     ?? $_ENV['STRIPE_PRICE_BUSINESS'] ?? '',
            $_ENV['STRIPE_PRICE_BUSINESS_QUARTERLY']    ?? '',
            $_ENV['STRIPE_PRICE_BUSINESS_SEMI_ANNUAL']  ?? '',
            $_ENV['STRIPE_PRICE_BUSINESS_ANNUAL']       ?? '',
        ]);

        if (in_array($priceId, $proIds, true))      return 'pro';
        if (in_array($priceId, $businessIds, true)) return 'business';
        return 'pro'; // default fallback
    }

    private function periodFromPriceId(string $priceId): string
    {
        $map = [
            'monthly'     => [
                $_ENV['STRIPE_PRICE_PRO_MONTHLY']     ?? $_ENV['STRIPE_PRICE_PRO'] ?? '',
                $_ENV['STRIPE_PRICE_BUSINESS_MONTHLY'] ?? $_ENV['STRIPE_PRICE_BUSINESS'] ?? '',
            ],
            'quarterly'   => [
                $_ENV['STRIPE_PRICE_PRO_QUARTERLY']     ?? '',
                $_ENV['STRIPE_PRICE_BUSINESS_QUARTERLY'] ?? '',
            ],
            'semi_annual' => [
                $_ENV['STRIPE_PRICE_PRO_SEMI_ANNUAL']     ?? '',
                $_ENV['STRIPE_PRICE_BUSINESS_SEMI_ANNUAL'] ?? '',
            ],
            'annual'      => [
                $_ENV['STRIPE_PRICE_PRO_ANNUAL']     ?? '',
                $_ENV['STRIPE_PRICE_BUSINESS_ANNUAL'] ?? '',
            ],
        ];

        foreach ($map as $period => $priceIds) {
            if (in_array($priceId, $priceIds, true)) {
                return $period;
            }
        }
        return 'monthly';
    }

    /**
     * Get the client limit for a given tier (null = unlimited).
     */
    public static function getClientLimit(string $tier): ?int
    {
        return self::TIER_LIMITS[$tier] ?? self::TIER_LIMITS['free'];
    }
}
