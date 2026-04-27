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
        'free'     => 3,
        'pro'      => 25,
        'business' => 999999,
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

        $clientCount = Database::collection('clients')->countDocuments([
            'coach_id' => $coachId,
            'active'   => true,
        ]);

        Response::success([
            'tier'             => $tier,
            'status'           => $status,
            'client_limit'     => $limit,
            'client_count'     => $clientCount,
            'trial_ends_at'    => isset($coach['trial_ends_at']) ? (string) $coach['trial_ends_at'] : null,
            'current_period_end' => isset($coach['subscription_period_end']) ? (string) $coach['subscription_period_end'] : null,
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
        if (!in_array($tier, ['free', 'pro', 'business'])) {
            Response::error('Invalid tier. Choose free, pro, or business', 422);
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
        $priceId = $stripe->getPriceIdForTier($tier);

        if (empty($priceId)) {
            Response::error('Stripe price not configured for this tier', 500);
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

        // Store selected tier temporarily for webhook to use
        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => [
                'pending_subscription_tier' => $tier,
                'updated_at'                => new \MongoDB\BSON\UTCDateTime(),
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
        $coachId = new ObjectId($params['_auth']['sub']);
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
        $priceId = $stripe->getPriceIdForTier($tier);

        if (empty($priceId)) {
            Response::error('Stripe price not configured for this tier', 500);
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
        $coachId = new ObjectId($params['_auth']['sub']);
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

        // Use pending_tier from metadata as fallback
        if ($pendingTier && in_array($pendingTier, ['pro', 'business'])) {
            $tier = $pendingTier;
        }

        // Determine subscription status - convert 'pending' to 'active'/'trialing'
        $status = $sub['status'] ?? 'active';
        if ($status === 'incomplete') {
            $status = 'trialing'; // Stripe sets incomplete for trials before first payment
        }

        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => [
                'stripe_customer_id'     => $customerId,
                'stripe_subscription_id' => $subscriptionId,
                'subscription_tier'      => $tier,
                'subscription_status'    => $status,
                'subscription_period_end' => new \MongoDB\BSON\UTCDateTime(($sub['current_period_end'] ?? time()) * 1000),
                'cancel_at_period_end'   => false,
                'updated_at'             => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );
    }

    private function handleSubscriptionUpdated(array $sub): void
    {
        $coachId = $sub['metadata']['coach_id'] ?? null;
        if (!$coachId) return;

        $tier = $this->tierFromPriceId($sub['items']['data'][0]['price']['id'] ?? '');

        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => [
                'subscription_tier'       => $tier,
                'subscription_status'     => $sub['status'] ?? 'active',
                'subscription_period_end' => new \MongoDB\BSON\UTCDateTime(($sub['current_period_end'] ?? time()) * 1000),
                'cancel_at_period_end'    => $sub['cancel_at_period_end'] ?? false,
                'updated_at'              => new \MongoDB\BSON\UTCDateTime(),
            ]]
        );
    }

    private function handleSubscriptionDeleted(array $sub): void
    {
        $coachId = $sub['metadata']['coach_id'] ?? null;
        if (!$coachId) return;

        Database::collection('coaches')->updateOne(
            ['_id' => new ObjectId($coachId)],
            ['$set' => [
                'subscription_tier'      => 'free',
                'subscription_status'    => 'cancelled',
                'cancel_at_period_end'   => false,
                'updated_at'             => new \MongoDB\BSON\UTCDateTime(),
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
        $proPriceId      = $_ENV['STRIPE_PRICE_PRO'] ?? '';
        $businessPriceId = $_ENV['STRIPE_PRICE_BUSINESS'] ?? '';

        if ($priceId === $proPriceId)      return 'pro';
        if ($priceId === $businessPriceId) return 'business';
        return 'pro'; // default fallback
    }

    /**
     * Get the client limit for a given tier.
     */
    public static function getClientLimit(string $tier): int
    {
        return self::TIER_LIMITS[$tier] ?? self::TIER_LIMITS['free'];
    }
}
