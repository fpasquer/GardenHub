# GardenHub 🌱

GardenHub is a local, self-hosted IoT backend for monitoring and eventually automating a connected garden.

The platform is designed to remain fully functional without cloud services. It consumes LoRaWAN sensor uplinks from ChirpStack through MQTT, persists normalized measurements in MySQL, exposes them through a Symfony/API Platform REST API, and visualizes them in Grafana.

The production deployment runs on a single local server (`gardenhub-server`) while keeping the LoRaWAN infrastructure and the GardenHub application as separate Docker Compose projects.

---

## Current Production Architecture

Production host:

```text
gardenhub-server
192.168.1.20
Ubuntu Server 26.04 LTS
Docker + Docker Compose
```

Directory layout:

```text
/opt/
├── lorastack/
│   ├── docker-compose.yml
│   ├── .env
│   └── configuration/
│
├── gardenhub/
│   ├── compose.yaml
│   ├── compose-dev.yaml
│   ├── .env
│   ├── api/
│   └── grafana/
│
└── scripts/
    └── backup/

/mnt/backup/
└── gardenhub-server/
    ├── daily/
    └── monthly/
```

High-level data flow:

```text
SE01-Avocado
     │
     │ LoRaWAN
     ▼
Dragino LPS8N
192.168.1.18
     │
     │ Semtech UDP / 1700
     ▼
┌──────────────────────────────────────────────┐
│ gardenhub-server — 192.168.1.20              │
│                                              │
│  LoRaStack                                   │
│  ┌────────────────────────────────────────┐  │
│  │ ChirpStack              :8080          │  │
│  │ Gateway Bridge          :1700/udp      │  │
│  │ Mosquitto               Docker only    │  │
│  │ PostgreSQL              Docker only    │  │
│  │ Redis                   Docker only    │  │
│  └────────────────────────────────────────┘  │
│                    │                         │
│                    │ MQTT                    │
│                    │ shared Docker network   │
│                    ▼                         │
│  GardenHub                                   │
│  ┌────────────────────────────────────────┐  │
│  │ MQTT Worker                            │  │
│  │ Symfony / API Platform                 │  │
│  │ MySQL                   Docker only    │  │
│  │ nginx                   :8081          │  │
│  │ Grafana                 :3000          │  │
│  └────────────────────────────────────────┘  │
└──────────────────────────────────────────────┘
```

The LoRaStack and GardenHub repositories remain logically independent, but in production they share the same physical host.

---

## Current Status

### Implemented

- Docker Compose based deployment.
- PHP 8.4 / Symfony 7.4 LTS backend.
- API Platform 4.3+.
- MySQL 8.4 persistent storage.
- Doctrine ORM and Doctrine Migrations.
- Domain model:
  - `Device`
  - `Sensor`
  - `Measurement`
  - `ApiClient`
- MQTT ingestion from ChirpStack.
- Automatic device provisioning from ChirpStack `devEui`.
- Automatic sensor provisioning from mapped payload fields.
- Symfony Messenger based ingestion pipeline.
- API key authentication.
- API filtering and pagination.
- Grafana 12 with a provisioned MySQL datasource and Garden overview dashboard.
- HTTP health endpoint for nginx → Symfony → Doctrine → MySQL.
- Persistent Docker volumes.
- Automated backups to a dedicated external SSD.
- Daily and monthly backup retention.
- Hardened local-network deployment.

### Planned

- Extended domain model:
  - `Garden`
  - `GardenBed`
  - `Plant`
  - `IrrigationEvent`
  - `Alert`
- Automation rules.
- Telegram alerts.
- Frontend.
- Optional HTTPS on the LAN.
- Additional devices and sensors.
- AI-assisted analysis where useful.

---

# GardenHub Stack

GardenHub contains five main services:

```text
gardenhub-nginx
gardenhub-api
gardenhub-worker
gardenhub-mysql
gardenhub-grafana
```

## `gardenhub-nginx`

nginx is the HTTP entry point for the GardenHub API.

Production binding:

```text
192.168.1.20:8081
```

Dynamic PHP requests are forwarded to `gardenhub-api` over FastCGI.

Static files are served from `api/public`.

## `gardenhub-api`

Symfony / API Platform backend running with PHP-FPM.

Responsibilities:

- REST API
- validation
- serialization
- Doctrine persistence
- API authentication
- health checks
- domain/application logic

The container is not directly exposed on the host.

## `gardenhub-worker`

Long-running Symfony command:

```bash
php bin/console gardenhub:mqtt:consume
```

Responsibilities:

- connect to Mosquitto with a **persistent session** (stable `MQTT_CLIENT_ID`)
- subscribe to ChirpStack uplinks at **QoS 1** (at-least-once from the broker)
- decode messages; malformed JSON is logged explicitly and discarded
- dispatch `ChirpStackUplink` messages to the **durable Doctrine transport**
  (`messenger_messages` table, `queue_name=async`)

The MQTT worker no longer persists measurements itself; it only parses the
MQTT envelope and enqueues a Messenger message. If the enqueue fails (e.g.
MySQL is briefly unavailable), the worker interrupts the MQTT loop and
reconnects after five seconds. Note the documented loss window: Mosquitto may
already have PUBACKed the message before the enqueue fails, so that specific
message can be lost. This is the accepted tradeoff of the current
php-mqtt/client integration.

