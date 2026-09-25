#!/bin/sh
set -eu

apk add --no-cache curl jq mariadb-client >/dev/null

GRAFANA_AUTH="${GRAFANA_USER}:${GRAFANA_PASSWORD}"
RULES_URL="${GRAFANA_URL}/api/prometheus/grafana/api/v1/rules"
CONTACT_POINTS_URL="${GRAFANA_URL}/api/v1/provisioning/contact-points"
FAIL=0

run_sql() {
    mysql -h "$MYSQL_HOST" -u root gardenhub < "$1"
}

# The rule group evaluates every 60s (see gardenhub-rules.yaml interval), so
# give it two full ticks before reading results.
wait_for_evaluation() {
    echo "Waiting for a rule evaluation tick..."
    sleep 75
}

print_rule() {
    name="$1"
    rule=$(curl -sf -u "$GRAFANA_AUTH" "$RULES_URL" \
        | jq --arg name "$name" '[.data.groups[].rules[] | select(.name == $name)][0]')

    if [ "$rule" = "null" ] || [ -z "$rule" ]; then
        echo "FAIL: rule '$name' not found in $RULES_URL"
        FAIL=1
        return
    fi

    health=$(echo "$rule" | jq -r '.health')
    state=$(echo "$rule" | jq -r '.state')
    lastError=$(echo "$rule" | jq -r '.lastError // ""')
    labels=$(echo "$rule" | jq -c '[.alerts[]?.labels]')

    echo "Rule: $name"
    echo "  health: $health"
    echo "  state:  $state"
    echo "  lastError: $lastError"
    echo "  instance labels: $labels"

    if [ "$health" = "error" ]; then
        echo "FAIL: rule '$name' health is 'error'"
        FAIL=1
    fi
}

check_labels_unique_and_nonempty() {
    name="$1"
    rule=$(curl -sf -u "$GRAFANA_AUTH" "$RULES_URL" \
        | jq --arg name "$name" '[.data.groups[].rules[] | select(.name == $name)][0]')
    total=$(echo "$rule" | jq '[.alerts[]?.labels] | length')
    empty=$(echo "$rule" | jq '[.alerts[]?.labels | select(. == {})] | length')
    unique=$(echo "$rule" | jq '[.alerts[]?.labels] | unique | length')

    if [ "$total" -gt 0 ] && { [ "$empty" -gt 0 ] || [ "$unique" != "$total" ]; }; then
        echo "FAIL: rule '$name' has empty or duplicate instance labels (total=$total unique=$unique empty=$empty)"
        FAIL=1
    fi
}

check_contact_point() {
    response=$(curl -sf -u "$GRAFANA_AUTH" "$CONTACT_POINTS_URL")
    chatid_type=$(echo "$response" | jq -r '[.[] | select(.name == "GardenHub Telegram")][0].settings.chatid | type')

    echo "Telegram contact point chatid JSON type: $chatid_type"
    if [ "$chatid_type" != "string" ]; then
        echo "FAIL: Telegram chatid decoded as '$chatid_type' instead of 'string'"
        FAIL=1
    fi
}

echo "=== Scenario 1: one active sensor + one silent sensor ==="
run_sql /tests/seed-active-and-silent.sql
wait_for_evaluation
print_rule "GardenHub device silent"
check_labels_unique_and_nonempty "GardenHub device silent"
print_rule "GardenHub no measurements received"

echo "=== Scenario 2: global silence (no measurements in the last 60 minutes) ==="
run_sql /tests/seed-global-silence.sql
wait_for_evaluation
print_rule "GardenHub device silent"
check_labels_unique_and_nonempty "GardenHub device silent"
print_rule "GardenHub no measurements received"

echo "=== Telegram contact point provisioning ==="
check_contact_point

if [ "$FAIL" -ne 0 ]; then
    echo "RESULT: FAIL"
    exit 1
fi
echo "RESULT: PASS"
