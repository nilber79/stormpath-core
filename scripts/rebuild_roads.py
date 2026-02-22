#!/usr/bin/env python3
"""
rebuild_roads.py — Fetch road data from the Overpass API and produce optimised
JSON/JSONL output for StormPath.

Usage:
    python rebuild_roads.py <config.yaml> [--output <dir>] [--cache-dir <dir>]

Output files (written to --output, default ./build-output/data/):
    roads_optimized.json    Full JSON payload (backwards compat)
    roads_optimized.jsonl   NDJSON for streaming (one road per line)
    roads.json              Raw Overpass API response cache

Python dependencies:
    pip install requests shapely pyproj pyyaml
"""

import argparse
import csv
import json
import math
import sqlite3
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from collections import defaultdict

try:
    import requests
    import yaml
    from shapely.geometry import LineString, mapping
    from shapely.ops import linemerge, polygonize
    from pyproj import Geod
except ImportError as e:
    print(f"ERROR: Missing dependency — {e}", file=sys.stderr)
    print("Run: pip install requests shapely pyproj pyyaml", file=sys.stderr)
    sys.exit(1)


GEOD = Geod(ellps="WGS84")


def log(msg: str):
    print(f"[{datetime.now().strftime('%Y-%m-%d %H:%M:%S')}] {msg}", flush=True)


# ── Distance helpers ────────────────────────────────────────────────────────────

def geodesic_distance_km(lat1, lon1, lat2, lon2) -> float:
    """Accurate geodesic distance between two lat/lon points, in km."""
    _, _, dist_m = GEOD.inv(lon1, lat1, lon2, lat2)
    return dist_m / 1000.0


def polyline_length_km(coords_lat_lon: list) -> float:
    """Total geodesic length of a polyline given as [(lat, lon), ...]."""
    total = 0.0
    for i in range(1, len(coords_lat_lon)):
        total += geodesic_distance_km(*coords_lat_lon[i - 1], *coords_lat_lon[i])
    return total


# ── Geometry simplification ────────────────────────────────────────────────────

def simplify_geometry(coords_lat_lon: list, tolerance: float) -> list:
    """
    Douglas-Peucker simplification via Shapely.
    Input/output: [(lat, lon), ...] — note Shapely needs (x=lon, y=lat).
    """
    if len(coords_lat_lon) <= 2:
        return coords_lat_lon
    # Shapely LineString uses (x, y) = (lon, lat)
    ls = LineString([(lon, lat) for lat, lon in coords_lat_lon])
    simplified = ls.simplify(tolerance, preserve_topology=False)
    if simplified.is_empty:
        return coords_lat_lon[:1] + coords_lat_lon[-1:]
    return [(y, x) for x, y in simplified.coords]


# ── Overpass API fetch ──────────────────────────────────────────────────────────

def fetch_overpass(cfg: dict, cache_file: Path, retries: int = 3) -> dict:
    """Fetch road data from Overpass API, with fallback to cache on failure."""
    relation_id = cfg["area"]["osm_relation_id"]
    area_id = relation_id + 3600000000
    road_types = "|".join(cfg["data"]["road_types"])
    overpass_url = cfg["data"]["overpass_url"]

    query = f"""[out:json][timeout:120];
area({area_id})->.searcharea;
(
  way(area.searcharea)["highway"~"{road_types}"]["name"];
);
out body;
>;
out skel qt;"""

    log(f"Fetching data from Overpass API (area {area_id})...")

    for attempt in range(1, retries + 1):
        try:
            resp = requests.post(
                overpass_url,
                data={"data": query},
                timeout=150,
                headers={"User-Agent": "StormPath/1.0 road-status-app"},
            )
            resp.raise_for_status()
            data = resp.json()
            if "elements" not in data:
                raise ValueError("Response missing 'elements' key")

            # Save raw cache
            cache_file.parent.mkdir(parents=True, exist_ok=True)
            cache_file.write_text(resp.text)
            log(f"Received {len(data['elements'])} elements from Overpass API")
            return data

        except Exception as exc:
            log(f"Attempt {attempt}/{retries} failed: {exc}")
            if attempt < retries:
                time.sleep(10 * attempt)

    # All attempts failed — try cache
    if cache_file.exists() and cache_file.stat().st_size > 0:
        log("Using cached roads.json (Overpass API unavailable)")
        data = json.loads(cache_file.read_text())
        if "elements" in data:
            return data

    log("ERROR: No data available from API or cache. Exiting.")
    sys.exit(1)


