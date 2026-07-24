<?php
declare(strict_types=1);

namespace App\Services;

class StripeService
{
    private string $secretKey;
    private string $webhookSecret;

    public static function isConfigured(): bool
    {
        return !empty($_ENV['STRIPE_SECRET_KEY']);
    }

    public function __construct()
    {
        $this->secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        $this->webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

        if (empty($this->secretKey)) {
            throw new \RuntimeException('STRIPE_SECRET_KEY not configured');
        }
    }

    /**
     * Make a Stripe API request.
     */
    private function request(string $method, string $endpoint, array $params = []): array
    {
        $url = "https://api.stripe.com/v1{$endpoint}";
        $ch  = curl_init();

        $headers = [
            "Authorization: Bearer {$this->secretKey}",
            'Content-Type: application/x-www-form-urlencoded',
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ];

        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
        } elseif ($method === 'GET') {
            if ($params) {
                $url .= '?' . http_build_query($params);
            }
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        $opts[CURLOPT_URL] = $url;
        curl_setopt_array($ch, $opts);

        $result = curl_exec($ch);
        $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($result, true) ?? [];

        if ($code >= 400) {
            $msg = $data['error']['message'] ?? 'Stripe API error';
            throw new \RuntimeException($msg, $code);
        }

        return $data;
    }

    public function createCustomer(string $email, string $name, string $coachId): array
    {
        return $this->request('POST', '/customers', [
            'email'            => $email,
            'name'             => $name,
            'metadata[coach_id]' => $coachId,
        ]);
    }

    public function createCheckoutSession(string $customerId, string $priceId, string $coachId, ?string $successUrl = null, ?string $cancelUrl = null): array
    {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000';
        return $this->request('POST', '/checkout/sessions', [
            'customer'                 => $customerId,
            'mode'                     => 'subscription',
            'line_items[0][price]'     => $priceId,
            'line_items[0][quantity]'  => 1,
            'success_url'              => $successUrl ?? "{$frontendUrl}/subscription/success",
            'cancel_url'               => $cancelUrl ?? "{$frontendUrl}/subscription/cancel",
            'metadata[coach_id]'       => $coachId,
            'subscription_data[trial_period_days]' => (int) ($_ENV['STRIPE_TRIAL_DAYS'] ?? 30),
            'subscription_data[metadata][coach_id]' => $coachId,
        ]);
    }

    /**
     * Create a Stripe Checkout session for signup flow.
     */
    public function createSignupCheckoutSession(string $customerId, string $priceId, string $coachId): array
    {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000';
        return $this->request('POST', '/checkout/sessions', [
            'customer'                 => $customerId,
            'mode'                     => 'subscription',
            'line_items[0][price]'     => $priceId,
            'line_items[0][quantity]'  => 1,
            'success_url'              => "{$frontendUrl}/subscription/success?session_id={CHECKOUT_SESSION_ID}",
            'cancel_url'               => "{$frontendUrl}/subscription/cancel",
            'metadata[coach_id]'       => $coachId,
            'subscription_data[trial_period_days]' => (int) ($_ENV['STRIPE_TRIAL_DAYS'] ?? 30),
            'subscription_data[metadata][coach_id]' => $coachId,
            'subscription_data[metadata][pending_tier]' => $this->getTierFromPriceId($priceId),
        ]);
    }

    /**
     * Get tier name from price ID.
     */
    private function getTierFromPriceId(string $priceId): string
    {
        if (!$priceId) return 'pro';

        foreach ($_ENV as $key => $val) {
            if (!str_starts_with($key, 'STRIPE_PRICE_')) continue;
            if ($val === $priceId) {
                $tier = strtolower(explode('_', substr($key, 13))[0]);
                if (in_array($tier, ['pro', 'business'])) return $tier;
            }
        }
        return 'pro';
    }

    public function createPortalSession(string $customerId): array
    {
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'http://localhost:3000';
        return $this->request('POST', '/billing_portal/sessions', [
            'customer'   => $customerId,
            'return_url' => "{$frontendUrl}/billing",
        ]);
    }

    public function getSubscription(string $subscriptionId): array
    {
        return $this->request('GET', "/subscriptions/{$subscriptionId}");
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        return $this->request('POST', "/subscriptions/{$subscriptionId}", [
            'cancel_at_period_end' => 'true',
        ]);
    }

