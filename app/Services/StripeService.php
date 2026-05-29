<?php
declare(strict_types=1);

namespace App\Services;

use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class StripeService
{
    private StripeClient $stripe;
    private string $webhookSecret;

    public function __construct()
    {
        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? '';
        $this->webhookSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? '';

        if (empty($secretKey)) {
            throw new \RuntimeException('STRIPE_SECRET_KEY not configured');
        }

        $this->stripe = new StripeClient($secretKey);
    }

    public function createCustomer(string $email, string $name, string $coachId): array
    {
        try {
            $customer = $this->stripe->customers->create([
                'email'    => $email,
                'name'     => $name,
                'metadata' => ['coach_id' => $coachId],
            ]);
            return $customer->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    /**
     * Create a standard redirect-based Checkout session.
     */
    public function createCheckoutSession(string $customerId, string $priceId, string $coachId, ?string $successUrl = null, ?string $cancelUrl = null): array
    {
        $frontendUrl = ($_ENV['FRONTEND_URL'] ?? '') ?: 'http://localhost:3000';
        try {
            $session = $this->stripe->checkout->sessions->create([
                'customer'     => $customerId,
                'mode'         => 'subscription',
                'line_items'   => [
                    ['price' => $priceId, 'quantity' => 1],
                ],
                'success_url'  => $successUrl ?? rtrim($frontendUrl, '/') . '/subscription/success',
                'cancel_url'   => $cancelUrl ?? rtrim($frontendUrl, '/') . '/subscription/cancel',
                'metadata'     => ['coach_id' => $coachId],
                'subscription_data' => [
                    'trial_period_days' => 14,
                    'metadata' => ['coach_id' => $coachId],
                ],
            ]);
            return $session->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    /**
     * Create an embedded Checkout session for the new Stripe Checkout Elements SDK.
     */
    public function createEmbeddedCheckoutSession(string $customerId, string $priceId, string $coachId): array
    {
        $frontendUrl = ($_ENV['FRONTEND_URL'] ?? '') ?: 'http://localhost:3000';
        try {
            $session = $this->stripe->checkout->sessions->create([
                'customer'     => $customerId,
                'mode'         => 'subscription',
                'ui_mode'      => 'embedded',
                'line_items'   => [
                    ['price' => $priceId, 'quantity' => 1],
                ],
                'return_url'   => rtrim($frontendUrl, '/') . '/subscription/success?session_id={CHECKOUT_SESSION_ID}',
                'metadata'     => ['coach_id' => $coachId],
                'subscription_data' => [
                    'trial_period_days' => 14,
                    'metadata' => ['coach_id' => $coachId],
                ],
            ]);
            return $session->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    /**
     * Create a Stripe Checkout session for signup flow.
     */
    public function createSignupCheckoutSession(string $customerId, string $priceId, string $coachId): array
    {
        $frontendUrl = ($_ENV['FRONTEND_URL'] ?? '') ?: 'http://localhost:3000';
        try {
            $session = $this->stripe->checkout->sessions->create([
                'customer'     => $customerId,
                'mode'         => 'subscription',
                'line_items'   => [
                    ['price' => $priceId, 'quantity' => 1],
                ],
                'success_url'  => rtrim($frontendUrl, '/') . '/subscription/success?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url'   => rtrim($frontendUrl, '/') . '/subscription/cancel',
                'metadata'     => ['coach_id' => $coachId],
                'subscription_data' => [
                    'trial_period_days' => 14,
                    'metadata' => [
                        'coach_id'      => $coachId,
                        'pending_tier'  => $this->getTierFromPriceId($priceId),
                    ],
                ],
            ]);
            return $session->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    /**
     * Get tier name from price ID.
     */
    private function getTierFromPriceId(string $priceId): string
    {
        $proPriceId      = $_ENV['STRIPE_PRICE_PRO_MONTHLY'] ?? $_ENV['STRIPE_PRICE_PRO'] ?? '';
        $businessPriceId = $_ENV['STRIPE_PRICE_BUSINESS_MONTHLY'] ?? $_ENV['STRIPE_PRICE_BUSINESS'] ?? '';

        if ($priceId === $proPriceId)      return 'pro';
        if ($priceId === $businessPriceId) return 'business';
        return 'pro';
    }

    public function createPortalSession(string $customerId): array
    {
        $frontendUrl = ($_ENV['FRONTEND_URL'] ?? '') ?: 'http://localhost:3000';
        try {
            $session = $this->stripe->billingPortal->sessions->create([
                'customer'  => $customerId,
                'return_url' => rtrim($frontendUrl, '/') . '/billing',
            ]);
            return $session->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    public function getSubscription(string $subscriptionId): array
    {
        try {
            $sub = $this->stripe->subscriptions->retrieve($subscriptionId);
            return $sub->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    public function cancelSubscription(string $subscriptionId): array
    {
        try {
            $sub = $this->stripe->subscriptions->update($subscriptionId, [
                'cancel_at_period_end' => true,
            ]);
            return $sub->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    public function verifyWebhookSignature(string $payload, string $sigHeader): array
    {
        if (empty($this->webhookSecret)) {
            throw new \RuntimeException('STRIPE_WEBHOOK_SECRET not configured');
        }

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
            return $event->toArray();
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            throw new \RuntimeException('Invalid webhook signature: ' . $e->getMessage(), 400);
        }
    }

    public function listInvoices(string $customerId, int $limit = 20): array
    {
        try {
            $invoices = $this->stripe->invoices->all([
                'customer' => $customerId,
                'limit'    => $limit,
                'status'   => 'paid',
                'expand'   => ['data.charge'],
            ]);
            return $invoices->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    /**
     * Update an existing subscription to a new price (upgrade or downgrade).
     * Uses 'always_invoice' proration so the customer is charged/credited immediately.
     */
    public function updateSubscription(string $subscriptionId, string $newPriceId): array
    {
        try {
            $sub = $this->stripe->subscriptions->retrieve($subscriptionId);
            $itemId = $sub->items->data[0]->id ?? null;

            if (!$itemId) {
                throw new \RuntimeException('No subscription item found to update');
            }

            $updated = $this->stripe->subscriptions->update($subscriptionId, [
                'items'              => [
                    ['id' => $itemId, 'price' => $newPriceId],
                ],
                'proration_behavior' => 'always_invoice',
            ]);
            return $updated->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    /**
     * Get the appropriate Stripe Price ID for a tier + billing period combination.
     * Falls back to monthly price if period-specific price is not configured.
     */
    public function getPriceIdForTier(string $tier, string $period = 'monthly'): string
    {
        $envMap = [
            'pro' => [
                'monthly'     => $_ENV['STRIPE_PRICE_PRO_MONTHLY']     ?? $_ENV['STRIPE_PRICE_PRO'] ?? '',
                'quarterly'   => $_ENV['STRIPE_PRICE_PRO_QUARTERLY']   ?? $_ENV['STRIPE_PRICE_PRO'] ?? '',
                'semi_annual' => $_ENV['STRIPE_PRICE_PRO_SEMI_ANNUAL'] ?? $_ENV['STRIPE_PRICE_PRO'] ?? '',
                'annual'      => $_ENV['STRIPE_PRICE_PRO_ANNUAL']      ?? $_ENV['STRIPE_PRICE_PRO'] ?? '',
            ],
            'business' => [
                'monthly'     => $_ENV['STRIPE_PRICE_BUSINESS_MONTHLY']     ?? $_ENV['STRIPE_PRICE_BUSINESS'] ?? '',
                'quarterly'   => $_ENV['STRIPE_PRICE_BUSINESS_QUARTERLY']   ?? $_ENV['STRIPE_PRICE_BUSINESS'] ?? '',
                'semi_annual' => $_ENV['STRIPE_PRICE_BUSINESS_SEMI_ANNUAL'] ?? $_ENV['STRIPE_PRICE_BUSINESS'] ?? '',
                'annual'      => $_ENV['STRIPE_PRICE_BUSINESS_ANNUAL']      ?? $_ENV['STRIPE_PRICE_BUSINESS'] ?? '',
            ],
        ];

        $priceId = $envMap[$tier][$period] ?? '';
        if (empty($priceId)) {
            throw new \RuntimeException("Stripe price not configured for tier: {$tier}, period: {$period}");
        }
        return $priceId;
    }

    public function retrieveInvoice(string $invoiceId): array
    {
        try {
            $invoice = $this->stripe->invoices->retrieve($invoiceId);
            return $invoice->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    public function createSetupIntent(string $customerId): array
    {
        try {
            $intent = $this->stripe->setupIntents->create([
                'customer'       => $customerId,
                'usage'          => 'off_session',
                'payment_method_types' => ['card'],
            ]);
            return $intent->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    public function getPaymentMethods(string $customerId): array
    {
        try {
            $methods = $this->stripe->customers->allPaymentMethods($customerId, ['type' => 'card']);
            return $methods->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    public function attachPaymentMethod(string $customerId, string $paymentMethodId): array
    {
        try {
            $pm = $this->stripe->paymentMethods->attach($paymentMethodId, ['customer' => $customerId]);
            return $pm->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId): array
    {
        try {
            $customer = $this->stripe->customers->update($customerId, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodId],
            ]);
            return $customer->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    public function detachPaymentMethod(string $paymentMethodId): array
    {
        try {
            $pm = $this->stripe->paymentMethods->detach($paymentMethodId);
            return $pm->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }

    public function getUpcomingInvoice(string $customerId): array
    {
        try {
            $invoice = $this->stripe->invoices->upcoming(['customer' => $customerId]);
            return $invoice->toArray();
        } catch (ApiErrorException $e) {
            throw new \RuntimeException($e->getMessage(), $e->getHttpStatusCode());
        }
    }
}
