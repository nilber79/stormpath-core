<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Totp.php';

// Already logged in
if (getCurrentUser()) {
    header('Location: /');
    exit;
}

$redirect    = filter_var($_GET['redirect'] ?? '/', FILTER_SANITIZE_URL);
// Only allow relative redirects
if (!str_starts_with($redirect, '/')) {
    $redirect = '/';
}

$error       = '';
$needTotp    = false;
$pendingUser = null;

// ── Password login POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $totpCode = trim($_POST['totp_code'] ?? '');

    $stmt = getDb()->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
        // Slow down brute-force attempts
        usleep(500_000);
        $error = 'Invalid username or password.';
    } elseif ($user['status'] === 'pending') {
        $error = 'Your account is pending admin approval.';
    } elseif ($user['status'] !== 'active') {
        $error = 'Your account is not active.';
    } elseif ($user['totp_enabled']) {
        if ($totpCode === '') {
            // Ask for TOTP code
            $needTotp    = true;
            $pendingUser = $user;
        } elseif (!Totp::verify($user['totp_secret'], $totpCode)) {
            $error = 'Invalid authenticator code.';
        } else {
            createSession((int)$user['id']);
            header('Location: ' . $redirect);
            exit;
        }
    } else {
        createSession((int)$user['id']);
        header('Location: ' . $redirect);
        exit;
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
    <title>Sign In — StormPath</title>
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
        }
        .card {
            background: #fff;
            border: 1px solid #e0ddd5;
            border-radius: 12px;
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 4px 24px rgba(0,0,0,.07);
        }
        .logo { font-size: 1.5rem; font-weight: 700; color: #d97706; margin-bottom: 0.25rem; }
        .sub  { font-size: 0.875rem; color: #6b6660; margin-bottom: 2rem; }
        .error {
            background: #fee2e2; color: #dc2626;
            border-radius: 8px; padding: 0.625rem 0.875rem;
            font-size: 0.875rem; margin-bottom: 1rem;
        }
        .field { margin-bottom: 1rem; }
        label { display: block; font-size: 0.8125rem; font-weight: 600; color: #2a2622; margin-bottom: 0.375rem; }
        input[type=text], input[type=password], input[type=email] {
            width: 100%; padding: 0.625rem 0.875rem;
            border: 1px solid #e0ddd5; border-radius: 8px;
            font-size: 1rem; outline: none;
            transition: border-color .15s;
        }
        input:focus { border-color: #d97706; }
        .btn {
            width: 100%; padding: 0.75rem; border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 600; cursor: pointer; margin-top: 0.5rem;
        }
        .btn-primary { background: #d97706; color: #fff; }
        .btn-primary:hover { background: #b45309; }
        .btn-passkey {
            background: #2a2622; color: #fff;
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            margin-bottom: 0.75rem;
        }
        .btn-passkey:hover { background: #3d3834; }
        .divider {
            display: flex; align-items: center; gap: 0.75rem;
            font-size: 0.8125rem; color: #9ca3af; margin: 1rem 0;
        }
        .divider::before, .divider::after {
            content: ''; flex: 1; height: 1px; background: #e0ddd5;
        }
        .footer-links {
            margin-top: 1.5rem; font-size: 0.8125rem; color: #6b6660; text-align: center;
        }
        .footer-links a { color: #d97706; text-decoration: none; }
        .footer-links a:hover { text-decoration: underline; }
        #passkey-error { display: none; background: #fee2e2; color: #dc2626; border-radius: 8px; padding: 0.5rem 0.75rem; font-size: 0.8125rem; margin-bottom: 0.75rem; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">StormPath</div>
    <div class="sub">Sign in — <?= h($county_name) ?></div>

    <?php if ($error): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($needTotp && $pendingUser): ?>
        <!-- TOTP second factor -->
        <form method="post">
            <input type="hidden" name="username" value="<?= h($pendingUser['username']) ?>">
            <input type="hidden" name="password" value="<?= h($_POST['password'] ?? '') ?>">
            <div class="field">
                <label for="totp_code">Authenticator Code</label>
                <input type="text" id="totp_code" name="totp_code" inputmode="numeric"
                       pattern="\d{6}" maxlength="6" autocomplete="one-time-code" autofocus required>
            </div>
            <button type="submit" class="btn btn-primary">Verify</button>
        </form>
    <?php else: ?>
        <!-- Passkey button -->
        <div id="passkey-error"></div>
        <button class="btn btn-passkey" id="passkey-btn" type="button">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 2C9.24 2 7 4.24 7 7c0 2.77 2.24 5 5 5s5-2.23 5-5c0-2.76-2.24-5-5-5zm0 8c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm-1 2h2v2h3v2h-3v2h-2v-2H8v-2h3v-2z"/>
            </svg>
            Sign in with Passkey
        </button>

        <div class="divider">or sign in with password</div>

        <form method="post" id="pw-form">
            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" autocomplete="username" autofocus required>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary">Sign In</button>
        </form>
    <?php endif; ?>

    <div class="footer-links">
        Don't have an account? <a href="/auth/register.php">Register</a>
    </div>
</div>

<script>
const passkeyBtn = document.getElementById('passkey-btn');
const passkeyError = document.getElementById('passkey-error');

function showPasskeyError(msg) {
    passkeyError.textContent = msg;
    passkeyError.style.display = 'block';
}

if (passkeyBtn) {
    passkeyBtn.addEventListener('click', async () => {
        passkeyError.style.display = 'none';
        try {
            // Get challenge
            const cRes = await fetch('/auth/webauthn.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'auth_challenge' }),
            });
            const cData = await cRes.json();
            if (!cData.success) throw new Error(cData.error || 'Failed to get challenge');

            const options = cData.options;
            const challengeId = options._cid;

            // Decode base64url challenge
            const challengeBytes = Uint8Array.from(
                atob(options.challenge.replace(/-/g,'+').replace(/_/g,'/')),
                c => c.charCodeAt(0)
            );

            const credential = await navigator.credentials.get({
                publicKey: {
                    challenge: challengeBytes,
                    rpId: options.rpId,
                    timeout: options.timeout || 60000,
                    userVerification: options.userVerification || 'preferred',
                    allowCredentials: [],
                }
            });

            if (!credential) throw new Error('No credential returned');

            // Encode for transmission
            function bufToBase64(buf) {
                return btoa(String.fromCharCode(...new Uint8Array(buf)));
            }

            const credPayload = {
                id: credential.id,
                rawId: bufToBase64(credential.rawId),
                type: credential.type,
                response: {
                    clientDataJSON: bufToBase64(credential.response.clientDataJSON),
                    authenticatorData: bufToBase64(credential.response.authenticatorData),
                    signature: bufToBase64(credential.response.signature),
                    userHandle: credential.response.userHandle
                        ? bufToBase64(credential.response.userHandle) : null,
                },
            };

            const vRes = await fetch('/auth/webauthn.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'auth_verify', challengeId, credential: credPayload }),
            });
            const vData = await vRes.json();
            if (!vData.success) throw new Error(vData.error || 'Passkey verification failed');

            window.location.href = <?= json_encode($redirect) ?>;
        } catch (err) {
            if (err.name !== 'NotAllowedError') {
                showPasskeyError(err.message || 'Passkey sign-in failed. Try again or use password.');
            }
        }
    });
}
</script>
</body>
</html>
