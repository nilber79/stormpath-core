<img src="app/favicon.svg" width="128" />

StormPath: A community-sourced situational awareness tool for real-time road status and emergency navigation. Report blocked roads, snow/ice conditions, and other hazards — and see what your neighbors are reporting in real time.

## Features

- Interactive map with real-time road condition overlays
- Community report submission (no account required)
- Segment-level reporting on long roads
- Server-Sent Events (SSE) for live updates
- Toast and browser notifications for new blocked-road reports
- Nightly automatic road data refresh from OpenStreetMap
- Automatic container updates via Watchtower (no manual update steps)
- Self-contained Docker deployment (no external database server)

---

## Step 1 — Publish Your Area Image

Before you can deploy StormPath, you need to publish a Docker image configured
for your specific area (county, city, township, etc.). GitHub Actions does this
automatically — you just need to provide a config file.

1. **Fork this repository** on GitHub
2. **Copy** `areas/example-area/` to `areas/your-area-slug/`
   (e.g. `areas/morgan-county-tn/` — use lowercase letters and hyphens)
3. **Edit** `areas/your-area-slug/config.yaml` with your area's values
   (see `config.schema.yaml` for all options)
4. **Push to `main`** — GitHub Actions builds and publishes your image automatically
5. **Wait** about 10–15 minutes for the first build to finish
6. Your image will be available at:
   `ghcr.io/<your-github-username>/stormpath:<your-area-slug>-latest`

> **Note:** GitHub Actions publishes images to the GitHub Container Registry (GHCR)
> associated with your GitHub account. The image is public by default and can be
> pulled by any Docker host without authentication.

---

## Step 2 — Deploy

Once your image is published, deploy it on your server.
StormPath runs as a single Docker container — no external database server required.

### Which scenario am I?

| Situation | Scenario |
|---|---|
| Fresh server, no other web services running, want the simplest possible setup | **Scenario A — Standalone** |
| Server already runs other websites or has Caddy / Nginx / Traefik handling HTTPS | **Scenario B — Behind a Proxy** |

---

### Configuring `.env`

Both scenarios read configuration from a `.env` file that must live in the **same directory
as the compose file you download in the next section**. Docker Compose automatically loads this file when you
run `docker compose` — no extra steps are needed to connect them.

Copy `.env.example` to `.env` and fill in your values:

```env
GHCR_ORG=your-github-username            # Your GitHub username (the fork owner)
AREA_TAG=your-area-slug-latest           # Your area slug + "-latest"
DOMAIN=roadstatus.yourcounty.gov         # Your domain — Scenario A only (see below)
ADMIN_PASSWORD=your-strong-password-here # Password for /admin.php and /phpliteadmin.php
```

| Variable | Scenario A | Scenario B |
|---|---|---|
| `GHCR_ORG` | Required | Required |
| `AREA_TAG` | Required | Required |
| `ADMIN_PASSWORD` | Required | Required |
| `DOMAIN` | Required — set to your real hostname | Not used — leave blank or omit |

> **How `DOMAIN` reaches the container (Scenario A):** Docker Compose reads `DOMAIN` from
> your `.env` file and passes it into the container as an environment variable called
> `SERVER_NAME` — which is what FrankenPHP/Caddy reads to know what hostname to serve and
> obtain a Let's Encrypt certificate for. The domain must already point to your server's
> public IP before you start the container.
>
> **Scenario B note:** `docker-compose.proxy.yml` hardcodes `SERVER_NAME: ":80"` directly,
> so no `.env` value is needed or consulted for this. HTTP-only mode means the container
> never attempts to obtain a certificate; your upstream proxy handles TLS entirely.

---

### Scenario A — Standalone (Recommended for new deployments)

StormPath handles everything itself: it serves the website, obtains a free HTTPS
certificate from Let's Encrypt automatically, and renews it without any extra steps.

**Prerequisites:** A Linux server with Docker and Docker Compose installed,
and a domain name pointed at your server's IP address (e.g. `roadstatus.your-area.gov`).

```bash
# 1. Download the two config files
curl -O https://raw.githubusercontent.com/nilber79/stormpath-core/main/deploy/docker-compose.yml
curl -O https://raw.githubusercontent.com/nilber79/stormpath-core/main/deploy/.env.example

# 2. Create your local config file
cp .env.example .env
nano .env

# 3. Start StormPath
docker compose up -d
```

StormPath will be live at `https://your.domain` within a minute or two.
The HTTPS certificate is obtained and renewed automatically — you do not need to
configure certificates or ports manually.

> **How ports work (Scenario A):** Port 80 and 443 are mapped directly from your
> server to the container. Port 80 is used only to redirect visitors to HTTPS and
> to complete the certificate verification process. Port 443 serves the actual
> website over HTTPS. If anything else on your server is using port 80 or 443,
> use Scenario B instead.

---

### Scenario B — Behind an Existing Reverse Proxy

Use this if your server already runs Caddy, Nginx, Traefik, or another reverse
proxy that handles HTTPS for all your websites. StormPath runs as an ordinary
HTTP service on your internal Docker network; your proxy routes traffic to it
and handles the HTTPS certificate.

