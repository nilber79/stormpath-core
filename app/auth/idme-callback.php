<?php
/**
 * idme-callback.php — OAuth 2.0 callback from ID.me after first-responder verification.
 *
 * Flow:
 *   1. Exchange the ?code= for an access token via the ID.me token endpoint.
 *   2. Fetch the user's verified group memberships (attributes) from ID.me.
 *   3. If the "responder" group is verified, mark the StormPath user as
 *      ID.me-verified, set their role to first_responder, and activate the account.
 *   4. Start a session so they can use the app immediately without waiting for
 *      a manual admin review.
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$clientId     = getenv('IDME_CLIENT_ID')     ?: '';
$clientSecret = getenv('IDME_CLIENT_SECRET') ?: '';

if (!$clientId || !$clientSecret) {
    http_response_code(404);
    exit('ID.me integration is not configured.');
}

function idmeError(string $msg): void
{
    // Show a simple error and link back to login
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<title>Verification Error — StormPath</title>'
       . '<style>body{font-family:system-ui,sans-serif;padding:3rem 2rem;max-width:480px;margin:auto}'
       . 'h1{color:#dc2626}a{color:#d97706}</style></head><body>'
       . '<h1>Verification failed</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p><a href="/auth/login.php">← Back to Sign In</a></p>'
       . '</body></html>';
    exit;
}

// ── CSRF state check ──────────────────────────────────────────────────────────
$returnedState = $_GET['state'] ?? '';
$sessionState  = $_SESSION['sp_idme_state'] ?? '';
if (!$returnedState || !hash_equals($sessionState, $returnedState)) {
    idmeError('Invalid state parameter. Please try again.');
}
unset($_SESSION['sp_idme_state']);

// ── Decode state to get the pending user ID ───────────────────────────────────
$stateDecoded = json_decode(base64_decode($returnedState), true);
$uid          = (int)($stateDecoded['uid'] ?? 0);
if (!$uid) {
    idmeError('Could not identify the user account. Please register again.');
}

// ── Error returned from ID.me ─────────────────────────────────────────────────
if (isset($_GET['error'])) {
    $desc = $_GET['error_description'] ?? $_GET['error'];
    // User cancelled or is not a verified first responder
    header('Location: /auth/login.php?notice=idme_cancelled');
    exit;
}

$code = $_GET['code'] ?? '';
if (!$code) {
    idmeError('No authorization code received from ID.me.');
}

// ── Build redirect URI (must match idme-start.php) ────────────────────────────
$scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
$redirectUri = getenv('IDME_REDIRECT_URI')
    ?: "$scheme://$host/auth/idme-callback.php";

// ── Exchange code for access token ────────────────────────────────────────────
$tokenBody = http_build_query([
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => $redirectUri,
]);
$tokenCtx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                     . 'Content-Length: ' . strlen($tokenBody),
    'content'       => $tokenBody,
    'timeout'       => 10,
    'ignore_errors' => true,
]]);
$tokenRaw = @file_get_contents('https://api.id.me/oauth/token', false, $tokenCtx);
$token    = $tokenRaw ? json_decode($tokenRaw, true) : null;

if (!$token || empty($token['access_token'])) {
    idmeError('Could not exchange the authorization code for an access token.');
}

$accessToken = $token['access_token'];

// ── Fetch verified attributes / group memberships ─────────────────────────────
$attrCtx = stream_context_create(['http' => [
    'method'        => 'GET',
    'header'        => "Authorization: Bearer $accessToken",
    'timeout'       => 10,
    'ignore_errors' => true,
]]);
$attrRaw = @file_get_contents('https://api.id.me/api/public/v3/attributes.json', false, $attrCtx);
$attrs   = $attrRaw ? json_decode($attrRaw, true) : null;

// ID.me returns an array of group objects; each has "group" and "verified" fields.
// The "responder" group covers fire, EMS, law enforcement, emergency management.
$responderVerified = false;
if (!empty($attrs)) {
    foreach ($attrs as $group) {
        if (!empty($group['group']) && strtolower($group['group']) === 'responder'
            && !empty($group['verified'])) {
            $responderVerified = true;
            break;
        }
    }
}

if (!$responderVerified) {
    // ID.me was reached but the user does not have a verified responder affiliation.
    // Redirect to login with a notice; account remains pending for manual review.
    header('Location: /auth/login.php?notice=idme_not_verified');
    exit;
}

// ── Mark the StormPath user as verified and activate their FR role ─────────────
$db = getDb();
$db->prepare(
    "UPDATE users
     SET fr_idme_verified = 1,
         role   = 'first_responder',
         status = 'active'
     WHERE id = ? AND fr_claim = 1"
)->execute([$uid]);

// ── Log them in immediately ───────────────────────────────────────────────────
unset($_SESSION['sp_idme_verify_uid']);
createSession($uid);

header('Location: /?notice=idme_verified');
exit;
