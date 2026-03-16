<?php
ini_set('display_errors', 0);
ini_set('memory_limit', '64M');
set_time_limit(300);
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth/auth.php';

$dataDir = __DIR__ . '/data';
$cacheFile = $dataDir . '/roads_optimized.json';

if (!file_exists($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

/**
 * Notify the Mercure hub that the reports list has changed.
 * PHP posts to the internal hub on 127.0.0.1:2099 (loopback-only, never
 * reachable from outside).  The hub is reverse-proxied to clients via the
 * main Caddy site block, so this single fire-and-forget POST reaches every
 * connected browser regardless of whether TLS is used on the public endpoint.
 * Silently skips if MERCURE_JWT_SECRET is not set (e.g. very old deployments).
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

/**
 * Convert a database row to the report object format the frontend expects
 */
function rowToReport($row) {
    return [
        'id' => $row['id'],
        'road_id' => (int)$row['road_id'],
        'road_name' => $row['road_name'],
        'segment' => $row['segment'],
        'segment_description' => $row['segment_description'],
        'geometry' => $row['geometry'] ? json_decode($row['geometry'], true) : null,
        'status' => $row['status'],
        'notes' => $row['notes'],
        'timestamp' => $row['timestamp'],
        'segmentIds' => $row['segment_ids'] ? json_decode($row['segment_ids'], true) : null,
        'confirmed' => (int)($row['confirmed'] ?? 0),
    ];
}

/**
 * Filter inappropriate content from text
 */
function filterInappropriateContent($text) {
    if (empty($text)) {
        return $text;
    }

    $badPatterns = [
        '/\bf+[\W_]*u+[\W_]*c+[\W_]*k+/i',
        '/\bs+[\W_]*h+[\W_]*i+[\W_]*t+/i',
        '/\bb+[\W_]*i+[\W_]*t+[\W_]*c+[\W_]*h+/i',
        '/\ba+[\W_]*s+[\W_]*s+[\W_]*h+[\W_]*o+[\W_]*l+[\W_]*e+/i',
        '/\bd+[\W_]*a+[\W_]*m+[\W_]*n+/i',
        '/\bh+[\W_]*e+[\W_]*l+[\W_]*l+/i',
        '/\bc+[\W_]*r+[\W_]*a+[\W_]*p+/i',
    ];

    foreach ($badPatterns as $pattern) {
        if (preg_match($pattern, $text)) {
            throw new Exception('Please keep comments appropriate and professional');
        }
    }

    $upperCount = preg_match_all('/[A-Z]/', $text);
    $totalLetters = preg_match_all('/[a-zA-Z]/', $text);
    if ($totalLetters > 10 && $upperCount / $totalLetters > 0.7) {
        throw new Exception('Please avoid excessive use of capital letters');
    }

    return trim($text);
}

$action = $_GET['action'] ?? null;
$postData = null;

if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $postData = json_decode($input, true);
    $action = $postData['action'] ?? null;
}

if (!$action) {
    echo json_encode(['success' => false, 'error' => 'No action specified']);
    exit;
}

/**
 * Get the real client IP address (handles Cloudflare proxy)
 */
function getClientIp() {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ??
          $_SERVER['HTTP_X_FORWARDED_FOR'] ??
          $_SERVER['REMOTE_ADDR'] ??
          'unknown';

    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }

    return $ip;
}

/**
 * Check if an IP is on a specific list (whitelist or blacklist)
 */