    public function verifyWebhookSignature(string $payload, string $sigHeader): array
    {
        if (empty($this->webhookSecret)) {
            throw new \RuntimeException('STRIPE_WEBHOOK_SECRET not configured');
        }

        $elements = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$key, $value] = explode('=', trim($part), 2);
            $elements[$key] = $value;
        }

        $timestamp = $elements['t'] ?? '';
        $signature = $elements['v1'] ?? '';

        if (empty($timestamp) || empty($signature)) {
            throw new \RuntimeException('Invalid Stripe signature format');
        }

        // Reject webhooks older than 5 minutes
        if (abs(time() - (int) $timestamp) > 300) {
            throw new \RuntimeException('Webhook timestamp too old');
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->webhookSecret);

        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Invalid webhook signature');
        }

        return json_decode($payload, true);
    }

    public function getPriceIdForTier(string $tier): string
    {
        return match ($tier) {
            'pro'      => $_ENV['STRIPE_PRICE_PRO'] ?? '',
            'business' => $_ENV['STRIPE_PRICE_BUSINESS'] ?? '',
            default    => throw new \RuntimeException("Unknown tier: {$tier}"),
        };
    }

    public function getPriceIdForTierAndPeriod(string $tier, string $period): string
    {
        $key = 'STRIPE_PRICE_' . strtoupper($tier) . '_' . strtoupper($period);
        // Fall back to generic tier price if period-specific price not set
        return $_ENV[$key] ?? $this->getPriceIdForTier($tier);
    }

    public function updateSubscription(string $subscriptionId, string $newPriceId): array
    {
        // Get current subscription to find the item ID
        $sub    = $this->request('GET', "/subscriptions/{$subscriptionId}");
        $itemId = $sub['items']['data'][0]['id'] ?? null;

        if (!$itemId) {
            throw new \RuntimeException('No subscription item found');
        }

        return $this->request('POST', "/subscriptions/{$subscriptionId}", [
            'items[0][id]'    => $itemId,
            'items[0][price]' => $newPriceId,
            'proration_behavior' => 'create_prorations',
        ]);
    }

    public function listInvoices(string $customerId, int $limit = 24): array
    {
        return $this->request('GET', '/invoices', [
            'customer' => $customerId,
            'limit'    => $limit,
        ]);
    }

    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', "/invoices/{$invoiceId}");
    }

    public function listPaymentMethods(string $customerId): array
    {
        return $this->request('GET', '/payment_methods', [
            'customer' => $customerId,
            'type'     => 'card',
        ]);
    }

    public function createSetupIntent(string $customerId): array
    {
        return $this->request('POST', '/setup_intents', [
            'customer'             => $customerId,
            'payment_method_types' => ['card'],
        ]);
    }

    /**
     * Create a SetupIntent specifically for trial onboarding.
     * Returns client_secret so the frontend can confirm the card via Stripe.js.
     */
    public function createTrialSetupIntent(string $customerId, string $coachId): array
    {
        return $this->request('POST', '/setup_intents', [
            'customer'                  => $customerId,
            'payment_method_types'      => ['card'],
            'usage'                     => 'off_session',
            'metadata[coach_id]'        => $coachId,
            'metadata[purpose]'         => 'trial_signup',
        ]);
    }

    /**
     * Create a trial subscription using a pre-confirmed payment method.
     * The subscription enters trialing state immediately; Stripe charges
     * the default_payment_method automatically when the trial ends.
     */
    public function createTrialSubscription(
        string $customerId,
        string $priceId,
        string $paymentMethodId,
        string $coachId,
        int    $trialDays = 30
    ): array {
        // Set default payment method on customer first
        $this->setDefaultPaymentMethod($customerId, $paymentMethodId);

        $trialEnd = time() + ($trialDays * 86400);

        return $this->request('POST', '/subscriptions', [
            'customer'                              => $customerId,
            'items[0][price]'                       => $priceId,
            'trial_end'                             => $trialEnd,
            'default_payment_method'                => $paymentMethodId,
            'payment_settings[save_default_payment_method]' => 'on_subscription',
            'metadata[coach_id]'                    => $coachId,
            'expand[]'                              => 'latest_invoice.payment_intent',
        ]);
    }

    public function attachPaymentMethod(string $paymentMethodId, string $customerId): array
    {
        return $this->request('POST', "/payment_methods/{$paymentMethodId}/attach", [
            'customer' => $customerId,
        ]);
    }

    public function detachPaymentMethod(string $paymentMethodId): array
    {
        return $this->request('POST', "/payment_methods/{$paymentMethodId}/detach");
    }

    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): array
    {
        return $this->request('POST', "/customers/{$customerId}", [
            'invoice_settings[default_payment_method]' => $paymentMethodId,
        ]);
    }
}
