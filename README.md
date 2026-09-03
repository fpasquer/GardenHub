# GardenHub 🌱

GardenHub is the backend platform for a local, self-hosted IoT system designed to monitor and eventually automate a connected garden.

The project is intentionally separated from the LoRaWAN infrastructure running on the Raspberry Pi.

The goal is to build a reliable, modular and fully local backend capable of collecting sensor data, storing historical measurements, exposing an API and later supporting automation, dashboards and additional IoT devices.

---

## Current Status

### Implemented now

* Docker Compose stack (`compose.yaml`) with a dedicated `gardenhub` network:
  * `gardenhub-nginx` — nginx 1.27 web server, the only service exposed on the host
  * `gardenhub-api` — PHP 8.4 FPM + Symfony 7.4 LTS + API Platform 4.3 (FastCGI behind nginx)
  * `gardenhub-mysql` — MySQL 8.4 with a persistent named volume (`gardenhub-mysql-data`)
  * `gardenhub-grafana` — Grafana 12 dashboards reading MySQL directly (read-only SQL),
    with a provisioned datasource and a GardenHub overview dashboard
* Doctrine ORM and Doctrine Migrations configured and connected to MySQL through the Docker network
* Symfony Messenger installed (sync transport by default, ready for future MQTT ingestion)
* Symfony Validator, Serializer and Monolog configured
* API Platform entrypoint, OpenAPI documentation and Swagger UI exposed
* Domain model with three API resources: `Device`, `Sensor`, `Measurement`
  (`/api/devices`, `/api/sensors`, `/api/measurements`) with validation
* MQTT ingestion: a `gardenhub-worker` container consumes ChirpStack uplinks from
  the lorastack-pi broker and persists them through Symfony Messenger; devices
  and sensors are auto-provisioned from incoming payloads
* API key authentication (`Authorization: Bearer <key>`) with per-consumer
  read/write roles; public docs, protected resources
* API filtering (device/sensor/date/value) and pagination on all collections
* Grafana dashboards: a pre-provisioned "Garden Overview" dashboard (current
  values + history per sensor type) backed by a provisioned MySQL datasource
* Measurements are immutable over HTTP (read + create only)
* HTTP health endpoint (`/healthz`) verifying the Symfony → Doctrine → MySQL chain, used by the Docker health check
* Environment-based configuration via `.env` (safe defaults) and git-ignored `.env.local`

### Planned later (not implemented yet)

* Extended domain model (`Garden`, `GardenBed`, `Plant`, `IrrigationEvent`, `Alert`, ...)
* HTTPS termination (currently plain HTTP on the LAN)
* Frontend, automation, AI integrations

---

## Getting Started

A new developer only needs Docker and Docker Compose on the host. PHP, Composer, Symfony CLI and MySQL all run inside containers.

### 1. Prerequisites

* Docker 24+
* Docker Compose v2 (`docker compose`)

### 2. Clone the repository

```bash
git clone <repository-url> GardenHub
cd GardenHub
```

### 3. Configure the environment

The committed `.env` file contains safe development defaults (database name, user, passwords and the API port). To override any value locally, create an untracked `.env.local` file (see `.gitignore`) and start the stack with both files (the first provides defaults, the second overrides them):

```bash
docker compose --env-file .env --env-file .env.local up -d
```

| Variable              | Default                   | Purpose                          |
|-----------------------|---------------------------|----------------------------------|
| `APP_ENV`             | `dev`                     | Symfony environment              |
| `APP_PORT`            | `8080`                    | Host port exposing the API       |
| `GRAFANA_PORT`        | `3000`                    | Host port exposing Grafana       |
| `GRAFANA_ADMIN_USER`  | `admin`                   | Grafana initial admin user       |
| `GRAFANA_ADMIN_PASSWORD` | `admin`                | Grafana initial admin password   |
| `ADMINER_PORT`        | `8081`                    | Host port exposing Adminer (dev only) |
| `MYSQL_DATABASE`      | `gardenhub`               | MySQL database name              |
| `MYSQL_USER`          | `gardenhub`               | MySQL application user           |
| `MYSQL_PASSWORD`      | `gardenhub_dev_password`  | MySQL application password       |
| `MYSQL_ROOT_PASSWORD` | `gardenhub_dev_root_password` | MySQL root password          |
| `MQTT_HOST`           | `lorastack-pi`            | MQTT broker host (ChirpStack)    |
| `MQTT_PORT`           | `1883`                    | MQTT broker port                 |
| `MQTT_USERNAME`       | *(empty)*                 | MQTT username                    |
| `MQTT_PASSWORD`       | *(empty)*                 | MQTT password                    |

