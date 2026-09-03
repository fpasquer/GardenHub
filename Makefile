SHELL := /bin/sh

COMPOSE := docker compose --env-file .env
COMPOSE_FILES := -f compose.yaml

ifneq ($(wildcard .env.local),)
COMPOSE += --env-file .env.local
endif

ifneq ($(filter dev,$(MAKECMDGOALS)),)
COMPOSE_FILES += -f compose-dev.yaml
endif

.PHONY: start dev

start:
	$(COMPOSE) $(COMPOSE_FILES) up -d --build

dev: start