function isIpOnList($ip, $listType) {
    $db = getDb();
    $stmt = $db->prepare("SELECT COUNT(*) FROM ip_lists WHERE ip = :ip AND list_type = :type");
    $stmt->execute([':ip' => $ip, ':type' => $listType]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Check if the client IP is blacklisted — call before any write action
 */
function checkBlacklist() {
    $ip = getClientIp();
    if (isIpOnList($ip, 'blacklist')) {
        throw new Exception("Access denied.");
    }
}

/**
 * Rate limiting via SQLite (whitelist checked from ip_lists table)
 */
function checkRateLimit($action) {
    $ip = getClientIp();

    // Whitelist IPs are exempt from rate limiting
    if (isIpOnList($ip, 'whitelist')) {
        return true;
    }

    $db = getDb();

    // Clean old entries
    $db->exec("DELETE FROM rate_limits WHERE requested_at < datetime('now', '-1 day')");

    // Record this request
    $stmt = $db->prepare("INSERT INTO rate_limits (ip, action, requested_at) VALUES (:ip, :action, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))");
    $stmt->execute([':ip' => $ip, ':action' => $action]);

    // Count requests in the last hour
    $stmt = $db->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip = :ip AND action = :action AND requested_at > datetime('now', '-1 hour')");
    $stmt->execute([':ip' => $ip, ':action' => $action]);
    $count = $stmt->fetchColumn();

    $maxRequests = 10;
    if ($count > $maxRequests) {
        throw new Exception("Rate limit exceeded. Please try again later.");
    }

    return true;
}

try {
    switch ($action) {
        case 'auth_check':
            // Returns current user info (or null) — used by the frontend on load
            $user = getCurrentUser();
            if ($user) {
                echo json_encode([
                    'success'  => true,
                    'user'     => [
                        'id'           => (int)$user['id'],
                        'username'     => $user['username'],
                        'display_name' => $user['display_name'] ?? $user['username'],
                        'role'         => $user['role'],
                    ],
                ]);
            } else {
                echo json_encode(['success' => true, 'user' => null]);
            }
            break;

        case 'get_prefs':
            // Returns saved notification preferences for the current user (null if not logged in)
            $user = getCurrentUser();
            $prefs = ($user && $user['prefs']) ? json_decode($user['prefs'], true) : null;
            echo json_encode(['success' => true, 'prefs' => $prefs]);
            break;

        case 'save_prefs':
            // Persists notification preferences for the current user
            $user = getCurrentUser();
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                break;
            }
            $prefs = json_encode([
                'notif_enabled'  => (bool)($postData['notif_enabled'] ?? false),
                'notif_statuses' => array_values(array_filter(
                    (array)($postData['notif_statuses'] ?? []),
                    'is_string'
                )),
            ]);
            getDb()->prepare("UPDATE users SET prefs=? WHERE id=?")->execute([$prefs, $user['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'login_password':
            // JSON login endpoint used by the login modal on the map page.
            // Handles password-only and TOTP second-factor in one action.
            require_once __DIR__ . '/auth/Totp.php';
            $username = trim($postData['username'] ?? '');
            $password = $postData['password'] ?? '';
            $totpCode = trim($postData['totp_code'] ?? '');

            $stmt = getDb()->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $loginUser = $stmt->fetch();

            if (!$loginUser || !password_verify($password, (string)($loginUser['password_hash'] ?? ''))) {
                usleep(500_000); // slow brute-force
                echo json_encode(['success' => false, 'error' => 'Invalid username or password.']);
                break;
            }
            if ($loginUser['status'] === 'pending') {
                echo json_encode(['success' => false, 'error' => 'Your account is pending admin approval.']);
                break;
            }
            if ($loginUser['status'] !== 'active') {
                echo json_encode(['success' => false, 'error' => 'Your account is not active.']);
                break;
            }
            if ($loginUser['totp_enabled']) {
                if ($totpCode === '') {
                    echo json_encode(['success' => false, 'needTotp' => true]);
                    break;
                }
                if (!Totp::verify($loginUser['totp_secret'], $totpCode)) {
                    echo json_encode(['success' => false, 'error' => 'Invalid authenticator code.']);
                    break;
                }
            }
            createSession((int)$loginUser['id']);
            echo json_encode(['success' => true]);
            break;

        case 'get_metadata':
            $db = getDb();
            $rows = $db->query("SELECT key, value FROM metadata")->fetchAll(PDO::FETCH_ASSOC);
            $meta = [];
            foreach ($rows as $row) {
                $meta[$row['key']] = $row['value'];
            }
            echo json_encode(['success' => true, 'metadata' => $meta]);
            break;

        case 'get_reports':
            // Let Cloudflare cache this for 15 s so concurrent users share one origin hit.
            // Browsers revalidate every request (max-age=0) but CDN serves from cache.
            header('Cache-Control: public, s-maxage=15, max-age=0');
            $db = getDb();
            $stmt = $db->query("SELECT * FROM reports WHERE timestamp > datetime('now', '-3 days') ORDER BY timestamp DESC");
            $reports = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $reports[] = rowToReport($row);
            }
            echo json_encode([
                'success' => true,
                'reports' => $reports
            ]);
            break;

        case 'add_report':
            checkBlacklist();
            checkRateLimit('add_report');

            if (!$postData || !isset($postData['report'])) {
                throw new Exception('No report data provided');
            }

            $report = $postData['report'];

            if (!isset($report['road_id']) || !isset($report['road_name']) || !isset($report['status'])) {
                throw new Exception('Missing required fields');
            }

            // Sanitise scalar fields
            $report['road_id']   = (int)$report['road_id'];
            $report['road_name'] = mb_substr(strip_tags((string)$report['road_name']), 0, 200);

            // Validate geometry: must be an array of coordinate pairs, max 2 000 points
            if (isset($report['geometry'])) {
                if (!is_array($report['geometry']) || count($report['geometry']) > 2000) {
                    throw new Exception('Invalid geometry');
                }
            }

            $validStatuses = ['clear', 'snow', 'ice-patches', 'blocked-tree', 'blocked-power',
                             'accident', 'road-closure', 'lz'];
            if (!in_array($report['status'], $validStatuses)) {
                throw new Exception('Invalid status');
            }

            $reportId = uniqid('report_', true);

            if (isset($report['notes'])) {
                $report['notes'] = strip_tags($report['notes']);
                $report['notes'] = filterInappropriateContent($report['notes']);

                if (strlen($report['notes']) > 500) {
                    throw new Exception('Notes are too long (maximum 500 characters)');
                }
            }

            // Always use the server clock — never trust a client-supplied timestamp
            $timestamp = gmdate('Y-m-d\TH:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000) . 'Z';

            $db = getDb();
            $db->beginTransaction();

            $clientIp = getClientIp();

            $currentUser = getCurrentUser();
            $submittedBy = $currentUser['id'] ?? null;

            $roleHierarchy = ['user' => 1, 'first_responder' => 2, 'admin' => 3];
            $confirmed = 0;
            if ($currentUser) {
                $role = $currentUser['role'] ?? 'user';
                if (($roleHierarchy[$role] ?? 0) >= 2) $confirmed = 1;
            }

            $stmt = $db->prepare('
                INSERT INTO reports (id, road_id, road_name, segment, segment_description, geometry, status, notes, timestamp, segment_ids, ip, submitted_by, confirmed)
                VALUES (:id, :road_id, :road_name, :segment, :segment_description, :geometry, :status, :notes, :timestamp, :segment_ids, :ip, :submitted_by, :confirmed)
            ');
            $stmt->execute([
                ':id' => $reportId,
                ':road_id' => $report['road_id'],
                ':road_name' => $report['road_name'],
                ':segment' => $report['segment'] ?? null,
                ':segment_description' => $report['segment_description'] ?? null,
                ':geometry' => isset($report['geometry']) ? json_encode($report['geometry']) : null,
                ':status' => $report['status'],
                ':notes' => $report['notes'] ?? null,
                ':timestamp' => $timestamp,
                ':segment_ids' => isset($report['segmentIds']) ? json_encode($report['segmentIds']) : null,
                ':ip' => $clientIp,
                ':submitted_by' => $submittedBy,
                ':confirmed' => $confirmed,
            ]);

            // Triggers on reports table auto-insert into report_changes

            // Purge expired reports periodically
            $db->exec("DELETE FROM reports WHERE timestamp <= datetime('now', '-3 days')");
            // Purge old change log entries
            $db->exec("DELETE FROM report_changes WHERE changed_at < datetime('now', '-1 day')");

            $db->commit();

            // Return the report as the frontend expects it
            $report['id'] = $reportId;
            $report['timestamp'] = $timestamp;
            $report['confirmed'] = $confirmed;

            echo json_encode([
                'success' => true,
                'report' => $report
            ]);

            // Notify connected browsers via the Mercure hub (fire-and-forget).
            // The hub broadcasts to all subscribers so they refresh immediately
            // instead of waiting for the next 30-second poll cycle.
            publishMercureUpdate();

            break;

        case 'delete_report':
            // Only authenticated users (or admin via admin.php) can delete
            requireAuth();
            checkBlacklist();

            if (!$postData || !isset($postData['id'])) {
                throw new Exception('No report ID provided');
            }

            $db = getDb();
            $db->beginTransaction();

            $stmt = $db->prepare('DELETE FROM reports WHERE id = :id');
            $stmt->execute([':id' => $postData['id']]);

            if ($stmt->rowCount() === 0) {
                $db->rollBack();
                throw new Exception('Report not found');
            }

            $db->commit();

            echo json_encode(['success' => true]);
            break;

        case 'edit_report':
            $authUser = requireAuth();
            checkBlacklist();

            if (!$postData || !isset($postData['id'])) {
                throw new Exception('No report ID provided');
            }

            $editId = $postData['id'];
            $db     = getDb();

            $existing = $db->prepare("SELECT * FROM reports WHERE id = ?");
            $existing->execute([$editId]);
            $existingReport = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$existingReport) {
                throw new Exception('Report not found');
            }

            // Validate new status if provided
            $validStatuses = ['clear', 'snow', 'ice-patches', 'blocked-tree', 'blocked-power',
                             'accident', 'road-closure', 'lz'];
            $newStatus = $postData['status'] ?? $existingReport['status'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception('Invalid status');
            }

            $newNotes = $postData['notes'] ?? $existingReport['notes'];
            if ($newNotes !== null) {
                $newNotes = strip_tags((string)$newNotes);
                $newNotes = filterInappropriateContent($newNotes);
                if (strlen($newNotes) > 500) {
                    throw new Exception('Notes are too long (maximum 500 characters)');
                }
            }

            $db->beginTransaction();
            $db->prepare("UPDATE reports SET status = ?, notes = ? WHERE id = ?")
               ->execute([$newStatus, $newNotes, $editId]);
            $db->prepare("INSERT INTO report_changes (change_type, report_id) VALUES ('update', ?)")
               ->execute([$editId]);
            $db->commit();

            $updated = $db->prepare("SELECT * FROM reports WHERE id = ?");
            $updated->execute([$editId]);
            echo json_encode(['success' => true, 'report' => rowToReport($updated->fetch(PDO::FETCH_ASSOC))]);
            break;

        case 'get_changes':
            // SSE uses this to get delta updates since a given change_id
            $sinceId = isset($_GET['since']) ? (int)$_GET['since'] : 0;

            $db = getDb();
            $stmt = $db->prepare("
                SELECT c.change_id, c.change_type, c.report_id, c.changed_at,
                       r.id, r.road_id, r.road_name, r.segment, r.segment_description,
                       r.geometry, r.status, r.notes, r.timestamp, r.segment_ids, r.confirmed
                FROM report_changes c
                LEFT JOIN reports r ON c.report_id = r.id
                WHERE c.change_id > :since_id
                ORDER BY c.change_id ASC
            ");
            $stmt->execute([':since_id' => $sinceId]);

            $changes = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $change = [
                    'changeId' => (int)$row['change_id'],
                    'changeType' => $row['change_type'],
                    'reportId' => $row['report_id'],
                ];
                // For 'add' changes, include the full report data
                if ($row['change_type'] === 'add' && $row['id'] !== null) {
                    $change['report'] = rowToReport($row);
                }
                $changes[] = $change;
            }

            echo json_encode(['success' => true, 'changes' => $changes]);
            break;

        case 'get_roads':
            if (file_exists($cacheFile) && filesize($cacheFile) > 0) {
                header("Cache-Control: no-cache, must-revalidate");
                header("Pragma: no-cache");
                header("Expires: 0");

                readfile($cacheFile);
            } else {
                throw new Exception('Road data not available. Please wait for the next data rebuild.');
            }
            break;

        case 'get_roads_stream':
            $jsonlFile = $dataDir . '/roads_optimized.jsonl';

            if (!file_exists($jsonlFile) || filesize($jsonlFile) == 0) {
                throw new Exception('Road data not available. Please wait for the next data rebuild.');
            }

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header("Content-Type: application/x-ndjson");
            header("Cache-Control: no-cache, must-revalidate");
            header("Pragma: no-cache");
            header("Expires: 0");
            header("X-Accel-Buffering: no");

            $handle = fopen($jsonlFile, 'r');
            if ($handle) {
                while (($line = fgets($handle)) !== false) {
                    echo $line;
                    flush();
                }
                fclose($handle);
            }
            exit(0);

        case 'get_reports_stream':
            // Stream reports from SQLite as NDJSON
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header("Content-Type: application/x-ndjson");
            header("Cache-Control: no-cache, must-revalidate");
            header("Pragma: no-cache");
            header("Expires: 0");
            header("X-Accel-Buffering: no");

            $db = getDb();
            $stmt = $db->query("SELECT * FROM reports WHERE timestamp > datetime('now', '-3 days') ORDER BY timestamp DESC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                echo json_encode(rowToReport($row)) . "\n";
                flush();
            }
            exit(0);

        default:
            throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
