SHELL := /bin/sh

COMPOSE := docker compose --env-file .env
COMPOSE_FILES := -f compose.yaml

ifneq ($(wildcard .env.local),)
COMPOSE += --env-file .env.local
endif

ifneq ($(filter dev dev-sim dev-hardware,$(MAKECMDGOALS)),)
COMPOSE_FILES += -f compose-dev.yaml
endif

ifneq ($(filter dev-sim,$(MAKECMDGOALS)),)
COMPOSE += --profile watering-sim
endif

ifneq ($(filter dev-hardware,$(MAKECMDGOALS)),)
COMPOSE += --profile watering-hardware
START_MODE := hardware
endif

START_MODE ?= start
SELECT_SERVICES = $(COMPOSE) $(COMPOSE_FILES) config --format json | python3 scripts/compose-services.py

.PHONY: start dev dev-sim dev-hardware
start dev dev-sim dev-hardware: SHELL := /bin/bash

start:
	@set -Eeuo pipefail; \
	services=$$($(SELECT_SERVICES) $(START_MODE)); \
	$(COMPOSE) $(COMPOSE_FILES) build gardenhub-api; \
	$(COMPOSE) $(COMPOSE_FILES) up -d --wait gardenhub-mysql; \
	$(MAKE) --no-print-directory deploy-stop COMPOSE='$(COMPOSE)' COMPOSE_FILES='$(COMPOSE_FILES)'; \
	$(COMPOSE) $(COMPOSE_FILES) run --rm --no-deps -T -u www-data gardenhub-api \
	  php bin/console doctrine:migrations:migrate --no-interaction; \
	if [[ "$(START_MODE)" == hardware ]]; then $(COMPOSE) $(COMPOSE_FILES) stop gardenhub-watering-sim; fi; \
	$(COMPOSE) $(COMPOSE_FILES) up -d --no-build --force-recreate --wait $$services

dev dev-sim dev-hardware: start

# --- Production deployment --------------------------------------------------

DEPLOY_REMOTE ?= origin
DEPLOY_BRANCH ?= master
DEPLOY_WRITERS := gardenhub-nginx gardenhub-api gardenhub-worker gardenhub-consumer gardenhub-scheduler gardenhub-watering-proposals
HEALTHZ_HOST ?= 192.168.1.20
HEALTHZ_PORT ?= 8081
DEPLOY_HEALTH_TIMEOUT ?= 120
DEPLOY_HEALTH_INTERVAL ?= 5

.PHONY: deploy deploy-check deploy-ci deploy-build deploy-stop deploy-migrate deploy-up deploy-cache deploy-health deploy-grafana deploy-logs deploy-info

# bash is required here: dash (Ubuntu's /bin/sh) has no pipefail/ERR trap
deploy deploy-check deploy-ci deploy-build deploy-stop deploy-migrate deploy-up deploy-cache deploy-health deploy-grafana: SHELL := /bin/bash

deploy-check: ## Verify git/compose state is safe to deploy, then fast-forward to $(DEPLOY_REMOTE)/$(DEPLOY_BRANCH)
	@set -Eeuo pipefail; \
	if [[ "$${GARDENHUB_DEPLOY_LOCKED:-}" != 1 ]]; then \
	  exec 9>"$$(git rev-parse --git-path gardenhub-deploy.lock)"; \
	  flock -n 9 || { echo "!! Another deployment is running"; exit 1; }; \
	fi; \
	echo "==> [deploy-check] .env present"; \
	test -f .env || { echo "!! .env not found in $$(pwd)"; exit 1; }; \
	echo "==> [deploy-check] Validating compose configuration"; \
	$(COMPOSE) $(COMPOSE_FILES) config --quiet; \
	echo "==> [deploy-check] Branch must be $(DEPLOY_BRANCH)"; \
	branch=$$(git rev-parse --abbrev-ref HEAD); \
	if [ "$$branch" != "$(DEPLOY_BRANCH)" ]; then \
	  echo "!! Refusing to deploy: current branch is '$$branch', expected '$(DEPLOY_BRANCH)'"; \
	  exit 1; \
	fi; \
	echo "==> [deploy-check] Working tree must be clean"; \
	if [ -n "$$(git status --porcelain)" ]; then \
	  echo "!! Refusing to deploy: working tree is not clean"; \
	  git status --short; \
	  exit 1; \
	fi; \
	echo "==> [deploy-check] Fetching and fast-forwarding to $(DEPLOY_REMOTE)/$(DEPLOY_BRANCH)"; \
	git pull --ff-only $(DEPLOY_REMOTE) $(DEPLOY_BRANCH); \
	echo "==> [deploy-check] Verifying HEAD matches $(DEPLOY_REMOTE)/$(DEPLOY_BRANCH)"; \
	if [ "$$(git rev-parse HEAD)" != "$$(git rev-parse $(DEPLOY_REMOTE)/$(DEPLOY_BRANCH))" ]; then \
	  echo "!! HEAD does not match $(DEPLOY_REMOTE)/$(DEPLOY_BRANCH) after pull"; \
	  exit 1; \
	fi; \
	echo "==> [deploy-check] Validating pulled compose configuration"; \
	$(COMPOSE) $(COMPOSE_FILES) config --quiet; \
	sha=$$(git rev-parse HEAD); \
	$(MAKE) --no-print-directory deploy-ci; \
	echo "==> [deploy-check] Deploying commit $$sha"; \
	git log -1 --pretty='    %h %s'

