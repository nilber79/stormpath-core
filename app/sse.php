<?php
/**
 * Server-Sent Events endpoint for real-time report updates
 * Uses SQLite change log for efficient delta-based updates
 */

// Disable output buffering completely
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', 'off');
ini_set('implicit_flush', '1');

ob_implicit_flush(1);

require_once __DIR__ . '/db.php';

/**
 * Convert a database row to the report object format the frontend expects.
 * Must stay in sync with api.php:rowToReport().
 */
function rowToReport($row) {
    return [
        'id'                  => $row['id'],
        'road_id'             => (int)$row['road_id'],
        'road_name'           => $row['road_name'],
        'segment'             => $row['segment'],
        'segment_description' => $row['segment_description'],
        'geometry'            => $row['geometry'] ? json_decode($row['geometry'], true) : null,
        'status'              => $row['status'],
        'notes'               => $row['notes'],
        'timestamp'           => $row['timestamp'],
        'segmentIds'          => $row['segment_ids'] ? json_decode($row['segment_ids'], true) : null,
        'confirmed'           => (int)($row['confirmed'] ?? 0),
    ];
}

$db = getDb();

$lastChangeId = (int)$db->query("SELECT COALESCE(MAX(change_id), 0) FROM report_changes")->fetchColumn();

// On reconnect the browser sends Last-Event-ID; only send a full init on first connect.
$resumeFromId = isset($_SERVER['HTTP_LAST_EVENT_ID']) ? (int)$_SERVER['HTTP_LAST_EVENT_ID'] : -1;

if ($resumeFromId < 0) {
    // First connect: send full initial state
    $stmt = $db->query("SELECT * FROM reports WHERE timestamp > datetime('now', '-3 days') ORDER BY timestamp DESC");
    $reports = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $reports[] = rowToReport($row);
    }
    echo "id: {$lastChangeId}\n";
    echo "data: " . json_encode([
        'type' => 'init',
        'reports' => $reports,
        'lastChangeId' => $lastChangeId
    ]) . "\n\n";
} else {
    // Reconnect: resume delta stream from where we left off
    $lastChangeId = $resumeFromId;
}

flush();

// Recycle connection after 4 minutes so PHP worker threads are never permanently
// held. The browser's EventSource reconnects automatically, passing Last-Event-ID
// so the client picks up any missed deltas on reconnect.
$deadline = time() + 240;

// Poll for changes
while (time() < $deadline) {
    if (connection_aborted()) {
        break;
    }

    // Check for new changes since our last known change_id
    $currentMax = (int)$db->query("SELECT COALESCE(MAX(change_id), 0) FROM report_changes")->fetchColumn();

    if ($currentMax > $lastChangeId) {
        // Fetch delta changes
        $stmt = $db->prepare("
            SELECT c.change_id, c.change_type, c.report_id,
                   r.id, r.road_id, r.road_name, r.segment, r.segment_description,
                   r.geometry, r.status, r.notes, r.timestamp, r.segment_ids, r.confirmed
            FROM report_changes c
            LEFT JOIN reports r ON c.report_id = r.id
            WHERE c.change_id > :since_id
            ORDER BY c.change_id ASC
        ");
        $stmt->execute([':since_id' => $lastChangeId]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $changeId = (int)$row['change_id'];

            if (($row['change_type'] === 'add' || $row['change_type'] === 'update') && $row['id'] !== null) {
                $eventType = $row['change_type'] === 'add' ? 'report_added' : 'report_updated';
                echo "id: {$changeId}\n";
                echo "data: " . json_encode([
                    'type' => $eventType,
                    'report' => rowToReport($row),
                    'changeId' => $changeId
                ]) . "\n\n";
            } elseif ($row['change_type'] === 'delete') {
                echo "id: {$changeId}\n";
                echo "data: " . json_encode([
                    'type' => 'report_deleted',
                    'reportId' => $row['report_id'],
                    'changeId' => $changeId
                ]) . "\n\n";
            }

            $lastChangeId = $changeId;
        }

        flush();
    }

    // Send keepalive every 15 seconds
    static $lastKeepalive = 0;
    if (time() - $lastKeepalive > 15) {
        echo ": keepalive\n\n";
        flush();
        $lastKeepalive = time();
    }

    sleep(1);
}
