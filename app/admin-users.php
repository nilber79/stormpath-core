<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth/auth.php';

$currentUser = requireRole('admin');
$db          = getDb();

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

// ── Actions ───────────────────────────────────────────────────────────────

$action = $_POST['action'] ?? '';

if ($action === 'approve') {
    $id = (int)($_POST['user_id'] ?? 0);
    if ($id && $id !== (int)$currentUser['id']) {
        $db->prepare("UPDATE users SET status = 'active' WHERE id = ?")->execute([$id]);
    }
    header('Location: admin-users.php');
    exit;
}

if ($action === 'bulk_approve') {
    $ids = array_map('intval', (array)($_POST['user_ids'] ?? []));
    foreach ($ids as $id) {
        if ($id && $id !== (int)$currentUser['id']) {
            $db->prepare("UPDATE users SET status = 'active' WHERE id = ? AND status = 'pending'")
               ->execute([$id]);
        }
    }
    header('Location: admin-users.php');
    exit;
}

if ($action === 'deactivate') {
    $id = (int)($_POST['user_id'] ?? 0);
    if ($id && $id !== (int)$currentUser['id']) {
        $db->prepare("UPDATE users SET status = 'pending' WHERE id = ?")->execute([$id]);
    }
    header('Location: admin-users.php');
    exit;
}

if ($action === 'set_role') {
    $id   = (int)($_POST['user_id'] ?? 0);
    $role = $_POST['role'] ?? '';
    if ($id && $id !== (int)$currentUser['id'] && in_array($role, ['user', 'first_responder', 'admin'], true)) {
        $db->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
    }
    header('Location: admin-users.php');
    exit;
}

if ($action === 'delete') {
    $id = (int)($_POST['user_id'] ?? 0);
    if ($id && $id !== (int)$currentUser['id']) {
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    }
    header('Location: admin-users.php');
    exit;
}

// ── Data ──────────────────────────────────────────────────────────────────