# ── Way merging ────────────────────────────────────────────────────────────────

def merge_connected_ways(ways_group: list, nodes: dict, merge_issues: list) -> list:
    """Merge OSM ways with the same name that share endpoints."""
    if len(ways_group) == 1:
        return ways_group

    # Build connectivity graph
    road_name = ways_group[0]["tags"].get("name", "Unknown")
    conns = {}
    for i, way in enumerate(ways_group):
        ns = way.get("nodes", [])
        if len(ns) < 2:
            continue
        conns[i] = {"way": way, "start": ns[0], "end": ns[-1], "adj": []}

    for i in conns:
        for j in conns:
            if i >= j:
                continue
            s1, e1 = conns[i]["start"], conns[i]["end"]
            s2, e2 = conns[j]["start"], conns[j]["end"]
            if e1 == s2 or e1 == e2 or s1 == s2 or s1 == e2:
                conns[i]["adj"].append(j)
                conns[j]["adj"].append(i)

    # Find connected components via DFS
    visited: set = set()
    components = []
    for start in conns:
        if start in visited:
            continue
        comp, stack = [], [start]
        while stack:
            cur = stack.pop()
            if cur in visited:
                continue
            visited.add(cur)
            comp.append(cur)
            stack.extend(nb for nb in conns[cur]["adj"] if nb not in visited)
        components.append(comp)

    merged_ways = []
    for comp in components:
        if len(comp) == 1:
            merged_ways.append(conns[comp[0]]["way"])
        else:
            result = _merge_component(comp, conns, nodes, road_name, merge_issues)
            if result:
                merged_ways.append(result)
    return merged_ways


def _order_component(comp: list, conns: dict) -> list:
    """Order ways in a connected component into a linear chain."""
    adj = {i: list(conns[i]["adj"]) for i in comp}
    # Prefer starting from an endpoint (degree 1)
    endpoints = [i for i in comp if len(adj[i]) == 1]
    candidates = endpoints if endpoints else comp

    best = []
    for start in candidates:
        order, vis, cur = [start], {start}, start
        while len(order) < len(comp):
            nxt = next((nb for nb in adj[cur] if nb not in vis), None)
            if nxt is None:
                break
            order.append(nxt)
            vis.add(nxt)
            cur = nxt
        if len(order) > len(best):
            best = order
    return best


def _merge_component(comp, conns, nodes, road_name, merge_issues) -> dict | None:
    ordered = _order_component(comp, conns)
    merged_nodes = []
    merged_ids = []
    first_way = None
    skipped = 0

    for idx, way_idx in enumerate(ordered):
        way = conns[way_idx]["way"]
        if first_way is None:
            first_way = way
        merged_ids.append(way["id"])
        way_nodes = list(way["nodes"])

        # Orient first way so its end connects to the next
        if idx == 0 and len(ordered) > 1:
            next_conn = conns[ordered[1]]
            if way_nodes[0] in (next_conn["start"], next_conn["end"]) and \
               way_nodes[-1] not in (next_conn["start"], next_conn["end"]):
                way_nodes = list(reversed(way_nodes))

        if merged_nodes:
            last = merged_nodes[-1]
            if way_nodes[-1] == last:
                way_nodes = list(reversed(way_nodes))
            if way_nodes[0] != last:
                # Gap detected — record and skip
                gap_km = 0.0
                if last in nodes and way_nodes[0] in nodes:
                    n1, n2 = nodes[last], nodes[way_nodes[0]]
                    gap_km = geodesic_distance_km(n1["lat"], n1["lon"], n2["lat"], n2["lon"])
                merge_issues.append({
                    "road_name": road_name,
                    "osm_way_id": way["id"],
                    "way_position": f"{idx + 1} of {len(ordered)}",
                    "gap_meters": round(gap_km * 1000),
                    "osm_way_url": f"https://www.openstreetmap.org/way/{way['id']}",
                })
                skipped += 1
                continue
            way_nodes = way_nodes[1:]  # drop duplicate junction node

        merged_nodes.extend(way_nodes)

    if not merged_nodes or first_way is None:
        return None

    if skipped:
        log(f"WARNING: {road_name} — skipped {skipped} disconnected way(s)")

    return {
        "id": first_way["id"],
        "type": "way",
        "tags": first_way["tags"],
        "nodes": merged_nodes,
        "merged_from": merged_ids,
    }


