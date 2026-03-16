<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

// Already logged in
if (getCurrentUser()) {
    header('Location: /');
    exit;
}

$error   = '';
$success = false;
$newUserId = null;

// ID.me configured?
$idmeClientId = getenv('IDME_CLIENT_ID') ?: '';

$frRoleLabels = [
    'fire'  => 'Firefighter / Fire Department',
    'ems'   => 'EMS / Paramedic / Medical',
    'law'   => 'Law Enforcement',
    'em'    => 'Emergency Management / EOC',
    'other' => 'Other',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username     = trim($_POST['username']     ?? '');
    $password     = $_POST['password']          ?? '';
    $password2    = $_POST['password2']         ?? '';
    $display_name = trim($_POST['display_name'] ?? '');
    $email        = trim($_POST['email']        ?? '');

    // FR claim fields
    $fr_claim      = !empty($_POST['fr_claim']);
    $fr_agency     = mb_substr(trim($_POST['fr_agency']     ?? ''), 0, 200);
    $fr_role       = $_POST['fr_role'] ?? '';
    $fr_identifier = mb_substr(trim($_POST['fr_identifier'] ?? ''), 0, 100);

    if (!array_key_exists($fr_role, $frRoleLabels)) $fr_role = '';

    // Validate
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
        $error = 'Username must be 3–32 characters: letters, numbers, underscores only.';
    } elseif (strlen($password) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif ($fr_claim && !$fr_agency) {
        $error = 'Please provide your agency or department name.';
    } elseif ($fr_claim && !$fr_role) {
        $error = 'Please select your first responder role.';
    } else {
        $db = getDb();
        $existing = $db->prepare("SELECT id FROM users WHERE username = ?");
        $existing->execute([$username]);
        if ($existing->fetch()) {
            $error = 'That username is already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->prepare(
                "INSERT INTO users
                    (username, email, display_name, password_hash, role, status,
                     fr_claim, fr_agency, fr_role, fr_identifier)
                 VALUES (?, ?, ?, ?, 'user', 'pending', ?, ?, ?, ?)"
            )->execute([
                $username,
                $email ?: null,
                $display_name ?: $username,
                $hash,
                $fr_claim ? 1 : 0,
                $fr_claim ? $fr_agency : null,
                $fr_claim ? $fr_role   : null,
                ($fr_claim && $fr_identifier) ? $fr_identifier : null,
            ]);

            $newUserId = (int)$db->lastInsertId();

            // Store the new user ID in session so the ID.me callback can
            // look it up without requiring the user to be logged in yet.
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SESSION['sp_idme_verify_uid'] = $newUserId;

            $success = true;
        }
    }
}

