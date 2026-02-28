<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/auth.php';

destroySession();
header('Location: /');
exit;