# ── Segment calculation ─────────────────────────────────────────────────────────

def calculate_segments(way: dict, nodes: dict, node_to_ways: dict,
                        intersection_nodes: dict, cfg: dict) -> list | None:
    """
    Split a (merged) road into segments at intersection points.
    Returns a list of segment dicts, or None if the road should be one segment.
    """
    way_nodes = way.get("nodes", [])
    road_name = way["tags"].get("name", "")
    seg_cfg = cfg["segments"]
    MIN_KM = seg_cfg["min_distance_km"]
    MAX_KM = seg_cfg["max_distance_km"]

    if len(way_nodes) < 10:
        return None

    # Find intersection nodes (shared with differently-named roads)
    intersection_indices = []
    for idx, nid in enumerate(way_nodes):
        if nid not in intersection_nodes:
            continue
        cross = [w["way_name"] for w in intersection_nodes[nid]
                 if w["way_name"] != road_name]
        cross = list(dict.fromkeys(cross))  # deduplicate, preserve order
        if cross:
            intersection_indices.append({"index": idx, "node_id": nid, "cross": cross})

    if not intersection_indices:
        return None

    # Filter by minimum distance
    filtered, last_node_pos = [], None
    for inter in intersection_indices:
        nid = way_nodes[inter["index"]]
        if nid not in nodes:
            continue
        nd = nodes[nid]
        if last_node_pos is None:
            filtered.append(inter)
            last_node_pos = (nd["lat"], nd["lon"])
        else:
            if geodesic_distance_km(*last_node_pos, nd["lat"], nd["lon"]) >= MIN_KM:
                filtered.append(inter)
                last_node_pos = (nd["lat"], nd["lon"])

    if not filtered:
        return None

    # Build segments between intersections
    segments = []
    start_idx = 0

    def build_geom(node_ids):
        return [(nodes[n]["lat"], nodes[n]["lon"]) for n in node_ids if n in nodes]

    for i, inter in enumerate(filtered):
        end_idx = inter["index"]
        geom = build_geom(way_nodes[start_idx:end_idx + 1])
        if i == 0:
            desc = f"To {inter['cross'][0]}"
        else:
            desc = f"From {filtered[i-1]['cross'][0]} to {inter['cross'][0]}"
        if len(geom) >= 2:
            segments.append({"description": desc, "geometry": geom})
        start_idx = end_idx

    # Final segment from last intersection to end
    geom = build_geom(way_nodes[start_idx:])
    if len(geom) >= 2:
        last = filtered[-1]
        segments.append({"description": f"From {last['cross'][0]}", "geometry": geom})

    # Split long segments
    segments = _split_long_segments(segments, MAX_KM)
    return segments if segments else None


def _split_long_segments(segments: list, max_km: float) -> list:
    """Split any segment longer than max_km into equal-length parts."""
    result = []
    for seg in segments:
        geom = seg["geometry"]
        total = polyline_length_km(geom)
        if total <= max_km:
            result.append(seg)
            continue
        n = math.ceil(total / max_km)
        target = total / n
        cur_geom = [geom[0]]
        cur_dist = 0.0
        part = 1
        for i in range(1, len(geom)):
            d = geodesic_distance_km(*geom[i - 1], *geom[i])
            cur_dist += d
            cur_geom.append(geom[i])
            if cur_dist >= target and part < n:
                result.append({"description": f"{seg['description']} (part {part} of {n})",
                                "geometry": cur_geom})
                cur_geom = [geom[i]]
                cur_dist = 0.0
                part += 1
        if len(cur_geom) > 1:
            result.append({"description": f"{seg['description']} (part {part} of {n})",
                            "geometry": cur_geom})
    return result


