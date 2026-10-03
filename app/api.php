<?php
ini_set('display_errors', 0);
ini_set('memory_limit', '64M');
header('Content-Type: application/json');

require_once __DIR__ . '/common.php';
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
require_once __DIR__ . '/mercure.php';

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
        'submitted_by' => isset($row['submitted_by']) ? (int)$row['submitted_by'] : null,
    ];
}

/**
 * Reports can be edited/deleted by their author or by a first responder / admin.
 */
function canModifyReport(?array $user, array $row): bool {
    if (!$user) {
        return false;
    }
    return spRoleLevel($user) >= SP_ROLE_LEVELS['first_responder']
        || ($row['submitted_by'] !== null && (int)$row['submitted_by'] === (int)$user['id']);
}

/**
 * Filter inappropriate content from text
 */
function filterInappropriateContent($text) {
    if (empty($text)) {
        return $text;
    }

    // Letters may be separated by punctuation (f.u.c.k) but not by spaces, and
    // the word must end at a word boundary — otherwise "Hello", "Hellwig Rd",
    // "crappie" and "it's hit" are all rejected.
    $sep = '[^\w\s]*';
    $badWords = [
        'f+%su+%sc+%sk+(?:ing|ed|er|s)?',
        's+%sh+%si+%st+(?:ty|s)?',
        'b+%si+%st+%sc+%sh+(?:es)?',
        'a+%ss+%ss+%sh+%so+%sl+%se+s?',
        'd+%sa+%sm+%sn+(?:ed|it)?',
        'h+%se+%sl+%sl+',
        'c+%sr+%sa+%sp+(?:py|s)?',
    ];

    foreach ($badWords as $word) {
        $pattern = '/\b' . str_replace('%s', $sep, $word) . '\b/i';
        if (preg_match($pattern, $text)) {
            throw new SpClientError('Please keep comments appropriate and professional');
        }
    }

    return trim($text);
}

/**
 * Validate and normalise free-text notes (shared by add_report and edit_report).
 */
