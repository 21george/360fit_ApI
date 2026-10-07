<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\{Request, Response};
use App\Services\EmailService;
use MongoDB\BSON\ObjectId;

class SettingsController
{
    // ── Integrations ─────────────────────────────────────────────────────────

    // GET /integrations/settings
    public function getIntegrations(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(
            ['_id' => $coachId],
            ['projection' => ['integrations' => 1]]
        );

        $integrations = $coach['integrations'] ?? [];

        Response::success([
            'webhook_url' => $integrations['webhook_url'] ?? null,
            'api_key'     => $integrations['api_key'] ?? null,
        ]);
    }

    // PUT /integrations/settings
    public function updateIntegrations(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();

        $set = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];

        if (array_key_exists('webhook_url', $body)) {
            $webhookUrl = trim((string) ($body['webhook_url'] ?? ''));
            if ($webhookUrl !== '' && !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                Response::error('Invalid webhook URL', 422);
            }
            $set['integrations.webhook_url'] = $webhookUrl ?: null;
        }

        if (!empty($body['generate_api_key'])) {
            $set['integrations.api_key'] = bin2hex(random_bytes(32));
        }

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => $set]
        );

        // Return updated values
        $coach = Database::collection('coaches')->findOne(
            ['_id' => $coachId],
            ['projection' => ['integrations' => 1]]
        );

        Response::success([
            'webhook_url' => $coach['integrations']['webhook_url'] ?? null,
            'api_key'     => $coach['integrations']['api_key'] ?? null,
        ], 'Integration settings updated');
    }

    // ── Notification Settings ────────────────────────────────────────────────

    // GET /notification-settings
    public function getNotificationSettings(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $coach   = Database::collection('coaches')->findOne(
            ['_id' => $coachId],
            ['projection' => ['notification_settings' => 1]]
        );

        $defaults = [
            'email_sms'      => true,
            'appointments'   => true,
            'consultation'   => true,
            'test_result'    => true,
            'login_alerts'   => true,
            'dnd_enabled'    => false,
            'dnd_from'       => '22:00',
            'dnd_to'         => '08:00',
        ];

        $stored = $coach['notification_settings'] ?? [];

        Response::success(array_merge($defaults, (array) $stored));
    }

    // PUT /notification-settings
    public function updateNotificationSettings(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();

        $allowed = ['email_sms', 'appointments', 'consultation', 'test_result', 'login_alerts', 'dnd_enabled', 'dnd_from', 'dnd_to'];
        $set     = ['updated_at' => new \MongoDB\BSON\UTCDateTime()];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $value = $body[$field];
                // Booleans
                if (in_array($field, ['email_sms', 'appointments', 'consultation', 'test_result', 'login_alerts', 'dnd_enabled'])) {
                    $value = (bool) $value;
                }
                // Time strings HH:MM
                if (in_array($field, ['dnd_from', 'dnd_to'])) {
                    $value = (string) $value;
                    if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
                        Response::error("Invalid time format for {$field}. Use HH:MM", 422);
                    }
                }
                $set["notification_settings.{$field}"] = $value;
            }
        }

        Database::collection('coaches')->updateOne(
            ['_id' => $coachId],
            ['$set' => $set]
        );

        Response::success(null, 'Notification settings updated');
    }

    // ── Support Contact ──────────────────────────────────────────────────────

    // POST /support/contact
    public function createSupportTicket(array $params): void
    {
        $coachId = new ObjectId($params['_org_id'] ?? $params['_auth']['sub']);
        $body    = Request::body();
        $errors  = Request::validate($body, [
            'subject' => 'required',
            'message' => 'required',
        ]);
        if ($errors) Response::error('Validation failed', 422, $errors);

        $coach = Database::collection('coaches')->findOne(
            ['_id' => $coachId],
            ['projection' => ['name' => 1, 'email' => 1]]
        );

        if (!$coach) Response::error('Coach not found', 404);

        $rawSubject = trim($body['subject']);
        $subject    = htmlspecialchars($rawSubject, ENT_QUOTES, 'UTF-8');
        $message    = htmlspecialchars(trim($body['message']), ENT_QUOTES, 'UTF-8');
        $name       = htmlspecialchars((string) ($coach['name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
        $email      = (string) ($coach['email'] ?? '');

        // Store ticket in database
        Database::collection('support_tickets')->insertOne([
            'coach_id'   => $coachId,
            'coach_email'=> $email,
            'subject'    => $body['subject'],
            'message'    => $body['message'],
            'status'     => 'open',
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
        ]);

        // Send email to support if configured
        $supportEmail = $_ENV['SUPPORT_EMAIL'] ?? $_ENV['MAIL_FROM'] ?? null;
        if ($supportEmail) {
            $html = <<<HTML
            <div style="font-family:sans-serif;max-width:600px;margin:0 auto">
              <h2>New Support Ticket</h2>
              <p><strong>From:</strong> {$name} ({$email})</p>
              <p><strong>Subject:</strong> {$subject}</p>
              <hr>
              <p>{$message}</p>
            </div>
            HTML;
            // Use raw subject for the email subject line (HTML-escaping corrupts non-HTML contexts)
            EmailService::send($supportEmail, "Support Ticket: {$rawSubject}", $html);
        }

        Response::success(null, 'Support ticket submitted. We will get back to you soon.', 201);
    }
}
