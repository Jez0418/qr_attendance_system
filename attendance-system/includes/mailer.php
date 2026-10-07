<?php
/**
 * ------------------------------------------------------------
 * includes/mailer.php
 * Sends one email through Brevo's HTTPS API (https://developers.brevo.com).
 * Vercel has no mail server, so this is a single API call, no SMTP and no Composer.
 *
 * Environment variables (set in Vercel, never commit):
 *   MAIL_API_KEY    Brevo API key (Brevo -> SMTP & API -> API keys)
 *   MAIL_FROM       a sender address verified in Brevo (Senders, Domains & dedicated IPs)
 *   MAIL_FROM_NAME  optional display name (default: the app name)
 *
 * Without MAIL_API_KEY nothing is sent. On a local machine the message is written to the PHP
 * error log instead (so the reset link can be copied while testing); on Vercel it is a failure.
 * ------------------------------------------------------------
 */
require_once __DIR__ . '/config.php';

function mail_is_configured(): bool {
    return (string) getenv('MAIL_API_KEY') !== '' && (string) getenv('MAIL_FROM') !== '';
}

/** Mail can be "sent" (logged) locally without a key, but not on Vercel. */
function mail_available(): bool {
    return mail_is_configured() || !getenv('VERCEL');
}

/** Returns true when the message was accepted by Brevo (or logged locally). Never throws. */
function send_mail(string $toEmail, string $toName, string $subject, string $html, string $text): bool {
    if (!mail_is_configured()) {
        if (getenv('VERCEL')) return false;
        error_log("MAIL (not sent, MAIL_API_KEY not set) to $toEmail: $subject\n$text");
        return true;
    }
    $body = json_encode([
        'sender'      => ['email' => getenv('MAIL_FROM'), 'name' => getenv('MAIL_FROM_NAME') ?: APP_NAME],
        'to'          => [['email' => $toEmail, 'name' => $toName !== '' ? $toName : $toEmail]],
        'subject'     => $subject,
        'htmlContent' => $html,
        'textContent' => $text,
    ]);
    $headers = ['api-key: ' . getenv('MAIL_API_KEY'), 'Content-Type: application/json', 'Accept: application/json'];
    $url = 'https://api.brevo.com/v3/smtp/email';
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers),
                'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]);
            $resp = @file_get_contents($url, false, $ctx);
            $code = 0;
            foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int) $m[1];
        }
        if ($code >= 200 && $code < 300) return true;
        error_log("mailer: Brevo returned HTTP $code: " . substr((string) $resp, 0, 300));   // never includes the API key
    } catch (Throwable $e) {
        error_log('mailer: ' . $e->getMessage());
    }
    return false;
}
