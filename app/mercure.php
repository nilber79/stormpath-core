<?php
/**
 * mercure.php — Shared Mercure publisher helper.
 *
 * Included by api.php and admin.php so any server-side change that
 * modifies reports can push an SSE notification to all connected clients.
 */

function publishMercureUpdate(): void {
    $secret = getenv('MERCURE_JWT_SECRET') ?: '';
    if (!$secret) return;

    // Minimal HS256 JWT granting publish rights to all topics.
    $h = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"HS256"}'), '+/', '-_'), '=');
    $p = rtrim(strtr(base64_encode('{"mercure":{"publish":["*"]}}'), '+/', '-_'), '=');
    $s = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", $secret, true)), '+/', '-_'), '=');
    $jwt = "$h.$p.$s";

    $body = http_build_query(['topic' => 'stormpath/reports', 'data' => '{"type":"update"}']);
    $ctx  = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Authorization: Bearer $jwt\r\n"
                         . "Content-Type: application/x-www-form-urlencoded\r\n"
                         . 'Content-Length: ' . strlen($body),
        'content'       => $body,
        'timeout'       => 2,
        'ignore_errors' => true,
    ]]);
    @file_get_contents('http://127.0.0.1:2099/.well-known/mercure', false, $ctx);
}
