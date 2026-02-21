#!/usr/bin/env python3
"""
update_pmtiles.py — Download a Geofabrik OSM extract and convert it to PMTiles
using the Planetiler Docker image.

Usage:
    python update_pmtiles.py <config.yaml> [--output <dir>] [--cache-dir <dir>]

Output:
    <output>/<pmtiles_area_name>.pmtiles

Dependencies:
    pip install requests pyyaml
    Docker must be available (for planetiler)
"""

import argparse
import subprocess
import sys
import tempfile
import time
from datetime import datetime
from pathlib import Path

try:
    import requests
    import yaml
except ImportError as e:
    print(f"ERROR: Missing dependency — {e}", file=sys.stderr)
    print("Run: pip install requests pyyaml", file=sys.stderr)
    sys.exit(1)

PLANETILER_IMAGE = "ghcr.io/onthegomap/planetiler:latest"


def log(msg: str):
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}", flush=True)


def geofabrik_last_modified(url: str) -> str | None:
    """Return the Last-Modified header from Geofabrik, or None on failure."""
    try:
        resp = requests.head(url, timeout=30)
        return resp.headers.get("Last-Modified")
    except Exception:
        return None


def download_pbf(url: str, dest: Path, cache_file: Path) -> Path:
    """
    Download the Geofabrik PBF file if the cached version is outdated.
    Returns the path to the local PBF file.
    """
    remote_ts = geofabrik_last_modified(url)
    cached_ts_file = cache_file.with_suffix(".timestamp")

    if cache_file.exists() and cache_file.stat().st_size > 0:
        cached_ts = cached_ts_file.read_text().strip() if cached_ts_file.exists() else ""
        if remote_ts and cached_ts == remote_ts:
            log(f"Geofabrik PBF is current ({remote_ts}) — using cached file")
            return cache_file

    log(f"Downloading OSM PBF from {url}...")
    cache_file.parent.mkdir(parents=True, exist_ok=True)
    with requests.get(url, stream=True, timeout=1800) as resp:
        resp.raise_for_status()
        total = int(resp.headers.get("Content-Length", 0))
        downloaded = 0
        last_pct = -1
        with cache_file.open("wb") as f:
            for chunk in resp.iter_content(chunk_size=1024 * 1024):
                f.write(chunk)
                downloaded += len(chunk)
                if total:
                    pct = int(downloaded * 100 / total)
                    if pct != last_pct and pct % 10 == 0:
                        log(f"  {pct}% ({downloaded // 1024 // 1024} MB)")
                        last_pct = pct

    if remote_ts:
        cached_ts_file.write_text(remote_ts)

    log(f"Download complete — {cache_file.stat().st_size // 1024 // 1024} MB")
    return cache_file


