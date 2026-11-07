#!/bin/sh
set -eu

cd "$(dirname "$0")/../.."
export PWD
mkdir -p .test-reports/phpunit/production-smoke
available=$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)
if [ "$available" -lt 393216 ]; then
    echo 'Packaged smoke needs 384 MiB available; no live services were stopped.' >&2
    exit 2
fi
compose() {
    docker compose -f tests/production-smoke/compose.yaml "$@"
}

cleanup() {
    status=$?
    trap - EXIT
    if [ "$status" -ne 0 ]; then
        compose ps -a || true
        compose logs --no-color --tail=100 mysql nginx || true
    fi
    compose down -v --remove-orphans || status=1
    exit "$status"
}
trap cleanup EXIT

compose up -d --wait --wait-timeout 120 mysql
compose run --rm --no-deps -T tests