MQTT topology differs per environment:

* **Production** (GardenHub on its own machine): `MQTT_HOST` is the Raspberry Pi
  hostname/IP, port 1883 — the containers talk to the Pi over the LAN.
* **Development** (GardenHub and the broker on the same machine): point
  `MQTT_HOST` to `host.docker.internal` in `.env.local`. The `gardenhub-worker`
  service maps that name to the **gardenhub network gateway** (`172.22.0.1`),
  so a broker reachable on the Docker host stays reachable from the container.

  > Docker's built-in `host-gateway` keyword resolves to the default `docker0`
  > bridge (`172.17.0.1`), which containers on a custom network cannot use to
  > reach host services. That is why `compose.yaml` maps the name explicitly
  > and pins the `gardenhub` subnet to `172.22.0.0/16`.

  To expose the Raspberry Pi MQTT broker on port 1884 of the Azure development
  machine, run this from Windows PowerShell and keep the SSH session open:

  ```powershell
  ssh -i C:\Users\H295226\Desktop\fpasquer-dev-linux_key.pem -N -R 0.0.0.0:1884:192.168.1.16:1883 azureuser@10.145.88.202
  ```

  Two details matter here:

  * The `0.0.0.0:` bind address is required. A plain `-R 1884:...` binds the
    tunnel to the host **loopback only** (`127.0.0.1:1884`), which containers
    cannot reach — from inside a container the host is the bridge gateway IP,
    not loopback. Binding `0.0.0.0` requires `GatewayPorts clientspecified`
    (or `yes`) in the VM's `/etc/ssh/sshd_config` — Azure images may ship an
    sshd_config **without** an `Include` for `sshd_config.d/`, so put the
    directive in the main file, then `sudo sshd -t && sudo systemctl restart ssh`
    (verify with `sudo sshd -T | grep gatewayports`). Check the effective bind
    with `ss -ltn | grep 1884` (want `0.0.0.0:1884`).
  * Binding `0.0.0.0` also exposes port 1884 on the VM's external IP. Restrict
    it with the Azure NSG, or bind only the bridge gateway instead:
    `-R 172.22.0.1:1884:192.168.1.16:1883`.

  Configure `.env.local` with `MQTT_HOST=host.docker.internal` and
  `MQTT_PORT=1884` while this tunnel is running, and start the stack with both
  env files (`--env-file .env --env-file .env.local`) — a single `--env-file`
  **replaces** the defaults instead of merging them.

### 4. Build the containers

```bash
docker compose build
```

### 5. Start the containers

```bash
docker compose up -d
```

### 6. Run the database migrations

```bash
docker compose exec gardenhub-api bin/console doctrine:database:create --if-not-exists
docker compose exec gardenhub-api bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
```

There are no domain migrations yet (no entities have been created); this validates that the migration tooling works and creates the `doctrine_migration_versions` table.

### 7. Verify Symfony

```bash
docker compose ps
docker compose exec gardenhub-api bin/console about
```

All four containers should be `healthy`, and `about` should report Symfony 7.4.

### 8. Verify API Platform

