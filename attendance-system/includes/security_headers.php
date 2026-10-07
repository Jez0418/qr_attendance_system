<?php
/**
 * ------------------------------------------------------------
 * includes/security_headers.php
 * Browser security headers, sent on every PHP response (included from config.php).
 *
 * The CSP is deliberately not the strictest possible: the pages use inline <script> blocks and
 * onclick="" handlers, so script-src needs 'unsafe-inline'. It still stops the page being framed
 * (clickjacking), blocks plugins, <base> hijacking and forms posting to other sites, and limits
 * scripts/styles/fonts to this site plus the CDNs the app actually uses (cdnjs, unpkg, jsDelivr,
 * Google Fonts). If a new page needs another host, add it here.
 * Camera and location stay allowed for this site only (QR scanner, GPS check).
 * ------------------------------------------------------------
 */
function security_headers(bool $https): array {
    $cdnScripts = 'https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net';
    $csp = implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' $cdnScripts",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com",
        "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com",
        "img-src 'self' data: blob:",       // profile photos are data: URIs, the QR code may render as an image
        "media-src 'self' blob:",           // camera stream in the scanner
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'none'",
    ]);
    $headers = [
        'Content-Security-Policy' => $csp,
        'X-Frame-Options' => 'DENY',                       // older browsers; frame-ancestors covers the rest
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=()',
    ];
    if ($https) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000';   // one year; no includeSubDomains/preload on purpose
    }
    return $headers;
}

function send_security_headers(bool $https): void {
    if (headers_sent()) return;
    foreach (security_headers($https) as $name => $value) header("$name: $value");
}
