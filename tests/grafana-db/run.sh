#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
compose_files='tests/grafana-db/compose.yaml'
cleanup() {
    docker compose -f "$compose_files" down --volumes --remove-orphans >/dev/null
}
trap cleanup EXIT HUP INT TERM
docker compose -f "$compose_files" up -d --wait
python3 tests/grafana-db/assert-rules.py