```bash
# 1. Download the proxy compose file
curl -O https://raw.githubusercontent.com/nilber79/stormpath-core/main/deploy/docker-compose.proxy.yml
curl -O https://raw.githubusercontent.com/nilber79/stormpath-core/main/deploy/.env.example

# 2. Create your local config file
cp .env.example .env
nano .env

# 3. Start StormPath
docker compose -f docker-compose.proxy.yml up -d
```

Then add a rule to your proxy config pointing to the `stormpath` container.

**Caddy example** (add to your existing `Caddyfile`):
```caddy
roadstatus.your-area.gov {
    reverse_proxy stormpath:80
}
```

**Nginx example:**
```nginx
server {
    listen 443 ssl;
    server_name roadstatus.your-area.gov;
    # ... your existing ssl_certificate lines ...

    location / {
        proxy_pass http://stormpath:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

> **How ports work (Scenario B):** The container only listens on port 80 inside
> your server's private Docker network — this port is **never** exposed to the
> internet. Your proxy container and the StormPath container communicate privately
> using the container name `stormpath` as the hostname. No port conflicts with
> anything else running on your server.

---

## Automatic Updates (Watchtower)

Both compose files include **[Watchtower](https://github.com/nickfedor/watchtower)**
(`nickfedor/watchtower`, the actively maintained fork), which monitors the StormPath
container and automatically pulls and restarts it whenever a new image is published to GHCR.

This is what keeps your road and map data current: every night GitHub Actions rebuilds
the image with fresh OpenStreetMap road data and (when changed) updated PMTiles. Watchtower
checks for a new image every hour, so your site will be running the latest data within an
hour of the nightly build completing — no manual steps required.

Watchtower is configured to watch **only the `stormpath` container** (not everything on
your server) and will perform a graceful restart, which typically takes only a few seconds.

> **Note on downtime:** When Watchtower pulls a new image and restarts the container,
> the site is briefly unavailable (usually under 10 seconds). This happens in the early
> morning hours, well before peak usage. Road condition reports stored in the database
> volume are never affected by image updates.

If you prefer to control updates manually and disable Watchtower, remove or comment out
the `watchtower:` service block from your compose file. You can then update on demand with:
```bash
docker compose pull && docker compose up -d
```

---

## Admin Tools

StormPath includes two password-protected admin tools for managing report data.
Both share the `ADMIN_PASSWORD` you set in your `.env` file.

> **Important:** The default password is `changeme`. Always set a strong password
> before your site is publicly accessible. Restart the container after changing it:
> ```bash
> docker compose restart
> ```

### `/admin.php` — Report and IP list management

A built-in admin interface for day-to-day operations:

- **Reports tab** — View all reports from the past 30 days grouped by road.
  Update a report's status (e.g. mark a blocked road as Clear once the hazard is
  resolved) or delete a report outright. Status changes are pushed to connected
  browsers in real time via Server-Sent Events.
- **IP Lists tab** — Add or remove IP addresses from the whitelist (always
  allowed, bypasses rate limits) or blacklist (blocked from submitting reports).

### `/phpliteadmin.php` — Direct database access

[pla-ng](https://github.com/emanueleg/pla-ng) provides a full web-based browser
for the SQLite database (`reports.db`). Use it when you need to run custom
queries, inspect raw data, or make changes that the admin interface does not
cover. The database is pre-selected automatically.

---

## Available Area Images

| Area | Image Tag |
|---|---|
| Morgan County, TN | `ghcr.io/nilber79/stormpath:morgan-county-tn-latest` |

## Architecture

```
GitHub Actions (nightly)
    │
    ├── rebuild_roads.py   → Overpass API → roads_optimized.jsonl
    ├── update_pmtiles.py  → Geofabrik PBF → <state>.pmtiles
    └── docker build       → ghcr.io/<org>/stormpath:<area>-latest
                                │
                        Docker container (FrankenPHP)
                                │
                    ┌───────────┴───────────┐
                  PHP API              Static files
                (api.php, sse.php)    (HTML/CSS/JS/tiles)
                    │
              SQLite (reports.db)     ← volume-mounted (persists across updates)
```

**Image layers:**
- `stormpath-core` — FrankenPHP + PHP extensions + app source (api.php, sse.php, index.html, CSS, JS)
- `stormpath:<area>` — extends core with baked-in roads data, PMTiles, and area-config.json

## Data Sources

- Road geometry: [OpenStreetMap](https://openstreetmap.org) via [Overpass API](https://overpass-api.de)
- Base map tiles: [OpenMapTiles](https://openmaptiles.org) / [Geofabrik](https://download.geofabrik.de)
- Tile conversion: [Planetiler](https://github.com/onthegomap/planetiler)

## License

StormPath Source Available License v1.0 — see [LICENSE](LICENSE).

**Non-Commercial Use** (individuals, non-profits, government agencies for public benefit) is free.
**Commercial Use** (SaaS, hosted services sold to third parties) requires a separate written license.
Contact [info@stormpath.app](mailto:info@stormpath.app) for commercial licensing.

Road condition data submitted by users remains the contribution of the respective submitters.
