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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username     = trim($_POST['username']     ?? '');
    $password     = $_POST['password']          ?? '';
    $password2    = $_POST['password2']         ?? '';
    $display_name = trim($_POST['display_name'] ?? '');
    $email        = trim($_POST['email']        ?? '');

    // Validate
    if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
        $error = 'Username must be 3–32 characters: letters, numbers, underscores only.';
    } elseif (strlen($password) < 10) {
        $error = 'Password must be at least 10 characters.';
    } elseif ($password !== $password2) {
        $error = 'Passwords do not match.';
    } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $db = getDb();
        // Check username uniqueness
        $existing = $db->prepare("SELECT id FROM users WHERE username = ?");
        $existing->execute([$username]);
        if ($existing->fetch()) {
            $error = 'That username is already taken.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->prepare(
                "INSERT INTO users (username, email, display_name, password_hash, role, status)
                 VALUES (?, ?, ?, ?, 'user', 'pending')"
            )->execute([$username, $email ?: null, $display_name ?: $username, $hash]);

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
            max-width: 420px;
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
        .footer-links {
            margin-top: 1.5rem; font-size: 0.8125rem; color: #6b6660; text-align: center;
        }
        .footer-links a { color: #d97706; text-decoration: none; }
        .footer-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">StormPath</div>
    <div class="sub">Create Account — <?= h($county_name) ?></div>

    <?php if ($success): ?>
        <div class="success-box">
            <strong>Registration submitted!</strong><br>
            Your account is pending admin approval. You'll be able to sign in once an admin activates it.
        </div>
        <div class="footer-links">
            <a href="/auth/login.php">Back to Sign In</a>
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
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
            <button type="submit" class="btn btn-primary">Create Account</button>
        </form>

        <div class="footer-links">
            Already have an account? <a href="/auth/login.php">Sign In</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