def run_planetiler(pbf_path: Path, area_name: str, output_file: Path):
    """Run the Planetiler Docker image to convert PBF → PMTiles."""
    pbf_dir    = pbf_path.parent.resolve()     # read-only PBF cache location
    output_dir = output_file.parent.resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    pbf_filename    = pbf_path.name
    output_filename = output_file.name

    log("Converting PBF to PMTiles via Planetiler (this may take 2–10 minutes)...")

    # Planetiler writes downloaded source files (lake_centerline, etc.) to /data/sources
    # and intermediate work to /data/tmp.  We give it a dedicated writable directory
    # as /data so those writes never touch the read-only PBF cache.
    #
    # We do NOT use tempfile.TemporaryDirectory because Planetiler runs as root inside
    # Docker, creating root-owned files that the non-root Actions runner cannot delete.
    # Instead we create a plain directory alongside the output and leave cleanup to the
    # Actions runner (which owns the workspace and handles it at job end).
    planet_workdir = output_dir.parent / "planetiler-workdir"
    planet_workdir.mkdir(parents=True, exist_ok=True)

    cmd = [
        "docker", "run", "--rm",
        "--label", "dockhand.notifications=false",
        "--label", "dockhand.ignore=true",
        "--label", "com.centurylinklabs.watchtower.enable=false",
        "-v", f"{pbf_dir}:/pbf:ro",                  # PBF source file — read-only
        "-v", f"{str(planet_workdir)}:/data",         # Planetiler's writable working dir
        "-v", f"{output_dir}:/output",
        PLANETILER_IMAGE,
        f"--osm-path=/pbf/{pbf_filename}",
        f"--output=/output/{output_filename}",
        f"--area={area_name}",
        "--download",   # fetch missing sources (lake_centerline, etc.) into /data/sources
        "--force",
    ]

    # Planetiler downloads ancillary source files (water polygons, lake centrelines)
    # from external servers that can be slow or temporarily unreachable.  Retry up to
    # three times with a 60-second pause so transient timeouts don't fail the build.
    _TRANSIENT_ERRORS = (
        "TimeoutException",
        "Error getting size of",
        "ConnectException",
        "SocketTimeoutException",
        "Connection reset",
    )
    MAX_RETRIES = 3
    for attempt in range(1, MAX_RETRIES + 1):
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode == 0:
            break
        combined = result.stdout + result.stderr
        is_transient = any(kw in combined for kw in _TRANSIENT_ERRORS)
        if is_transient and attempt < MAX_RETRIES:
            log(f"WARNING: Planetiler download failed (attempt {attempt}/{MAX_RETRIES}) — retrying in 60 s...")
            time.sleep(60)
        else:
            break

    # Print filtered output (errors and completion messages only)
    for line in (result.stdout + result.stderr).splitlines():
        stripped = line.strip()
        # Remove ANSI colour codes
        import re
        stripped = re.sub(r"\x1b\[[0-9;]*m", "", stripped)
        if any(kw in stripped for kw in ("WRN", "ERR", "FINISHED", "Finished in", "Exception")):
            log(f"  [planetiler] {stripped}")

    if result.returncode != 0:
        log(f"ERROR: Planetiler exited with code {result.returncode}")
        log("Full stderr:")
        for line in result.stderr.splitlines():
            print(f"  {line}", file=sys.stderr)
        sys.exit(1)

    if not output_file.exists():
        log(f"ERROR: Expected output file not found: {output_file}")
        sys.exit(1)

    size_mb = output_file.stat().st_size / 1024 / 1024
    log(f"PMTiles written: {output_file} ({size_mb:.1f} MB)")


def main():
    parser = argparse.ArgumentParser(description="SignalPath PMTiles update script")
    parser.add_argument("config", help="Path to area config.yaml")
    parser.add_argument("--output", default="build-output/tiles",
                        help="Output directory (default: build-output/tiles)")
    parser.add_argument("--cache-dir", default="/tmp/geofabrik-cache",
                        help="Directory to cache the downloaded Geofabrik PBF")
    parser.add_argument("--max-age-hours", type=float, default=20,
                        help="Skip rebuild if the existing PMTiles file is younger than this "
                             "many hours (default: 20). Set to 0 to always rebuild.")
    args = parser.parse_args()

    config_path = Path(args.config)
    if not config_path.exists():
        log(f"ERROR: Config not found: {config_path}")
        sys.exit(1)

    with config_path.open() as f:
        cfg = yaml.safe_load(f)

    geofabrik_url = cfg["data"]["geofabrik_url"]
    area_name     = cfg["data"]["pmtiles_area_name"]

    output_dir  = Path(args.output)
    cache_dir   = Path(args.cache_dir)
    pbf_name    = geofabrik_url.rsplit("/", 1)[-1]        # e.g. tennessee-latest.osm.pbf
    cache_file  = cache_dir / pbf_name
    output_file = output_dir / f"{area_name}.pmtiles"

    # Skip the rebuild if the existing file is fresh enough.  This avoids
    # re-running Planetiler when a push to main (e.g. a code or config change)
    # triggers Actions shortly after the nightly build already ran.
    if args.max_age_hours > 0 and output_file.exists():
        age_hours = (time.time() - output_file.stat().st_mtime) / 3600
        if age_hours < args.max_age_hours:
            log(f"PMTiles file is {age_hours:.1f}h old (< {args.max_age_hours:.0f}h) — skipping rebuild")
            sys.exit(0)

    log(f"PMTiles update for {area_name}")
    pbf_path = download_pbf(geofabrik_url, cache_dir, cache_file)
    run_planetiler(pbf_path, area_name, output_file)
    log("PMTiles update complete")


if __name__ == "__main__":
    main()
