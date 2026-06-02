<?php
declare(strict_types=1);

namespace App\Services;

/**
 * EmailService — sends transactional email via SMTP, AWS SES, or native mail().
 *
 * Driver selection (MAIL_DRIVER env var):
 *   smtp  → SMTP via stream_socket_client (supports STARTTLS / SSL)
 *   ses   → AWS Simple Email Service (requires aws/aws-sdk-php + SES IAM creds)
 *   mail  → PHP native mail() — development / simple server only (default)
 *
 * Required .env keys per driver:
 *   smtp: MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD, MAIL_ENCRYPTION (tls|ssl|none)
 *   ses:  AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_REGION, MAIL_FROM
 *   mail: (none — uses system sendmail)
 *
 * Common: MAIL_FROM, MAIL_FROM_NAME, APP_NAME
 * Optional: APP_IOS_URL, APP_ANDROID_URL, APP_WEB_URL
 */
class EmailService
{
    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Send the welcome / login-code email to a newly created client.
     *
     * @param string $to        Client's email address
     * @param string $clientName Client's display name
     * @param string $coachName  Coach's display name
     * @param string $loginCode  Plaintext login code (e.g. FIT-ABCD-EF)
     * @param string $coachEmail Coach's email (shown as reply-to / contact)
     */
    public static function sendClientLoginCode(
        string $to,
        string $clientName,
        string $coachName,
        string $loginCode,
        string $coachEmail = ''
    ): bool {
        $appName = $_ENV['APP_NAME'] ?? 'CoachPro';
        $subject = "Welcome to {$appName} — Your Login Code";

        $esc = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $escAppName    = $esc($appName);
        $escClientName = $esc($clientName);
        $escCoachName  = $esc($coachName);
        $escLoginCode  = $esc($loginCode);
        $escCoachEmail = $esc($coachEmail);

        // Optional app download / web links
        $iosUrl     = $_ENV['APP_IOS_URL']     ?? '';
        $androidUrl = $_ENV['APP_ANDROID_URL'] ?? '';
        $webUrl     = $_ENV['APP_WEB_URL']     ?? '';

        $downloadLinks = '';
        if ($iosUrl || $androidUrl) {
            $downloadLinks .= '<p style="margin:8px 0 0;font-size:12px;">';
            if ($iosUrl) {
                $escIos = $esc($iosUrl);
                $downloadLinks .= "<a href=\"{$escIos}\" style=\"color:#3b82f6;text-decoration:none;margin-right:12px;\">&#8599; App Store</a>";
            }
            if ($androidUrl) {
                $escAndroid = $esc($androidUrl);
                $downloadLinks .= "<a href=\"{$escAndroid}\" style=\"color:#3b82f6;text-decoration:none;\">&#8599; Google Play</a>";
            }
            $downloadLinks .= '</p>';
        }

        $webStep = '';
        if ($webUrl) {
            $escWeb = $esc($webUrl);
            $webStep = "<p style=\"margin:8px 0 0;font-size:12px;\">Or log in via browser: <a href=\"{$escWeb}\" style=\"color:#3b82f6;\">{$escWeb}</a></p>";
        }

        $contactHtml = $escCoachEmail
            ? "Contact <strong style=\"color:#0f172a;\">{$escCoachName}</strong> at <a href=\"mailto:{$escCoachEmail}\" style=\"color:#3b82f6;\">{$escCoachEmail}</a>"
            : "Contact <strong style=\"color:#0f172a;\">{$escCoachName}</strong> directly";

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>Your Login Code</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
  <div style="max-width:560px;margin:40px auto;padding:0 16px;">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#0d1b2a 0%,#132e35 100%);border-radius:12px 12px 0 0;padding:28px 32px;text-align:center;">
      <h1 style="margin:0;font-size:22px;font-weight:700;color:#ffffff;letter-spacing:-0.5px;">{$escAppName}</h1>
      <p style="margin:5px 0 0;font-size:11px;color:rgba(255,255,255,0.45);letter-spacing:0.1em;text-transform:uppercase;">Coaching Platform</p>
    </div>

    <!-- Body -->
    <div style="background:#ffffff;padding:36px 32px;border:1px solid #e2e8f0;border-top:none;">
      <p style="margin:0 0 4px;font-size:21px;font-weight:700;color:#0f172a;">Welcome, {$escClientName}! 👋</p>
      <p style="margin:0 0 24px;font-size:14px;color:#64748b;line-height:1.65;">
        Your coach <strong style="color:#0f172a;">{$escCoachName}</strong> has set up your personal coaching account on {$escAppName}.
        Use the code below to log in to the app and get started.
      </p>

      <!-- Login Code Box -->
      <div style="background:linear-gradient(135deg,#0d1b2a 0%,#1a3a4a 100%);border-radius:12px;padding:28px 24px;text-align:center;margin:0 0 28px;">
        <p style="margin:0 0 10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.14em;color:rgba(255,255,255,0.5);">Your Personal Login Code</p>
        <div style="display:inline-block;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);border-radius:8px;padding:14px 28px;margin:0 0 10px;">
          <span style="font-size:30px;font-weight:700;letter-spacing:8px;color:#ffffff;font-family:'Courier New',Courier,monospace;">{$escLoginCode}</span>
        </div>
        <p style="margin:0;font-size:11px;color:rgba(255,255,255,0.4);">Enter this code in the app to sign in — keep it private</p>
      </div>

      <!-- Steps -->
      <p style="margin:0 0 16px;font-size:13px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:0.06em;">How to get started</p>

      <!-- Step 1 -->
      <table style="width:100%;border-collapse:collapse;margin:0 0 14px;">
        <tr>
          <td style="width:36px;vertical-align:top;padding-right:14px;">
            <div style="width:30px;height:30px;background:#eff6ff;border-radius:50%;font-size:13px;font-weight:700;color:#3b82f6;text-align:center;line-height:30px;">1</div>
          </td>
          <td style="vertical-align:top;">
            <p style="margin:0;font-size:13px;font-weight:600;color:#0f172a;line-height:30px;">Download the {$escAppName} app</p>
            {$downloadLinks}
            {$webStep}
          </td>
        </tr>
      </table>

      <!-- Step 2 -->
      <table style="width:100%;border-collapse:collapse;margin:0 0 14px;">
        <tr>
          <td style="width:36px;vertical-align:top;padding-right:14px;">
            <div style="width:30px;height:30px;background:#eff6ff;border-radius:50%;font-size:13px;font-weight:700;color:#3b82f6;text-align:center;line-height:30px;">2</div>
          </td>
          <td style="vertical-align:top;">
            <p style="margin:0;font-size:13px;font-weight:600;color:#0f172a;line-height:30px;">Tap <em>"Login with Code"</em> on the sign-in screen</p>
            <p style="margin:4px 0 0;font-size:12px;color:#64748b;">Look for the code login option — no password needed</p>
          </td>
        </tr>
      </table>

      <!-- Step 3 -->
      <table style="width:100%;border-collapse:collapse;margin:0 0 28px;">
        <tr>
          <td style="width:36px;vertical-align:top;padding-right:14px;">
            <div style="width:30px;height:30px;background:#eff6ff;border-radius:50%;font-size:13px;font-weight:700;color:#3b82f6;text-align:center;line-height:30px;">3</div>
          </td>
          <td style="vertical-align:top;">
            <p style="margin:0;font-size:13px;font-weight:600;color:#0f172a;line-height:30px;">Enter your code above and tap <em>"Sign In"</em></p>
            <p style="margin:4px 0 0;font-size:12px;color:#64748b;">Type the code exactly as shown, including dashes</p>
          </td>
        </tr>
      </table>

      <!-- Contact box -->
      <div style="background:#f8fafc;border-radius:8px;padding:16px 18px;border:1px solid #e2e8f0;">
        <p style="margin:0;font-size:13px;color:#475569;line-height:1.6;">
          Questions or need help? {$contactHtml} — they're here to guide you every step of the way.
        </p>
      </div>
    </div>

    <!-- Footer -->
    <div style="background:#f8fafc;border-radius:0 0 12px 12px;padding:18px 32px;border:1px solid #e2e8f0;border-top:none;text-align:center;">
      <p style="margin:0;font-size:11px;color:#94a3b8;line-height:1.7;">
        You received this email because your coach registered you on {$escAppName}.<br>
        Your login code is personal — <strong>do not share it with anyone.</strong>
      </p>
    </div>

  </div>
</body>
</html>
HTML;

        $plain = "Welcome to {$appName}, {$clientName}!\n\n"
            . "Your coach {$coachName} has set up your coaching account.\n\n"
            . "YOUR LOGIN CODE: {$loginCode}\n\n"
            . "HOW TO GET STARTED:\n"
            . "1. Download the {$appName} app"
            . ($iosUrl ? " (App Store: {$iosUrl})" : '')
            . ($androidUrl ? " (Google Play: {$androidUrl})" : '')
            . "\n"
            . ($webUrl ? "   Or use the web app: {$webUrl}\n" : '')
            . "2. Tap \"Login with Code\" on the sign-in screen\n"
            . "3. Enter your code: {$loginCode}\n\n"
            . ($coachEmail
                ? "Questions? Contact {$coachName} at {$coachEmail}\n\n"
                : "Questions? Contact {$coachName} directly\n\n")
            . "Keep your login code private — do not share it with anyone.";

        return self::send($to, $subject, $html, $plain, $coachEmail ?: '');
    }

