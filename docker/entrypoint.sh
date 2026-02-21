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
for f in roads_optimized.json roads_optimized.jsonl; do
    if [ -f "$IMAGE_ROADS/$f" ]; then
        cp "$IMAGE_ROADS/$f" "$DATA_DIR/$f"
    fi
done

echo "[entrypoint] Roads data ready in $DATA_DIR"

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

# Configure phpLiteAdmin with the ADMIN_PASSWORD env var and the correct DB path.
# This runs at every container start so password changes in .env take effect on restart.
if [ -f "/app/public/phpliteadmin.php" ]; then
    php -r "
\$file = '/app/public/phpliteadmin.php';
\$content = file_get_contents(\$file);
// Set password (phpLiteAdmin 1.9.x compares sha1 of submitted password vs stored value)
\$pass = getenv('ADMIN_PASSWORD') ?: 'changeme';
\$hash = sha1(\$pass);
\$content = preg_replace('/^\\\$password\s*=\s*[^\n]+;/m', '\\\$password = \\'' . \$hash . '\\';', \$content, 1);
// Point phpLiteAdmin at the data directory where reports.db lives
\$content = preg_replace('/^\\\$directory\s*=\s*[^\n]+;/m', '\\\$directory = \\'/app/public/data\\';', \$content, 1);
file_put_contents(\$file, \$content);
echo \"[entrypoint] phpLiteAdmin configured.\\n\";
"
fi

exec "$@"
