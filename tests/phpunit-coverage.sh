#!/bin/bash
set -Eeuo pipefail

cd "$(dirname "$0")/.."
export GARDENHUB_COVERAGE=1
export GARDENHUB_REPORT_ROOT=.test-reports/coverage/$(date +%Y%m%d-%H%M%S)-$$
mkdir -p "$GARDENHUB_REPORT_ROOT"
GROUP=all bash tests/phpunit.sh
docker run --rm --network none --user "$(id -u):$(id -g)" \
    -v "$PWD/api/tests:/app/tests:ro" \
    -v "$PWD/$GARDENHUB_REPORT_ROOT:/reports" \
    --entrypoint php gardenhub-tests:phpunit -d memory_limit=512M \
    tests/Support/merge_coverage.php /reports
printf 'Coverage reports: %s/html/index.html\n' "$GARDENHUB_REPORT_ROOT"