<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\{StripeService, BillingService};
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;

class SettingsController
{
    // ─── Notifications ──────────────────────────────────────────────────────────

    public function getNotifications(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(
            ['_id' => $coachId],
            ['projection' => ['notification_settings' => 1]]
        );

        $defaults = [
            'email_sms'      => true,
            'appointments'   => false,
            'consultation'   => false,
            'test_result'    => true,
            'login_alerts'   => true,
            'dnd_enabled'    => true,
            'dnd_from'       => '22:00',
            'dnd_to'         => '07:00',
        ];

        $settings = array_merge($defaults, (array) ($coach['notification_settings'] ?? []));
        Response::success($settings);
    }

    public function updateNotifications(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();

        $allowed = [
            'email_sms', 'appointments', 'consultation',
            'test_result', 'login_alerts',
            'dnd_enabled', 'dnd_from', 'dnd_to',
        ];
        $update = array_filter($body, fn($k) => in_array($k, $allowed), ARRAY_FILTER_USE_KEY);

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => [
                'notification_settings' => $update,
                'updated_at'          => new UTCDateTime(),
            ]]
        );

        Response::success($update, 'Notification settings updated');
    }

    // ─── Billing Address ────────────────────────────────────────────────────────

    public function getBilling(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(
            ['_id' => $coachId],
            ['projection' => [
                'billing_address' => 1,
                'billing_email'   => 1,
                'payment_methods' => 1,
                'stripe_customer_id' => 1,
                'subscription_tier'  => 1,
            ]]
        );

        $billing = new BillingService();
        $country = $billing->detectCountry($coach['billing_address'] ?? null);
        $pricing = $billing->calculatePrice($country);

        $upcomingInvoice = null;
        if (!empty($coach['stripe_customer_id']) && ($coach['subscription_tier'] ?? 'free') !== 'free') {
            try {
                $stripe  = new StripeService();
                $invoice = $stripe->getUpcomingInvoice($coach['stripe_customer_id']);
                $upcomingInvoice = [
                    'amount_due' => $invoice['amount_due'] ?? null,
                    'currency'   => $invoice['currency'] ?? 'eur',
                    'period_start' => $invoice['period_start'] ?? null,
                    'period_end'   => $invoice['period_end'] ?? null,
                ];
            } catch (\Throwable $e) {
                error_log('Failed to fetch upcoming invoice in billing: ' . $e->getMessage());
            }
        }

        Response::success([
            'billing_address' => $coach['billing_address'] ?? [
                'street'  => '',
                'city'    => '',
                'state'   => '',
                'zip'     => '',
                'country' => 'Australia',
            ],
            'billing_email'   => $coach['billing_email'] ?? ($coach['email'] ?? ''),
            'payment_methods' => $coach['payment_methods'] ?? [],
            'tax_info'        => [
                'base_price' => $pricing['base'],
                'vat_rate'   => $pricing['vat_rate'],
                'vat_amount' => $pricing['vat_amount'],
                'total'      => $pricing['total'],
                'currency'   => $pricing['currency'],
                'country'    => $pricing['country'],
            ],
            'upcoming_invoice' => $upcomingInvoice,
        ]);
    }

    public function updateBilling(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();

        $update = [];
        if (isset($body['billing_address'])) {
            $update['billing_address'] = array_intersect_key(
                $body['billing_address'],
                array_flip(['street', 'city', 'state', 'zip', 'country'])
            );
        }
        if (isset($body['billing_email'])) {
            $update['billing_email'] = filter_var($body['billing_email'], FILTER_VALIDATE_EMAIL) ?: '';
        }

        if (empty($update)) {
            Response::error('No valid fields provided', 422);
        }

        $update['updated_at'] = new UTCDateTime();
        Database::collection('coaches')->updateOne(['_id' => $coachId], ['$set' => $update]);

        Response::success(null, 'Billing details updated');
    }

    public function createSetupIntent(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        try {
            $stripe = new StripeService();
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

        $intent = $stripe->createSetupIntent($customerId);

        Response::success([
            'client_secret' => $intent['client_secret'],
            'setup_intent_id' => $intent['id'],
        ], 'SetupIntent created');
    }

    public function listPaymentMethods(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            Response::success(['payment_methods' => []]);
            return;
        }

        try {
            $stripe = new StripeService();
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }

        try {
            $result = $stripe->getPaymentMethods($customerId);
        } catch (\RuntimeException $e) {
            Response::error('Failed to fetch payment methods: ' . $e->getMessage(), 500);
        }

        $methods = array_map(fn($pm) => [
            'id'        => $pm['id'],
            'brand'     => $pm['card']['brand'] ?? 'unknown',
            'last4'     => $pm['card']['last4'] ?? '',
            'exp_month' => $pm['card']['exp_month'] ?? null,
            'exp_year'  => $pm['card']['exp_year'] ?? null,
            'is_default' => ($coach['stripe_default_payment_method'] ?? '') === $pm['id'],
        ], $result['data'] ?? []);

        Response::success(['payment_methods' => $methods]);
    }

    public function addPaymentMethod(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['payment_method_id' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $customerId = $coach['stripe_customer_id'] ?? null;
        if (!$customerId) {
            Response::error('No Stripe customer. Create a subscription first.', 400);
        }

        try {
            $stripe = new StripeService();
        } catch (\RuntimeException $e) {
            Response::error('Payment processing is not configured. Please contact support.', 503);
        }

        $paymentMethodId = $body['payment_method_id'];

        try {
            $stripe->attachPaymentMethod($customerId, $paymentMethodId);
            if (!empty($body['is_default'])) {
                $stripe->setDefaultPaymentMethod($customerId, $paymentMethodId);
                Database::collection('coaches')->updateOne(
                    ['_id' => $coachId],
                    ['$set' => ['stripe_default_payment_method' => $paymentMethodId]]
                );
            }
        } catch (\RuntimeException $e) {
            Response::error('Failed to attach payment method: ' . $e->getMessage(), 500);
        }

        Response::success(['payment_method_id' => $paymentMethodId], 'Payment method attached');
    }

    public function deletePaymentMethod(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $id      = $params['id'] ?? '';

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        try {
            $stripe = new StripeService();
            $stripe->detachPaymentMethod($id);
        } catch (\RuntimeException $e) {
            error_log('Stripe detach failed: ' . $e->getMessage());
        }

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$pull' => ['payment_methods' => ['id' => $id]], '$set' => ['updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Payment method removed');
    }

    public function setDefaultPaymentMethod(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $id      = $params['id'] ?? '';

        $coach = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $customerId = $coach['stripe_customer_id'] ?? null;
        if ($customerId) {
            try {
                $stripe = new StripeService();
                $stripe->setDefaultPaymentMethod($customerId, $id);
            } catch (\RuntimeException $e) {
                Response::error('Failed to set default payment method: ' . $e->getMessage(), 500);
            }
        }

        $methods = $coach['payment_methods'] ?? [];
        foreach ($methods as &$m) {
            $m['is_default'] = ($m['id'] === $id);
        }

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => [
                'payment_methods' => $methods,
                'stripe_default_payment_method' => $id,
                'updated_at' => new UTCDateTime(),
            ]]
        );

        Response::success(null, 'Default payment method updated');
    }

    // ─── Team Members ───────────────────────────────────────────────────────────

    public function getTeam(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(
            ['_id' => $coachId],
            ['projection' => ['team_members' => 1]]
        );

        $members = $coach['team_members'] ?? [
            ['id' => '1', 'name' => 'Zaza Gonzales', 'role' => 'Assistant', 'access' => 'Full Access', 'avatar' => 'ZG'],
            ['id' => '2', 'name' => 'Grace White', 'role' => 'Nurse', 'access' => 'View Only', 'avatar' => 'GW'],
            ['id' => '3', 'name' => 'Freddy Ulric', 'role' => 'Nurse', 'access' => 'View Only', 'avatar' => 'FU'],
            ['id' => '4', 'name' => 'Sarah Chen', 'role' => 'Therapist', 'access' => 'Edit', 'avatar' => 'SC'],
        ];

        Response::success($members);
    }

    public function addTeamMember(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, ['name' => 'required', 'role' => 'required']);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $member = [
            'id'     => bin2hex(random_bytes(8)),
            'name'   => $body['name'],
            'role'   => $body['role'],
            'access' => $body['access'] ?? 'View Only',
            'avatar' => implode('', array_map(fn($n) => $n[0], explode(' ', $body['name']))),
        ];

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$push' => ['team_members' => $member], '$set' => ['updated_at' => new UTCDateTime()]]
        );

        Response::success($member, 'Team member added');
    }

    public function updateTeamMember(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $id      = $params['id'] ?? '';
        $body    = Request::body();

        $coach   = Database::collection('coaches')->findOne(['_id' => $coachId]);
        if (!$coach) Response::error('Coach not found', 404);

        $members = $coach['team_members'] ?? [];
        $found   = false;
        foreach ($members as &$m) {
            if ($m['id'] === $id) {
                if (isset($body['access'])) $m['access'] = $body['access'];
                if (isset($body['role']))   $m['role']   = $body['role'];
                $found = true;
                break;
            }
        }

        if (!$found) Response::error('Team member not found', 404);

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => ['team_members' => $members, 'updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Team member updated');
    }

    public function removeTeamMember(array $params): void
    {
        $coachId = new ObjectId($params['_auth']['sub']);
        $id      = $params['id'] ?? '';

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$pull' => ['team_members' => ['id' => $id]], '$set' => ['updated_at' => new UTCDateTime()]]
        );

        Response::success(null, 'Team member removed');
    }
}
