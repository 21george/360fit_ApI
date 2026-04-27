<?php
declare(strict_types=1);

namespace App\Services;

class StripeService
{
    private string $secretKey;
    private string $webhookSecret;

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
            'subscription_data[trial_period_days]' => '14',
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
            'subscription_data[trial_period_days]' => '14',
            'subscription_data[metadata][coach_id]' => $coachId,
            'subscription_data[metadata][pending_tier]' => $this->getTierFromPriceId($priceId),
        ]);
    }

    /**
     * Get tier name from price ID.
     */
    private function getTierFromPriceId(string $priceId): string
    {
        $proPriceId      = $_ENV['STRIPE_PRICE_PRO'] ?? '';
        $businessPriceId = $_ENV['STRIPE_PRICE_BUSINESS'] ?? '';

        if ($priceId === $proPriceId)      return 'pro';
        if ($priceId === $businessPriceId) return 'business';
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
}
