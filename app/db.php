<?php
/**
 * Shared SQLite PDO singleton.
 * Included by both api.php and auth/auth.php to avoid function redefinition.
 */
function getDb(): PDO
{
    static $db = null;
    if ($db === null) {
        $dbPath = __DIR__ . '/data/reports.db';
        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA busy_timeout=5000');
    }
    return $db;
}