After every MQTT callback, the command explicitly invokes Symfony's service
resetter. This clears Doctrine's identity map and pending unit-of-work state,
Doctrine SQL profiling/backtraces in debug mode, and other registered resettable
services such as buffered logging and Messenger tracing when enabled. This
custom synchronous loop does not run Messenger's standard worker reset hooks.

MQTT client failures are logged as connection failures and retried after five
seconds. Message-processing, persistence, or cleanup failures interrupt the MQTT
loop and return exit code 1, allowing the existing `restart: unless-stopped`
policy to start a fresh process with a usable entity manager. The callback must
signal failure explicitly because the MQTT library otherwise catches callback
exceptions. A failed worker cannot remain alive indefinitely with a closed
entity manager while passing the process-only health check.

### Delivery guarantees

The durability boundary is the **successful insertion of `ChirpStackUplink`
into `messenger_messages`**. From that point on, Symfony Messenger provides
at-least-once processing:

- **Retry policy:** 3 retries after the initial attempt (4 attempts total),
  60s initial delay, 2x multiplier, 10% jitter. Expected worst-case delay
  before failure transport: ~7 minutes (60s + 120s + 240s + processing).
- **Failure transport:** after retry exhaustion, messages move to the same
  `messenger_messages` table with `queue_name=failed`. They are inspectable
  and retryable, not lost.
- **Malformed MQTT JSON:** logged with topic, payload length, and a
  non-sensitive preview; discarded without creating a Messenger message.
- **Per-measurement validation:** invalid measurements are skipped and logged;
  valid measurements from the same uplink still persist.
