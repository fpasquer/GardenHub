#!/bin/bash
set -Eeuo pipefail

cd "$(dirname "$0")/.."
if [[ ! -e /proc/$$/fd/9 ]]; then
    mkdir -p .test-reports/phpunit
    exec 9>.test-reports/phpunit/run.lock
    flock -n 9 || { echo 'Another PHPUnit run is active.' >&2; exit 2; }
fi
export GARDENHUB_TEST_GROUP=${1:-functional_gardenhub-scheduler-delivery}
case "$GARDENHUB_TEST_GROUP" in
    functional_gardenhub-scheduler-delivery)
        export GARDENHUB_TEST_DATABASE=scheduler_cron
        export GARDENHUB_TEST_FILE=tests/Integration/SchedulerDeliveryTest.php
        export GARDENHUB_TEST_REPORT=scheduler-delivery;;
    functional_gardenhub-telegram-summary)
        export GARDENHUB_TEST_DATABASE=telegram_summary
        export GARDENHUB_TEST_FILE=tests/Integration/TelegramDailySummaryTest.php
        export GARDENHUB_TEST_REPORT=telegram-summary;;
    functional_gardenhub-sensor-identity)
        export GARDENHUB_TEST_DATABASE=sensor_identity
        export GARDENHUB_TEST_FILE=tests/Integration/SensorIdentityTest.php
        export GARDENHUB_TEST_REPORT=sensor-identity;;
    functional_gardenhub-sensor-concurrency)
        export GARDENHUB_TEST_DATABASE=sensor_identity
        export GARDENHUB_TEST_FILE=tests/Integration/SensorConcurrencyTest.php
        export GARDENHUB_TEST_REPORT=sensor-concurrency;;
    functional_gardenhub-mqtt-worker)
        export GARDENHUB_TEST_DATABASE=worker_lifecycle
        export GARDENHUB_TEST_FILE=tests/Integration/MqttWorkerTest.php
        export GARDENHUB_TEST_REPORT=mqtt-worker;;
    functional_gardenhub-mqtt-session-resume)
        export GARDENHUB_TEST_DATABASE=worker_lifecycle
        export GARDENHUB_TEST_FILE=tests/Integration/MqttSessionResumeTest.php
        export GARDENHUB_TEST_REPORT=mqtt-session-resume;;
    functional_gardenhub-mqtt-delivery)
        export GARDENHUB_TEST_DATABASE=worker_lifecycle
        export GARDENHUB_TEST_FILE=tests/Integration/MqttDeliveryTest.php
        export GARDENHUB_TEST_REPORT=mqtt-delivery;;
    functional_gardenhub-mqtt-identity-race)
        export GARDENHUB_TEST_DATABASE=worker_lifecycle
        export GARDENHUB_TEST_FILE=tests/Integration/MqttIdentityRaceTest.php
        export GARDENHUB_TEST_REPORT=mqtt-identity-race;;
    functional_gardenhub-migration-upgrade)
        export GARDENHUB_TEST_DATABASE=worker_lifecycle
        export GARDENHUB_TEST_FILE=tests/Integration/MigrationUpgradeTest.php
        export GARDENHUB_TEST_REPORT=migration-upgrade;;
    functional_gardenhub-watering-orm)
        export GARDENHUB_TEST_DATABASE=watering_control_test
        export GARDENHUB_TEST_FILE=tests/Integration/WateringOrmTest.php
        export GARDENHUB_TEST_REPORT=watering-orm;;
    functional_gardenhub-watering-control)
        export GARDENHUB_TEST_DATABASE=watering_control_test
        export GARDENHUB_TEST_FILE=tests/Integration/WateringControlTest.php
        export GARDENHUB_TEST_REPORT=watering-control;;
    functional_gardenhub-watering-production)
        export GARDENHUB_TEST_DATABASE=watering_control_test
        export GARDENHUB_TEST_FILE=tests/Integration/WateringProductionTest.php
        export GARDENHUB_TEST_REPORT=watering-production;;
    functional_gardenhub-watering-proposals)
        export GARDENHUB_TEST_DATABASE=watering_control_test
        export GARDENHUB_TEST_FILE=tests/Integration/WateringProposalTest.php
        export GARDENHUB_TEST_REPORT=watering-proposals;;
    functional_gardenhub-watering-actuator-switch)
        export GARDENHUB_TEST_DATABASE=watering_control_test
        export GARDENHUB_TEST_FILE=tests/Integration/WateringActuatorSwitchTest.php
        export GARDENHUB_TEST_REPORT=watering-actuator-switch;;
    functional_gardenhub-watering-mqtt)
        export GARDENHUB_TEST_DATABASE=watering_control_test
        export GARDENHUB_TEST_FILE=tests/Integration/WateringMqttTest.php
        export GARDENHUB_TEST_REPORT=watering-mqtt;;
    *) echo 'Unknown integration group.' >&2; exit 2;;
esac
available=$(awk '/MemAvailable:/ {print $2}' /proc/meminfo)
if [[ "$available" -lt 393216 && "${GARDENHUB_COVERAGE:-0}" != 1 ]]; then
    echo 'Isolated MySQL tests need 384 MiB available; no live services were stopped.' >&2
    exit 2
fi
compose=(docker compose --profile mqtt -f tests/scheduler-cron/compose-phpunit.yaml)
if [[ "${GARDENHUB_COVERAGE:-0}" == 1 ]]; then
    export GARDENHUB_TEST_TIMEOUT=900
fi
services=(mysql)
if [[ "$GARDENHUB_TEST_GROUP" == functional_gardenhub-mqtt-* || "$GARDENHUB_TEST_GROUP" == functional_gardenhub-watering-production || "$GARDENHUB_TEST_GROUP" == functional_gardenhub-watering-mqtt ]]; then
    services+=(mqtt)
fi
reports=${GARDENHUB_REPORT_ROOT:-.test-reports/phpunit}/$GARDENHUB_TEST_REPORT
mkdir -p "$reports"
cleanup() {
    "${compose[@]}" down -v --remove-orphans
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
if ! "${compose[@]}" up -d --wait --wait-timeout 180 "${services[@]}" >"$reports/services.log" 2>&1; then
    tail -n 80 "$reports/services.log"
    "${compose[@]}" logs --tail 80 "${services[@]}"
    exit 1
fi
if [[ "${GARDENHUB_COVERAGE:-0}" == 1 ]]; then
    "${compose[@]}" run --rm --no-deps -T -e XDEBUG_MODE=coverage -e GARDENHUB_COVERAGE=1 tests \
        --do-not-record-test-run-history --testdox --display-all-issues \
        --group "$GARDENHUB_TEST_GROUP" \
        --log-junit /reports/junit.xml --testdox-text /reports/testdox.txt \
        --coverage-php /reports/aggregate.cov "$GARDENHUB_TEST_FILE"
else
    "${compose[@]}" run --rm --no-deps -T tests
fi