# ── Main processing pipeline ────────────────────────────────────────────────────

def process(cfg: dict, raw_data: dict, output_dir: Path) -> list:
    elements = raw_data["elements"]
    nodes = {e["id"]: e for e in elements if e["type"] == "node"}
    ways  = [e for e in elements if e["type"] == "way" and "tags" in e
             and "name" in e["tags"]]

    log(f"Processing {len(ways)} named ways...")

    # Build node→ways index
    node_to_ways: dict = defaultdict(list)
    for way in ways:
        for nid in way.get("nodes", []):
            node_to_ways[nid].append({"way_id": way["id"], "way_name": way["tags"]["name"]})

    # Intersection nodes: shared by 2+ differently-named roads
    intersection_nodes = {
        nid: wlist for nid, wlist in node_to_ways.items()
        if len({w["way_name"] for w in wlist}) >= 2
    }

    # Group ways by name and merge connected segments
    ways_by_name: dict = defaultdict(list)
    for way in ways:
        ways_by_name[way["tags"]["name"]].append(way)

    merge_issues: list = []
    merged_ways = []
    for name, group in ways_by_name.items():
        merged_ways.extend(merge_connected_ways(group, nodes, merge_issues))

    log(f"After merging: {len(merged_ways)} road features")

    tol = cfg["segments"]["simplify_tolerance"]
    optimized = []

    for way in merged_ways:
        way_nodes = way.get("nodes", [])
        full_coords = [(nodes[n]["lat"], nodes[n]["lon"])
                       for n in way_nodes if n in nodes]
        if len(full_coords) < 2:
            continue

        simplified_full = simplify_geometry(full_coords, tol)
        # Store as [[lat, lon], ...] (matches original PHP output format)
        full_geom_out = [[lat, lon] for lat, lon in simplified_full]

        # Calculate intersection-based segments
        raw_segs = calculate_segments(way, nodes, node_to_ways, intersection_nodes, cfg)

        if raw_segs:
            seg_out = []
            for seg_num, seg in enumerate(raw_segs, start=1):
                simp = simplify_geometry(seg["geometry"], tol)
                seg_out.append({
                    "id": f"{way['id']}-{seg_num}",
                    "description": seg["description"],
                    "geometry": [[lat, lon] for lat, lon in simp],
                })
        else:
            seg_out = [{
                "id": f"{way['id']}-1",
                "description": "Entire road",
                "geometry": full_geom_out,
            }]

        optimized.append({
            "type": way.get("type", "way"),
            "id": way["id"],
            "tags": {"name": way["tags"].get("name", "Unnamed Road")},
            "geometry": full_geom_out,
            "segments": seg_out,
        })

    return optimized, merge_issues, raw_data.get("osm3s", {}).get("timestamp_osm_base", "unknown")


def write_outputs(optimized: list, merge_issues: list, osm_ts: str,
                  data_source: str, output_dir: Path, cfg: dict):
    output_dir.mkdir(parents=True, exist_ok=True)

    # roads_optimized.json
    full_json = {
        "version": 0.6,
        "generator": "StormPath rebuild_roads.py",
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "elements": optimized,
    }
    (output_dir / "roads_optimized.json").write_text(json.dumps(full_json))
    log(f"Wrote roads_optimized.json ({len(optimized)} roads)")

    # roads_optimized.jsonl
    jsonl_path = output_dir / "roads_optimized.jsonl"
    with jsonl_path.open("w") as f:
        for road in optimized:
            f.write(json.dumps(road) + "\n")
    log(f"Wrote roads_optimized.jsonl")

    # merge_issues.csv
    issues_csv = output_dir / "merge_issues.csv"
    if merge_issues:
        with issues_csv.open("w", newline="") as f:
            writer = csv.DictWriter(f, fieldnames=merge_issues[0].keys())
            writer.writeheader()
            writer.writerows(merge_issues)
        log(f"Found {len(merge_issues)} merge issue(s) — see merge_issues.csv")
    elif issues_csv.exists():
        issues_csv.unlink()

    # rebuild_metadata.json — baked into the image, copied to the data volume
    # by entrypoint.sh on every container start, then written to the metadata
    # SQLite table so admin.php and api.php can read it.
    metadata = {
        "last_rebuild":       datetime.now(timezone.utc).isoformat(),
        "road_count":         len(optimized),
        "merge_issues_count": len(merge_issues),
        "data_source":        data_source,
        "osm_timestamp":      osm_ts,
    }
    (output_dir / "rebuild_metadata.json").write_text(json.dumps(metadata, indent=2))
    log("Wrote rebuild_metadata.json")


