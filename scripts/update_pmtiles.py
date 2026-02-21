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
    # Docker volume mounts require absolute paths — resolve() handles relative inputs
    build_dir  = pbf_path.parent.resolve()
    output_dir = output_file.parent.resolve()
    output_dir.mkdir(parents=True, exist_ok=True)

    pbf_filename = pbf_path.name
    output_filename = output_file.name

    log("Converting PBF to PMTiles via Planetiler (this may take 2–10 minutes)...")

    cmd = [
        "docker", "run", "--rm",
        "--label", "dockhand.notifications=false",
        "--label", "dockhand.ignore=true",
        "--label", "com.centurylinklabs.watchtower.enable=false",
        "-v", f"{build_dir}:/data:ro",
        "-v", f"{output_dir}:/output",
        PLANETILER_IMAGE,
        f"--osm-path=/data/{pbf_filename}",
        f"--output=/output/{output_filename}",
        f"--area={area_name}",
        "--tmp=/tmp",       # use container's own /tmp; /data is mounted read-only
        "--download",       # fetch any missing source files (lake_centerline, etc.)
        "--force",
    ]

    result = subprocess.run(cmd, capture_output=True, text=True)

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
    parser.add_argument("config", help="Path to county config.yaml")
    parser.add_argument("--output", default="build-output/tiles",
                        help="Output directory (default: build-output/tiles)")
    parser.add_argument("--cache-dir", default="/tmp/geofabrik-cache",
                        help="Directory to cache the downloaded Geofabrik PBF")
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

    log(f"PMTiles update for {area_name}")
    pbf_path = download_pbf(geofabrik_url, cache_dir, cache_file)
    run_planetiler(pbf_path, area_name, output_file)
    log("PMTiles update complete")


if __name__ == "__main__":
    main()
