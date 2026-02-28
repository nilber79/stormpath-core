#!/bin/sh
# entrypoint.sh — Runs before the FrankenPHP/Caddy process starts.
#
# 1. Copies the baked-in roads data from the image into the data volume.
#    This ensures that every time a new image version is pulled and the
#    container restarted, the roads data is updated without touching reports.db.
#
# 2. Initialises the SQLite database schema on first run (when reports.db
#    is absent — i.e., a fresh deployment with an empty volume).

set -e

DATA_DIR="/app/public/data"
IMAGE_ROADS="/image-roads"

mkdir -p "$DATA_DIR"

# Always overwrite roads data from the baked-in image copy
for f in roads_optimized.json roads_optimized.jsonl area_boundary_geojson.json rebuild_metadata.json merge_issues.csv; do
    if [ -f "$IMAGE_ROADS/$f" ]; then
        cp "$IMAGE_ROADS/$f" "$DATA_DIR/$f"
    fi
done

echo "[entrypoint] Roads data ready in $DATA_DIR"

# Restore from Litestream replica before schema init so a VPS restore gets
# current data rather than starting from a blank schema.
# -if-replica-exists makes this a no-op on first run when nothing has been
# pushed yet, so it is safe to run unconditionally when credentials are set.
if [ -n "${LITESTREAM_ACCESS_KEY_ID}" ] && [ -n "${LITESTREAM_SECRET_ACCESS_KEY}" ] && [ -n "${LITESTREAM_BUCKET}" ]; then
    echo "[entrypoint] Litestream: restoring from replica (no-op if none exists yet)..."
    litestream restore -config /etc/litestream.yml -if-replica-exists "$DATA_DIR/reports.db"
fi

# Initialise SQLite schema on first run
if [ ! -f "$DATA_DIR/reports.db" ]; then
    echo "[entrypoint] Initialising reports.db schema..."
    php -r "
