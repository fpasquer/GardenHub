#!/bin/sh
set -eu

apk add --no-cache curl jq mariadb-client >/dev/null

GRAFANA_AUTH="${GRAFANA_USER}:${GRAFANA_PASSWORD}"
RULES_URL="${GRAFANA_URL}/api/prometheus/grafana/api/v1/rules"
CONTACT_POINTS_URL="${GRAFANA_URL}/api/v1/provisioning/contact-points"
FAIL=0

DEVICE_SILENT="GardenHub device silent"
NO_MEASUREMENTS="GardenHub no measurements received"

# gardenhub-rules.yaml: 60s group eval interval, device-silent for:1m,
# no-measurements for:5m. Timeouts allow one eval tick + the pending period
# + a buffer tick.
POLL_INTERVAL=5
DEVICE_SILENT_TIMEOUT=180
NO_MEASUREMENTS_TIMEOUT=480
# Bounds each individual request so one stalled call can't silently outlast
# the outer polling timeouts above.
CURL_MAX_TIME=10

run_sql() {
    mysql -h "$MYSQL_HOST" -u root gardenhub < "$1"
}

fetch_rule() {
    curl -sf --max-time "$CURL_MAX_TIME" -u "$GRAFANA_AUTH" "$RULES_URL" \
        | jq --arg name "$1" '[.data.groups[].rules[] | select(.name == $name)][0]'
}

# Polls a rule until its Prometheus-style top-level `state` (lowercase
# firing/pending/inactive) equals $2, echoing the matching rule JSON on
# success. Returns 1 with diagnostics on stderr if $3 seconds pass without
# reaching it.
wait_for_rule_state() {
    name="$1"
    want="$2"
    timeout="$3"
    elapsed=0
    rule='null'
    while [ "$elapsed" -lt "$timeout" ]; do
        rule=$(fetch_rule "$name")
        state=$(echo "$rule" | jq -r '.state // "missing"')
        if [ "$state" = "$want" ]; then
            echo "$rule"
            return 0
        fi
        sleep "$POLL_INTERVAL"
        elapsed=$((elapsed + POLL_INTERVAL))
    done
    {
        echo "FAIL: rule '$name' did not reach state '$want' within ${timeout}s"
        echo "  last state:  $(echo "$rule" | jq -r '.state // "missing"')"
        echo "  last health: $(echo "$rule" | jq -r '.health // "missing"')"
        echo "  lastError:   $(echo "$rule" | jq -r '.lastError // ""')"
        echo "  instances:   $(echo "$rule" | jq -c '[.alerts[]? | {labels, state}]')"
    } >&2
    # Runs inside a `rule=$(...)` command substitution in every caller, so
    # setting FAIL here would be lost in that subshell - return 1 instead so
    # `set -e` aborts the script at the failing assignment.
    return 1
}

find_instance() {
    rule_json="$1"
    device="$2"
    sensor_type="$3"
    echo "$rule_json" | jq --arg d "$device" --arg s "$sensor_type" \
        '[.alerts[]? | select(.labels.device == $d and .labels.sensor_type == $s)][0] // empty'
}

# Polls a single alert instance (by device/sensor_type labels) until its own
# per-instance `state` matches $4, echoing the matching rule JSON on success.
# Each instance has its own independent "for" pending timer, so the
# rule-level state (which fires as soon as ANY instance is Alerting - see
# ComputeRuleState's Alerting > Pending priority) is not sufficient evidence
# that a specific instance has also finished transitioning.
wait_for_instance_state() {
    name="$1"
    device="$2"
    sensor_type="$3"
    want="$4"
    timeout="$5"
    elapsed=0
    rule='null'
    while [ "$elapsed" -lt "$timeout" ]; do
        rule=$(fetch_rule "$name")
        instance=$(find_instance "$rule" "$device" "$sensor_type")
        if [ -n "$instance" ]; then
            state=$(echo "$instance" | jq -r '.state')
            case "$state" in
                "$want"*)
                    echo "$rule"
                    return 0
                    ;;
            esac
        fi
        sleep "$POLL_INTERVAL"
        elapsed=$((elapsed + POLL_INTERVAL))
    done
    {
        echo "FAIL: instance device=$device sensor_type=$sensor_type on rule '$name' did not reach state '$want' within ${timeout}s"
        echo "  instances: $(echo "$rule" | jq -c '[.alerts[]? | {labels, state}]')"
    } >&2
    # Same subshell caveat as wait_for_rule_state - return 1, don't set FAIL.
    return 1
}

# Checks one alert instance's own per-instance `state` (e.g. "Normal" vs
# "Alerting"), not just whether it's present - Grafana's rules API lists
# every tracked instance regardless of state, so absence from `.alerts[]`
# is not evidence that an instance is normal.
assert_instance_state() {
    rule_json="$1"
    device="$2"
    sensor_type="$3"
    expected="$4"
    ctx="$5"
    instance=$(find_instance "$rule_json" "$device" "$sensor_type")
    if [ -z "$instance" ]; then
        echo "FAIL: $ctx - no alert instance found for device=$device sensor_type=$sensor_type"
        FAIL=1
        return
    fi
    state=$(echo "$instance" | jq -r '.state')
    case "$state" in
        "$expected"*) ;;
        *)
            echo "FAIL: $ctx - expected instance state '$expected*', got '$state' (device=$device sensor_type=$sensor_type)"
            FAIL=1
            ;;
    esac
}