- **Idempotent storage:** each measurement carries the ChirpStack
  `deduplicationId` and its type, with a unique `(deduplication_id, type)`
  index. A replayed uplink is stored **at most once per `(deduplicationId,
  type)`**, with bounded retries. See [Idempotency](#idempotency).

**External requirement:** the GardenHub-side QoS 1 subscription only buffers
messages during worker downtime if ChirpStack publishes application events at
QoS 1. Verify the ChirpStack MQTT integration configuration (`qos = 1`).
Mosquitto must also run with `persistence true` and a `persistence_location`
for session state to survive broker restarts.

### Idempotency

ChirpStack attaches a unique `deduplicationId` UUID to every uplink event.
GardenHub stores it on each measurement row together with a denormalized
measurement `type`, and enforces a database unique index on
`(deduplication_id, type)`.

Guarantee: **at most one stored row per `(deduplicationId, type)`, with bounded
retries.** This is not exactly-once delivery — if a message exhausts its retries
it lands in the `failed` queue and must be retried or resolved manually.

How it works:

- **Pre-check / top-up:** before persisting, the handler queries which of the
  event's measurement types are already stored for this `deduplicationId` and
  only inserts the missing ones. A fully replayed uplink therefore no-ops.
- **Concurrent collision:** if two consumers process the same event at once and
  both pass the pre-check, one flush hits the unique index and throws. The
  exception is left to propagate into Messenger's bounded retry; on retry the
  pre-check finds the committed rows and completes only the missing types.
- **Malformed events:** an uplink with a missing or invalid `deduplicationId`
  is logged and discarded at the MQTT boundary, same as other malformed events.
- **API creation:** `POST /measurements` accepts a client-supplied
  `deduplicationId` (validated as a UUID). `type` is derived from the sensor
  server-side and is read-only over the API.

### Deploying the idempotency migration

The migration makes `deduplication_id`/`type` mandatory and adds the unique
index. Queued `ChirpStackUplink` messages serialized by the old code lack
`deduplicationId` and would crash the new handler, so they must be drained by
the **old** code first. Because `api/src` is bind-mounted into the containers,
pulling new code before draining would change the code the drain consumer runs
— so code is pulled only after the queues are empty and the drain consumer is
stopped.

**Important:** `gardenhub-mysql` is never stopped by this procedure — only the
writer services are — so the two mysql commands below use `docker compose exec`
against that already-running container; never `exec` into a stopped service.
Every other CLI step runs in a **one-off container**
(`docker compose run --rm --no-deps`), which starts its own throwaway container
using the *currently checked-out* code. `--no-deps` explicitly prevents Compose
from starting that command's dependencies (e.g. `gardenhub-mysql`) as a side
effect — it is not an optional precaution — so the writer services stay
stopped for the whole procedure.

```bash
# 1. Stop MQTT ingestion and HTTP write access (measurement writers).
docker compose stop gardenhub-worker gardenhub-consumer gardenhub-api gardenhub-nginx

# 2. Drain async with the OLD code, in a one-off container, until empty.
#    The command exits on its own once the queue is drained and the time limit
#    elapses; --time-limit keeps it from running forever.
docker compose run --rm --no-deps gardenhub-api \
  php bin/console messenger:consume async --time-limit=120

# 2a. If retries are scheduled in the future, fast-forward them (scoped to the
#     async queue) and drain again until `messenger:stats` shows 0 pending.
#     Runs inside the already-running gardenhub-mysql container so the client
#     reaches the real server (not a fresh container's empty local socket);
#     the single-quoted sh -c defers $MYSQL_ROOT_PASSWORD/$MYSQL_DATABASE
#     expansion to that container's own environment, not the host shell.
docker compose exec gardenhub-mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = \"async\" AND delivered_at IS NULL;"'
docker compose run --rm --no-deps gardenhub-api \
  php bin/console messenger:consume async --time-limit=120

# 3. Resolve failed messages with the OLD code: retry them so their
#    measurements are stored. Do NOT failed:remove as a routine step — a failed
#    message may hold data you still need. If a message cannot be resolved,
#    STOP and investigate before continuing the deployment.
docker compose run --rm --no-deps gardenhub-api \
  php bin/console messenger:failed:retry --force

# 4. Verify BOTH queues are truly empty (including delayed/delivered rows).
docker compose exec gardenhub-mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT queue_name, COUNT(*) AS n FROM messenger_messages GROUP BY queue_name;"'
#    Expect: no rows for 'async' or 'failed'. If any remain, pause here.

# 5. Stop the drain consumer before touching code (it is already stopped — the
#    one-off containers exited). NOW pull the new code and rebuild the image.
git pull
docker compose build gardenhub-api

# 6. Run the migration using the NEW code (writers still stopped).
docker compose run --rm --no-deps gardenhub-api \
  php bin/console doctrine:migrations:migrate --no-interaction

# 7. Start the updated services.
docker compose up -d gardenhub-worker gardenhub-consumer gardenhub-api gardenhub-nginx
```

Legacy rows are backfilled: `type` is copied from the owning sensor and each
row gets a unique `deduplication_id` (random UUID), so the new index cannot
collide on existing data. Historical duplicates are left as-is.

## `gardenhub-consumer`

Long-running Symfony command:

```bash
php bin/console messenger:consume async --time-limit=3600
```

Responsibilities:

- dequeue `ChirpStackUplink` messages from the Doctrine `async` queue
- auto-provision devices and sensors
- validate and persist measurements
- ack/requeue messages according to the retry policy

The consumer recycles itself every hour (`--time-limit=3600`) as routine
maintenance of a long-running PHP process. The existing
`restart: unless-stopped` policy starts a fresh consumer automatically.

### Operating the queues

Inspect queued messages:

```bash
docker compose exec gardenhub-api php bin/console messenger:stats
docker compose exec gardenhub-mysql mysql -uroot -p \
  -e "SELECT queue_name, COUNT(*) FROM gardenhub.messenger_messages GROUP BY queue_name;"
```

Inspect failed messages:

```bash
docker compose exec gardenhub-api php bin/console messenger:failed:show
```

Retry a failed message after fixing the underlying problem:

```bash
docker compose exec gardenhub-api php bin/console messenger:failed:retry <id> --force
```

Remove a permanently bad failed message:

```bash
docker compose exec gardenhub-api php bin/console messenger:failed:remove <id> --force
```

Behavior during outages:

| Component down | Behavior |
|---|---|
| `gardenhub-worker` | Mosquitto queues QoS 1 messages for the persistent session; the worker receives them after reconnect (if ChirpStack publishes at QoS 1). |
| `gardenhub-consumer` | Messages accumulate in `messenger_messages` with `queue_name=async`; the consumer processes the backlog after restart. |
| `gardenhub-mysql` | Worker enqueue fails → MQTT loop interrupted → reconnect loop. Messages PUBACKed during the outage may be lost (documented loss window). Already-queued messages retry automatically per the retry policy. |

Focused regressions use the existing `gardenhub-api` image and disposable MySQL
and MQTT services on a separate Compose network, without production data or
credentials:

```bash
docker compose -f tests/mqtt-lifecycle/compose.yaml run --rm tests
docker compose -f tests/mqtt-lifecycle/compose.yaml down -v
```

Build the API image first if it is not available. Tests cover repeated uplinks,
validation rejection and pending-state cleanup, SQL debug-state cleanup, real
MySQL flush failures, nonzero worker exits, persistence in a fresh PHP process,
cleanup failures, and distinct MQTT reconnect behavior. Each child worker has
a 30-second timeout. They do not interrupt the deployed worker or exercise
Docker's live restart/health transition.

The worker is connected to both:

```text
gardenhub
iot_iot-lan
```

This allows GardenHub to reach Mosquitto directly through Docker DNS.

Production MQTT host:

```text
mosquitto
```

MQTT is **not exposed on the host LAN**.

## `gardenhub-mysql`

MySQL 8.4 database.

Persistent volume:

```text
gardenhub-mysql-data
```

MySQL is not published on the host network.

## `gardenhub-grafana`

Grafana 12 dashboard service.

Production binding:

```text
192.168.1.20:3000
```

Grafana reads MySQL directly through the internal GardenHub Docker network.

Anonymous access and user self-registration are disabled.

Grafana Alerting is provisioned from `grafana/provisioning/alerting` and sends
notifications through the same Telegram bot and chat configured for Symfony
(`TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` in the root Compose environment).
`TELEGRAM_ENABLED` only controls Symfony's Monolog channel; Grafana evaluates
and sends its own alerts independently.

Both `bottoken` and `chatid` are plain (non-secure) contact point settings —
Grafana 12.1's file-provisioning schema has no secure-setting mechanism for
any contact point type, so the bot token unavoidably lands on disk in
plaintext. Because Grafana also unconditionally coerces any `$VAR`-substituted
setting that looks numeric into a JSON number — crashing `chatid`, which must
stay a string, regardless of YAML quoting — the container's entrypoint is
overridden to `grafana/docker/render-provisioning.sh`, which resolves
`$TELEGRAM_CHAT_ID`/`$TELEGRAM_BOT_TOKEN` itself and writes an
already-resolved, quoted `contact-points.yaml` before Grafana's own
provisioning loader ever parses it. That script fails fast if either variable
is unset and restricts the rendered directory (`700`) and files (`600`) to
the container's own user, since file permissions are the only protection
available for the token.

The initial rules are:

- **GardenHub device silent** — fires per sensor that has previously reported
  but has no measurement stored in the previous 60 minutes, after one
  additional minute of confirmation. Each firing instance is uniquely
  identified by `device`/`sensor_type` labels (one row per sensor from a
  `format: table` query — required so Grafana can tell sensors apart; a
  `time_series` query with a synthetic "metric" column collapses every sensor
  into the same empty label set and fails evaluation). It checks `created_at`
  (when GardenHub stored the reading), not the device's measurement
  timestamp. Sensors that have never reported are covered by the
  platform-wide alert below.
- **GardenHub no measurements received** — fires when no uplink event was
  stored for any device during the previous 60 minutes, after five minutes of
  confirmation. The underlying query also uses `format: table` (a single
  reduced row); Grafana rejects a raw `time_series` result here with
  "looks like time series data, only reduced data can be alerted on".

Both rules are evaluated every minute and notify the `GardenHub Telegram`
contact point, including a notification when the condition resolves.

Compose only re-injects environment variables when a container is
**recreated**, not on a plain restart, and bind-mounted files (everything
under `grafana/provisioning/`) have no effect on Compose's own config-diff
detection at all — so a plain `docker compose up -d gardenhub-grafana` only
reliably recreates the container for an env var change, never for a
provisioning-file-only edit.

Production `make deploy` handles both cases automatically: its
`deploy-grafana` step always force-recreates `gardenhub-grafana` (without
rebuilding the image or touching `gardenhub-mysql`) and waits for it to
report healthy, so every deploy applies the latest `grafana/provisioning/`
content and any changed Telegram env vars, whether or not Compose would have
detected a config diff on its own.

For a manual/dev redeploy, force recreation explicitly rather than relying on
a plain `up -d`:

```bash
docker compose up -d --force-recreate gardenhub-grafana
docker compose logs --tail=100 gardenhub-grafana
```

`docker compose restart gardenhub-grafana` reuses the existing container and
its already-injected environment, so it will not pick up either kind of
change.

### Testing Grafana alerts

`tests/grafana-db/` runs the pinned Grafana image against this repo's actual,
unmodified provisioning files and a disposable MySQL database, seeding
representative devices/sensors/measurements (an active sensor, a silent one on
the same device, and a global-silence scenario). It bounded-polls each rule's
Prometheus-style state through Grafana's rules API and, once a rule reaches
the expected state, asserts every individual alert instance's own state by
`device`/`sensor_type` labels — confirming, for example, that the silent
sensor's instance fires while its still-active sibling's instance stays
Normal, rather than assuming a non-firing sensor is simply absent from the
response (Grafana lists every tracked instance regardless of state). It also
asserts that the Telegram contact point's `chatid` decodes as a string:

```bash
docker compose -f tests/grafana-db/compose.yaml up --abort-on-container-exit --exit-code-from tests
docker compose -f tests/grafana-db/compose.yaml down -v
```

This does not send a real Telegram message — Grafana's Telegram integration
has no configurable API endpoint to intercept, so real delivery still
requires manual verification with real credentials (see
[Telegram Logging](#telegram-logging)).

The active root `.env` (production) or `.env.local` (development, loaded by
the Makefile) must provide the intended bot token and chat ID. Keep separate
credentials or chats for development and production if you do not want test
alerts sent to the production conversation.

---

# LoRaStack Integration

The LoRaWAN infrastructure lives in the separate `lorastack-pi` project, deployed on the same production server under:

```text
/opt/lorastack
```

It contains:

- ChirpStack
- ChirpStack Gateway Bridge
- Mosquitto
- PostgreSQL
- Redis

Production bindings:

| Service | Binding |
|---|---|
| ChirpStack | `192.168.1.20:8080` |
| Gateway Bridge | `192.168.1.20:1700/udp` |
| Mosquitto | Docker networks only |
| PostgreSQL | Docker internal only |
| Redis | Docker internal only |

The LPS8N gateway is configured to forward Semtech UDP packets to:

```text
192.168.1.20:1700
```

Gateway EUI:

```text
a84041ffff2e1fc0
```

Current device:

```text
SE01-Avocado
DevEUI: a84041b04f5a93be
```

---

# MQTT

The GardenHub worker subscribes to ChirpStack uplink events.

Default topic:

```text
application/+/device/+/event/up
```

Configured in:

```text
api/.env
```

Example:

```dotenv
MQTT_CLIENT_ID=gardenhub
MQTT_TOPIC="application/+/device/+/event/up"
```

Production credentials are provided through the root `.env` file:

```dotenv
MQTT_HOST=mosquitto
MQTT_PORT=1883
MQTT_USERNAME=symfony
MQTT_PASSWORD=<secret>
```

The `symfony` Mosquitto account is restricted by the Mosquitto ACL.

Because GardenHub and Mosquitto communicate through the shared Docker network, port `1883` does not need to be published on the A6 host.

---

# Payload Mapping

Payload fields are mapped through:

```text
api/config/services.yaml
```

Current SE01 mapping:

| Payload field | Sensor type | Unit |
|---|---|---|
| `BatV` | `battery` | V |
| `water_SOIL` | `soil_moisture` | % |
| `temp_SOIL` | `soil_temperature` | °C |
| `temp_DS18B20` | `air_temperature` | °C |
| `conduct_SOIL` | `soil_conductivity` | µS/cm |

Unknown payload fields are ignored.

Non-numeric values are skipped.

Devices and sensors are automatically provisioned on first sight.

---

# Database Model

Current MySQL tables include:

```text
api_client
device
doctrine_migration_versions
measurement
sensor
```

Doctrine migrations are applied with:

```bash
docker compose exec gardenhub-api php bin/console doctrine:migrations:migrate --no-interaction
```

Measurements are persisted in MySQL and survive:

- container recreation
- Docker restart
- server reboot

because MySQL uses the persistent volume:

```text
gardenhub-mysql-data
```

---

# Getting Started

## Prerequisites

- Docker
- Docker Compose v2+
- Git

No host installation of PHP, Composer, Symfony CLI, nginx or MySQL is required.

## Clone

```bash
git clone <repository-url> GardenHub
cd GardenHub
```

## Environment

Never commit production secrets.

The deployment uses a root `.env` file for Compose variables (`/opt/gardenhub/.env` in this repository's layout). Plain `docker compose` commands only read this single file automatically; they do **not** merge an untracked `.env.local` on their own. Only this repository's `Makefile` does that, via explicit `--env-file` flags:

```make
COMPOSE := docker compose --env-file .env
ifneq ($(wildcard .env.local),)
COMPOSE += --env-file .env.local
endif
```

Use `make start` (base stack) or `make dev` (base stack + `compose-dev.yaml`) to get this behavior. To reproduce it with plain Compose, pass both files explicitly:

```bash
docker compose --env-file .env --env-file .env.local -f compose.yaml -f compose-dev.yaml up -d --build
```

Whichever mechanism is used, the resulting values are injected as real container environment variables, which always take precedence over `api/.env` — Symfony's own tracked, safe-placeholder fallback file used only when running the app outside Docker.

Example development values:

```dotenv
APP_ENV=dev

APP_PORT=8080
GRAFANA_PORT=3000

MYSQL_DATABASE=gardenhub
MYSQL_USER=gardenhub
MYSQL_PASSWORD=<password>
MYSQL_ROOT_PASSWORD=<root-password>

MQTT_HOST=mosquitto
MQTT_PORT=1883
MQTT_USERNAME=<username>
MQTT_PASSWORD=<password>

TELEGRAM_ENABLED=false
TELEGRAM_BOT_TOKEN=<bot-token>
TELEGRAM_CHAT_ID=<chat-id>
TELEGRAM_MIN_LEVEL=warning
```

Grafana uses `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` directly for alerting;
the Symfony-only `TELEGRAM_ENABLED` switch does not disable Grafana alerts.

Production on `gardenhub-server` currently uses:

```text
GardenHub API: 192.168.1.20:8081
Grafana:       192.168.1.20:3000
ChirpStack:    192.168.1.20:8080
```

Do not commit `.env` or `.env.local`.

To verify a Telegram configuration with real credentials, put them in the untracked root `.env.local` (never in `.env`), recreate the stack with `make dev` (or the explicit `--env-file` command above), and run the test command described in [Telegram Logging](#telegram-logging).

## Build

```bash
docker compose build
```

## Start

```bash
docker compose up -d
```

## Run migrations

```bash
docker compose exec gardenhub-api php bin/console doctrine:migrations:migrate --no-interaction
```

## Check containers

```bash
docker compose ps
```

Expected GardenHub services:

```text
gardenhub-nginx
gardenhub-api
gardenhub-worker
gardenhub-mysql
gardenhub-grafana
```

Services with health checks should become `healthy`.

---

# API

Production API base URL:

```text
http://192.168.1.20:8081
```

Endpoints:

```text
/api
/api/docs
/api/devices
/api/sensors
/api/measurements
/healthz
```

Examples:

```bash
curl http://192.168.1.20:8081/healthz
```

Authenticated API request:

```bash
curl \
  -H "Authorization: Bearer gh_..." \
  http://192.168.1.20:8081/api/devices
```

## API clients

Read/write client:

```bash
docker compose exec gardenhub-api php bin/console gardenhub:api-client:create my-client
```

Read-only client:

```bash
docker compose exec gardenhub-api php bin/console gardenhub:api-client:create grafana --readonly
```

The API key is displayed once.

Only its SHA-256 hash is persisted.

---

# Filtering and Pagination

Examples:

```text
/api/devices?name=SE01

/api/sensors?device=/api/devices/1

/api/sensors?type=soil_moisture

/api/measurements?sensor=/api/sensors/5

/api/measurements?measuredAt[after]=2026-08-20

/api/measurements?measuredAt[before]=2026-08-24

/api/measurements?value[gte]=20

/api/measurements?order[measuredAt]=desc&page=2
```

---

# Grafana

Production URL:

```text
http://192.168.1.20:3000
```

The GardenHub dashboard and MySQL datasource are provisioned from:

```text
grafana/provisioning/
grafana/dashboards/
```

Grafana security configuration includes:

```yaml
GF_USERS_ALLOW_SIGN_UP: "false"
GF_AUTH_ANONYMOUS_ENABLED: "false"
```

The initial admin password must be set through environment configuration and changed after first login.

Grafana data is stored in:

```text
gardenhub-grafana-data
```

---

# Development Environment

Adminer is available only in the development Compose overlay:

```bash
docker compose -f compose.yaml -f compose-dev.yaml up -d
```

Do not run `compose-dev.yaml` in production.

The API source directories are bind-mounted, so most PHP source changes are immediately visible inside the running development container.

Rebuild after dependency changes:

```bash
docker compose up -d --build
```

---

# MQTT Simulation

A ChirpStack uplink can be simulated without a broker:

```bash
docker compose exec gardenhub-api \
  php bin/console gardenhub:mqtt:simulate \
  a84041a1c182b3e0 \
  '{"water_SOIL":"25.34","temp_SOIL":"21.06"}'
```

This uses the same Messenger ingestion path as a real MQTT message.

---

# Telegram Logging

A dedicated Monolog `telegram` channel can forward log records to a Telegram chat. Two narrow, hardcoded backend alerts (see [Backend alerts](#backend-alerts)) send `critical` records through this channel. Grafana independently sends the provisioned sensor-silence and no-measurement alerts described under [`gardenhub-grafana`](#gardenhub-grafana).

## Configuration

Four environment variables control it (see [Environment](#environment)):

| Variable              | Default   | Purpose                                   |
| --------------------- | --------- | ------------------------------------------ |
| `TELEGRAM_ENABLED`    | `false`   | Enables the channel                        |
| `TELEGRAM_BOT_TOKEN`  | *(empty)* | Telegram bot token                         |
| `TELEGRAM_CHAT_ID`    | *(empty)* | Target chat id                             |
| `TELEGRAM_MIN_LEVEL`  | `warning` | Minimum PSR-3 level forwarded              |

Set real values only in the untracked root `.env.local` (dev) or the production server's own root `.env` — never in a committed file. Use a **separate bot/chat per environment**; every message is prefixed with the app name and `APP_ENV` so the source is always clear.

## Usage

Inject the channel logger with the `WithMonologChannel` attribute:

```php
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

#[WithMonologChannel('telegram')]
class MyService
{
    public function __construct(private readonly LoggerInterface $logger) {}
}
```

Pass fully-formed strings — the channel does not support PSR-3 `{placeholder}` interpolation, and only the message itself is sent (never the context/extra arrays).

## Manual verification

```bash
docker compose exec gardenhub-api php bin/console gardenhub:telegram:test
```

This reports whether a record was *dispatched*, never *delivered*: delivery failures are swallowed by design (see below), so check the target Telegram chat to confirm receipt.

Run the automated test scripts (dev stack only, requires the `compose-dev.yaml` bind mount):

```bash
docker compose -f compose.yaml -f compose-dev.yaml run --rm gardenhub-api php tests/telegram_handler_test.php
docker compose -f compose.yaml -f compose-dev.yaml run --rm gardenhub-api php tests/stream_telegram_transport_test.php
```

The second script exercises `StreamTelegramTransport` itself (HTTP errors, invalid JSON, `ok:false`, connection failures, and repeated calls) against a local fake server — no real Telegram access involved.

## Daily summary

`gardenhub:telegram:daily-summary` sends a compact rolling summary of ChirpStack
uplink events to Telegram:

```bash
docker compose exec gardenhub-api php bin/console gardenhub:telegram:daily-summary
docker compose exec gardenhub-api php bin/console gardenhub:telegram:daily-summary --hours=12
```

`--hours` defaults to `24` and must be a positive integer; zero, negative,
non-numeric or malformed values (e.g. `abc`, `12abc`, `1.5`) exit with
`Command::INVALID` and never send anything.

The header counts **distinct ChirpStack uplink events**
(`COUNT(DISTINCT deduplication_id)`), not measurement rows — a single uplink
can produce several rows (one per sensor type), so the event count is
normally lower than the row count. Per-sensor rows show the **min → max**
value observed in the window (not first/latest), grouped by device:

```
🌱 24h · 72 events

SE01-Avocado
Moisture      25 → 31.2 %
Temperature 22.9 → 23.4 °C
```

An empty window reports just `🌱 24h · 0 events`, with no table.

This command runs automatically every day at **22:00 UTC**, dispatched by
[Symfony Scheduler](https://symfony.com/doc/current/scheduler.html) via a
single `#[AsCronTask]` attribute on `TelegramDailySummaryCommand` — no other
application class or `Kernel.php` change is involved; FrameworkBundle wires
Scheduler support natively once `symfony/scheduler` is installed. See
[`gardenhub-scheduler`](#gardenhub-scheduler) below for the worker that runs
it and how to opt in.

Run the isolated test suites (own disposable MySQL container, never the dev database):

```bash
docker compose -f tests/telegram-summary/compose.yaml run --rm tests
docker compose -f tests/telegram-summary/compose.yaml down -v

docker compose -f tests/scheduler-cron/compose.yaml run --rm tests
docker compose -f tests/scheduler-cron/compose.yaml down -v
```

## `gardenhub-scheduler`

Long-running Symfony command:

```bash
php bin/console messenger:consume scheduler_default --no-interaction -v
```

Responsibilities:

- polls the Symfony Scheduler `default` schedule and dispatches each due message
- currently runs the single `gardenhub:telegram:daily-summary --hours=24` task, scheduled at 22:00 UTC via the `#[AsCronTask]` attribute on `TelegramDailySummaryCommand` (added by `composer require symfony/scheduler dragonmantank/cron-expression`)

Not started by a plain `docker compose up` (including `make dev`): the
service is gated behind the `scheduler` Compose profile, so it must be
opted into explicitly. Unlike the other long-running services (which run as
root), it runs as `www-data`.

No missed-run catch-up: if the worker is down when 22:00 UTC passes, that
day's summary is simply skipped — it does not fire retroactively on the next
start. Only one `gardenhub-scheduler` instance should run at a time; nothing
here provides distributed locking or exactly-once delivery, so running more
than one instance would send duplicate summaries.

Start it (dev or prod) and follow its logs:

```bash
docker compose --profile scheduler up -d gardenhub-scheduler
docker compose logs -f gardenhub-scheduler
```

Production deployment (`make deploy` already includes `gardenhub-scheduler`
in `DEPLOY_SERVICES`, so this is normally automatic; documented here for
reference/manual use):

```bash
docker compose build gardenhub-api
docker compose --profile scheduler up -d gardenhub-scheduler
docker compose logs -f gardenhub-scheduler
```

## Failure isolation

Telegram delivery is best-effort: any failure (missing configuration, network error, API error) is logged to the normal application logs instead and never propagates, so Telegram outages can never break requests, MQTT ingestion, or Messenger processing. The connection has a 5-second **read** timeout (bounds the connection and each read), not a guaranteed total delivery deadline.

## Backend alerts

Two hardcoded, backend-triggered `critical` alerts ride the `telegram` channel above (they clear the production `TELEGRAM_MIN_LEVEL=critical` threshold). Neither has its own entity, migration, or configuration — both are plain `LoggerInterface::critical()` calls, so [Failure isolation](#failure-isolation) applies to them as well.

- **Messenger terminal failure** (`App\Messenger\EventListener\FailedMessageAlertSubscriber`): fires once per message that exhausts all retries on the `async` transport and lands in the `failed` transport. The alert contains the message class, retry count, and error type — never the exception text, payload, or credentials. It never fires on intermediate retries, and never re-fires while reprocessing the `failed` transport.
- **MQTT sustained disconnection** (`App\Command\MqttConsumeCommand`): fires once after 12 consecutive failed connection attempts or dropped connection loops (the worker keeps retrying every 5s regardless), and exactly one recovery alert on the next successful reconnect. It does not fire for brief interruptions below the threshold, the worker's initial connection, or uplink-processing failures (a separate, pre-existing failure path).
  - **Every successful (re)connect resets the counter to zero**, even if the connection drops again immediately afterward. In practice this means the 12-count streak is driven by *repeated total inability to connect* (broker/network unreachable); a connection that keeps connecting then immediately dropping resets on each successful connect and will not, by itself, accumulate to 12 the way 12 consecutive connect failures would.
  - **The counter and "already alerted" flag are in-memory and per-process.** They reset to zero whenever the MQTT worker process restarts (deploy, crash, manual restart). A crash-loop can therefore re-alert before reaching 12 failures again, and a restart immediately after breaching the threshold silently re-arms without notice.

---

# Useful Commands

## GardenHub

```bash
cd /opt/gardenhub
```

Status:

```bash
docker compose ps
```

Logs:

```bash
docker compose logs -f
```

Worker logs:

```bash
docker compose logs -f gardenhub-worker
```

Grafana logs:

```bash
docker compose logs -f gardenhub-grafana
```

Symfony information:

```bash
docker compose exec gardenhub-api php bin/console about
```

MySQL shell:

```bash
docker compose exec gardenhub-mysql \
  sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'
```

Measurement count:

```bash
docker compose exec -T gardenhub-mysql \
  sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" \
  -e "SELECT COUNT(*) AS measurements FROM measurement;"'
```

## LoRaStack

```bash
cd /opt/lorastack
docker compose ps
```

Mosquitto logs:

```bash
docker compose logs -f mosquitto
```

Gateway Bridge logs:

```bash
docker compose logs -f chirpstack-gateway-bridge
```

---

# Persistence

Persistent GardenHub volumes:

```text
gardenhub-mysql-data
gardenhub-grafana-data
```

Persistent LoRaStack volumes include:

```text
iot_postgresqldata
iot_redisdata
iot_mosquittodata
iot_mosquittolog
```

Do not use:

```bash
docker compose down -v
```

unless you intentionally want to remove persistent data.

---

# Backup Strategy

The A6 uses a dedicated external ext4 SSD mounted at:

```text
/mnt/backup
```

Persistent mount is configured in `/etc/fstab`.

Backup root:

```text
/mnt/backup/gardenhub-server/
```

Layout:

```text
/mnt/backup/gardenhub-server/
├── daily/
│   └── YYYY-MM-DD/
└── monthly/
    └── YYYY-MM/
```

Each successful backup contains:

```text
backup-complete
backup-info.txt
chirpstack-postgres.sql.gz
gardenhub-mysql.sql.gz
gardenhub-project.tar.gz
lorastack-config.tar.gz
mosquitto.db
```

Backup script:

```text
/opt/scripts/backup/gardenhub-backup.sh
```

The script:

- prevents concurrent runs
- validates PostgreSQL dumps
- briefly stops Mosquitto before copying its persistence database
- validates MySQL dumps
- saves LoRaStack configuration and secrets
- saves the GardenHub project
- creates a monthly snapshot
- applies retention

Retention:

```text
30 daily backups
12 monthly backups
```

Systemd service:

```text
gardenhub-backup.service
```

Systemd timer:

```text
gardenhub-backup.timer
```

Schedule:

```text
03:00 UTC every day
```

Check timer:

```bash
systemctl status gardenhub-backup.timer --no-pager
systemctl list-timers gardenhub-backup.timer
```

Check backup logs:

```bash
journalctl -u gardenhub-backup.service
```

Run manually:

```bash
sudo /opt/scripts/backup/gardenhub-backup.sh
```

---

# Security

GardenHub is designed as a local-only deployment.

## SSH

SSH is configured for key-only access.

Effective settings:

```text
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
```

Validate:

```bash
sudo sshd -T | grep -E \
'passwordauthentication|kbdinteractiveauthentication|permitrootlogin'
```

UFW allows SSH only from:

```text
192.168.1.0/24
```

## Host bindings

Production services are bound only to the server LAN IP:

```text
192.168.1.20
```

Published services:

| Port | Service | Access |
|---|---|---|
| `22/tcp` | SSH | LAN only through UFW |
| `3000/tcp` | Grafana | LAN bind |
| `8080/tcp` | ChirpStack | LAN bind |
| `8081/tcp` | GardenHub | LAN bind |
| `1700/udp` | Gateway Bridge | LAN bind + source restriction |

MQTT `1883` is **not published on the host**.

MySQL, PostgreSQL and Redis are not published.

## Gateway restriction

The `DOCKER-USER` firewall chain allows UDP 1700 only from the LPS8N:

```text
192.168.1.18
```

All other inbound UDP traffic to port 1700 is dropped.

The rules are persisted with:

```text
iptables-persistent
netfilter-persistent
```

Check:

```bash
sudo iptables -L DOCKER-USER -n -v --line-numbers
```

## Router

There are no NAT/PAT forwarding rules from the Internet to:

```text
192.168.1.20
```

UPnP remains enabled on the router, but no UPnP mapping currently targets the GardenHub server.

## Grafana

Anonymous access:

```text
disabled
```

Self-registration:

```text
disabled
```

Use a strong admin password.

## Secrets

Production secrets belong only in untracked environment files.

Never commit:

- database passwords
- MQTT credentials
- API keys
- Grafana credentials
- application secrets

---

# Disaster Recovery

A recovery should rebuild the server from source/configuration and restore persistent data from `/mnt/backup`.

High-level recovery order:

```text
1. Install Ubuntu Server + Docker
2. Restore /opt/lorastack configuration
3. Start PostgreSQL
4. Restore chirpstack-postgres.sql.gz
5. Start Redis
6. Restore mosquitto.db
7. Start Mosquitto
8. Start ChirpStack
9. Start Gateway Bridge
10. Restore /opt/gardenhub project
11. Start MySQL
12. Restore gardenhub-mysql.sql.gz
13. Start GardenHub API / worker / nginx / Grafana
14. Verify LPS8N gateway connectivity
15. Verify SE01 uplink
16. Verify MySQL ingestion
17. Verify Grafana
```

Do not consider a backup valid only because files exist. A recovery test should periodically verify that the database dumps can actually be restored.

---

# Production Validation

The current production path has been validated end-to-end:

```text
SE01-Avocado
      │
      ▼
LPS8N
      │
      ▼
Gateway Bridge
      │
      ▼
ChirpStack
      │
      ▼
Mosquitto
      │
      ▼
GardenHub Worker
      │
      ▼
Symfony Messenger
      │
      ▼
MySQL
      │
      ▼
Grafana / REST API
```

The complete stack has also been tested across a full A6 reboot:

- Docker services restart automatically.
- ChirpStack reconnects.
- Gateway Bridge reconnects.
- Mosquitto restores persistence.
- GardenHub worker reconnects to MQTT.
- MySQL data persists.
- Grafana data persists.
- backup SSD remounts automatically.
- backup systemd timer remains active.

---

# Development Principles

## Local first

The complete production system should work without cloud dependencies.

## Docker first

Application and infrastructure services run in containers.

## API first

The backend remains usable independently of a future frontend.

## Modular

LoRaWAN infrastructure and application logic remain separate projects even when deployed on the same host.

## Simple

Do not introduce infrastructure without a demonstrated need.

## Observable

Health checks, logs, Grafana and explicit validation commands should make troubleshooting straightforward.

## Persistent

Sensor data and configuration must survive container recreation and server reboot.

## Secure by default

Do not expose internal databases or MQTT unless there is a concrete requirement.

## Extensible

The platform should support additional sensors, devices, alerts and automation without a major redesign.

---

# Next Roadmap

## Infrastructure

- [x] A6 production server
- [x] LoRaStack migration from Raspberry Pi
- [x] Persistent LoRaStack data
- [x] GardenHub deployment
- [x] MySQL persistence
- [x] Grafana
- [x] MQTT ingestion
- [x] Automated daily/monthly backups
- [x] Reboot validation
- [x] SSH hardening
- [x] MQTT internal-only networking
- [x] Gateway UDP source restriction
- [ ] Periodic disaster-recovery restore test
- [ ] Optional HTTPS for LAN services

## Application

- [x] Device / Sensor / Measurement model
- [x] Doctrine migrations
- [x] MQTT ingestion
- [x] API authentication
- [x] API filtering and pagination
- [x] Grafana dashboard
- [ ] Alerting
- [ ] Telegram notifications
- [ ] Irrigation automation
- [ ] Additional devices
- [ ] Frontend
- [ ] AI-assisted analysis

---

# Related Project

## `lorastack-pi`

`lorastack-pi` remains the repository for the LoRaWAN infrastructure.

Despite the historical repository name, the production LoRaStack is now deployed on the A6 under:

```text
/opt/lorastack
```

GardenHub and LoRaStack remain separate projects with separate responsibilities:

```text
LoRaStack
→ LoRaWAN transport, ChirpStack, MQTT

GardenHub
→ domain model, ingestion, persistence, API, dashboards, automation
```

---

# Project Philosophy

GardenHub is intended to evolve from a connected-garden backend into a general-purpose local IoT platform.

Priorities:

```text
Reliability → Simplicity → Security → Extensibility → Automation
```

The system should remain under local control and avoid unnecessary third-party cloud dependencies.