deploy-ci: ## Refuse deployment unless exact-commit master-push CI is successful
	@set -Eeuo pipefail; \
	python3 scripts/check-release-ci.py --sha "$$(git rev-parse HEAD)" --branch '$(DEPLOY_BRANCH)'

deploy-build: ## Build the gardenhub-api image from the current checkout
	@set -Eeuo pipefail; \
	echo "==> [deploy-build] Building gardenhub-api image"; \
	$(COMPOSE) $(COMPOSE_FILES) build gardenhub-api

deploy-stop: ## Stop application containers ahead of migrations; MySQL/Grafana are left running
	@set -Eeuo pipefail; \
	$(SELECT_SERVICES) deploy >/dev/null; \
	guard_option=; \
	monitor=$$($(COMPOSE) $(COMPOSE_FILES) ps -a -q gardenhub-watering-monitor); \
	poller=$$($(COMPOSE) $(COMPOSE_FILES) ps -a -q gardenhub-watering-proposals); \
	if [[ -z "$$monitor$$poller" ]]; then guard_option=--allow-uninitialized; fi; \
	echo "==> [deploy-stop] Read-only watering preflight"; \
	$(COMPOSE) $(COMPOSE_FILES) run --rm --no-deps -T -u www-data gardenhub-api \
	  php bin/console gardenhub:watering:check $$guard_option; \
	echo "==> [deploy-stop] Quiescing request sources; monitor stays running"; \
	$(COMPOSE) $(COMPOSE_FILES) stop -t 30 $(DEPLOY_WRITERS); \
	$(COMPOSE) $(COMPOSE_FILES) run --rm --no-deps -T -u www-data gardenhub-api \
	  php bin/console gardenhub:watering:check $$guard_option; \
	$(COMPOSE) $(COMPOSE_FILES) stop -t 30 gardenhub-watering-monitor

# If this fails, the app tier is intentionally left stopped rather than
# auto-restarted: the schema may be partially migrated, and restarting old
# code against it could be worse than staying down. Assess the DB by hand.
deploy-migrate: ## Run Doctrine migrations from the newly built image, before app containers restart
	@set -Eeuo pipefail; \
	echo "==> [deploy-migrate] Running migrations (one-off container, new image, as www-data)"; \
	$(COMPOSE) $(COMPOSE_FILES) run --rm --no-deps -T -u www-data gardenhub-api \
	  php bin/console doctrine:migrations:migrate --no-interaction --env=prod

deploy-up: ## Start the application containers using only the image deploy-build already produced
	@set -Eeuo pipefail; \
	services=$$($(SELECT_SERVICES) deploy); \
	$(MAKE) --no-print-directory deploy-stop COMPOSE='$(COMPOSE)' COMPOSE_FILES='$(COMPOSE_FILES)'; \
	echo "==> [deploy-up] Recreating: $$services"; \
	$(COMPOSE) $(COMPOSE_FILES) up -d --force-recreate --no-build --no-deps $$services

deploy-cache: ## Clear/warm the Symfony prod cache as www-data (never as root)
	@set -Eeuo pipefail; \
	echo "==> [deploy-cache] Clearing/warming prod cache as www-data"; \
	$(COMPOSE) $(COMPOSE_FILES) exec -T -u www-data gardenhub-api \
	  php bin/console cache:clear --env=prod --no-debug

