<?php
/**
 * StormPath Admin Interface
 *
 * Accessible at /admin.php
 * Requires an account with the 'admin' role.
 *
 * Features:
 *   - View, update status, and delete road condition reports
 *   - Manage IP whitelist and blacklist
 *   - Manage user accounts
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth/auth.php';

$currentUser = requireRole('admin');

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$area_cfg   = [];
$cfg_file   = __DIR__ . '/area-config.json';
if (file_exists($cfg_file)) {
    $area_cfg = json_decode(file_get_contents($cfg_file), true) ?? [];
}
$county_name  = $area_cfg['area_name']  ?? 'StormPath';
$county_state = $area_cfg['area_state'] ?? '';

// ── Database ──────────────────────────────────────────────────────────────

try {
    $pdo = getDb();
} catch (Exception $e) {
    die('<p style="color:red;padding:2rem">Cannot open database: ' . h($e->getMessage()) . '</p>');
}

// ── Actions ───────────────────────────────────────────────────────────────

$valid_statuses = ['clear', 'snow', 'ice', 'blocked_tree', 'blocked_power'];
$action         = $_POST['action'] ?? '';
$redirect_tab   = 'reports';

if ($action === 'update_status') {
    $id     = $_POST['report_id'] ?? '';
    $status = $_POST['status']    ?? '';
    if ($id && in_array($status, $valid_statuses, true)) {
        $pdo->prepare("UPDATE reports SET status = ? WHERE id = ?")->execute([$status, $id]);
        // Notify SSE clients of the change
        $pdo->prepare("INSERT INTO report_changes (change_type, report_id) VALUES ('update', ?)")->execute([$id]);
    }
    header('Location: admin.php?tab=reports');
    exit;
}

if ($action === 'delete_report') {
    $id = $_POST['report_id'] ?? '';
    if ($id) {
        $pdo->prepare("DELETE FROM reports WHERE id = ?")->execute([$id]);
        // report_changes trigger fires automatically on DELETE
    }
    header('Location: admin.php?tab=reports');
    exit;
}

if ($action === 'add_ip') {
    $ip        = trim($_POST['ip_address'] ?? '');
    $list_type = $_POST['list_type']       ?? '';
    $note      = trim($_POST['note']       ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP) && in_array($list_type, ['whitelist', 'blacklist'], true)) {
        $pdo->prepare("INSERT OR REPLACE INTO ip_lists (ip, list_type, note) VALUES (?, ?, ?)")
            ->execute([$ip, $list_type, $note]);
    }
    $redirect_tab = 'ip';
    header('Location: admin.php?tab=ip');
    exit;
}

if ($action === 'remove_ip') {
    $ip = $_POST['ip'] ?? '';
    if ($ip) {
        $pdo->prepare("DELETE FROM ip_lists WHERE ip = ?")->execute([$ip]);
    }
    header('Location: admin.php?tab=ip');
    exit;
}

// ── Data ──────────────────────────────────────────────────────────────────

$active_tab = in_array($_GET['tab'] ?? 'reports', ['reports', 'ip', 'merge_issues', 'users'])
    ? ($_GET['tab'] ?? 'reports') : 'reports';

// Show reports from the last 30 days so admins can see recent history
$reports = $pdo->query("
    SELECT * FROM reports
    WHERE datetime(timestamp) > datetime('now', '-30 days')
    ORDER BY timestamp DESC
")->fetchAll();

// Group reports by road name
$grouped = [];
foreach ($reports as $r) {
    $grouped[$r['road_name']][] = $r;
}

$ip_lists  = $pdo->query("SELECT * FROM ip_lists ORDER BY list_type, ip")->fetchAll();
$whitelist = array_filter($ip_lists, fn($row) => $row['list_type'] === 'whitelist');
$blacklist = array_filter($ip_lists, fn($row) => $row['list_type'] === 'blacklist');

// Rebuild metadata from the SQLite metadata table
$meta_rows = $pdo->query("SELECT key, value FROM metadata")->fetchAll();
$rebuild_meta = [];
foreach ($meta_rows as $row) {
    $rebuild_meta[$row['key']] = $row['value'];
}

// Merge issues from the CSV file in the data directory
$merge_issues       = [];
$merge_issues_file  = __DIR__ . '/data/merge_issues.csv';
if (file_exists($merge_issues_file) && ($fh = fopen($merge_issues_file, 'r')) !== false) {
    $headers = fgetcsv($fh, 0, ',', '"', '\\');
    while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($headers && count($row) === count($headers)) {
            $merge_issues[] = array_combine($headers, $row);
        }
    }
    fclose($fh);
}

$status_labels = [
    'clear'         => 'Clear',
    'snow'          => 'Snow Covered',
    'ice'           => 'Icy',
    'blocked_tree'  => 'Blocked — Tree',
    'blocked_power' => 'Blocked — Power Line',
];
$status_colors = [
    'clear'         => '#10b981',
    'snow'          => '#60a5fa',
    'ice'           => '#a78bfa',
    'blocked_tree'  => '#dc2626',
    'blocked_power' => '#f59e0b',
];

// Is this report still within the 3-day public window?
function is_active(string $ts): bool {
    return strtotime($ts) > strtotime('-3 days');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — <?= h($county_name) ?> | StormPath</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: #f8f6f2;
            color: #2a2622;
            font-size: 0.9375rem;
        }

        /* ── Header ── */
        .admin-header {
            background: #2a2622;
            color: #f8f6f2;
            padding: 0.875rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .admin-header-brand { font-size: 1.1rem; font-weight: 700; }
        .admin-header-brand span { color: #d97706; }
        .admin-header-sub { font-size: 0.8125rem; color: #9ca3af; margin-top: 0.125rem; }
        .admin-header-actions { display: flex; align-items: center; gap: 0.75rem; }
        .btn-back {
            background: transparent;
            border: 1px solid rgba(255,255,255,.3);
            color: #f8f6f2;
            padding: 0.4rem 1rem;
            border-radius: 6px;
            font-size: 0.8125rem;
            text-decoration: none;
        }
        .btn-back:hover { background: rgba(255,255,255,.1); color: #f8f6f2; }
        .btn-logout {
            background: transparent;
            border: 1px solid rgba(255,255,255,.3);
            color: #f8f6f2;
            padding: 0.4rem 1rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8125rem;
        }
        .btn-logout:hover { background: rgba(255,255,255,.1); }

        /* ── Tabs ── */
        .tabs {
            display: flex;
            gap: 0;
            background: #fff;
            border-bottom: 1px solid #e0ddd5;
            padding: 0 2rem;
        }
        .tab {
            padding: 0.875rem 1.25rem;
            font-weight: 600;
            font-size: 0.875rem;
            color: #6b6660;
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
        }
        .tab.active { color: #d97706; border-bottom-color: #d97706; }
        .tab:hover:not(.active) { color: #2a2622; }

        /* ── Main content ── */
        .content { max-width: 1100px; margin: 0 auto; padding: 1.5rem 2rem; }

        /* ── Section heading ── */
        .section-heading {
            font-size: 1.125rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .count-badge {
            background: #e0ddd5;
            color: #6b6660;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.125rem 0.625rem;
            border-radius: 999px;
        }

        /* ── Road group ── */
        .road-group { margin-bottom: 1.25rem; }
        .road-group-header {
            font-weight: 700;
            font-size: 0.875rem;
            color: #6b6660;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e0ddd5;
            margin-bottom: 0.5rem;
        }

        /* ── Report row ── */
        .report-row {
            background: #fff;
            border: 1px solid #e0ddd5;
            border-radius: 8px;
            padding: 0.875rem 1rem;
            margin-bottom: 0.5rem;
            display: grid;
            grid-template-columns: 12px 1fr auto;
            gap: 0.75rem;
            align-items: start;
        }
        .report-row.expired { opacity: 0.55; }
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-top: 4px;
            flex-shrink: 0;
        }
        .report-body { min-width: 0; }
        .report-segment { font-weight: 600; font-size: 0.9375rem; }
        .report-meta {
            font-size: 0.8125rem;
            color: #6b6660;
            margin-top: 0.25rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem 1rem;
        }
        .report-notes {
            font-size: 0.875rem;
            color: #2a2622;
            margin-top: 0.375rem;
            font-style: italic;
        }
        .expired-tag {
            font-size: 0.75rem;
            background: #f3f4f6;
            color: #9ca3af;
            padding: 0.1rem 0.5rem;
            border-radius: 4px;
        }

        /* ── Report actions ── */
        .report-actions { display: flex; gap: 0.5rem; align-items: flex-start; flex-shrink: 0; }
        .status-select {
            padding: 0.375rem 0.5rem;
            border: 1px solid #e0ddd5;
            border-radius: 6px;
            font-size: 0.8125rem;
            background: #fff;
            cursor: pointer;
        }
        .btn-update {
            padding: 0.375rem 0.75rem;
            background: #d97706;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 0.8125rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-update:hover { background: #b45309; }
        .btn-delete {
            padding: 0.375rem 0.625rem;
            background: transparent;
            color: #dc2626;
            border: 1px solid #fca5a5;
            border-radius: 6px;
            font-size: 0.8125rem;
            cursor: pointer;
        }
        .btn-delete:hover { background: #fee2e2; }

        /* ── IP Lists ── */
        .ip-section { margin-bottom: 2rem; }
        .ip-section h3 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e0ddd5;
        }
        .ip-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .ip-table th {
            text-align: left;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b6660;
            padding: 0.5rem 0.75rem;
            background: #f8f6f2;
            border-bottom: 1px solid #e0ddd5;
        }
        .ip-table td {
            padding: 0.625rem 0.75rem;
            border-bottom: 1px solid #f0ede8;
            vertical-align: middle;
        }
        .ip-table tr:last-child td { border-bottom: none; }
        .ip-table-wrap {
            border: 1px solid #e0ddd5;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
            margin-bottom: 1rem;
        }
        .empty-ip { padding: 1.25rem; color: #9ca3af; font-size: 0.875rem; text-align: center; }
        .btn-remove {
            padding: 0.25rem 0.625rem;
            color: #dc2626;
            background: transparent;
            border: 1px solid #fca5a5;
            border-radius: 5px;
            font-size: 0.8125rem;
            cursor: pointer;
        }
        .btn-remove:hover { background: #fee2e2; }

        /* ── Add IP form ── */
        .add-ip-form {
            background: #fff;
            border: 1px solid #e0ddd5;
            border-radius: 8px;
            padding: 1.25rem;
        }
        .add-ip-form h3 { font-size: 1rem; font-weight: 700; margin-bottom: 1rem; }
        .form-row { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; }
        .form-field { display: flex; flex-direction: column; gap: 0.375rem; }
        .form-field label { font-size: 0.8125rem; font-weight: 600; }
        .form-field input, .form-field select {
            padding: 0.5rem 0.75rem;
            border: 1px solid #e0ddd5;
            border-radius: 6px;
            font-size: 0.875rem;
        }
        .form-field input:focus, .form-field select:focus {
            outline: none;
            border-color: #d97706;
        }
        .btn-add {
            padding: 0.5rem 1.25rem;
            background: #2a2622;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            align-self: flex-end;
        }
        .btn-add:hover { background: #d97706; }

        /* ── Rebuild stats panel ── */
        .rebuild-stats-panel {
            background: #fff;
            border: 1px solid #e0ddd5;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.25rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1.25rem 2.5rem;
        }
        .rebuild-stat {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }
        .rebuild-stat-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #9ca3af;
        }
        .rebuild-stat-value {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #2a2622;
        }
        .rebuild-stat-sub {
            font-size: 0.75rem;
            color: #6b6660;
        }

        /* ── Merge issues table ── */
        .merge-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .merge-table th {
            text-align: left;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6b6660;
            padding: 0.5rem 0.75rem;
            background: #f8f6f2;
            border-bottom: 1px solid #e0ddd5;
        }
        .merge-table td {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid #f0ede8;
            vertical-align: top;
            word-break: break-word;
        }
        .merge-table tr:last-child td { border-bottom: none; }
        .merge-table-wrap {
            border: 1px solid #e0ddd5;
            border-radius: 8px;
            overflow: hidden;
            background: #fff;
        }
        .merge-issues-note {
            font-size: 0.8125rem;
            color: #6b6660;
            margin-top: 0.75rem;
        }

        /* ── Misc ── */
        .empty-state { text-align: center; padding: 3rem 1rem; color: #9ca3af; }
        .whitelist-badge { color: #059669; font-weight: 600; }
        .blacklist-badge { color: #dc2626; font-weight: 600; }

        @media (max-width: 600px) {
            .admin-header { padding: 0.75rem 1rem; }
            .content { padding: 1rem; }
            .report-row { grid-template-columns: 12px 1fr; }
            .report-actions { grid-column: 2; flex-wrap: wrap; }
            .tabs { padding: 0 1rem; }
        }
    </style>
</head>
<body>

<header class="admin-header">
    <div>
        <div class="admin-header-brand"><span>StormPath</span> Admin</div>
        <div class="admin-header-sub"><?= h($county_name) ?><?= $county_state ? ', ' . h($county_state) : '' ?></div>
    </div>
    <div class="admin-header-actions">
        <span style="font-size:0.8125rem;color:#9ca3af"><?= h($currentUser['username']) ?></span>
        <a class="btn-back" href="/">← Map</a>
        <a class="btn-logout" href="/auth/logout.php" style="text-decoration:none">Sign out</a>
    </div>
</header>

<nav class="tabs">
    <a class="tab <?= $active_tab === 'reports' ? 'active' : '' ?>" href="admin.php?tab=reports">
        Reports
    </a>
    <a class="tab <?= $active_tab === 'ip' ? 'active' : '' ?>" href="admin.php?tab=ip">
        IP Access Lists
    </a>
    <a class="tab <?= $active_tab === 'merge_issues' ? 'active' : '' ?>" href="admin.php?tab=merge_issues">
        Merge Issues<?php if (count($merge_issues) > 0): ?> <span class="count-badge"><?= count($merge_issues) ?></span><?php endif; ?>
    </a>
    <a class="tab <?= $active_tab === 'users' ? 'active' : '' ?>" href="admin-users.php">
        Users
    </a>
</nav>

<div class="content">

<?php if ($active_tab === 'reports'): ?>

    <?php if (!empty($rebuild_meta)): ?>
    <div class="rebuild-stats-panel">
        <?php if (!empty($rebuild_meta['last_rebuild'])): ?>
        <div class="rebuild-stat">
            <span class="rebuild-stat-label">Last rebuild</span>
            <span class="rebuild-stat-value"><?= h(date('M j, Y', strtotime($rebuild_meta['last_rebuild']))) ?></span>
            <span class="rebuild-stat-sub"><?= h(date('g:i A', strtotime($rebuild_meta['last_rebuild']))) ?> UTC</span>
        </div>
        <?php endif; ?>
        <?php if (isset($rebuild_meta['road_count'])): ?>
        <div class="rebuild-stat">
            <span class="rebuild-stat-label">Roads</span>
            <span class="rebuild-stat-value"><?= h(number_format((int)$rebuild_meta['road_count'])) ?></span>
        </div>
        <?php endif; ?>
        <?php if (isset($rebuild_meta['merge_issues_count'])): ?>
        <div class="rebuild-stat">
            <span class="rebuild-stat-label">Merge issues</span>
            <span class="rebuild-stat-value">
                <?php if ((int)$rebuild_meta['merge_issues_count'] > 0): ?>
                    <a href="admin.php?tab=merge_issues" style="color:#d97706"><?= h($rebuild_meta['merge_issues_count']) ?></a>
                <?php else: ?>
                    0
                <?php endif; ?>
            </span>
        </div>
        <?php endif; ?>
        <?php if (!empty($rebuild_meta['osm_timestamp'])): ?>
        <div class="rebuild-stat">
            <span class="rebuild-stat-label">OSM data as of</span>
            <span class="rebuild-stat-value"><?= h(date('M j, Y', strtotime($rebuild_meta['osm_timestamp']))) ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="section-heading">
        Reports
        <span class="count-badge"><?= count($reports) ?> (past 30 days)</span>
    </div>

    <?php if (empty($grouped)): ?>
        <div class="empty-state">No reports in the last 30 days.</div>
    <?php else: ?>
        <?php foreach ($grouped as $road_name => $road_reports): ?>
            <div class="road-group">
                <div class="road-group-header"><?= h($road_name ?: 'Unnamed Road') ?></div>
                <?php foreach ($road_reports as $r): ?>
                    <?php
                        $status  = $r['status'] ?? 'unknown';
                        $color   = $status_colors[$status]  ?? '#9ca3af';
                        $label   = $status_labels[$status]  ?? ucfirst($status);
                        $active  = is_active($r['timestamp'] ?? '');
                        $ts_disp = $r['timestamp'] ? date('M j, Y g:i A', strtotime($r['timestamp'])) : '—';
                    ?>
                    <div class="report-row <?= $active ? '' : 'expired' ?>">
                        <div class="status-dot" style="background:<?= h($color) ?>" title="<?= h($label) ?>"></div>
                        <div class="report-body">
                            <div class="report-segment">
                                <?= h($label) ?>
                                <?php if (!$active): ?><span class="expired-tag">expired</span><?php endif; ?>
                            </div>
                            <?php if (!empty($r['segment_description']) && $r['segment_description'] !== 'All visible segments'): ?>
                                <div class="report-meta"><?= h($r['segment_description']) ?></div>
                            <?php endif; ?>
                            <div class="report-meta">
                                <span><?= h($ts_disp) ?></span>
                                <?php if (!empty($r['ip'])): ?>
                                    <span>IP: <?= h($r['ip']) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($r['notes'])): ?>
                                <div class="report-notes"><?= h($r['notes']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="report-actions">
                            <form method="post" style="display:flex;gap:0.375rem;align-items:center">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="report_id" value="<?= h($r['id']) ?>">
                                <select name="status" class="status-select">
                                    <?php foreach ($status_labels as $val => $lbl): ?>
                                        <option value="<?= h($val) ?>" <?= $val === $status ? 'selected' : '' ?>>
                                            <?= h($lbl) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn-update">Update</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Delete this report?')">
                                <input type="hidden" name="action" value="delete_report">
                                <input type="hidden" name="report_id" value="<?= h($r['id']) ?>">
                                <button type="submit" class="btn-delete">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php elseif ($active_tab === 'merge_issues'): ?>

    <div class="section-heading">
        Merge Issues
        <?php if (count($merge_issues) > 0): ?>
            <span class="count-badge"><?= count($merge_issues) ?></span>
        <?php endif; ?>
    </div>

    <?php if (empty($merge_issues)): ?>
        <div class="empty-state">No merge issues from the last rebuild.</div>
    <?php else: ?>
        <div class="merge-table-wrap">
            <table class="merge-table">
                <thead>
                    <tr>
                        <?php foreach (array_keys($merge_issues[0]) as $col): ?>
                            <th><?= h(ucwords(str_replace('_', ' ', $col))) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($merge_issues as $row): ?>
                        <tr>
                            <?php foreach ($row as $val): ?>
                                <td><?= h($val) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="merge-issues-note">
            Merge issues occur when two or more disconnected segments share a road name but could
            not be joined into a single geometry. Each entry above is treated as a separate road.
            They are usually caused by missing OSM nodes at intersections.
        </p>
    <?php endif; ?>

<?php elseif ($active_tab === 'ip'): ?>

    <div class="section-heading">IP Access Lists</div>

    <div class="ip-section">
        <h3>Whitelist <span style="font-weight:400;color:#6b6660">(always allowed, bypasses rate limits)</span></h3>
        <div class="ip-table-wrap">
            <?php if (empty($whitelist)): ?>
                <div class="empty-ip">No whitelist entries.</div>
            <?php else: ?>
                <table class="ip-table">
                    <thead>
                        <tr><th>IP Address</th><th>Note</th><th>Added</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($whitelist as $row): ?>
                            <tr>
                                <td><strong><?= h($row['ip']) ?></strong></td>
                                <td><?= h($row['note'] ?? '') ?></td>
                                <td><?= $row['created_at'] ? h(date('M j, Y', strtotime($row['created_at']))) : '—' ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Remove <?= h($row['ip']) ?> from whitelist?')">
                                        <input type="hidden" name="action" value="remove_ip">
                                        <input type="hidden" name="ip" value="<?= h($row['ip']) ?>">
                                        <button class="btn-remove">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="ip-section">
        <h3>Blacklist <span style="font-weight:400;color:#6b6660">(blocked from submitting reports)</span></h3>
        <div class="ip-table-wrap">
            <?php if (empty($blacklist)): ?>
                <div class="empty-ip">No blacklist entries.</div>
            <?php else: ?>
                <table class="ip-table">
                    <thead>
                        <tr><th>IP Address</th><th>Note</th><th>Added</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blacklist as $row): ?>
                            <tr>
                                <td><strong><?= h($row['ip']) ?></strong></td>
                                <td><?= h($row['note'] ?? '') ?></td>
                                <td><?= $row['created_at'] ? h(date('M j, Y', strtotime($row['created_at']))) : '—' ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Remove <?= h($row['ip']) ?> from blacklist?')">
                                        <input type="hidden" name="action" value="remove_ip">
                                        <input type="hidden" name="ip" value="<?= h($row['ip']) ?>">
                                        <button class="btn-remove">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="add-ip-form">
        <h3>Add IP Address</h3>
        <form method="post">
            <input type="hidden" name="action" value="add_ip">
            <div class="form-row">
                <div class="form-field" style="flex:1;min-width:160px">
                    <label for="ip_address">IP Address</label>
                    <input type="text" id="ip_address" name="ip_address" placeholder="e.g. 192.168.1.100" required>
                </div>
                <div class="form-field">
                    <label for="list_type">List</label>
                    <select id="list_type" name="list_type">
                        <option value="whitelist">Whitelist</option>
                        <option value="blacklist">Blacklist</option>
                    </select>
                </div>
                <div class="form-field" style="flex:2;min-width:180px">
                    <label for="note">Note (optional)</label>
                    <input type="text" id="note" name="note" placeholder="e.g. Morgan County EMA">
                </div>
                <button type="submit" class="btn-add">Add</button>
            </div>
        </form>
    </div>

<?php endif; ?>

</div>
</body>
</html>
