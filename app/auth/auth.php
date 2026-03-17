<?php
/**
 * StormPath authentication helpers.
 * Provides session management and role-based access control.
 */
require_once __DIR__ . '/../db.php';

const SP_SESSION_NAME     = 'sp_sess';
const SP_SESSION_LIFETIME = 86400 * 30; // 30 days

/**
 * SQLite-backed session handler so sessions survive container restarts.
 * Reads/writes the `sessions` table in reports.db (persistent volume).
 */
class SqliteSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool  { return true; }
    public function close(): bool                           { return true; }

    public function read(string $id): string|false
    {
        $stmt = getDb()->prepare('SELECT data FROM sessions WHERE id = ? AND expires > ?');
        $stmt->execute([$id, time()]);
        return $stmt->fetchColumn() ?: '';
    }

    public function write(string $id, string $data): bool
    {
        getDb()->prepare('INSERT OR REPLACE INTO sessions (id, data, expires) VALUES (?, ?, ?)')
               ->execute([$id, $data, time() + SP_SESSION_LIFETIME]);
        return true;
    }

    public function destroy(string $id): bool
    {
        getDb()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = getDb()->prepare('DELETE FROM sessions WHERE expires < ?');
        $stmt->execute([time()]);
        return $stmt->rowCount();
    }
}

function spStartSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_save_handler(new SqliteSessionHandler(), true);
        session_name(SP_SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

/**
 * Return the currently logged-in user row, or null if not authenticated.
 */
function getCurrentUser(): ?array
{
    spStartSession();
    $userId = $_SESSION['sp_user_id'] ?? null;
    if (!$userId) {
        return null;
    }
    $stmt = getDb()->prepare(
        "SELECT id, username, email, display_name, role, status, totp_enabled
           FROM users WHERE id = ? AND status = 'active'"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/**
 * Require a logged-in user. Redirects or returns 401 JSON on failure.
 */
function requireAuth(): array
{
    $user = getCurrentUser();
    if (!$user) {
        spSendUnauth('Authentication required');
    }
    return $user;
}

/**
 * Require a minimum role level (user < first_responder < admin).
 */
function requireRole(string $role): array
{
    $user = requireAuth();
    $hierarchy = ['user' => 1, 'first_responder' => 2, 'admin' => 3];
    $required  = $hierarchy[$role] ?? 0;
    $current   = $hierarchy[$user['role']] ?? 0;
    if ($current < $required) {
        spSendUnauth('Insufficient permissions', 403);
    }
    return $user;
}

/**
 * Send an auth error (JSON for XHR/API, redirect for browser).
 */
function spSendUnauth(string $message, int $code = 401): never
{
    $isJson = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ||
              (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
              (isset($_SERVER['HTTP_CONTENT_TYPE']) && str_contains($_SERVER['HTTP_CONTENT_TYPE'], 'json'));

    if ($isJson) {
        header('Content-Type: application/json');
        http_response_code($code);
        echo json_encode(['success' => false, 'error' => $message]);
        exit;
    }
    $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: /auth/login.php?redirect=' . $redirect);
    exit;
}

/**
 * Create a session for the given user (call after successful login verification).
 */
function createSession(int $userId): void
{
    spStartSession();
    session_regenerate_id(true);
    $_SESSION['sp_user_id'] = $userId;
    getDb()->prepare("UPDATE users SET last_login = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE id = ?")
           ->execute([$userId]);
}

/**
 * Destroy the current session and clear the session cookie.
 */
function destroySession(): void
{
    spStartSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 86400, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Remove expired WebAuthn challenges (>5 minutes old).
 */
function cleanupExpiredChallenges(): void
{
    getDb()->exec("DELETE FROM webauthn_challenges WHERE created_at < datetime('now', '-5 minutes')");
}
