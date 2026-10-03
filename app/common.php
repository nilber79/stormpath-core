<?php
/**
 * Shared helpers: report constants, client IP resolution and SQLite-backed
 * rate limiting.  Included by api.php, admin.php and the auth pages.
 */
require_once __DIR__ . '/db.php';

const SP_REPORT_STATUSES = ['clear', 'snow', 'ice-patches', 'blocked-tree', 'blocked-power',
                            'accident', 'road-closure', 'lz'];

// Reports are visible publicly for this long; admin.php keeps SP_REPORT_RETENTION of history.
const SP_REPORT_WINDOW    = '-3 days';
const SP_REPORT_RETENTION = '-30 days';

// Failed password / TOTP attempts allowed per IP per SP_LOGIN_FAIL_WINDOW
const SP_LOGIN_MAX_FAILS   = 10;
const SP_LOGIN_FAIL_WINDOW = '-15 minutes';

// https://www.cloudflare.com/ips/ — CF-Connecting-IP is only trusted from these
const SP_CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

/**
 * A problem with the request (validation, permissions, rate limit) — its
 * message is safe to show the user.  The exception code is the HTTP status.
 */
class SpClientError extends RuntimeException
{
    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message, $httpStatus);
    }
}

/**
 * SQL expression for an ISO-8601 UTC timestamp offset from now — the same
 * format every timestamp column uses.  Comparing those columns against
 * datetime('now', …) is wrong: 'YYYY-MM-DD HH:MM:SS' sorts before
 * 'YYYY-MM-DDTHH…' for the whole day, so windows silently stretch to a day.
 */
function spIsoAgo(string $modifier): string
{
    return "strftime('%Y-%m-%dT%H:%M:%fZ', 'now', " . getDb()->quote($modifier) . ")";
}

function spIpInCidr(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $ipBin  = @inet_pton($ip);
    $netBin = @inet_pton($net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $bits  = $bits === null ? strlen($ipBin) * 8 : (int)$bits;
    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($bytes > 0 && strncmp($ipBin, $netBin, $bytes) !== 0) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $rem)) & 0xFF);
    return ($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask);
}

function spIpInList(string $ip, array $cidrs): bool
{
    foreach ($cidrs as $cidr) {
        if (spIpInCidr($ip, $cidr)) {
            return true;
        }
    }
    return false;
}

/**
 * Proxies allowed to set X-Forwarded-For.  Override with TRUSTED_PROXIES
 * (comma-separated CIDRs); defaults to loopback + private ranges, which covers
 * a reverse proxy on the same Docker network.
 */
function spTrustedProxies(): array
{
    $env = getenv('TRUSTED_PROXIES');
    if ($env) {
        return array_filter(array_map('trim', explode(',', $env)));
    }
    return ['127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7'];
}

/**
 * Real client IP.  Headers are only believed when they come from a hop we
 * trust: X-Forwarded-For from a trusted proxy, CF-Connecting-IP from a
 * Cloudflare edge.  Anyone reaching the origin directly can't spoof either.
 */
function getClientIp(): string
{
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $trusted = spTrustedProxies();

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && spIpInList($ip, $trusted)) {
        $hops = array_reverse(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])));
        foreach ($hops as $hop) {
            if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                break;
            }
            $ip = $hop;
            if (!spIpInList($hop, $trusted)) {
                break;
            }
        }
    }

    $cfIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if ($cfIp && filter_var($cfIp, FILTER_VALIDATE_IP) && spIpInList($ip, SP_CLOUDFLARE_RANGES)) {
        $ip = $cfIp;
    }

    return $ip;
}

function isIpOnList(string $ip, string $listType): bool
{
    $stmt = getDb()->prepare("SELECT COUNT(*) FROM ip_lists WHERE ip = :ip AND list_type = :type");
    $stmt->execute([':ip' => $ip, ':type' => $listType]);
    return $stmt->fetchColumn() > 0;
}

/** Log one occurrence of $action for the client IP (and prune entries older than a day). */
function spRecordAction(string $action): void
{
    $db = getDb();
    $db->exec("DELETE FROM rate_limits WHERE requested_at < " . spIsoAgo('-1 day'));
    $db->prepare("INSERT OR IGNORE INTO rate_limits (ip, action, requested_at)
                  VALUES (?, ?, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))")
       ->execute([getClientIp(), $action]);
}

/** How many times the client IP performed $action within $window (e.g. '-1 hour'). */
function spCountRecent(string $action, string $window): int
{
    $stmt = getDb()->prepare(
        "SELECT COUNT(*) FROM rate_limits WHERE ip = ? AND action = ? AND requested_at > " . spIsoAgo($window)
    );
    $stmt->execute([getClientIp(), $action]);
    return (int)$stmt->fetchColumn();
}

/**
 * Record the request and refuse it if the client IP exceeded $max in $window.
 * Whitelisted IPs are exempt.
 */
function checkRateLimit(string $action, int $max = 10, string $window = '-1 hour'): void
{
    if (isIpOnList(getClientIp(), 'whitelist')) {
        return;
    }
    spRecordAction($action);
    if (spCountRecent($action, $window) > $max) {
        throw new SpClientError('Rate limit exceeded. Please try again later.', 429);
    }
}

function spLoginThrottled(): bool
{
    return !isIpOnList(getClientIp(), 'whitelist')
        && spCountRecent('login_fail', SP_LOGIN_FAIL_WINDOW) >= SP_LOGIN_MAX_FAILS;
}

function spRecordLoginFailure(): void
{
    spRecordAction('login_fail');
}
