<?php
declare(strict_types=1);

namespace App\Services;

class EmailService
{
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
