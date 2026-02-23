#!/bin/bash
# Builds the stormpath:dev image from the current working tree and restarts
# the stormpath-dev container.
#
# Road/tile data is copied from the live production container so you don't
# need to run the full Python pipeline for UI/PHP/JS changes.
# Re-run the Python scripts and repeat this build when road data changes.
set -e

REPO="$(cd "$(dirname "$0")" && pwd)"
AREA=morgan-county-tn
COMPOSE=/home/debian-admin/webserver/compose.yaml

echo "→ Extracting road/tile data from production container..."
mkdir -p "$REPO/build-output/data" "$REPO/build-output/tiles"
docker cp stormpath-morgantn:/image-roads/. "$REPO/build-output/data/"
docker cp stormpath-morgantn:/app/public/tiles/. "$REPO/build-output/tiles/"

echo "→ Building stormpath:dev image..."
docker build \
    -f "$REPO/docker/Dockerfile.area" \
    --build-arg AREA="$AREA" \
    --build-arg GHCR_ORG=nilber79 \
    -t stormpath:dev \
    "$REPO"

echo "→ Restarting stormpath-dev container..."
docker compose -f "$COMPOSE" up -d stormpath-dev

echo "✓ Done — https://dev.stormpath.app"
