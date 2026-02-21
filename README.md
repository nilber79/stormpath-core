<img src="logo.svg" width="128" />

SignalPath: A community-sourced situational awareness tool for real-time road status and emergency navigation. Report blocked roads, snow/ice conditions, and other hazards — and see what your neighbours are reporting in real time.

## Features

- Interactive map with real-time road condition overlays
- Community report submission (no account required)
- Segment-level reporting on long roads
- Server-Sent Events (SSE) for live updates
- Toast and browser notifications for new blocked-road reports
- Nightly automatic road data refresh from OpenStreetMap
- Self-contained Docker deployment (no external database server)

---

## Step 1 — Publish Your Area Image

Before you can deploy SignalPath, you need to publish a Docker image configured
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
   `ghcr.io/<your-github-username>/signalpath:<your-area-slug>-latest`

> **Note:** GitHub Actions publishes images to the GitHub Container Registry (GHCR)
> associated with your GitHub account. The image is public by default and can be
> pulled by any Docker host without authentication.

---

## Step 2 — Deploy

Once your image is published, deploy it on your server.
SignalPath runs as a single Docker container — no external database server required.

### Which scenario am I?

| Situation | Scenario |
|---|---|
| Fresh server, no other web services running, want the simplest possible setup | **Scenario A — Standalone** |
| Server already runs other websites or has Caddy / Nginx / Traefik handling HTTPS | **Scenario B — Behind a Proxy** |

---

### Scenario A — Standalone (Recommended for new deployments)

SignalPath handles everything itself: it serves the website, obtains a free HTTPS
certificate from Let's Encrypt automatically, and renews it without any extra steps.

**Prerequisites:** A Linux server with Docker and Docker Compose installed,
and a domain name pointed at your server's IP address (e.g. `roadstatus.yourcounty.gov`).

```bash
# 1. Download the two config files
curl -O https://raw.githubusercontent.com/nilber79/signalpath-core/main/deploy/docker-compose.yml
curl -O https://raw.githubusercontent.com/nilber79/signalpath-core/main/deploy/.env.example

# 2. Create your local config file
cp .env.example .env
nano .env     # fill in your values (see below)

# 3. Start SignalPath
docker compose up -d
```

**What to put in `.env`:**
```env
GHCR_ORG=your-github-username            # Your GitHub username (the fork owner)
AREA_TAG=your-area-slug-latest           # Your area slug + "-latest"
DOMAIN=roadstatus.yourcounty.gov         # Your domain name (must point to this server)
ADMIN_PASSWORD=your-strong-password-here # Password for /admin.php and /phpliteadmin.php
```

SignalPath will be live at `https://your.domain` within a minute or two.
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
proxy that handles HTTPS for all your websites. SignalPath runs as an ordinary
HTTP service on your internal Docker network; your proxy routes traffic to it
and handles the HTTPS certificate.

```bash
# 1. Download the proxy compose file
curl -O https://raw.githubusercontent.com/nilber79/signalpath-core/main/deploy/docker-compose.proxy.yml
curl -O https://raw.githubusercontent.com/nilber79/signalpath-core/main/deploy/.env.example

# 2. Create your local config file
cp .env.example .env
nano .env     # fill in GHCR_ORG, AREA_TAG, and ADMIN_PASSWORD (DOMAIN is not used here)

# 3. Start SignalPath
docker compose -f docker-compose.proxy.yml up -d
```

Then add a rule to your proxy config pointing to the `signalpath` container.

**Caddy example** (add to your existing `Caddyfile`):
```caddy
roadstatus.yourcounty.gov {
    reverse_proxy signalpath:80
}
```

**Nginx example:**
```nginx
server {
    listen 443 ssl;
    server_name roadstatus.yourcounty.gov;
    # ... your existing ssl_certificate lines ...

    location / {
        proxy_pass http://signalpath:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

> **How ports work (Scenario B):** The container only listens on port 80 inside
> your server's private Docker network — this port is **never** exposed to the
> internet. Your proxy container and the SignalPath container communicate privately
> using the container name `signalpath` as the hostname. No port conflicts with
> anything else running on your server.

---

## Admin Tools

SignalPath includes two password-protected admin tools for managing report data.
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
| Morgan County, TN | `ghcr.io/nilber79/signalpath:morgan-tn-latest` |

## Architecture

```
GitHub Actions (nightly)
    │
    ├── rebuild_roads.py   → Overpass API → roads_optimized.jsonl
    ├── update_pmtiles.py  → Geofabrik PBF → <state>.pmtiles
    └── docker build       → ghcr.io/<org>/signalpath:<area>-latest
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
- `signalpath-core` — FrankenPHP + PHP extensions + app source (api.php, sse.php, index.html, CSS, JS)
- `signalpath:<area>` — extends core with baked-in roads data, PMTiles, and area-config.json

## Data Sources

- Road geometry: [OpenStreetMap](https://openstreetmap.org) via [Overpass API](https://overpass-api.de)
- Base map tiles: [OpenMapTiles](https://openmaptiles.org) / [Geofabrik](https://download.geofabrik.de)
- Tile conversion: [Planetiler](https://github.com/onthegomap/planetiler)

## License

SignalPath Source Available License v1.0 — see [LICENSE](LICENSE).

**Non-Commercial Use** (individuals, non-profits, government agencies for public benefit) is free.
**Commercial Use** (SaaS, hosted services sold to third parties) requires a separate written license.
Contact [signalpath@reblin.us](mailto:signalpath@reblin.us) for commercial licensing.

Road condition data submitted by users remains the contribution of the respective submitters.