$area_cfg    = [];
$cfg_file    = __DIR__ . '/../area-config.json';
if (file_exists($cfg_file)) {
    $area_cfg = json_decode(file_get_contents($cfg_file), true) ?? [];
}
$county_name = $area_cfg['area_name'] ?? 'StormPath';

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Account — StormPath</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: #f8f6f2;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem 1rem;
        }
        .card {
            background: #fff;
            border: 1px solid #e0ddd5;
            border-radius: 12px;
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 4px 24px rgba(0,0,0,.07);
        }
        .logo { font-size: 1.5rem; font-weight: 700; color: #d97706; margin-bottom: 0.25rem; }
        .sub  { font-size: 0.875rem; color: #6b6660; margin-bottom: 2rem; }
        .error {
            background: #fee2e2; color: #dc2626;
            border-radius: 8px; padding: 0.625rem 0.875rem;
            font-size: 0.875rem; margin-bottom: 1rem;
        }
        .success-box {
            background: #d1fae5; color: #065f46;
            border-radius: 8px; padding: 1rem 1.25rem;
            font-size: 0.9375rem; margin-bottom: 1rem; line-height: 1.5;
        }
        .field { margin-bottom: 1rem; }
        label { display: block; font-size: 0.8125rem; font-weight: 600; color: #2a2622; margin-bottom: 0.375rem; }
        .field-hint { font-size: 0.75rem; color: #6b6660; margin-top: 0.25rem; }
        input[type=text], input[type=password], input[type=email], select {
            width: 100%; padding: 0.625rem 0.875rem;
            border: 1px solid #e0ddd5; border-radius: 8px;
            font-size: 1rem; outline: none;
            transition: border-color .15s;
            background: #fff;
        }
        input:focus, select:focus { border-color: #d97706; }

        /* FR checkbox row */
        .fr-check-label {
            display: flex; align-items: flex-start; gap: 0.625rem;
            font-size: 0.9375rem; font-weight: 600; cursor: pointer;
        }
        .fr-check-label input[type=checkbox] {
            width: auto; margin-top: 0.2rem; accent-color: #d97706;
        }

        /* FR expanded fields panel */
        .fr-panel {
            margin-top: 0.75rem;
            padding: 1rem;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
        }
        .fr-panel-note {
            font-size: 0.8125rem; color: #92400e; margin-bottom: 0.875rem; line-height: 1.45;
        }

        .btn {
            width: 100%; padding: 0.75rem; border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem;
            text-decoration: none; text-align: center; display: block;
        }
        .btn-primary { background: #d97706; color: #fff; }
        .btn-primary:hover { background: #b45309; }
        .btn-idme {
            background: #1a56db; color: #fff;
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            margin-top: 0.75rem;
        }
        .btn-idme:hover { background: #1e429f; }
        .btn-idme svg { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }
        .btn-skip {
            background: transparent; border: 1px solid #e0ddd5; color: #6b6660;
            margin-top: 0.5rem;
        }
        .btn-skip:hover { background: #f0ede8; color: #2a2622; }

        .footer-links {
            margin-top: 1.5rem; font-size: 0.8125rem; color: #6b6660; text-align: center;
        }
        .footer-links a { color: #d97706; text-decoration: none; }
        .footer-links a:hover { text-decoration: underline; }

        .idme-section { margin-top: 1.25rem; }
        .idme-divider {
            text-align: center; font-size: 0.8125rem; color: #9ca3af;
            margin: 0.75rem 0; position: relative;
        }
        .idme-divider::before, .idme-divider::after {
            content: ''; position: absolute; top: 50%; width: 40%; height: 1px;
            background: #e0ddd5;
        }
        .idme-divider::before { left: 0; }
        .idme-divider::after  { right: 0; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">StormPath</div>
    <div class="sub">Create Account — <?= h($county_name) ?></div>

    <?php if ($success): ?>
        <div class="success-box">
            <strong>Registration submitted!</strong><br>
            Your account is pending admin approval.
            <?php if ($fr_claim): ?>
                Your first responder claim will be reviewed before that role is assigned.
            <?php else: ?>
                You'll be able to sign in once an admin activates it.
            <?php endif; ?>
        </div>

        <?php if ($fr_claim && $idmeClientId): ?>
            <p style="font-size:0.875rem;color:#2a2622;margin-bottom:0.5rem;line-height:1.5">
                <strong>Speed up verification:</strong> Confirm your first responder status
                through ID.me and your role will be approved automatically.
            </p>
            <a href="/auth/idme-start.php" class="btn btn-idme">
                <!-- ID.me shield icon -->
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.25C17.25 22.15 21 17.25 21 12V7L12 2z"/>
                </svg>
                Verify with ID.me
            </a>
            <div class="idme-divider">or</div>
            <a href="/auth/login.php" class="btn btn-skip">Skip — wait for admin review</a>
        <?php else: ?>
            <div class="footer-links">
                <a href="/auth/login.php">Back to Sign In</a>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <?php if ($error): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" id="reg-form">
            <div class="field">
                <label for="username">Username <span style="color:#dc2626">*</span></label>
                <input type="text" id="username" name="username"
                       value="<?= h($_POST['username'] ?? '') ?>"
                       autocomplete="username" autofocus required>
                <div class="field-hint">3–32 characters: letters, numbers, underscores</div>
            </div>
            <div class="field">
                <label for="display_name">Display Name</label>
                <input type="text" id="display_name" name="display_name"
                       value="<?= h($_POST['display_name'] ?? '') ?>"
                       autocomplete="name">
                <div class="field-hint">Shown in the app (defaults to username)</div>
            </div>
            <div class="field">
                <label for="email">Email <span style="color:#9ca3af">(optional)</span></label>
                <input type="email" id="email" name="email"
                       value="<?= h($_POST['email'] ?? '') ?>"
                       autocomplete="email">
            </div>
            <div class="field">
                <label for="password">Password <span style="color:#dc2626">*</span></label>
                <input type="password" id="password" name="password"
                       autocomplete="new-password" required>
                <div class="field-hint">At least 10 characters</div>
            </div>
            <div class="field">
                <label for="password2">Confirm Password <span style="color:#dc2626">*</span></label>
                <input type="password" id="password2" name="password2"
                       autocomplete="new-password" required>
            </div>

            <!-- First Responder claim -->
            <div class="field" style="margin-top:1.25rem">
                <label class="fr-check-label">
                    <input type="checkbox" name="fr_claim" id="fr_claim" value="1"
                           <?= !empty($_POST['fr_claim']) ? 'checked' : '' ?>>
                    <span>I am a First Responder</span>
                </label>
                <div class="field-hint" style="margin-top:0.375rem">
                    Firefighter, EMS, Law Enforcement, Emergency Management, etc.
                </div>
            </div>

            <div id="fr-panel" class="fr-panel"
                 style="display:<?= !empty($_POST['fr_claim']) ? 'block' : 'none' ?>">
                <p class="fr-panel-note">
                    First responder status requires verification before the role is granted.
                    Provide your agency details so an admin can confirm your affiliation.
                    <?php if ($idmeClientId): ?>
                        After registering you can also verify instantly through
                        <strong>ID.me</strong>.
                    <?php endif; ?>
                </p>

                <div class="field">
                    <label for="fr_agency">Agency / Department <span style="color:#dc2626">*</span></label>
                    <input type="text" id="fr_agency" name="fr_agency"
                           value="<?= h($_POST['fr_agency'] ?? '') ?>"
                           placeholder="e.g. Morgan County Fire Department">
                </div>

                <div class="field">
                    <label for="fr_role">Role <span style="color:#dc2626">*</span></label>
                    <select id="fr_role" name="fr_role">
                        <option value="">— Select role —</option>
                        <?php foreach ($frRoleLabels as $val => $lbl): ?>
                            <option value="<?= h($val) ?>"
                                <?= ($_POST['fr_role'] ?? '') === $val ? 'selected' : '' ?>>
                                <?= h($lbl) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="fr_identifier">
                        Call Sign, Badge Number, or Unit ID
                        <span style="color:#9ca3af;font-weight:400">(optional)</span>
                    </label>
                    <input type="text" id="fr_identifier" name="fr_identifier"
                           value="<?= h($_POST['fr_identifier'] ?? '') ?>"
                           placeholder="e.g. E7, Unit 12, Badge 4521">
                    <div class="field-hint">Helps the admin confirm your affiliation.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Create Account</button>
        </form>

        <div class="footer-links">
            Already have an account? <a href="/auth/login.php">Sign In</a>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const cb    = document.getElementById('fr_claim');
    const panel = document.getElementById('fr-panel');
    if (!cb || !panel) return;
    cb.addEventListener('change', () => {
        panel.style.display = cb.checked ? 'block' : 'none';
    });
})();
</script>
</body>
</html>
