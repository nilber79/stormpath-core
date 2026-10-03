<?php
/**
 * phpLiteAdmin gate — only signed-in StormPath admins reach the database editor.
 *
 * pla-ng itself is installed outside the web root by Dockerfile.area and still
 * asks for ADMIN_PASSWORD on top of this check.
 */
require_once __DIR__ . '/auth/auth.php';

requireRole('admin');

$pla = '/app/phpliteadmin/phpliteadmin.php';
if (!is_file($pla)) {
    http_response_code(404);
    exit('phpLiteAdmin is not installed in this image.');
}

// Give pla-ng its own plain file session.  Its logout calls session_destroy(),
// which would otherwise wipe the StormPath session and sign the admin out.
session_write_close();
ini_set('session.save_handler', 'files');
session_name('sp_pla');
session_set_cookie_params([
    'path'     => '/phpliteadmin.php',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly' => true,
    'samesite' => 'Strict',
]);

require $pla;