\$db = new PDO('sqlite:$DATA_DIR/reports.db');
\$db->exec('PRAGMA journal_mode=WAL');
\$db->exec('PRAGMA busy_timeout=5000');
\$db->exec('
    CREATE TABLE IF NOT EXISTS reports (
        id TEXT PRIMARY KEY,
        road_id INTEGER,
        road_name TEXT,
        segment TEXT,
        segment_description TEXT,
        geometry TEXT,
        status TEXT,
        notes TEXT,
        timestamp TEXT,
        segment_ids TEXT,
        ip TEXT
    )
');
\$db->exec('
    CREATE INDEX IF NOT EXISTS idx_reports_timestamp ON reports (timestamp DESC)
');
\$db->exec('
    CREATE INDEX IF NOT EXISTS idx_reports_road_id ON reports (road_id)
');
\$db->exec('
    CREATE TABLE IF NOT EXISTS report_changes (
        change_id INTEGER PRIMARY KEY AUTOINCREMENT,
        change_type TEXT,
        report_id TEXT,
        changed_at TEXT DEFAULT (strftime(\\'%Y-%m-%dT%H:%M:%fZ\\', \\'now\\'))
    )
');
\$db->exec('
    CREATE TRIGGER IF NOT EXISTS trg_report_add
    AFTER INSERT ON reports BEGIN
        INSERT INTO report_changes (change_type, report_id) VALUES (\\'add\\', NEW.id);
    END
');
\$db->exec('
    CREATE TRIGGER IF NOT EXISTS trg_report_delete
    AFTER DELETE ON reports BEGIN
        INSERT INTO report_changes (change_type, report_id) VALUES (\\'delete\\', OLD.id);
    END
');
\$db->exec('
    CREATE TABLE IF NOT EXISTS rate_limits (
        ip TEXT,
        action TEXT,
        requested_at TEXT,
        PRIMARY KEY (ip, action, requested_at)
    )
');
\$db->exec('
    CREATE INDEX IF NOT EXISTS idx_rate_limits_time ON rate_limits (requested_at)
');
\$db->exec('
    CREATE TABLE IF NOT EXISTS ip_lists (
        ip TEXT PRIMARY KEY,
        list_type TEXT,
        note TEXT,
        created_at TEXT DEFAULT (strftime(\\'%Y-%m-%dT%H:%M:%fZ\\', \\'now\\'))
    )
');
\$db->exec('
    CREATE TABLE IF NOT EXISTS metadata (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL,
        updated_at TEXT DEFAULT (strftime(\\'%Y-%m-%dT%H:%M:%fZ\\', \\'now\\'))
    )
');
echo \"Schema initialised.\\n\";
"
    echo "[entrypoint] reports.db ready"
fi

# Migrate schema: add auth tables and new columns (idempotent, runs on every start)
php -r "
\$db = new PDO('sqlite:$DATA_DIR/reports.db');
\$db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
\$db->exec('
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        email TEXT UNIQUE,
        display_name TEXT,
        password_hash TEXT,
        totp_secret TEXT,
        totp_enabled INTEGER DEFAULT 0,
        role TEXT NOT NULL DEFAULT \\'user\\',
        status TEXT NOT NULL DEFAULT \\'pending\\',
        created_at TEXT DEFAULT (strftime(\\'%Y-%m-%dT%H:%M:%fZ\\',\\'now\\')),
        last_login TEXT
    )
');
\$db->exec('
    CREATE TABLE IF NOT EXISTS passkeys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        credential_id BLOB NOT NULL UNIQUE,
        public_key_cbor BLOB NOT NULL,
        sign_count INTEGER NOT NULL DEFAULT 0,
        aaguid TEXT,
        name TEXT,
        created_at TEXT DEFAULT (strftime(\\'%Y-%m-%dT%H:%M:%fZ\\',\\'now\\')),
        last_used TEXT
    )
');
\$db->exec('
    CREATE TABLE IF NOT EXISTS webauthn_challenges (
        id TEXT PRIMARY KEY,
        challenge TEXT NOT NULL,
        user_id INTEGER,
        type TEXT NOT NULL,
        created_at TEXT DEFAULT (strftime(\\'%Y-%m-%dT%H:%M:%fZ\\',\\'now\\'))
    )
');
\$cols    = \$db->query('PRAGMA table_info(reports)')->fetchAll(PDO::FETCH_ASSOC);
\$colNames = array_column(\$cols, 'name');
if (!in_array('submitted_by', \$colNames)) {
    \$db->exec('ALTER TABLE reports ADD COLUMN submitted_by INTEGER REFERENCES users(id)');
    echo \"[entrypoint] Added submitted_by column to reports.\\n\";
}
\$userCols = array_column(\$db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('prefs', \$userCols)) {
    \$db->exec('ALTER TABLE users ADD COLUMN prefs TEXT');
    echo \"[entrypoint] Added prefs column to users.\\n\";
}
\$adminUser = getenv('ADMIN_USERNAME') ?: 'admin';
\$adminPass = getenv('ADMIN_PASSWORD') ?: '';
\$userCount = (int)\$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
if (\$adminPass && \$userCount === 0) {
    \$hash = password_hash(\$adminPass, PASSWORD_BCRYPT);
    \$stmt = \$db->prepare('INSERT OR IGNORE INTO users (username, password_hash, role, status) VALUES (?, ?, \\'admin\\', \\'active\\')');
    \$stmt->execute([\$adminUser, \$hash]);
    echo \"[entrypoint] Admin user seeded (username: \$adminUser).\\n\";
}
echo \"[entrypoint] Auth schema ready.\\n\";
"

# Write rebuild metadata from the baked-in JSON into the SQLite metadata table.
# Runs on every container start so the table stays current when a new image is pulled.
if [ -f "$DATA_DIR/rebuild_metadata.json" ]; then
    php -r "
\$meta = json_decode(file_get_contents('$DATA_DIR/rebuild_metadata.json'), true);
if (\$meta) {
    \$db = new PDO('sqlite:$DATA_DIR/reports.db');
    \$db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    \$now = gmdate('Y-m-d\TH:i:s.000Z');
    \$stmt = \$db->prepare('INSERT OR REPLACE INTO metadata (key, value, updated_at) VALUES (?, ?, ?)');
    foreach (\$meta as \$k => \$v) {
        \$stmt->execute([\$k, (string)\$v, \$now]);
    }
    echo \"[entrypoint] Rebuild metadata written to reports.db.\\n\";
}
"
fi

# Configure phpLiteAdmin with the ADMIN_PASSWORD env var and the correct DB path.
# This runs at every container start so password changes in .env take effect on restart.
if [ -f "/app/public/phpliteadmin.php" ]; then
    php -r "
\$file = '/app/public/phpliteadmin.php';
\$content = file_get_contents(\$file);
// pla-ng bcrypt-compares the submitted password directly against SYSTEMPASSWORD,
// so store the plaintext ADMIN_PASSWORD (pla-ng handles its own hashing internally).
\$pass = getenv('ADMIN_PASSWORD') ?: 'changeme';
\$pass_escaped = str_replace([\"'\", '\\\\'], [\"\\\\'\", '\\\\\\\\'], \$pass);
\$content = preg_replace('/^\\\$password\s*=\s*[^\n]+;/m', '\\\$password = \\'' . \$pass_escaped . '\\';', \$content, 1);
// Point phpLiteAdmin at the data directory where reports.db lives
\$content = preg_replace('/^\\\$directory\s*=\s*[^\n]+;/m', '\\\$directory = \\'/app/public/data\\';', \$content, 1);
file_put_contents(\$file, \$content);
echo \"[entrypoint] phpLiteAdmin configured.\\n\";
"
fi

# Start FrankenPHP — wrapped in Litestream replication when credentials are set,
# plain otherwise.  The same image works in both modes.
if [ -n "${LITESTREAM_ACCESS_KEY_ID}" ] && [ -n "${LITESTREAM_SECRET_ACCESS_KEY}" ] && [ -n "${LITESTREAM_BUCKET}" ]; then
    echo "[entrypoint] Litestream: starting replication..."
    exec litestream replicate -config /etc/litestream.yml \
        -exec "frankenphp run --config /etc/caddy/Caddyfile"
else
    exec "$@"
fi