def fetch_boundary(cfg: dict, output_dir: Path):
    """
    Fetch the area boundary polygon from the Overpass API and write
    area_boundary_geojson.json.  Used by the app to draw the county outline.
    Failures are non-fatal: a warning is logged and the file is left absent.
    """
    relation_id = cfg["area"]["osm_relation_id"]
    overpass_url = cfg["data"]["overpass_url"]

    query = f"""[out:json][timeout:60];
relation({relation_id});
out geom;"""

    log(f"Fetching area boundary (relation {relation_id})...")
    try:
        resp = requests.post(
            overpass_url,
            data={"data": query},
            timeout=90,
            headers={"User-Agent": "StormPath/1.0 road-status-app"},
        )
        resp.raise_for_status()
        data = resp.json()

        elements = data.get("elements", [])
        if not elements:
            log("WARNING: No elements returned for boundary relation — outline will be absent")
            return

        relation = elements[0]
        members  = relation.get("members", [])

        # Collect all outer ring way geometries (role "outer" or empty)
        lines = []
        for member in members:
            if member.get("type") == "way" and member.get("role") in ("outer", ""):
                geom = member.get("geometry", [])
                if len(geom) >= 2:
                    lines.append(LineString([(pt["lon"], pt["lat"]) for pt in geom]))

        if not lines:
            log("WARNING: No outer ring geometry in boundary relation — outline will be absent")
            return

        merged   = linemerge(lines)
        polygons = list(polygonize(merged))
        if not polygons:
            log("WARNING: Could not assemble boundary polygon — outline will be absent")
            return

        # Use the largest polygon (county outline)
        polygon = max(polygons, key=lambda p: p.area)
        geojson = {
            "type": "FeatureCollection",
            "features": [{"type": "Feature", "geometry": mapping(polygon), "properties": {}}],
        }

        boundary_path = output_dir / "area_boundary_geojson.json"
        boundary_path.write_text(json.dumps(geojson))
        log(f"Wrote area_boundary_geojson.json")

    except Exception as exc:
        log(f"WARNING: Could not fetch area boundary: {exc} — outline will be absent")


def main():
    parser = argparse.ArgumentParser(description="StormPath road data rebuild script")
    parser.add_argument("config", help="Path to area config.yaml")
    parser.add_argument("--output", default="build-output/data",
                        help="Output directory (default: build-output/data)")
    parser.add_argument("--cache-dir", default=None,
                        help="Directory for raw API cache (default: same as --output)")
    args = parser.parse_args()

    config_path = Path(args.config)
    if not config_path.exists():
        log(f"ERROR: Config not found: {config_path}")
        sys.exit(1)

    with config_path.open() as f:
        cfg = yaml.safe_load(f)

    output_dir = Path(args.output)
    cache_dir  = Path(args.cache_dir) if args.cache_dir else output_dir
    cache_file = cache_dir / "roads.json"

    log("Starting road data rebuild...")
    raw_data = fetch_overpass(cfg, cache_file)

    data_source = "Overpass API (live)"
    optimized, merge_issues, osm_ts = process(cfg, raw_data, output_dir)

    write_outputs(optimized, merge_issues, osm_ts, data_source, output_dir, cfg)
    fetch_boundary(cfg, output_dir)

    log(f"Rebuild complete — {len(optimized)} roads written")


if __name__ == "__main__":
    main()