* API entrypoint: [http://localhost:8080/api](http://localhost:8080/api) (returns the JSON-LD entrypoint)
* API documentation (Swagger UI): [http://localhost:8080/api/docs](http://localhost:8080/api/docs)
* Health check: [http://localhost:8080/healthz](http://localhost:8080/healthz) (returns `{"status":"ok"}` only when the database is reachable)

### Grafana dashboards

Grafana runs at [http://localhost:3000](http://localhost:3000) (log in with
`GRAFANA_ADMIN_USER` / `GRAFANA_ADMIN_PASSWORD`, default `admin` / `admin` —
change them in `.env.local`). The **GardenHub / Garden Overview** dashboard is
provisioned automatically on first start, together with its MySQL datasource
(read-only SQL queries against `gardenhub-mysql`, no writes).

The dashboard opens with a **Devices** table (one row per device: name,
creation date, sensor count) — new devices appear automatically on their first
uplink. Below it, the current value of each sensor type (soil moisture, soil
temperature, air temperature, soil conductivity, battery) plus their history,
with a deliberately sober, muted color palette. Edit it in the UI or replace
`grafana/dashboards/gardenhub-overview.json` — the provisioning provider
reloads the file every 30 seconds.

### API authentication

The API resources require an API key; the docs, entrypoint and health check stay public. Create a client:

```bash
# Read + write (devices, sensors, measurement creation)
docker compose exec gardenhub-api bin/console gardenhub:api-client:create my-client

# Read-only (e.g. Grafana, dashboards)
docker compose exec gardenhub-api bin/console gardenhub:api-client:create grafana --readonly
```

The key is printed **once** — store it. Only its SHA-256 hash is kept in the database. Use it as a Bearer token (or `X-API-KEY` header):

```bash
curl -H "Authorization: Bearer gh_..." http://localhost:8080/api/devices
```

In the [Swagger UI](http://localhost:8080/api/docs), click **Authorize** and paste the `gh_...` key — every request then sends it automatically.

Available resources:

* `GET/POST /api/devices`, `GET/PATCH/DELETE /api/devices/{id}`
* `GET/POST /api/sensors`, `GET/PATCH/DELETE /api/sensors/{id}`
* `GET/POST /api/measurements`, `GET /api/measurements/{id}` (immutable: no PATCH/DELETE)

### Database admin UI (Adminer, dev only)

Adminer runs only in development: it is defined in `compose-dev.yaml`, which is
merged on top of `compose.yaml`. Start the dev stack with both files:

```bash
docker compose -f compose.yaml -f compose-dev.yaml up -d
```

Production starts with `compose.yaml` alone, so the container never runs there.
Then open [http://localhost:8081](http://localhost:8081) — the MySQL server is
pre-filled (`gardenhub-mysql`, reachable over the internal Docker network);
log in with `MYSQL_USER` / `MYSQL_PASSWORD` (or the root credentials) from
`.env` / `.env.local`.

### Filtering and pagination

All collections are paginated (default 30 items/page, 100 for measurements) and support filters:

```bash
# Devices by name (partial match)
/api/devices?name=SE01

# Sensors of a device / of a type
/api/sensors?device=/api/devices/1
/api/sensors?type=soil_moisture

# Measurements of a sensor, in a date range, above a value
/api/measurements?sensor=/api/sensors/5
/api/measurements?measuredAt[after]=2026-08-20&measuredAt[before]=2026-08-24
/api/measurements?value[gte]=20

# Ordering and pagination
/api/measurements?order[measuredAt]=desc&page=2
```

Replace `8080` with your `APP_PORT` if you changed it.

### 9. Stop the environment

```bash
docker compose down
```

MySQL data survives `docker compose down` because it is stored in the persistent `gardenhub-mysql-data` volume. To also delete the data, run `docker compose down -v`.

### Useful commands

```bash
# Follow logs
docker compose logs -f

# Open a shell in the API container
docker compose exec gardenhub-api sh

# Run Composer inside the API container (no host PHP/Composer needed)
docker compose exec gardenhub-api composer require <package>

# Access MySQL (not exposed on the host network by design)
docker compose exec gardenhub-mysql mysql -uroot -p

# Follow MQTT worker logs
docker compose logs -f gardenhub-worker

# Follow Grafana logs
docker compose logs -f gardenhub-grafana

# Follow Adminer logs (dev stack)
docker compose logs -f gardenhub-adminer

# Simulate a ChirpStack uplink without a broker (same Messenger path as real MQTT)
docker compose exec gardenhub-api bin/console gardenhub:mqtt:simulate a84041a1c182b3e0 '{"hum_SOIL":"25.34","temp_SOIL":"21.06"}'

# Rebuild the image after changing composer.json/composer.lock
docker compose up -d --build
```

The `api/src`, `api/config`, `api/public`, `api/migrations`, `api/templates` and `api/bin` directories are bind-mounted into the API container, so code changes apply immediately in `dev` mode without rebuilding.

---

## Web Server

HTTP is served by **nginx** (`gardenhub-nginx`), which is the only service exposed on the host (`APP_PORT`, default 8080). Dynamic requests are forwarded over FastCGI to the `gardenhub-api` PHP-FPM container; static assets (such as the Swagger UI files) are served directly by nginx.

The nginx vhost lives in `api/docker/nginx/default.conf` and is mounted read-only into the container.

---

## Architecture

GardenHub is designed to run entirely in Docker on a **dedicated machine**, separate from the Raspberry Pi running the LoRaWAN infrastructure.

```text
                        IoT Devices
                            │
                         LoRaWAN
                            │
                    ┌───────▼─────────┐
                    │   LPS8N Gateway │
                    └───────┬─────────┘
                            │
                         LoRaWAN
                            │
                    ┌───────▼─────────┐
                    │   Raspberry Pi  │
                    │                 │
                    │  lorastack-pi   │
                    │                 │
                    │   ChirpStack    │
                    │   MQTT Broker   │
                    └───────┬─────────┘
                            │
                           MQTT
                            │
                  ┌─────────▼──────────┐
                  │    GardenHub Host  │
                  │                    │
                  │      Docker        │
                  │                    │
                  │  ┌──────────────┐  │
                  │  │    nginx     │  │
                  │  └──────┬───────┘  │
                  │       FastCGI      │
                  │  ┌──────▼───────┐  │
                  │  │   Symfony    │  │
                  │  │ API Platform │  │
                  │  └──────┬───────┘  │
                  │         │          │
                  │  ┌──────▼───────┐  │
                  │  │   MySQL 8    │  │
                  │  └──────▲───────┘  │
                  │      Doctrine      │
                  │  ┌──────┴───────┐  │
                  │  │ MQTT Worker  │  │
                  │  │  (Messenger) │  │
                  │  └──────────────┘  │
                  │                    │
                  └─────────┬──────────┘
                            │
                         REST API
                            │
                    ┌───────▼─────────┐
                    │ Future Frontend │
                    │                 │
                    │   React / TBD   │
                    └─────────────────┘

                     Optional later
                            │
                       ┌────▼─────┐
                       │ Grafana  │
                       └──────────┘
```

---

## Project Responsibilities

### `lorastack-pi`

The Raspberry Pi project is responsible for the IoT infrastructure:

* LoRaWAN gateway connectivity
* ChirpStack
* MQTT broker
* LoRaWAN device communication
* Device uplinks/downlinks
* MQTT message transport

Repository:

```text
lorastack-pi
```

GardenHub does **not** replace this infrastructure.

---

### `GardenHub`

GardenHub is responsible for the application layer:

* IoT domain model
* Sensor and device management
* Measurement persistence
* Business logic
* MQTT consumption
* REST API
* Data validation
* Historical data access
* Future automation logic
* Future integrations

The backend should remain independent from the frontend.

---

# Technology Stack

## Backend

* PHP 8.2+
* Symfony 7.4 LTS
* API Platform 4.3+
* Doctrine ORM
* Symfony Messenger
* Symfony Validator
* Symfony Serializer

## Database

* MySQL 8.0+
* Doctrine Migrations

MySQL is the initial persistent storage solution.

The project will not introduce a dedicated time-series database unless this becomes necessary later.

## Messaging

GardenHub will consume IoT messages through MQTT.

The MQTT broker itself remains part of the `lorastack-pi` infrastructure.

The backend should therefore communicate with the existing MQTT broker over the network rather than running a second MQTT broker.

## Infrastructure

Everything belonging to GardenHub should run through Docker.

The target machine should therefore only require the host operating system and Docker/Docker Compose.

---

# Docker Architecture

The initial GardenHub stack contains:

```text
GardenHub
│
├── nginx
│
├── Symfony / API Platform (PHP-FPM)
│
├── MQTT Worker
│
├── Grafana
│
└── MySQL 8
```

Additional services may be introduced later when justified.

Potential future services:

```text
└── Redis
```

Services should not be added simply because they are commonly used.

The architecture should remain as small as reasonably possible.

---

# Initial Backend Architecture

The first version should follow this data flow:

```text
LoRaWAN Sensor
      │
      ▼
lorastack-pi
      │
      ▼
   ChirpStack
      │
      ▼
     MQTT
      │
      ▼
GardenHub
      │
      ▼
Symfony Messenger
      │
      ▼
Domain / Application Logic
      │
      ▼
Doctrine ORM
      │
      ▼
   MySQL 8
```

API Platform exposes the stored information through HTTP:

```text
MySQL
  ▲
  │
Doctrine
  ▲
  │
Symfony
  │
  ▼
API Platform
  │
  ▼
REST API
```

---

# API

API Platform will provide the main HTTP API for GardenHub.

The API should expose resources such as:

```text
/api/devices
/api/sensors
/api/measurements
```

The exact resources and relationships will be defined during the domain-model phase.

API Platform should provide:

* REST endpoints
* OpenAPI documentation
* Validation
* Serialization
* Filtering
* Pagination
* Resource operations
* API security when required

The API should remain independent from any future frontend.

---

# Initial Domain Model

The first domain model should remain intentionally simple.

The initial concepts are expected to include:

```text
Device
Sensor
Measurement
```

For example:

```text
Device
 └── SE01-Avocado

Sensor
 ├── Temperature
 ├── Humidity
 └── Soil Moisture

Measurement
 ├── timestamp
 ├── sensor
 └── value
```

The final model should be designed before creating a large number of database tables.

The model must also be flexible enough to support future devices and sensors without requiring a redesign for every new sensor type.

---

# Data Ingestion

GardenHub receives sensor data from the MQTT infrastructure hosted by `lorastack-pi`.

The `gardenhub-worker` container subscribes to the ChirpStack uplink topic
(`application/+/device/+/event/up` by default, see `MQTT_TOPIC` in `api/.env`),
decodes the JSON payload and dispatches it to Symfony Messenger:

```text
MQTT
  │
  ▼
Symfony Messenger
  │
  ▼
MQTT Message Handler
  │
  ▼
Validation / Transformation
  │
  ▼
Domain Model
  │
  ▼
Doctrine
  │
  ▼
MySQL
```

MQTT ingestion is separated from HTTP/API processing: it runs in the dedicated
`gardenhub-worker` container (`gardenhub:mqtt:consume`), which reconnects
automatically when the broker is unreachable and logs every failure.

The API is not responsible for receiving live sensor messages from ChirpStack.

## Payload mapping

Payload fields are mapped to sensors through the `mqtt.field_map` parameter in
`api/config/services.yaml` (defaults match the real Dragino SE01-LB codec output):

| Payload field   | Sensor type         | Unit  |
|-----------------|---------------------|-------|
| `BatV`          | `battery`           | V     |
| `water_SOIL`    | `soil_moisture`     | %     |
| `temp_SOIL`     | `soil_temperature`  | °C    |
| `temp_DS18B20`  | `air_temperature`   | °C    |
| `conduct_SOIL`  | `soil_conductivity` | µS/cm |

Devices and sensors are auto-provisioned on the first uplink: an unknown
`devEui` creates a `Device` named after the EUI, and each mapped field creates
the matching `Sensor`. Unknown fields (`Node_type`, `s_flag`, `i_flag`, `Mod`,
...) are ignored; non-numeric values (such as the `"NULL"` strings produced by
the SE01 codec) are skipped with a warning.

To test the ingestion chain without a broker:

```bash
docker compose exec gardenhub-api bin/console gardenhub:mqtt:simulate a84041a1c182b3e0 '{"hum_SOIL":"25.34","temp_SOIL":"21.06"}'
```

---

# Frontend

There is intentionally **no frontend in the initial GardenHub project**.

The backend and API should be developed first.

A frontend may be introduced later, potentially using React, but the technology has not yet been finalized.

The frontend must consume the GardenHub API rather than accessing MySQL directly.

```text
Frontend
    │
    ▼
GardenHub API
    │
    ▼
Symfony
    │
    ▼
MySQL
```

---

# Grafana

Grafana is part of the stack: it reads MySQL directly for sensor dashboards
(see the "Grafana dashboards" section above). Additional Grafana dashboards
can be added by dropping JSON files into `grafana/dashboards/`.

---

# Deployment

GardenHub will run on a dedicated machine separate from the Raspberry Pi.

Example:

```text
Machine 1
────────────────────────────
Raspberry Pi
lorastack-pi
├── ChirpStack
└── MQTT
```

```text
Machine 2
────────────────────────────
GardenHub Server
├── nginx
├── Symfony
├── API Platform
├── MQTT Worker
└── MySQL
```

The two machines communicate through the local network.

The Raspberry Pi should therefore remain focused on IoT/LoRaWAN infrastructure while GardenHub handles application and data processing.

---

# Persistence

MySQL data must be stored using a Docker volume or another persistent storage mechanism.

Destroying and recreating the Symfony container must never destroy the database.

Example:

```text
Docker
│
├── gardenhub-nginx
│
├── gardenhub-api
│
└── gardenhub-mysql
        │
        └── Persistent Volume
```

Database migrations will be managed using Doctrine Migrations.

---

# Configuration

Environment-specific configuration must not be hardcoded into the repository.

Examples include:

* Database credentials
* MQTT host
* MQTT credentials
* API configuration
* Application secrets

Development configuration should use environment variables and/or an untracked `.env.local` file.

Secrets must never be committed to Git.

---

# Development Principles

GardenHub should follow these principles:

### Local first

The system should remain fully functional without cloud services.

### Docker first

Application services should run inside Docker.

### API first

The backend API should be usable independently from any frontend.

### Modular

IoT infrastructure, backend application and frontend should remain loosely coupled.

### Simple

Do not introduce infrastructure before it is required.

### Observable

Logs and health checks should make troubleshooting straightforward.

### Persistent

Sensor data must survive container recreation and machine reboots.

### Extensible

The architecture should support additional sensors, devices and automation without major restructuring.

---

# Initial Development Roadmap

## Phase 1 — Project foundation

* [x] Create GardenHub repository
* [x] Create Docker Compose configuration
* [x] Add Nginx web server container
* [x] Add PHP/Symfony container
* [x] Add MySQL 8 container
* [x] Configure persistent MySQL storage
* [x] Configure Symfony environment
* [x] Verify Symfony ↔ MySQL connectivity

## Phase 2 — API foundation

* [x] Install API Platform
* [x] Configure Doctrine
* [x] Configure Doctrine Migrations
* [x] Create initial API resource
* [x] Verify OpenAPI/Swagger
* [x] Test CRUD operations

## Phase 3 — Domain model

* [x] Design Device entity
* [x] Design Sensor entity
* [x] Design Measurement entity
* [x] Define relationships
* [x] Define validation rules
* [x] Create database migrations
* [x] Test persistence

## Phase 4 — MQTT integration

* [x] Configure MQTT connection to `lorastack-pi`
* [x] Configure Symfony Messenger
* [x] Consume ChirpStack MQTT messages
* [x] Decode/normalize payloads
* [x] Validate measurements
* [x] Persist measurements into MySQL
* [x] Add error handling
* [x] Add logging

## Phase 5 — API refinement

* [x] Device filtering
* [x] Sensor filtering
* [x] Measurement filtering
* [x] Date/time filtering
* [x] Pagination
* [x] API security
* [x] API documentation

## Phase 6 — Future

* [x] Grafana dashboards
* [ ] Frontend
* [ ] Automation
* [ ] Alerts
* [ ] HomeKit / Apple Home integration
* [ ] Additional IoT devices

---

# First Success Criteria

GardenHub's first important milestone is:

```text
SE01
 │
 ▼
LoRaWAN
 │
 ▼
ChirpStack
 │
 ▼
MQTT
 │
 ▼
GardenHub
 │
 ▼
Symfony Messenger
 │
 ▼
MySQL
 │
 ▼
API Platform
 │
 ▼
GET /api/measurements
```

A real measurement generated by the SE01 should ultimately be visible through the GardenHub API.

For example:

```http
GET /api/measurements
```

should return persisted sensor measurements originating from the real IoT infrastructure.

---

# Project Philosophy

GardenHub is intended to evolve from a simple connected-garden backend into a general-purpose local IoT platform.

The project should therefore prioritize:

**Reliability → Simplicity → Extensibility → Automation**

rather than introducing unnecessary infrastructure at the beginning.

The system should remain completely under local control and should not depend on third-party cloud IoT platforms.

---

## Related Project

### lorastack-pi

`lorastack-pi` contains the Raspberry Pi-based LoRaWAN infrastructure used by GardenHub.

GardenHub and `lorastack-pi` are intentionally separate projects with separate responsibilities.