function cleanNotes($notes): ?string {
    if ($notes === null) {
        return null;
    }
    $notes = filterInappropriateContent(strip_tags((string)$notes));
    if (mb_strlen($notes) > 500) {
        throw new SpClientError('Notes are too long (maximum 500 characters)');
    }
    return $notes;
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
 * Check if the client IP is blacklisted — call before any write action
 */
function checkBlacklist() {
    if (isIpOnList(getClientIp(), 'blacklist')) {
        throw new SpClientError('Access denied.', 403);
    }
}

try {
    switch ($action) {
        case 'auth_check':
            // Returns current user info (or null) — used by the frontend on load
            $user = getCurrentUser();
            if ($user) {
                echo json_encode([
                    'success'      => true,
                    'idme_enabled' => (bool)getenv('IDME_CLIENT_ID'),
                    'user'         => [
                        'id'               => (int)$user['id'],
                        'username'         => $user['username'],
                        'display_name'     => $user['display_name'] ?? $user['username'],
                        'email'            => $user['email'] ?? '',
                        'role'             => $user['role'],
                        'fr_claim'         => (bool)($user['fr_claim'] ?? false),
                        'fr_agency'        => $user['fr_agency'] ?? '',
                        'fr_role'          => $user['fr_role'] ?? '',
                        'fr_identifier'    => $user['fr_identifier'] ?? '',
                        'fr_idme_verified' => (bool)($user['fr_idme_verified'] ?? false),
                    ],
                ]);
            } else {
                echo json_encode(['success' => true, 'user' => null, 'idme_enabled' => false]);
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
            if (spLoginThrottled()) {
                throw new SpClientError('Too many failed sign-in attempts. Please wait 15 minutes and try again.', 429);
            }
            $username = trim($postData['username'] ?? '');
            $password = $postData['password'] ?? '';
            $totpCode = trim($postData['totp_code'] ?? '');

            $stmt = getDb()->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $loginUser = $stmt->fetch();

            if (!$loginUser || !password_verify($password, (string)($loginUser['password_hash'] ?? ''))) {
                spRecordLoginFailure();
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
                if (!spVerifyLoginTotp($loginUser, $totpCode)) {
                    spRecordLoginFailure();
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
            header('Cache-Control: no-cache, must-revalidate');
            $db = getDb();
            $stmt = $db->query("SELECT * FROM reports WHERE timestamp > " . spIsoAgo(SP_REPORT_WINDOW) . " ORDER BY timestamp DESC");
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

            if (!$postData || !isset($postData['report']) || !is_array($postData['report'])) {
                throw new SpClientError('No report data provided');
            }

            $report = $postData['report'];

            if (!isset($report['road_id']) || !isset($report['road_name']) || !isset($report['status'])) {
                throw new SpClientError('Missing required fields');
            }

            // Sanitise scalar fields
            $report['road_id']   = (int)$report['road_id'];
            $report['road_name'] = mb_substr(strip_tags((string)$report['road_name']), 0, 200);
            if (isset($report['segment'])) {
                $report['segment'] = mb_substr((string)$report['segment'], 0, 64);
            }
            if (isset($report['segment_description'])) {
                $report['segment_description'] = mb_substr(strip_tags((string)$report['segment_description']), 0, 300);
            }

            // Validate geometry: must be an array of [x, y] numeric pairs, max 2 000 points
            if (isset($report['geometry'])) {
                if (!is_array($report['geometry']) || count($report['geometry']) > 2000) {
                    throw new SpClientError('Invalid geometry');
                }
                foreach ($report['geometry'] as $pt) {
                    if (!is_array($pt) || count($pt) < 2 || count($pt) > 3
                        || !is_numeric($pt[0] ?? null) || !is_numeric($pt[1] ?? null)) {
                        throw new SpClientError('Invalid geometry');
                    }
                }
            }

            // Segment IDs are used as hash keys below — must be short scalars
            if (isset($report['segmentIds'])) {
                if (!is_array($report['segmentIds']) || count($report['segmentIds']) > 2000) {
                    throw new SpClientError('Invalid segment IDs');
                }
                foreach ($report['segmentIds'] as $sid) {
                    if (!is_int($sid) && !(is_string($sid) && strlen($sid) <= 64)) {
                        throw new SpClientError('Invalid segment IDs');
                    }
                }
            }

            if (!in_array($report['status'], SP_REPORT_STATUSES, true)) {
                throw new SpClientError('Invalid status');
            }

            $reportId = uniqid('report_', true);

            if (isset($report['notes'])) {
                $report['notes'] = cleanNotes($report['notes']);
            }

            // Always use the server clock — never trust a client-supplied timestamp
            $timestamp = gmdate('Y-m-d\TH:i:s.') . sprintf('%03d', (int)(microtime(true) * 1000) % 1000) . 'Z';

            $db = getDb();
            $db->beginTransaction();

            $clientIp = getClientIp();

            $currentUser = getCurrentUser();
            $submittedBy = $currentUser['id'] ?? null;
            $confirmed   = spRoleLevel($currentUser) >= SP_ROLE_LEVELS['first_responder'] ? 1 : 0;

            // Find reports this one replaces.
            // Entire-road report → replaces everything on that road.
            // Segment-specific report → replaces only reports whose segment_ids overlap;
            //   entire-road reports and non-overlapping segments are left intact.
            $replaced = [];
            if (($report['segment'] ?? '') === 'entire') {
                $existing = $db->prepare('SELECT id, confirmed FROM reports WHERE road_id = ?');
                $existing->execute([$report['road_id']]);
                $replaced = $existing->fetchAll(PDO::FETCH_ASSOC);
            } elseif (!empty($report['segmentIds'])) {
                $existing = $db->prepare(
                    "SELECT id, confirmed, segment_ids FROM reports WHERE road_id = ? AND segment != 'entire'"
                );
                $existing->execute([$report['road_id']]);
                $newIds = array_flip($report['segmentIds']); // use as hash for fast lookup
                foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $existingIds = $row['segment_ids'] ? json_decode($row['segment_ids'], true) : [];
                    if (!is_array($existingIds)) continue;
                    foreach ($existingIds as $sid) {
                        if ((is_int($sid) || is_string($sid)) && isset($newIds[$sid])) {
                            $replaced[] = $row;
                            break;
                        }
                    }
                }
            }

            // A first responder's confirmed report can only be replaced by another first responder
            if (!$confirmed && array_filter($replaced, fn($r) => (int)$r['confirmed'] === 1)) {
                $db->rollBack();
                throw new SpClientError('A first responder has confirmed the current report for this road. Only a first responder can replace it.', 403);
            }

            if ($replaced) {
                $ids = array_column($replaced, 'id');
                $db->prepare('DELETE FROM reports WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')')
                   ->execute($ids);
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

            // Purge reports past the admin history window (public views only show SP_REPORT_WINDOW)
            $db->exec("DELETE FROM reports WHERE timestamp <= " . spIsoAgo(SP_REPORT_RETENTION));
            // Purge old change log entries
            $db->exec("DELETE FROM report_changes WHERE changed_at < " . spIsoAgo('-1 day'));

            $db->commit();

            // Return the report as the frontend expects it
            $report['id'] = $reportId;
            $report['timestamp'] = $timestamp;
            $report['confirmed'] = $confirmed;
            $report['submitted_by'] = $submittedBy !== null ? (int)$submittedBy : null;

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
            $authUser = requireAuth();
            checkBlacklist();

            if (!$postData || !isset($postData['id'])) {
                throw new SpClientError('No report ID provided');
            }

            $db = getDb();
            $existing = $db->prepare("SELECT * FROM reports WHERE id = ?");
            $existing->execute([$postData['id']]);
            $existingReport = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$existingReport) {
                throw new SpClientError('Report not found', 404);
            }
            if (!canModifyReport($authUser, $existingReport)) {
                throw new SpClientError('Only the author or a first responder can delete this report.', 403);
            }

            $db->prepare('DELETE FROM reports WHERE id = ?')->execute([$postData['id']]);

            echo json_encode(['success' => true]);

            publishMercureUpdate();
            break;

        case 'edit_report':
            $authUser = requireAuth();
            checkBlacklist();

            if (!$postData || !isset($postData['id'])) {
                throw new SpClientError('No report ID provided');
            }

            $editId = $postData['id'];
            $db     = getDb();

            $existing = $db->prepare("SELECT * FROM reports WHERE id = ?");
            $existing->execute([$editId]);
            $existingReport = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$existingReport) {
                throw new SpClientError('Report not found', 404);
            }
            if (!canModifyReport($authUser, $existingReport)) {
                throw new SpClientError('Only the author or a first responder can edit this report.', 403);
            }

            // Validate new status if provided
            $newStatus = $postData['status'] ?? $existingReport['status'];
            if (!in_array($newStatus, SP_REPORT_STATUSES, true)) {
                throw new SpClientError('Invalid status');
            }

            $newNotes = cleanNotes($postData['notes'] ?? $existingReport['notes']);

            // "Confirmed" reflects who last vouched for the content: a first
            // responder's edit confirms it, anyone else's edit un-confirms it.
            $newConfirmed = spRoleLevel($authUser) >= SP_ROLE_LEVELS['first_responder'] ? 1 : 0;

            $db->beginTransaction();
            $db->prepare("UPDATE reports SET status = ?, notes = ?, confirmed = ? WHERE id = ?")
               ->execute([$newStatus, $newNotes, $newConfirmed, $editId]);
            $db->prepare("INSERT INTO report_changes (change_type, report_id) VALUES ('update', ?)")
               ->execute([$editId]);
            $db->commit();

            $updated = $db->prepare("SELECT * FROM reports WHERE id = ?");
            $updated->execute([$editId]);
            echo json_encode(['success' => true, 'report' => rowToReport($updated->fetch(PDO::FETCH_ASSOC))]);

            publishMercureUpdate();
            break;

        case 'get_roads':
            if (file_exists($cacheFile) && filesize($cacheFile) > 0) {
                header("Cache-Control: no-cache, must-revalidate");
                header("Pragma: no-cache");
                header("Expires: 0");

                readfile($cacheFile);
            } else {
                throw new SpClientError('Road data not available. Please wait for the next data rebuild.', 503);
            }
            break;

        case 'update_fr_claim':
            $user = getCurrentUser();
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                break;
            }
            $frAgency     = mb_substr(trim($postData['fr_agency'] ?? ''), 0, 120);
            $frRole       = $postData['fr_role'] ?? '';
            $frIdentifier = mb_substr(trim($postData['fr_identifier'] ?? ''), 0, 120);
            $validRoles   = ['fire', 'ems', 'law', 'em', 'other'];
            if (!$frAgency) {
                echo json_encode(['success' => false, 'error' => 'Agency / organization is required.']);
                break;
            }
            if (!in_array($frRole, $validRoles, true)) {
                echo json_encode(['success' => false, 'error' => 'Please select a valid role.']);
                break;
            }
            getDb()->prepare('UPDATE users SET fr_claim=1, fr_agency=?, fr_role=?, fr_identifier=? WHERE id=?')
                   ->execute([$frAgency, $frRole, $frIdentifier ?: null, $user['id']]);
            echo json_encode([
                'success'      => true,
                'fr_agency'    => $frAgency,
                'fr_role'      => $frRole,
                'fr_identifier'=> $frIdentifier,
            ]);
            break;

        case 'update_profile':
            $user = getCurrentUser();
            if (!$user) {
                echo json_encode(['success' => false, 'error' => 'Not authenticated']);
                break;
            }
            $db          = getDb();
            $displayName = mb_substr(trim($postData['display_name'] ?? ''), 0, 80);
            $email       = trim($postData['email'] ?? '');
            $newPassword = $postData['new_password']     ?? '';
            $curPassword = $postData['current_password'] ?? '';

            if (!$displayName) {
                echo json_encode(['success' => false, 'error' => 'Display name is required.']);
                break;
            }
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
                break;
            }
            if ($email) {
                $taken = $db->prepare('SELECT 1 FROM users WHERE email = ? AND id != ?');
                $taken->execute([$email, $user['id']]);
                if ($taken->fetchColumn()) {
                    echo json_encode(['success' => false, 'error' => 'That email address is already in use.']);
                    break;
                }
            }
            if ($newPassword !== '') {
                if (strlen($newPassword) < 10) {
                    echo json_encode(['success' => false, 'error' => 'New password must be at least 10 characters.']);
                    break;
                }
                $row = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
                $row->execute([$user['id']]);
                $u = $row->fetch(PDO::FETCH_ASSOC);
                if (!$u || !password_verify($curPassword, (string)$u['password_hash'])) {
                    echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
                    break;
                }
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $db->prepare('UPDATE users SET display_name = ?, email = ?, password_hash = ? WHERE id = ?')
                   ->execute([$displayName, $email ?: null, $hash, $user['id']]);
            } else {
                $db->prepare('UPDATE users SET display_name = ?, email = ? WHERE id = ?')
                   ->execute([$displayName, $email ?: null, $user['id']]);
            }
            echo json_encode(['success' => true, 'display_name' => $displayName]);
            break;

        default:
            throw new SpClientError('Invalid action');
    }
} catch (SpClientError $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    // Don't leak SQL/internal details to the client — log them instead
    error_log('api.php [' . $action . ']: ' . $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error. Please try again.'
    ]);
}
?>
