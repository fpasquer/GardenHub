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

- connect to Mosquitto
- subscribe to ChirpStack uplinks
- decode messages
- dispatch messages through Symfony Messenger
- auto-provision devices and sensors
- persist measurements

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

The deployment uses a root `.env` file for Compose variables.

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
```

Production on `gardenhub-server` currently uses:

```text
GardenHub API: 192.168.1.20:8081
Grafana:       192.168.1.20:3000
ChirpStack:    192.168.1.20:8080
```

Do not commit `.env` or `.env.local`.

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
