#!/bin/bash
set -Eeuo pipefail

cd "$(dirname "$0")/.."
if [[ ! -e /proc/$$/fd/9 ]]; then
    mkdir -p .test-reports/phpunit
    exec 9>.test-reports/phpunit/run.lock
    flock -n 9 || { echo 'Another PHPUnit run is active.' >&2; exit 2; }
fi
export GARDENHUB_GRAFANA_GROUP=${1:-functional_gardenhub-grafana}
case "$GARDENHUB_GRAFANA_GROUP" in
    functional_gardenhub-grafana|functional_gardenhub-grafana-watering-history|functional_gardenhub-grafana-alerting|functional_gardenhub-grafana-provisioning) ;;
    *) echo 'Unknown Grafana group.' >&2; exit 2;;
esac
available=$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)
if [[ "$available" -lt 393216 ]]; then
    echo 'Grafana tests need 384 MiB available; no live services were stopped.' >&2
    exit 2
fi
compose=(docker compose -f tests/grafana-db/compose.yaml)
mkdir -p .test-reports/phpunit/grafana
cleanup() {
    "${compose[@]}" down -v --remove-orphans
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
"${compose[@]}" up -d --wait --wait-timeout 300 gardenhub-mysql grafana
"${compose[@]}" run --rm --no-deps -T tests