$users = $db->query("
    SELECT u.*, COUNT(p.id) AS passkey_count
    FROM users u
    LEFT JOIN passkeys p ON p.user_id = u.id
    GROUP BY u.id
    ORDER BY u.status DESC, u.created_at ASC
")->fetchAll();

$pending = array_filter($users, fn($u) => $u['status'] === 'pending');

$roleLabels = ['admin' => 'Admin', 'first_responder' => 'First Responder', 'user' => 'User'];
$frRoleLabels = [
    'fire'  => 'Firefighter',
    'ems'   => 'EMS / Paramedic',
    'law'   => 'Law Enforcement',
    'em'    => 'Emergency Mgmt',
    'other' => 'First Responder',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Users — <?= h($county_name) ?> | StormPath</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'DM Sans', system-ui, sans-serif; background: #f8f6f2; color: #2a2622; font-size: 0.9375rem; }

        .admin-header {
            background: #2a2622; color: #f8f6f2;
            padding: 0.875rem 2rem;
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 0.5rem;
        }
        .admin-header-brand { font-size: 1.1rem; font-weight: 700; }
        .admin-header-brand span { color: #d97706; }
        .admin-header-sub { font-size: 0.8125rem; color: #9ca3af; margin-top: 0.125rem; }
        .admin-header-actions { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .btn-back, .btn-logout {
            background: transparent; border: 1px solid rgba(255,255,255,.3);
            color: #f8f6f2; padding: 0.4rem 1rem; border-radius: 6px;
            font-size: 0.8125rem; text-decoration: none; cursor: pointer;
        }
        .btn-back:hover, .btn-logout:hover { background: rgba(255,255,255,.1); color: #f8f6f2; }

        .tabs { display: flex; background: #fff; border-bottom: 1px solid #e0ddd5; padding: 0 2rem; overflow-x: auto; }
        .tab {
            padding: 0.875rem 1.25rem; font-weight: 600; font-size: 0.875rem;
            color: #6b6660; text-decoration: none; white-space: nowrap;
            border-bottom: 2px solid transparent; margin-bottom: -1px;
        }
        .tab.active { color: #d97706; border-bottom-color: #d97706; }
        .tab:hover:not(.active) { color: #2a2622; }

        .content { max-width: 1100px; margin: 0 auto; padding: 1.5rem 2rem; }

        .section-heading {
            font-size: 1.125rem; font-weight: 700; margin-bottom: 1rem;
            display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
        }
        .count-badge {
            background: #e0ddd5; color: #6b6660; font-size: 0.75rem;
            font-weight: 600; padding: 0.125rem 0.625rem; border-radius: 999px;
        }
        .pending-badge {
            background: #fef3c7; color: #92400e; font-size: 0.75rem;
            font-weight: 700; padding: 0.125rem 0.625rem; border-radius: 999px;
        }

        /* ── Bulk action bar ────────────────────────────────────────────── */
        .bulk-bar {
            display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .bulk-bar label { font-size: 0.875rem; color: #6b6660; cursor: pointer; display: flex; align-items: center; gap: 0.375rem; }

        /* ── Desktop table ──────────────────────────────────────────────── */
        .table-wrap { border: 1px solid #e0ddd5; border-radius: 8px; overflow: hidden; background: #fff; margin-bottom: 2rem; }
        .users-table { width: 100%; border-collapse: collapse; }
        .users-table th {
            text-align: left; font-size: 0.75rem; text-transform: uppercase;
            letter-spacing: 0.05em; color: #6b6660;
            padding: 0.5rem 0.875rem; background: #f8f6f2;
            border-bottom: 1px solid #e0ddd5;
        }
        .users-table td {
            padding: 0.75rem 0.875rem; border-bottom: 1px solid #f0ede8;
            vertical-align: middle;
        }
        .users-table tr:last-child td { border-bottom: none; }

        /* ── Mobile cards ───────────────────────────────────────────────── */
        .user-cards { display: none; flex-direction: column; gap: 0.75rem; margin-bottom: 2rem; }
        .user-card {
            background: #fff; border: 1px solid #e0ddd5; border-radius: 8px;
            padding: 1rem;
        }
        .user-card-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem; margin-bottom: 0.625rem; }
        .user-card-name { font-weight: 700; font-size: 0.9375rem; }
        .user-card-meta { font-size: 0.75rem; color: #9ca3af; margin-top: 0.125rem; }
        .user-card-badges { display: flex; flex-wrap: wrap; gap: 0.375rem; margin-bottom: 0.75rem; }
        .user-card-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; }
        .card-role-form { display: flex; gap: 0.375rem; align-items: center; width: 100%; margin-top: 0.5rem; }
        .card-role-form select { flex: 1; }

        /* ── Shared badges & statuses ───────────────────────────────────── */
        .status-active  { color: #059669; font-weight: 600; font-size: 0.8125rem; }
        .status-pending { color: #d97706; font-weight: 600; font-size: 0.8125rem; }

        .badge {
            display: inline-flex; align-items: center; gap: 0.25rem;
            font-size: 0.75rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 4px;
        }
        .badge-fr       { background: #dbeafe; color: #1e40af; }
        .badge-idme     { background: #d1fae5; color: #065f46; }
        .badge-unverified { background: #fef3c7; color: #92400e; }
        .self-tag { font-size: 0.75rem; background: #e0ddd5; color: #6b6660; padding: 0.125rem 0.5rem; border-radius: 4px; }

        /* FR claim detail block */
        .fr-detail {
            font-size: 0.75rem; color: #6b6660; margin-top: 0.25rem;
            line-height: 1.4;
        }

        /* ── Buttons ────────────────────────────────────────────────────── */
        .role-select {
            padding: 0.3rem 0.5rem; border: 1px solid #e0ddd5; border-radius: 6px;
            font-size: 0.8125rem; background: #fff; cursor: pointer;
        }
        .actions-cell { display: flex; gap: 0.375rem; align-items: center; flex-wrap: wrap; }
        .btn-sm {
            padding: 0.3rem 0.75rem; border: none; border-radius: 5px;
            font-size: 0.8125rem; font-weight: 600; cursor: pointer; white-space: nowrap;
        }
        .btn-approve    { background: #d97706; color: #fff; }
        .btn-approve:hover    { background: #b45309; }
        .btn-deactivate { background: transparent; color: #6b6660; border: 1px solid #e0ddd5; }
        .btn-deactivate:hover { background: #f0ede8; }
        .btn-delete     { background: transparent; color: #dc2626; border: 1px solid #fca5a5; }
        .btn-delete:hover     { background: #fee2e2; }
        .btn-role       { background: #2a2622; color: #fff; }
        .btn-role:hover { background: #d97706; }
        .btn-bulk       { background: #059669; color: #fff; }
        .btn-bulk:hover { background: #047857; }

        .empty-state { text-align: center; padding: 2rem; color: #9ca3af; }

        /* ── Responsive breakpoint ──────────────────────────────────────── */
        @media (max-width: 700px) {
            .admin-header { padding: 0.75rem 1rem; }
            .content { padding: 1rem; }
            .tabs { padding: 0 1rem; }
            .table-wrap { display: none; }
            .user-cards { display: flex; }
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
        <a class="btn-logout" href="/auth/logout.php">Sign out</a>
    </div>
</header>

<nav class="tabs">
    <a class="tab" href="admin.php?tab=reports">Reports</a>
    <a class="tab" href="admin.php?tab=ip">IP Access Lists</a>
    <a class="tab" href="admin.php?tab=merge_issues">Merge Issues</a>
    <a class="tab active" href="admin-users.php">Users</a>
</nav>

<div class="content">

    <div class="section-heading">
        Users
        <span class="count-badge"><?= count($users) ?></span>
        <?php if (count($pending) > 0): ?>
            <span class="pending-badge"><?= count($pending) ?> pending</span>
        <?php endif; ?>
    </div>

    <?php if (empty($users)): ?>
        <div class="empty-state">No users yet.</div>
    <?php else: ?>

    <?php
    // Helper: render FR claim badges + detail for a user row
    function renderFrInfo(array $u): string {
        if (!$u['fr_claim']) return '';
        $out = '';
        if ($u['fr_idme_verified']) {
            $out .= '<span class="badge badge-idme">✓ ID.me Verified</span> ';
        } else {
            $out .= '<span class="badge badge-unverified">⚠ Needs Review</span> ';
        }
        $out .= '<span class="badge badge-fr">' . h($GLOBALS['frRoleLabels'][$u['fr_role'] ?? ''] ?? 'First Responder') . '</span>';
        $detail = [];
        if ($u['fr_agency'])     $detail[] = h($u['fr_agency']);
        if ($u['fr_identifier']) $detail[] = 'ID: ' . h($u['fr_identifier']);
        if ($detail) {
            $out .= '<div class="fr-detail">' . implode(' · ', $detail) . '</div>';
        }
        return $out;
    }
    ?>

    <!-- Bulk approve form wraps both table and cards -->
    <form method="post" id="bulk-form">
        <input type="hidden" name="action" value="bulk_approve">

        <!-- Bulk action bar (shown only when pending users exist) -->
        <?php if (count($pending) > 0): ?>
        <div class="bulk-bar">
            <label>
                <input type="checkbox" id="select-all-pending">
                Select all pending
            </label>
            <button type="submit" class="btn-sm btn-bulk">✓ Approve Selected</button>
        </div>
        <?php endif; ?>

        <!-- ── Desktop table ── -->
        <div class="table-wrap">
            <table class="users-table">
                <thead>
                    <tr>
                        <?php if (count($pending) > 0): ?><th style="width:2rem"></th><?php endif; ?>
                        <th>Username</th>
                        <th>Status</th>
                        <th>Role</th>
                        <th>Joined</th>
                        <th>Auth</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php $isSelf = ((int)$u['id'] === (int)$currentUser['id']); ?>
                        <tr>
                            <?php if (count($pending) > 0): ?>
                            <td>
                                <?php if ($u['status'] === 'pending' && !$isSelf): ?>
                                    <input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>" class="pending-cb">
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td>
                                <strong><?= h($u['username']) ?></strong>
                                <?php if ($isSelf): ?><span class="self-tag">you</span><?php endif; ?>
                                <?php if ($u['display_name'] && $u['display_name'] !== $u['username']): ?>
                                    <div style="font-size:0.75rem;color:#9ca3af"><?= h($u['display_name']) ?></div>
                                <?php endif; ?>
                                <?php if ($u['email']): ?>
                                    <div style="font-size:0.75rem;color:#9ca3af"><?= h($u['email']) ?></div>
                                <?php endif; ?>
                                <?php if ($u['fr_claim']): ?>
                                    <div style="margin-top:0.3rem"><?= renderFrInfo($u) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-<?= h($u['status']) ?>">
                                    <?= $u['status'] === 'active' ? 'Active' : 'Pending' ?>
                                </span>
                            </td>
                            <td><?= h($roleLabels[$u['role']] ?? $u['role']) ?></td>
                            <td style="color:#9ca3af;font-size:0.8125rem">
                                <?= h(date('M j, Y', strtotime($u['created_at']))) ?>
                            </td>
                            <td style="font-size:0.8125rem;color:#6b6660">
                                <?= $u['passkey_count'] > 0 ? $u['passkey_count'] . ' passkey' . ($u['passkey_count'] > 1 ? 's' : '') : '' ?>
                                <?php if ($u['totp_enabled']): ?><?= $u['passkey_count'] > 0 ? ' · ' : '' ?>TOTP<?php endif; ?>
                                <?php if (!$u['passkey_count'] && !$u['totp_enabled']): ?>Password only<?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isSelf): ?>
                                    <span style="font-size:0.8125rem;color:#9ca3af">—</span>
                                <?php else: ?>
                                    <div class="actions-cell">
                                        <?php if ($u['status'] === 'pending'): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="btn-sm btn-approve">Approve</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="deactivate">
                                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                                <button type="submit" class="btn-sm btn-deactivate">Deactivate</button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="post" style="display:flex;gap:0.25rem;align-items:center">
                                            <input type="hidden" name="action" value="set_role">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <select name="role" class="role-select">
                                                <?php foreach ($roleLabels as $val => $lbl): ?>
                                                    <option value="<?= h($val) ?>" <?= $val === $u['role'] ? 'selected' : '' ?>>
                                                        <?= h($lbl) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-sm btn-role">Set</button>
                                        </form>

                                        <form method="post" onsubmit="return confirm('Delete <?= h(addslashes($u['username'])) ?>? This cannot be undone.')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button type="submit" class="btn-sm btn-delete">Delete</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- ── Mobile cards ── -->
        <div class="user-cards">
            <?php foreach ($users as $u): ?>
                <?php $isSelf = ((int)$u['id'] === (int)$currentUser['id']); ?>
                <div class="user-card">
                    <div class="user-card-header">
                        <div>
                            <div class="user-card-name">
                                <?= h($u['username']) ?>
                                <?php if ($isSelf): ?><span class="self-tag">you</span><?php endif; ?>
                            </div>
                            <?php if ($u['display_name'] && $u['display_name'] !== $u['username']): ?>
                                <div class="user-card-meta"><?= h($u['display_name']) ?></div>
                            <?php endif; ?>
                            <?php if ($u['email']): ?>
                                <div class="user-card-meta"><?= h($u['email']) ?></div>
                            <?php endif; ?>
                            <div class="user-card-meta">
                                Joined <?= h(date('M j, Y', strtotime($u['created_at']))) ?>
                            </div>
                        </div>
                        <?php if ($u['status'] === 'pending' && !$isSelf && count($pending) > 0): ?>
                            <input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>" class="pending-cb" style="margin-top:0.25rem;width:1.1rem;height:1.1rem;accent-color:#d97706">
                        <?php endif; ?>
                    </div>

                    <div class="user-card-badges">
                        <span class="status-<?= h($u['status']) ?>">
                            <?= $u['status'] === 'active' ? '● Active' : '● Pending' ?>
                        </span>
                        <span style="font-size:0.8125rem;color:#6b6660">
                            <?= h($roleLabels[$u['role']] ?? $u['role']) ?>
                        </span>
                        <?php if ($u['passkey_count'] > 0): ?>
                            <span style="font-size:0.75rem;color:#6b6660">
                                <?= $u['passkey_count'] ?> passkey<?= $u['passkey_count'] > 1 ? 's' : '' ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($u['totp_enabled']): ?>
                            <span style="font-size:0.75rem;color:#6b6660">TOTP</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($u['fr_claim']): ?>
                        <div style="margin-bottom:0.75rem"><?= renderFrInfo($u) ?></div>
                    <?php endif; ?>

                    <?php if (!$isSelf): ?>
                    <div class="user-card-actions">
                        <?php if ($u['status'] === 'pending'): ?>
                            <form method="post">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn-sm btn-approve">Approve</button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <input type="hidden" name="action" value="deactivate">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <button type="submit" class="btn-sm btn-deactivate">Deactivate</button>
                            </form>
                        <?php endif; ?>

                        <form method="post" onsubmit="return confirm('Delete <?= h(addslashes($u['username'])) ?>? This cannot be undone.')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn-sm btn-delete">Delete</button>
                        </form>

                        <form method="post" class="card-role-form">
                            <input type="hidden" name="action" value="set_role">
                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <select name="role" class="role-select">
                                <?php foreach ($roleLabels as $val => $lbl): ?>
                                    <option value="<?= h($val) ?>" <?= $val === $u['role'] ? 'selected' : '' ?>>
                                        <?= h($lbl) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn-sm btn-role">Set Role</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    </form><!-- end bulk-form -->

    <?php endif; ?>

</div>

<script>
// "Select all pending" checkbox toggles all .pending-cb checkboxes
(function () {
    const selectAll = document.getElementById('select-all-pending');
    if (!selectAll) return;
    selectAll.addEventListener('change', () => {
        document.querySelectorAll('.pending-cb').forEach(cb => {
            cb.checked = selectAll.checked;
        });
    });
    // Uncheck master if any individual box is unchecked
    document.querySelectorAll('.pending-cb').forEach(cb => {
        cb.addEventListener('change', () => {
            if (!cb.checked) selectAll.checked = false;
        });
    });
})();
</script>
</body>
</html>
