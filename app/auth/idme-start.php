<?php
/**
 * idme-start.php — Redirect the user to ID.me to verify first-responder status.
 *
 * This endpoint is only useful if IDME_CLIENT_ID and IDME_CLIENT_SECRET are
 * configured.  ID.me verifies the user's credentials (firefighter, EMS, law
 * enforcement, emergency management, etc.) and redirects back to
 * idme-callback.php with a code.  The callback exchanges the code for a token
 * and, on success, marks the user's first-responder claim as ID.me-verified
 * and activates the first_responder role — no manual admin step required.
 *
 * Environment variables required:
 *   IDME_CLIENT_ID      — OAuth client ID from the ID.me developer portal
 *   IDME_CLIENT_SECRET  — OAuth client secret
 *
 * Optional:
 *   IDME_REDIRECT_URI   — Override the callback URL (defaults to
 *                         https://<host>/auth/idme-callback.php)
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

$clientId     = getenv('IDME_CLIENT_ID')     ?: '';
$clientSecret = getenv('IDME_CLIENT_SECRET') ?: '';

if (!$clientId || !$clientSecret) {
    http_response_code(404);
    exit('ID.me integration is not configured for this StormPath instance.');
}

// The user must have just registered (session holds their pending user ID) OR
// be logged in already and want to verify their existing FR claim.
if (session_status() === PHP_SESSION_NONE) session_start();

$uid = 0;
$currentUser = getCurrentUser();
if ($currentUser) {
    $uid = (int)$currentUser['id'];
} elseif (!empty($_SESSION['sp_idme_verify_uid'])) {
    $uid = (int)$_SESSION['sp_idme_verify_uid'];
}

if (!$uid) {
    header('Location: /auth/register.php');
    exit;
}

// Build the redirect URI
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$redirectUri = getenv('IDME_REDIRECT_URI')
    ?: "$scheme://$host/auth/idme-callback.php";

// CSRF state token: sign uid + nonce with a secret so the callback can trust it
$nonce = bin2hex(random_bytes(16));
$stateData = base64_encode(json_encode(['uid' => $uid, 'nonce' => $nonce]));
$_SESSION['sp_idme_state'] = $stateData;

// ID.me OAuth 2.0 authorization endpoint
// Scope "responder" covers: firefighter, EMS, law enforcement, emergency management
$params = http_build_query([
    'client_id'     => $clientId,
    'redirect_uri'  => $redirectUri,
    'response_type' => 'code',
    'scope'         => 'responder',
    'state'         => $stateData,
]);

header('Location: https://api.id.me/oauth/authorize?' . $params);
exit;
