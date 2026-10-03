<?php
/**
 * Waze CIFS Partner Feed
 *
 * Serves confirmed StormPath incidents in Waze's CIFS (Closure and Incident
 * Feed Specification) JSON format. Register this URL in your Waze For Cities
 * partner account; Waze polls it automatically every few minutes.
 *
 * Optional key protection: set WAZE_FEED_KEY env var.
 * Then access via /waze-feed.php?key=<your-key>
 */

$feedKey = getenv('WAZE_FEED_KEY');
if ($feedKey && !hash_equals($feedKey, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/common.php';

$db   = getDb();
$stmt = $db->query("
    SELECT * FROM reports
    WHERE confirmed = 1
      AND status != 'clear'
      AND timestamp > " . spIsoAgo(SP_REPORT_WINDOW) . "
    ORDER BY timestamp DESC
");

// Maps StormPath status values to Waze CIFS type/subtype
$typeMap = [
    'accident'      => ['type' => 'ACCIDENT',   'subtype' => 'ACCIDENT_MAJOR'],
    'road-closure'  => ['type' => 'ROAD_CLOSED', 'subtype' => null],
    'lz'            => ['type' => 'ROAD_CLOSED', 'subtype' => null],
    'blocked-tree'  => ['type' => 'HAZARD',      'subtype' => 'HAZARD_ON_ROAD_OBJECT'],
    'blocked-power' => ['type' => 'HAZARD',      'subtype' => 'HAZARD_ON_ROAD_OBJECT'],
    'snow'          => ['type' => 'HAZARD',      'subtype' => 'HAZARD_WEATHER_SNOW'],
    'ice-patches'   => ['type' => 'HAZARD',      'subtype' => 'HAZARD_WEATHER_ICE'],
];

$incidents = [];

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $status = $row['status'];
    $map    = $typeMap[$status] ?? null;
    if (!$map) continue;

    // Build polyline: space-separated "lat lng lat lng ..." pairs
    $coords   = json_decode($row['geometry'] ?? '[]', true);
    if (empty($coords)) continue;
    $polyline = implode(' ', array_map(fn($c) => sprintf('%.6f %.6f', (float)$c[0], (float)$c[1]), $coords));

    // ISO 8601 with +00:00 offset as required by CIFS
    $starttime = str_replace('Z', '+00:00', $row['timestamp']);
    $endtime   = gmdate('Y-m-d\TH:i:s+00:00', strtotime($row['timestamp']) + 259200); // +3 days

    $incident = [
        'id'        => $row['id'],
        'type'      => $map['type'],
        'street'    => $row['road_name'],
        'polyline'  => $polyline,
        'starttime' => $starttime,
        'endtime'   => $endtime,
        'direction' => 'BOTH_DIRECTIONS',
    ];

    if ($map['subtype']) {
        $incident['subtype'] = $map['subtype'];
    }

    if (!empty($row['notes'])) {
        $incident['description'] = mb_substr($row['notes'], 0, 40);
    }

    // Human-readable description for LZ incidents
    if ($status === 'lz' && empty($row['notes'])) {
        $incident['description'] = 'Helicopter LZ — Road Closed';
    }

    $incidents[] = $incident;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo json_encode(['incidents' => $incidents], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
