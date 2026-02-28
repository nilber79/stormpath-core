<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Totp.php';

$user  = requireAuth();
$db    = getDb();
$error = '';
$info  = '';

$area_cfg    = [];
$cfg_file    = __DIR__ . '/../area-config.json';
if (file_exists($cfg_file)) {
    $area_cfg = json_decode(file_get_contents($cfg_file), true) ?? [];
}
$county_name = $area_cfg['area_name'] ?? 'StormPath';

// Currently stored TOTP state
$stmt = $db->prepare("SELECT totp_secret, totp_enabled FROM users WHERE id = ?");
$stmt->execute([(int)$user['id']]);
$row = $stmt->fetch();
$totpEnabled = (bool)($row['totp_enabled'] ?? false);

// ── Actions ────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['disable_totp'])) {
        $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?")
           ->execute([(int)$user['id']]);
        header('Location: /auth/setup-totp.php');
        exit;
    }

    if (isset($_POST['verify_code'])) {
        $secret   = $_POST['pending_secret'] ?? '';
        $code     = trim($_POST['totp_code'] ?? '');

        if (!$secret) {
            $error = 'Missing secret. Please start over.';
        } elseif (!Totp::verify($secret, $code)) {
            $error       = 'Code incorrect. Please try again.';
            $pendingSecret = $secret;
        } else {
            $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?")
               ->execute([$secret, (int)$user['id']]);
            header('Location: /auth/setup-totp.php?enabled=1');
            exit;
        }
    }
}

// Generate a new pending secret if needed
if (!$totpEnabled && !isset($pendingSecret)) {
    $pendingSecret = Totp::generateSecret();
}

// Build OTP URI for QR code
$label   = rawurlencode($county_name . ':' . $user['username']);
$totpUri = isset($pendingSecret) ? Totp::getUri($pendingSecret, $county_name . ':' . $user['username'], $county_name) : '';

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
    <title>Authenticator App — StormPath</title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: #f8f6f2;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 2rem 1rem;
        }
        .card {
            background: #fff; border: 1px solid #e0ddd5; border-radius: 12px;
            padding: 2.5rem 2rem; width: 100%; max-width: 440px;
            box-shadow: 0 4px 24px rgba(0,0,0,.07);
        }
        h1 { font-size: 1.25rem; font-weight: 700; color: #2a2622; margin-bottom: 0.25rem; }
        .sub { font-size: 0.875rem; color: #6b6660; margin-bottom: 1.75rem; }
        .enabled-badge {
            display: inline-flex; align-items: center; gap: 0.375rem;
            background: #d1fae5; color: #065f46; border-radius: 999px;
            padding: 0.25rem 0.875rem; font-size: 0.8125rem; font-weight: 600;
            margin-bottom: 1.25rem;
        }
        .qr-wrap { text-align: center; margin-bottom: 1.25rem; }
        #qr-canvas { border-radius: 8px; }
        .secret-box {
            background: #f8f6f2; border: 1px solid #e0ddd5; border-radius: 8px;
            padding: 0.75rem 1rem; font-family: monospace; font-size: 1rem;
            letter-spacing: 0.1em; text-align: center; margin-bottom: 1rem;
            word-break: break-all;
        }
        .step { font-size: 0.875rem; color: #2a2622; margin-bottom: 1.25rem; line-height: 1.5; }
        .step strong { font-weight: 700; }
        .field { margin-bottom: 1rem; }
        label { display: block; font-size: 0.8125rem; font-weight: 600; color: #2a2622; margin-bottom: 0.375rem; }
        input[type=text] {
            width: 100%; padding: 0.625rem 0.875rem;
            border: 1px solid #e0ddd5; border-radius: 8px;
            font-size: 1.25rem; letter-spacing: 0.2em; outline: none;
            transition: border-color .15s; text-align: center;
        }
        input:focus { border-color: #d97706; }
        .btn {
            width: 100%; padding: 0.75rem; border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem;
        }
        .btn-primary { background: #d97706; color: #fff; }
        .btn-primary:hover { background: #b45309; }
        .btn-danger { background: transparent; color: #dc2626; border: 1px solid #fca5a5; }
        .btn-danger:hover { background: #fee2e2; }
        .error { background: #fee2e2; color: #dc2626; border-radius: 8px; padding: 0.625rem 0.875rem; font-size: 0.875rem; margin-bottom: 1rem; }
        .success { background: #d1fae5; color: #065f46; border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.9375rem; margin-bottom: 1rem; }
        .back-link { display: block; text-align: center; margin-top: 1.5rem; font-size: 0.875rem; color: #d97706; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <h1>Authenticator App (TOTP)</h1>
    <div class="sub">Use Google Authenticator, Authy, or any TOTP app.</div>

    <?php if (isset($_GET['enabled'])): ?>
        <div class="success">Authenticator enabled! You'll be asked for a code each time you sign in.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($totpEnabled): ?>
        <div class="enabled-badge">✓ Enabled</div>
        <p class="step">Authenticator codes are required when signing in with your password.</p>
        <form method="post">
            <button type="submit" name="disable_totp" value="1"
                    class="btn btn-danger"
                    onclick="return confirm('Disable authenticator? You will no longer need a code to sign in.')">
                Disable Authenticator
            </button>
        </form>

    <?php else: ?>
        <p class="step">
            <strong>1.</strong> Scan the QR code with your authenticator app,
            or enter the secret key manually.
        </p>
        <div class="qr-wrap">
            <canvas id="qr-canvas"></canvas>
        </div>
        <div class="secret-box" id="secret-display"><?= h($pendingSecret ?? '') ?></div>
        <p class="step">
            <strong>2.</strong> Enter the 6-digit code from your app to confirm setup.
        </p>
        <form method="post">
            <input type="hidden" name="pending_secret" value="<?= h($pendingSecret ?? '') ?>">
            <div class="field">
                <label for="totp_code">6-Digit Code</label>
                <input type="text" id="totp_code" name="totp_code"
                       inputmode="numeric" pattern="\d{6}" maxlength="6"
                       autocomplete="one-time-code" autofocus required>
            </div>
            <button type="submit" name="verify_code" value="1" class="btn btn-primary">
                Verify &amp; Enable
            </button>
        </form>
    <?php endif; ?>

    <a class="back-link" href="/">← Back to Map</a>
</div>

<?php if (!$totpEnabled && isset($pendingSecret)): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
(function() {
    const uri = <?= json_encode($totpUri) ?>;
    try {
        const qr = qrcode(0, 'M');
        qr.addData(uri);
        qr.make();
        const canvas = document.getElementById('qr-canvas');
        const size = Math.min(window.innerWidth - 80, 220);
        canvas.width = canvas.height = size;
        const ctx = canvas.getContext('2d');
        const modules = qr.getModuleCount();
        const cellSize = size / modules;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, size, size);
        ctx.fillStyle = '#000000';
        for (let row = 0; row < modules; row++) {
            for (let col = 0; col < modules; col++) {
                if (qr.isDark(row, col)) {
                    ctx.fillRect(col * cellSize, row * cellSize, cellSize, cellSize);
                }
            }
        }
    } catch (e) {
        document.getElementById('qr-canvas').style.display = 'none';
    }
})();
</script>
<?php endif; ?>
</body>
</html>