assert_rule_state() {
    name="$1"
    expected="$2"
    ctx="$3"
    rule=$(fetch_rule "$name")
    state=$(echo "$rule" | jq -r '.state // "missing"')
    if [ "$state" != "$expected" ]; then
        echo "FAIL: $ctx - expected '$name' state '$expected', got '$state' (health=$(echo "$rule" | jq -r '.health // "missing"'))"
        FAIL=1
    fi
}

# Rule-level `health` is "ok" only if every underlying eval was Normal/Pending/
# Alerting/Recovering - it flips to "error"/"nodata" on any eval.Error/NoData,
# independently of `state` (which can still read "firing" in both cases since
# this rule's noDataState/executionErrorState are both "Alerting"). This is
# the only reliable way to reject an error/No-Data-driven firing.
assert_rule_health() {
    rule_json="$1"
    expected="$2"
    ctx="$3"
    health=$(echo "$rule_json" | jq -r '.health // "missing"')
    if [ "$health" != "$expected" ]; then
        {
            echo "FAIL: $ctx - expected health '$expected', got '$health'"
            echo "  state:     $(echo "$rule_json" | jq -r '.state // "missing"')"
            echo "  lastError: $(echo "$rule_json" | jq -r '.lastError // ""')"
            echo "  instances: $(echo "$rule_json" | jq -c '[.alerts[]? | {labels, state}]')"
        } >&2
        FAIL=1
    fi
}

# For rules whose query has no string identity column (e.g. a single-row
# aggregate), Grafana produces exactly one unlabeled alert instance - unlike
# find_instance()/assert_instance_state(), which match on device/sensor_type
# labels that don't exist here.
assert_single_instance_state() {
    rule_json="$1"
    expected="$2"
    ctx="$3"
    count=$(echo "$rule_json" | jq '[.alerts[]?] | length')
    if [ "$count" != "1" ]; then
        echo "FAIL: $ctx - expected exactly 1 alert instance, found $count"
        FAIL=1
        return
    fi
    state=$(echo "$rule_json" | jq -r '.alerts[0].state')
    if [ "$state" != "$expected" ]; then
        echo "FAIL: $ctx - expected instance state '$expected', got '$state'"
        FAIL=1
    fi
}

check_contact_point() {
    response=$(curl -sf --max-time "$CURL_MAX_TIME" -u "$GRAFANA_AUTH" "$CONTACT_POINTS_URL")
    chatid_type=$(echo "$response" | jq -r '[.[] | select(.name == "GardenHub Telegram")][0].settings.chatid | type')

    echo "Telegram contact point chatid JSON type: $chatid_type"
    if [ "$chatid_type" != "string" ]; then
        echo "FAIL: Telegram chatid decoded as '$chatid_type' instead of 'string'"
        FAIL=1
    fi
    # This only proves provisioning parsed and stored the settings correctly.
    # It does NOT prove a Telegram message is actually delivered.
}

echo "=== Scenario 1: one active sensor + one silent sensor ==="
run_sql /tests/seed-active-and-silent.sql

rule=$(wait_for_instance_state "$DEVICE_SILENT" "SE01-Test" "soil_temperature" "Alerting" "$DEVICE_SILENT_TIMEOUT")
assert_instance_state "$rule" "SE01-Test" "soil_temperature" "Alerting" "device-silent scenario 1 (stale sensor must fire)"
assert_instance_state "$rule" "SE01-Test" "soil_moisture" "Normal" "device-silent scenario 1 (active sensor must stay normal)"
assert_rule_state "$NO_MEASUREMENTS" inactive "no-measurements scenario 1 (recent measurement exists)"

echo "=== Scenario 2: global silence (no measurements in the last 60 minutes) ==="
run_sql /tests/seed-global-silence.sql

rule=$(wait_for_instance_state "$DEVICE_SILENT" "SE01-Test" "soil_temperature" "Alerting" "$DEVICE_SILENT_TIMEOUT")
assert_instance_state "$rule" "SE01-Test" "soil_temperature" "Alerting" "device-silent scenario 2"
rule=$(wait_for_instance_state "$DEVICE_SILENT" "SE01-Test" "soil_moisture" "Alerting" "$DEVICE_SILENT_TIMEOUT")
assert_instance_state "$rule" "SE01-Test" "soil_moisture" "Alerting" "device-silent scenario 2"

rule=$(wait_for_rule_state "$NO_MEASUREMENTS" firing "$NO_MEASUREMENTS_TIMEOUT")
assert_rule_health "$rule" ok "no-measurements scenario 2 (must be a genuine breach, not eval error/No Data)"
assert_single_instance_state "$rule" Alerting "no-measurements scenario 2"

echo "=== Telegram contact point provisioning ==="
check_contact_point

if [ "$FAIL" -ne 0 ]; then
    echo "RESULT: FAIL"
    exit 1
fi
echo "RESULT: PASS"
