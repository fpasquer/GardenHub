#!/bin/bash
set -Eeuo pipefail

cd "$(dirname "$0")/.."
group=${GROUP:-all}
case "$group" in
    *[!a-z0-9_-]*|'') echo 'Invalid PHPUnit group name.' >&2; exit 2;;
esac
available=$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)
if [[ "$available" -lt 262144 ]]; then
    echo 'PHPUnit needs at least 256 MiB available; no services were stopped.' >&2
    exit 2
fi
report_root=${GARDENHUB_REPORT_ROOT:-.test-reports/phpunit}
integration_groups=(
    scheduler-delivery telegram-summary sensor-identity sensor-concurrency
    mqtt-worker mqtt-session-resume mqtt-delivery mqtt-identity-race migration-upgrade
    watering-orm watering-control watering-production watering-proposals
    watering-actuator-switch watering-mqtt
)
if [[ "${GARDENHUB_COVERAGE:-0}" == 1 && ( "$group" == all || "$group" == ci ) && "$*" != *--list-* ]]; then
    if [[ -d "$report_root" && -n "$(find "$report_root" -type f -print -quit)" ]]; then
        echo 'Coverage collection requires a fresh report root.' >&2
        exit 2
    fi
    mkdir -p "$report_root"
    printf '%s\n' "$group" "${integration_groups[@]}" > "$report_root/expected-suites.txt"
fi
mkdir -p .test-reports/phpunit/cache "$report_root/$group"
exec 9>.test-reports/phpunit/run.lock
flock -n 9 || { echo 'Another PHPUnit run is active.' >&2; exit 2; }
if [[ "$group" == functional_gardenhub-coverage-collection ]]; then
    set -- tests/Coverage "$@"
fi
if [[ "$group" == functional_gardenhub-production-smoke ]]; then
    if [[ "$*" != *--list-* ]]; then
        exec sh tests/production-smoke/run.sh
    fi
    set -- tests/Integration "$@"
fi
if [[ "$group" == functional_gardenhub-grafana* ]]; then
    if [[ "$*" != *--list-* ]]; then
        exec bash tests/phpunit-grafana.sh "$group"
    fi
    set -- tests/Integration "$@"
fi
if [[ "$group" == functional_gardenhub-scheduler-delivery || "$group" == functional_gardenhub-telegram-summary || "$group" == functional_gardenhub-sensor-identity || "$group" == functional_gardenhub-sensor-concurrency || "$group" == functional_gardenhub-mqtt-worker || "$group" == functional_gardenhub-mqtt-session-resume || "$group" == functional_gardenhub-mqtt-delivery || "$group" == functional_gardenhub-mqtt-identity-race || "$group" == functional_gardenhub-migration-upgrade || "$group" == functional_gardenhub-watering-* ]]; then
    if [[ "$*" != *--list-* ]]; then
        exec bash tests/phpunit-integration.sh "$group"
    fi
    set -- tests/Integration "$@"
fi
if [[ "$group" != all && "$group" != ci ]]; then
    set -- --group "$group" "$@"
fi
if [[ "${GARDENHUB_COVERAGE:-0}" == 1 ]]; then
    export XDEBUG_MODE=coverage
    set -- --coverage-php /reports/aggregate.cov "$@"
fi
docker run --rm --network none --user "$(id -u):$(id -g)" \
    -e GARDENHUB_COVERAGE="${GARDENHUB_COVERAGE:-0}" \
    -e GARDENHUB_ROOT=/workspace -e XDEBUG_MODE="${XDEBUG_MODE:-off}" \
    -v "$PWD:/workspace:ro" \
    -v "$PWD/api/tests:/app/tests:ro" \
    -v "$PWD/api/phpunit.xml.dist:/app/phpunit.xml.dist:ro" \
    -v "$PWD/.test-reports/phpunit/cache:/app/.phpunit.cache" \
    -v "$PWD/$report_root/$group:/reports" \
    --entrypoint php gardenhub-tests:phpunit \
    vendor/bin/phpunit --do-not-record-test-run-history \
    --testdox --display-all-issues --log-junit /reports/junit.xml \
    --testdox-text /reports/testdox.txt "$@"
if [[ ( "$group" == all || "$group" == ci ) && "$*" != *--list-* ]]; then
    for integration_group in "${integration_groups[@]}"; do
        bash tests/phpunit-integration.sh "functional_gardenhub-$integration_group"
    done
    if [[ "${GARDENHUB_COVERAGE:-0}" == 1 ]]; then
        while IFS= read -r suite; do
            [[ -s "$report_root/$suite/aggregate.cov" && -s "$report_root/$suite/junit.xml" ]] || {
                echo "Coverage collection incomplete: $suite" >&2
                exit 1
            }
        done < "$report_root/expected-suites.txt"
        touch "$report_root/collection-complete"
    elif [[ "$group" == all ]]; then
        sh tests/production-smoke/run.sh
        bash tests/phpunit-grafana.sh functional_gardenhub-grafana
    fi
fi