deploy-health: ## Wait for required containers to become healthy, then verify the real /healthz endpoint
	@set -Eeuo pipefail; \
	services=$$($(SELECT_SERVICES) deploy); \
	echo "==> [deploy-health] Waiting up to $(DEPLOY_HEALTH_TIMEOUT)s for: $$services"; \
	elapsed=0; \
	while :; do \
	  all_healthy=1; \
	  for s in $$services; do \
	    cid=$$($(COMPOSE) $(COMPOSE_FILES) ps -a -q $$s); \
	    if [ -z "$$cid" ]; then \
	      echo "!! [deploy-health] $$s: no container found"; \
	      all_healthy=0; \
	      break; \
	    fi; \
	    state=$$(docker inspect -f '{{.State.Status}}' $$cid); \
	    if [ "$$state" = "exited" ] || [ "$$state" = "dead" ]; then \
	      echo "!! [deploy-health] $$s exited (state: $$state)"; \
	      exit 1; \
	    fi; \
	    health=$$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' $$cid); \
	    restarting=$$(docker inspect -f '{{.State.Restarting}}' $$cid); \
	    if [ "$$health" = "unhealthy" ]; then \
	      echo "!! [deploy-health] $$s is unhealthy"; \
	      exit 1; \
	    fi; \
	    if [[ "$$state" != "running" || "$$restarting" != "false" || "$$health" != "healthy" ]]; then \
	      all_healthy=0; \
	    fi; \
	  done; \
	  if [ "$$all_healthy" = "1" ]; then \
	    echo "==> [deploy-health] All containers healthy"; \
	    break; \
	  fi; \
	  if [ "$$elapsed" -ge "$(DEPLOY_HEALTH_TIMEOUT)" ]; then \
	    echo "!! [deploy-health] Timed out after $(DEPLOY_HEALTH_TIMEOUT)s waiting for healthy containers"; \
	    exit 1; \
	  fi; \
	  sleep $(DEPLOY_HEALTH_INTERVAL); \
	  elapsed=$$((elapsed + $(DEPLOY_HEALTH_INTERVAL))); \
	done; \
	echo "==> [deploy-health] Calling http://$(HEALTHZ_HOST):$(HEALTHZ_PORT)/healthz"; \
	body=$$(curl --fail --silent --show-error "http://$(HEALTHZ_HOST):$(HEALTHZ_PORT)/healthz"); \
	echo "$$body" | grep -Eq '"status"[[:space:]]*:[[:space:]]*"ok"' || { \
	  echo "!! [deploy-health] Unexpected /healthz response: $$body"; \
	  exit 1; \
	}; \
	echo "==> [deploy-health] /healthz OK: $$body"

# Compose only recreates a container when its config changes; bind-mounted
# provisioning files never do, so a plain `up -d` would leave stale content
# running -- force recreation here on every deploy instead. Run after
# deploy-health so app-tier readiness is confirmed before touching Grafana.
deploy-grafana: ## Force-recreate gardenhub-grafana (no rebuild, no MySQL restart), then wait for it to become healthy
	@set -Eeuo pipefail; \
	dump_health_log() { docker inspect -f '{{range .State.Health.Log}}{{.Output}}{{end}}' "$$1" 2>/dev/null; }; \
	echo "==> [deploy-grafana] Recreating: gardenhub-grafana"; \
	$(COMPOSE) $(COMPOSE_FILES) up -d --force-recreate --no-build --no-deps gardenhub-grafana; \
	echo "==> [deploy-grafana] Waiting up to $(DEPLOY_HEALTH_TIMEOUT)s for gardenhub-grafana to become healthy"; \
	elapsed=0; \
	while :; do \
	  cid=$$($(COMPOSE) $(COMPOSE_FILES) ps -a -q gardenhub-grafana); \
	  if [ -z "$$cid" ]; then \
	    echo "!! [deploy-grafana] gardenhub-grafana: no container found"; \
	    exit 1; \
	  fi; \
	  state=$$(docker inspect -f '{{.State.Status}}' $$cid); \
	  if [ "$$state" = "exited" ] || [ "$$state" = "dead" ]; then \
	    echo "!! [deploy-grafana] gardenhub-grafana exited (state: $$state)"; \
	    echo "==> [deploy-grafana] Health-check log (no secrets):"; \
	    dump_health_log "$$cid"; \
	    exit 1; \
	  fi; \
	  health=$$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' $$cid); \
	  if [ "$$health" = "unhealthy" ]; then \
	    echo "!! [deploy-grafana] gardenhub-grafana is unhealthy"; \
	    echo "==> [deploy-grafana] Health-check log (no secrets):"; \
	    dump_health_log "$$cid"; \
	    exit 1; \
	  fi; \
	  if [ "$$health" = "healthy" ]; then \
	    echo "==> [deploy-grafana] gardenhub-grafana healthy"; \
	    break; \
	  fi; \
	  if [ "$$elapsed" -ge "$(DEPLOY_HEALTH_TIMEOUT)" ]; then \
	    echo "!! [deploy-grafana] Timed out after $(DEPLOY_HEALTH_TIMEOUT)s waiting for gardenhub-grafana to become healthy (last status: $$health)"; \
	    echo "==> [deploy-grafana] Health-check log (no secrets):"; \
	    dump_health_log "$$cid"; \
	    exit 1; \
	  fi; \
	  sleep $(DEPLOY_HEALTH_INTERVAL); \
	  elapsed=$$((elapsed + $(DEPLOY_HEALTH_INTERVAL))); \
	done

