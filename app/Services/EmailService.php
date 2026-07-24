<?php
declare(strict_types=1);

namespace App\Services;

class EmailService
{
    public static function sendCoachVerificationCode(string $to, string $coachName, string $verificationCode): bool
    {
        $appName = $_ENV['APP_NAME'] ?? 'CoachPro';
        $subject = "{$appName} — Verify Your Email";

        $escAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $escName = htmlspecialchars($coachName, ENT_QUOTES, 'UTF-8');
        $escCode = htmlspecialchars($verificationCode, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8f9fa;border-radius:12px">
          <h2 style="margin:0 0 8px;color:#0a2f60">{$escAppName}</h2>
          <p style="color:#4b5563;font-size:14px;margin:0 0 16px">Hi <strong>{$escName}</strong>,</p>
          <p style="color:#4b5563;font-size:14px;margin:0 0 16px">Use this code to verify your email address:</p>
          <div style="background:#0a2f60;color:#fff;text-align:center;padding:20px;border-radius:10px;margin:0 0 24px">
            <span style="font-size:28px;font-weight:700;letter-spacing:4px;font-family:monospace">{$escCode}</span>
          </div>
          <p style="color:#6b7280;font-size:12px;margin:0">This code expires in 15 minutes. If you did not create a CoachPro account, you can safely ignore this email.</p>
        </div>
        HTML;

        return self::send($to, $subject, $html);
    }

    public static function sendClientLoginCode(string $to, string $clientName, string $coachName, string $loginCode): bool
    {
        $appName = $_ENV['APP_NAME'] ?? 'CoachPro';
        $subject = "{$appName} — Your Login Code";

        $escAppName   = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $escClientName = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
        $escCoachName  = htmlspecialchars($coachName, ENT_QUOTES, 'UTF-8');
        $escLoginCode  = htmlspecialchars($loginCode, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8f9fa;border-radius:12px">
          <h2 style="margin:0 0 8px;color:#0a2f60">{$escAppName}</h2>
          <p style="color:#4b5563;font-size:14px;margin:0 0 24px">Hi <strong>{$escClientName}</strong>,</p>
          <p style="color:#4b5563;font-size:14px;margin:0 0 16px">Your coach <strong>{$escCoachName}</strong> has created an account for you. Use the code below to log in to the app:</p>
          <div style="background:#0a2f60;color:#fff;text-align:center;padding:20px;border-radius:10px;margin:0 0 24px">
            <span style="font-size:28px;font-weight:700;letter-spacing:4px;font-family:monospace">{$escLoginCode}</span>
          </div>
          <p style="color:#6b7280;font-size:12px;margin:0">This code is personal — do not share it. If you have questions, contact your coach directly.</p>
        </div>
        HTML;

        return self::send($to, $subject, $html);
    }

    public static function sendTrialStarted(string $to, string $coachName, string $tier, string $trialEndDate): bool
    {
        $appName  = $_ENV['APP_NAME'] ?? 'CoachPro';
        $subject  = "{$appName} — Your 30-day free trial has started";
        $escApp   = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $escName  = htmlspecialchars($coachName, ENT_QUOTES, 'UTF-8');
        $escTier  = htmlspecialchars(ucfirst($tier), ENT_QUOTES, 'UTF-8');
        $escDate  = htmlspecialchars($trialEndDate, ENT_QUOTES, 'UTF-8');
        $dashUrl  = htmlspecialchars($_ENV['FRONTEND_URL'] ?? 'http://localhost:3000', ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8f9fa;border-radius:12px">
          <h2 style="margin:0 0 8px;color:#0a2f60">{$escApp}</h2>
          <p style="color:#4b5563;font-size:14px;margin:0 0 16px">Hi <strong>{$escName}</strong>,</p>
          <p style="color:#4b5563;font-size:14px;margin:0 0 16px">Your 30-day free trial of the <strong>{$escTier}</strong> plan is now active. You won't be charged until <strong>{$escDate}</strong>.</p>
          <p style="color:#4b5563;font-size:14px;margin:0 0 24px">Start building your coaching business today — add clients, create workout and nutrition plans, and schedule your first sessions.</p>
          <a href="{$dashUrl}/dashboard" style="display:inline-block;background:#0a2f60;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px">Open Dashboard →</a>
          <p style="color:#6b7280;font-size:12px;margin:24px 0 0">Your payment method will be charged automatically at the end of the trial. You can cancel anytime from your billing settings.</p>
        </div>
        HTML;

        return self::send($to, $subject, $html);
    }

    public static function sendTrialEndingReminder(string $to, string $coachName, string $trialEndDate): bool
    {
        $appName  = $_ENV['APP_NAME'] ?? 'CoachPro';
        $subject  = "{$appName} — Your trial ends soon";
        $escApp   = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $escName  = htmlspecialchars($coachName, ENT_QUOTES, 'UTF-8');
        $escDate  = htmlspecialchars($trialEndDate, ENT_QUOTES, 'UTF-8');
        $dashUrl  = htmlspecialchars($_ENV['FRONTEND_URL'] ?? 'http://localhost:3000', ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8f9fa;border-radius:12px">
          <h2 style="margin:0 0 8px;color:#0a2f60">{$escApp}</h2>
          <p style="color:#4b5563;font-size:14px;margin:0 0 16px">Hi <strong>{$escName}</strong>,</p>
          <p style="color:#4b5563;font-size:14px;margin:0 0 16px">Your free trial ends on <strong>{$escDate}</strong>. After that, your card on file will be charged automatically — no action needed.</p>
          <p style="color:#4b5563;font-size:14px;margin:0 0 24px">If you wish to cancel before being charged, visit your billing settings.</p>
          <a href="{$dashUrl}/billing" style="display:inline-block;background:#0a2f60;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px">Manage Billing →</a>
          <p style="color:#6b7280;font-size:12px;margin:24px 0 0">Questions? Reply to this email and we'll help.</p>
        </div>
        HTML;

        return self::send($to, $subject, $html);
    }

    public static function sendTrialConverted(string $to, string $coachName, string $tier): bool
    {
        $appName  = $_ENV['APP_NAME'] ?? 'CoachPro';
        $subject  = "{$appName} — Your subscription is now active";
        $escApp   = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
        $escName  = htmlspecialchars($coachName, ENT_QUOTES, 'UTF-8');
        $escTier  = htmlspecialchars(ucfirst($tier), ENT_QUOTES, 'UTF-8');
        $dashUrl  = htmlspecialchars($_ENV['FRONTEND_URL'] ?? 'http://localhost:3000', ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8f9fa;border-radius:12px">
          <h2 style="margin:0 0 8px;color:#0a2f60">{$escApp}</h2>
          <p style="color:#4b5563;font-size:14px;margin:0 0 16px">Hi <strong>{$escName}</strong>,</p>
          <p style="color:#4b5563;font-size:14px;margin:0 0 16px">Your <strong>{$escTier}</strong> subscription is now active. Your card was charged successfully. Thank you for choosing {$escApp}!</p>
          <a href="{$dashUrl}/dashboard" style="display:inline-block;background:#0a2f60;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px">Open Dashboard →</a>
          <p style="color:#6b7280;font-size:12px;margin:24px 0 0">View your invoice and manage billing at any time from the billing settings.</p>
        </div>
        HTML;

        return self::send($to, $subject, $html);
    }

    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $from = $_ENV['MAIL_FROM'] ?? ('noreply@' . ($_ENV['MAIL_DOMAIN'] ?? 'coachpro.app'));
        $name = $_ENV['MAIL_FROM_NAME'] ?? ($_ENV['APP_NAME'] ?? 'CoachPro');

        $boundary = md5((string) time());
        $headers  = implode("\r\n", [
            "From: {$name} <{$from}>",
            "Reply-To: {$from}",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ]);

        $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
        $body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$plain}\r\n\r\n"
               . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$htmlBody}\r\n\r\n"
               . "--{$boundary}--";

        return @mail($to, $subject, $body, $headers);
    }
}