    // ─── Core send dispatcher ─────────────────────────────────────────────────

    /**
     * Send an email using the configured driver.
     * Falls back automatically: smtp → ses → mail().
     */
    public static function send(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainBody = '',
        string $replyTo   = ''
    ): bool {
        if (empty(trim($to))) {
            error_log('EmailService::send — empty recipient address');
            return false;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log("EmailService::send — invalid recipient address: {$to}");
            return false;
        }

        if (empty($plainBody)) {
            $plainBody = strip_tags(
                str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", $htmlBody)
            );
            $plainBody = html_entity_decode(preg_replace('/\n{3,}/', "\n\n", $plainBody) ?? $plainBody);
        }

        $driver = strtolower(trim($_ENV['MAIL_DRIVER'] ?? 'mail'));

        try {
            return match ($driver) {
                'smtp' => self::sendViaSmtp($to, $subject, $htmlBody, $plainBody, $replyTo),
                'ses'  => self::sendViaSes($to, $subject, $htmlBody, $plainBody, $replyTo),
                default => self::sendViaMail($to, $subject, $htmlBody, $plainBody, $replyTo),
            };
        } catch (\Throwable $e) {
            error_log("EmailService::send [{$driver}] exception: " . $e->getMessage());
            return false;
        }
    }

    // ─── SMTP driver ──────────────────────────────────────────────────────────

