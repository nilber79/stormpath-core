<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

$user = requireAuth();

// List existing passkeys for this user
$stmt = getDb()->prepare("SELECT id, name, created_at, last_used FROM passkeys WHERE user_id = ? ORDER BY created_at ASC");
$stmt->execute([(int)$user['id']]);
$passkeys = $stmt->fetchAll();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $deleteId = (int)($_POST['delete_id']);
    getDb()->prepare("DELETE FROM passkeys WHERE id = ? AND user_id = ?")
           ->execute([$deleteId, (int)$user['id']]);
    header('Location: /auth/setup-passkey.php');
    exit;
}

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
    <title>Manage Passkeys — StormPath</title>
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
            padding: 2.5rem 2rem; width: 100%; max-width: 480px;
            box-shadow: 0 4px 24px rgba(0,0,0,.07);
        }
        h1 { font-size: 1.25rem; font-weight: 700; color: #2a2622; margin-bottom: 0.25rem; }
        .sub { font-size: 0.875rem; color: #6b6660; margin-bottom: 1.75rem; }
        .passkey-list { margin-bottom: 1.5rem; }
        .passkey-item {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.75rem 0; border-bottom: 1px solid #f0ede8;
        }
        .passkey-item:last-child { border-bottom: none; }
        .pk-name { font-weight: 600; font-size: 0.9375rem; }
        .pk-meta { font-size: 0.75rem; color: #9ca3af; margin-top: 0.125rem; }
        .btn-remove {
            padding: 0.3rem 0.625rem; color: #dc2626;
            background: transparent; border: 1px solid #fca5a5;
            border-radius: 5px; font-size: 0.8125rem; cursor: pointer;
        }
        .btn-remove:hover { background: #fee2e2; }
        .empty { color: #9ca3af; font-size: 0.875rem; margin-bottom: 1.5rem; }
        .add-section { border-top: 1px solid #e0ddd5; padding-top: 1.5rem; }
        .field { margin-bottom: 1rem; }
        label { display: block; font-size: 0.8125rem; font-weight: 600; color: #2a2622; margin-bottom: 0.375rem; }
        input[type=text] {
            width: 100%; padding: 0.625rem 0.875rem;
            border: 1px solid #e0ddd5; border-radius: 8px;
            font-size: 1rem; outline: none; transition: border-color .15s;
        }
        input:focus { border-color: #d97706; }
        .btn {
            width: 100%; padding: 0.75rem; border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 600; cursor: pointer;
        }
        .btn-primary { background: #d97706; color: #fff; }
        .btn-primary:hover { background: #b45309; }
        .msg { border-radius: 8px; padding: 0.625rem 0.875rem; font-size: 0.875rem; margin-bottom: 1rem; }
        .msg-error   { background: #fee2e2; color: #dc2626; }
        .msg-success { background: #d1fae5; color: #065f46; }
        .back-link { display: block; text-align: center; margin-top: 1.5rem; font-size: 0.875rem; color: #d97706; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <h1>Passkeys</h1>
    <div class="sub">Sign in without a password using your device's biometrics or security key.</div>

    <div id="msg"></div>

    <?php if (empty($passkeys)): ?>
        <p class="empty">No passkeys registered yet.</p>
    <?php else: ?>
        <div class="passkey-list">
            <?php foreach ($passkeys as $pk): ?>
                <div class="passkey-item">
                    <div>
                        <div class="pk-name"><?= h($pk['name'] ?: 'Passkey') ?></div>
                        <div class="pk-meta">
                            Added <?= h(date('M j, Y', strtotime($pk['created_at']))) ?>
                            <?php if ($pk['last_used']): ?>
                                · Last used <?= h(date('M j, Y', strtotime($pk['last_used']))) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <form method="post" onsubmit="return confirm('Remove this passkey?')">
                        <input type="hidden" name="delete_id" value="<?= (int)$pk['id'] ?>">
                        <button type="submit" class="btn-remove">Remove</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="add-section">
        <div class="field">
            <label for="pk-name">New Passkey Name</label>
            <input type="text" id="pk-name" placeholder="e.g. iPhone, YubiKey" value="Passkey">
        </div>
        <button class="btn btn-primary" id="add-passkey-btn" type="button">Register New Passkey</button>
    </div>

    <a class="back-link" href="/">← Back to Map</a>
</div>

<script>
const btn = document.getElementById('add-passkey-btn');
const msgEl = document.getElementById('msg');

function showMsg(text, type) {
    msgEl.className = 'msg msg-' + type;
    msgEl.textContent = text;
}

btn.addEventListener('click', async () => {
    msgEl.className = '';
    msgEl.textContent = '';
    const passkeyName = document.getElementById('pk-name').value.trim() || 'Passkey';

    try {
        const cRes = await fetch('/auth/webauthn.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reg_challenge' }),
        });
        const cData = await cRes.json();
        if (!cData.success) throw new Error(cData.error || 'Challenge failed');

        const options = cData.options;
        const challengeId = options._cid;

        function b64ToBytes(b64) {
            return Uint8Array.from(atob(b64.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
        }
        function bufToBase64(buf) {
            return btoa(String.fromCharCode(...new Uint8Array(buf)));
        }

        const pubKeyOptions = {
            challenge: b64ToBytes(options.challenge),
            rp: options.rp,
            user: {
                id: b64ToBytes(options.user.id),
                name: options.user.name,
                displayName: options.user.displayName,
            },
            pubKeyCredParams: options.pubKeyCredParams,
            timeout: options.timeout || 60000,
            attestation: options.attestation || 'none',
            authenticatorSelection: options.authenticatorSelection || {},
        };

        const credential = await navigator.credentials.create({ publicKey: pubKeyOptions });
        if (!credential) throw new Error('No credential returned');

        const credPayload = {
            id: credential.id,
            rawId: bufToBase64(credential.rawId),
            type: credential.type,
            response: {
                clientDataJSON: bufToBase64(credential.response.clientDataJSON),
                attestationObject: bufToBase64(credential.response.attestationObject),
            },
        };

        const vRes = await fetch('/auth/webauthn.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'reg_verify', challengeId, credential: credPayload, name: passkeyName }),
        });
        const vData = await vRes.json();
        if (!vData.success) throw new Error(vData.error || 'Registration failed');

        showMsg('Passkey registered successfully!', 'success');
        setTimeout(() => location.reload(), 1200);
    } catch (err) {
        if (err.name !== 'NotAllowedError') {
            showMsg(err.message || 'Passkey registration failed.', 'error');
        }
    }
});
</script>
</body>
</html>