deploy-logs: ## Best-effort diagnostics: container status and recent logs (last 2 minutes)
	-@echo "==> [deploy-logs] docker compose ps"; $(COMPOSE) $(COMPOSE_FILES) ps
	-@echo "==> [deploy-logs] gardenhub-api (last 2m)"; $(COMPOSE) $(COMPOSE_FILES) logs --since=2m gardenhub-api
	-@echo "==> [deploy-logs] gardenhub-worker (last 2m)"; $(COMPOSE) $(COMPOSE_FILES) logs --since=2m gardenhub-worker
	-@echo "==> [deploy-logs] gardenhub-consumer (last 2m)"; $(COMPOSE) $(COMPOSE_FILES) logs --since=2m gardenhub-consumer
	-@echo "==> [deploy-logs] gardenhub-scheduler (last 2m)"; $(COMPOSE) $(COMPOSE_FILES) logs --since=2m gardenhub-scheduler
	-@echo "==> [deploy-logs] watering services (last 2m)"; $(COMPOSE) $(COMPOSE_FILES) logs --since=2m gardenhub-watering-monitor gardenhub-watering-proposals
	-@echo "==> [deploy-logs] gardenhub-nginx (last 2m)"; $(COMPOSE) $(COMPOSE_FILES) logs --since=2m gardenhub-nginx
	-@echo "==> [deploy-logs] gardenhub-grafana (last 2m)"; $(COMPOSE) $(COMPOSE_FILES) logs --since=2m gardenhub-grafana

deploy-info: ## Show current git/image/container state (read-only, safe anytime)
	@echo "==> Git"; \
	echo "    branch: $$(git rev-parse --abbrev-ref HEAD)"; \
	echo "    commit: $$(git rev-parse HEAD)"; \
	echo "==> Image"; \
	docker image inspect gardenhub-api --format '    id: {{.Id}}{{"\n"}}    created: {{.Created}}' 2>/dev/null || echo "    gardenhub-api image not found"; \
	echo "==> Containers"; \
	$(COMPOSE) $(COMPOSE_FILES) ps

# make deploy requires branch=master and a clean, fast-forwarded tree, so it
# intentionally refuses a detached HEAD -- it is NOT the rollback mechanism.
#
# Permanent rollback/revert must happen upstream on GitHub (e.g. `git revert`
# the bad commit, merged to master), never by creating new commit history
# directly on this server. Once master is fixed upstream, resume with
# `make deploy` as usual.
#
# For an urgent temporary mitigation on this server while the GitHub-side fix
# lands, a known-good SHA can be checked out manually (detached HEAD, never
# `git reset --hard`) and the independent helpers reused directly:
#
#   git fetch $(DEPLOY_REMOTE) && git checkout <good-sha>
#   make deploy-build
#   make deploy-stop
#   make deploy-up
#   make deploy-cache
#   make deploy-health
#   make deploy-grafana
#
# Deliberately skip `make deploy-migrate`: Doctrine migrations are not
# auto-reversible. Assess DB/schema compatibility with the old code by hand
# first (a migration may need manual reversal, or data restored from backup).
# Afterwards, get master fixed on GitHub, then explicitly switch back --
# `git checkout master && git pull --ff-only origin master` -- before running
# `make deploy` again. Do not rely on `make deploy` to recover a detached HEAD.
deploy: ## Safe, end-to-end production deployment (the only target that orchestrates the others)
	@set -Eeuo pipefail; \
	exec 9>"$$(git rev-parse --git-path gardenhub-deploy.lock)"; \
	flock -n 9 || { echo "!! Another deployment is running"; exit 1; }; \
	export GARDENHUB_DEPLOY_LOCKED=1; \
	trap '$(MAKE) --no-print-directory deploy-logs' ERR; \
	$(MAKE) --no-print-directory deploy-check; \
	approved_sha=$$(git rev-parse HEAD); \
	$(MAKE) --no-print-directory deploy-build; \
	if [[ "$$(git rev-parse HEAD)" != "$$approved_sha" || -n "$$(git status --porcelain)" ]]; then \
	  echo "!! Checkout changed after CI approval; refusing shutdown"; exit 1; \
	fi; \
	$(MAKE) --no-print-directory deploy-ci; \
	$(MAKE) --no-print-directory deploy-stop; \
	$(MAKE) --no-print-directory deploy-migrate; \
	$(MAKE) --no-print-directory deploy-up; \
	$(MAKE) --no-print-directory deploy-cache; \
	$(MAKE) --no-print-directory deploy-health; \
	$(MAKE) --no-print-directory deploy-grafana; \
	sha=$$(git rev-parse HEAD); \
	echo "==> Deployment successful: $$sha"