    private static function sendViaSmtp(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainBody,
        string $replyTo
    ): bool {
        $host     = $_ENV['MAIL_HOST']       ?? '';
        $port     = (int)($_ENV['MAIL_PORT'] ?? 587);
        $user     = $_ENV['MAIL_USERNAME']   ?? '';
        $pass     = $_ENV['MAIL_PASSWORD']   ?? '';
        $enc      = strtolower(trim($_ENV['MAIL_ENCRYPTION'] ?? 'tls')); // tls|ssl|none
        $from     = $_ENV['MAIL_FROM']       ?? ('noreply@' . ($_ENV['MAIL_DOMAIN'] ?? 'coachpro.app'));
        $fromName = $_ENV['MAIL_FROM_NAME']  ?? ($_ENV['APP_NAME'] ?? 'CoachPro');

        if (empty($host)) {
            error_log('EmailService SMTP: MAIL_HOST not configured');
            return false;
        }

        $timeout = 20;
        $scheme  = ($enc === 'ssl') ? 'ssl://' : '';
        $sock    = @stream_socket_client(
            "{$scheme}{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!$sock) {
            error_log("EmailService SMTP connect failed ({$host}:{$port}): {$errstr}");
            return false;
        }

        stream_set_timeout($sock, $timeout);

        /**
         * Read a full SMTP response (handles multi-line: "250-text" vs "250 text").
         */
        $read = static function () use ($sock): string {
            $buf = '';
            while ($line = fgets($sock, 512)) {
                $buf .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $buf;
        };

        $cmd = static function (string $c) use ($sock, $read): string {
            fwrite($sock, $c . "\r\n");
            return $read();
        };

        $code = static function (string $resp): int {
            return (int) substr($resp, 0, 3);
        };

        // Server greeting
        $read();

        $ehlo     = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $ehloResp = $cmd("EHLO {$ehlo}");

        // STARTTLS upgrade
        if ($enc === 'tls') {
            $starttlsResp = $cmd("STARTTLS");
            if ($code($starttlsResp) === 220) {
                if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                    // TLSv1.2 failed — try TLSv1.3
                    stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                }
                $ehloResp = $cmd("EHLO {$ehlo}");
            }
        }

        // Authentication
        if (!empty($user) && !empty($pass)) {
            $authResp = $cmd("AUTH LOGIN");
            if ($code($authResp) === 334) {
                $cmd(base64_encode($user));
                $passResp = $cmd(base64_encode($pass));
                if ($code($passResp) !== 235) {
                    fclose($sock);
                    error_log("EmailService SMTP auth failed: {$passResp}");
                    return false;
                }
            }
        }

        // Envelope
        $mailResp = $cmd("MAIL FROM:<{$from}>");
        if ($code($mailResp) !== 250) {
            fclose($sock);
            error_log("EmailService SMTP MAIL FROM rejected: {$mailResp}");
            return false;
        }

        $rcptResp = $cmd("RCPT TO:<{$to}>");
        if ($code($rcptResp) !== 250 && $code($rcptResp) !== 251) {
            fclose($sock);
            error_log("EmailService SMTP RCPT TO rejected ({$to}): {$rcptResp}");
            return false;
        }

        $cmd("DATA");

        // Build RFC 5322 message
        $boundary = 'MP_' . bin2hex(random_bytes(8));
        $msgId    = '<' . time() . '.' . bin2hex(random_bytes(4)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'coachpro.app') . '>';
        $replyHdr = !empty($replyTo) ? "Reply-To: {$replyTo}\r\n" : '';

        $message  = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$from}>\r\n";
        $message .= "To: {$to}\r\n";
        $message .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $message .= "Message-ID: {$msgId}\r\n";
        $message .= "Date: " . date('r') . "\r\n";
        $message .= $replyHdr;
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $message .= "\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $message .= quoted_printable_encode($plainBody) . "\r\n\r\n";
        $message .= "--{$boundary}\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $message .= quoted_printable_encode($htmlBody) . "\r\n\r\n";
        $message .= "--{$boundary}--";

