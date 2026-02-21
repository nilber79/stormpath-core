<img src="logo.svg" width="128" />

A self-hostable, community-driven road condition reporting application for counties and municipalities. Report blocked roads, snow/ice conditions, and other hazards — and see what your neighbours are reporting in real time.

## Features

- Interactive map with real-time road condition overlays
- Community report submission (no account required)
- Segment-level reporting on long roads
- Server-Sent Events (SSE) for live updates
- Toast and browser notifications for new blocked-road reports
- Nightly automatic road data refresh from OpenStreetMap
- Self-contained Docker deployment (no external database server)

## Deployment (Quick Start)

> **Prerequisites:** Docker and Docker Compose installed on your server.

```bash
# 1. Pull the deployment files for your county
curl -O https://raw.githubusercontent.com/philreblin/signalpath-core/main/deploy/docker-compose.yml
curl -O https://raw.githubusercontent.com/philreblin/signalpath-core/main/deploy/.env.example

# 2. Configure your deployment
cp .env.example .env
nano .env          # set DOMAIN, GHCR_ORG, COUNTY_TAG

# 3. Start
docker compose up -d
```

Your site will be live at `https://your.domain` with an automatic Let's Encrypt certificate.

### Behind an Existing Reverse Proxy (Caddy, Nginx, Traefik)

Use `docker-compose.proxy.yml` instead:

```bash
docker compose -f deploy/docker-compose.proxy.yml up -d
```

Then add a reverse proxy rule pointing to the `signalpath` container on port 80.

**Caddy example:**
```caddy
roadstatus.yourcounty.gov {
    reverse_proxy signalpath:80
}
```

## Available County Images

| County | Image Tag |
|---|---|
| Morgan County, TN | `ghcr.io/philreblin/signalpath:morgan-tn-latest` |

## Adding a New County

1. Fork this repository
2. Copy `counties/example-county/` to `counties/your-county-slug/`
3. Edit `config.yaml` with your county's values (see `config.schema.yaml` for all options)
4. Push to `main` — GitHub Actions builds and publishes your county image automatically
5. (Optional) Add your county to the table above and open a pull request

## Architecture

```
GitHub Actions (nightly)
    │
    ├── rebuild_roads.py   → Overpass API → roads_optimized.jsonl
    ├── update_pmtiles.py  → Geofabrik PBF → <state>.pmtiles
    └── docker build       → ghcr.io/<org>/signalpath:<county>-latest
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
- `signalpath:<county>` — extends core with baked-in roads data, PMTiles, and county-config.json

## Data Sources

- Road geometry: [OpenStreetMap](https://openstreetmap.org) via [Overpass API](https://overpass-api.de)
- Base map tiles: [OpenMapTiles](https://openmaptiles.org) / [Geofabrik](https://download.geofabrik.de)
- Tile conversion: [Planetiler](https://github.com/onthegomap/planetiler)

## License

MIT — see [LICENSE](LICENSE)

Road condition data submitted by users remains the contribution of the respective submitters.
