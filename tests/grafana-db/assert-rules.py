"""Exercise provisioned alert rules against real Grafana and disposable MySQL."""

import base64
import json
import subprocess
import time
import urllib.request


URL = "http://127.0.0.1:13000"
AUTH = base64.b64encode(b"admin:test-only").decode("ascii")


def get(path):
    request = urllib.request.Request(URL + path, headers={"Authorization": "Basic " + AUTH})
    with urllib.request.urlopen(request, timeout=10) as response:
        return json.load(response)


def rules():
    result = {}
    for group in get("/api/prometheus/grafana/api/v1/rules")["data"]["groups"]:
        for rule in group["rules"]:
            uid = rule.get("uid")
            if uid in ("gardenhub-device-silent", "gardenhub-no-measurements"):
                result[uid] = rule
    return result


def wait_for(check, description, timeout=150):
    deadline = time.monotonic() + timeout
    last = None
    while time.monotonic() < deadline:
        last = rules()
        if check(last):
            print("PASS", description)
            return
        time.sleep(5)
    raise AssertionError(f"{description}: {json.dumps(last, indent=2)[:5000]}")


def healthy(ruleset):
    return len(ruleset) == 2 and all(
        rule.get("health", "").lower() == "ok" for rule in ruleset.values()
    )


def sensor_instances(ruleset):
    return ruleset["gardenhub-device-silent"].get("alerts", [])


wait_for(healthy, "both rules evaluate without errors")


def sensor_labels_ok(ruleset):
    if not healthy(ruleset):
        return False
    instances = sensor_instances(ruleset)
    ids = {a.get("labels", {}).get("sensor_id") for a in instances}
    silent = [a for a in instances if a.get("labels", {}).get("sensor_id") in ("2", "3")]
    return (
        {"2", "3"}.issubset(ids)
        and ids.issubset({"1", "2", "3"})
        and len(instances) == len(ids)
        and all(a.get("labels", {}).get("device") for a in instances)
        and all(a.get("labels", {}).get("sensor_type") for a in instances)
        and all("[no value]" not in str(a.get("annotations", {})) for a in silent)
    )


wait_for(sensor_labels_ok, "distinct sensor labels, no never-reported sensor")


def global_normal(ruleset):
    if not healthy(ruleset):
        return False
    alerts = ruleset["gardenhub-no-measurements"].get("alerts", [])
    return not any(a.get("state", "").lower() in ("pending", "firing", "alerting") for a in alerts)


wait_for(global_normal, "global alert normal with a recent measurement")

subprocess.run(
    ["docker", "compose", "-f", "tests/grafana-db/compose.yaml", "exec", "-T",
     "gardenhub-mysql", "mysql", "-uroot", "gardenhub_test", "-e",
     "DELETE FROM measurement WHERE sensor_id = 1"],
    check=True,
)


def global_pending(ruleset):
    if not healthy(ruleset):
        return False
    return any(
        a.get("state", "").lower() in ("pending", "firing", "alerting")
        for a in ruleset["gardenhub-no-measurements"].get("alerts", [])
    )


wait_for(global_pending, "global alert enters pending when all recent measurements disappear")