        // Dot-stuff: lines starting with '.' need an extra '.'
        $message = preg_replace('/^\./', '..', $message) ?? $message;
        $message = str_replace("\n.", "\n..", $message);

        $dataResp = $cmd($message . "\r\n.");
        $cmd("QUIT");
        fclose($sock);

        if ($code($dataResp) !== 250) {
            error_log("EmailService SMTP DATA rejected: {$dataResp}");
            return false;
        }

        return true;
    }

    // ─── AWS SES driver ───────────────────────────────────────────────────────

    private static function sendViaSes(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainBody,
        string $replyTo
    ): bool {
        if (!class_exists(\Aws\Ses\SesClient::class)) {
            error_log('EmailService SES: aws/aws-sdk-php not installed');
            return false;
        }

        $from     = $_ENV['MAIL_FROM']      ?? ('noreply@' . ($_ENV['MAIL_DOMAIN'] ?? 'coachpro.app'));
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? ($_ENV['APP_NAME'] ?? 'CoachPro');
        $region   = $_ENV['AWS_REGION']     ?? 'eu-central-1';
        $key      = $_ENV['AWS_ACCESS_KEY_ID']     ?? '';
        $secret   = $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '';

        if (empty($key) || empty($secret)) {
            error_log('EmailService SES: AWS credentials not configured');
            return false;
        }

        $client = new \Aws\Ses\SesClient([
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => ['key' => $key, 'secret' => $secret],
        ]);

        $params = [
            'Source'      => "{$fromName} <{$from}>",
            'Destination' => ['ToAddresses' => [$to]],
            'Message'     => [
                'Subject' => ['Data' => $subject,   'Charset' => 'UTF-8'],
                'Body'    => [
                    'Text' => ['Data' => $plainBody, 'Charset' => 'UTF-8'],
                    'Html' => ['Data' => $htmlBody,  'Charset' => 'UTF-8'],
                ],
            ],
        ];

        if (!empty($replyTo)) {
            $params['ReplyToAddresses'] = [$replyTo];
        }

        $client->sendEmail($params);
        return true;
    }

    // ─── PHP mail() fallback ─────────────────────────────────────────────────

    private static function sendViaMail(
        string $to,
        string $subject,
        string $htmlBody,
        string $plainBody,
        string $replyTo
    ): bool {
        $from     = $_ENV['MAIL_FROM']      ?? ('noreply@' . ($_ENV['MAIL_DOMAIN'] ?? 'coachpro.app'));
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? ($_ENV['APP_NAME'] ?? 'CoachPro');

        $boundary = 'MP_' . md5((string) microtime(true));
        $headers  = [
            "From: {$fromName} <{$from}>",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ];

        if (!empty($replyTo)) {
            $headers[] = "Reply-To: {$replyTo}";
        }

        $body  = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$plainBody}\r\n\r\n";
        $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$htmlBody}\r\n\r\n";
        $body .= "--{$boundary}--";

        $sent = @mail($to, $subject, $body, implode("\r\n", $headers));

        if (!$sent) {
            error_log("EmailService mail() failed to {$to}");
        }

        return $sent;
    }
}
