.DEFAULT_GOAL := help
.ONESHELL:
MAKEFLAGS += --no-print-directory

ENV ?= dev
NO_COLOR ?=

DOTENV_FILES := .env .env.local .env.$(ENV) .env.$(ENV).local
DOTENV_FOUND := $(foreach f,$(DOTENV_FILES),$(if $(wildcard $(f)),$(f),))
-include $(DOTENV_FOUND)
export

COMPOSE_FILES := -f compose.yaml
ifeq ($(ENV),dev)
	ifneq ("$(wildcard compose.dev.yaml)","")
		COMPOSE_FILES += -f compose.dev.yaml
	endif
endif
ifeq ($(ENV),prod)
	ifneq ("$(wildcard compose.prod.yaml)","")
		COMPOSE_FILES += -f compose.prod.yaml
	endif
endif

COMPOSE := docker compose $(COMPOSE_FILES)

HTTP_PORT ?= 8080
PG_LOCAL_PORT ?= 5433
POSTGRES_VERSION ?= 18
POSTGRES_DB ?= snt
POSTGRES_USER ?= snt
POSTGRES_PASSWORD ?= snt
PHP_WEB_SERVICE ?= php-web
PHP_CLI_SERVICE ?= php-cli
COMPOSER_SERVICE ?= composer
E2E_EMAIL ?= smoke.playwright@example.test
E2E_PASSWORD ?= smoke-password-123
E2E_WORKSPACE_CODE ?= demo-smoke
E2E_WORKSPACE_NAME ?= Демо smoke КомУчёт
E2E_DEMO_SIZE ?= small
E2E_DEMO_AS_OF ?= 2026-05-14
FRESH_SMOKE_PROJECT ?= snt_fresh_smoke
FRESH_SMOKE_HTTP_PORT ?= 18080
FRESH_SMOKE_PG_LOCAL_PORT ?= 15433
FRESH_SMOKE_ADMIN_EMAIL ?= fresh.smoke@example.test
FRESH_SMOKE_ADMIN_PASSWORD ?= fresh-smoke-password-123
FRESH_SMOKE_WORKSPACE_CODE ?= fresh-smoke
FRESH_SMOKE_WORKSPACE_NAME ?= Fresh Smoke КомУчёт
ZM_WORKSPACE ?= demo
ZM_IMPORT_PATH ?= local/imports/zavety-michurina
ZM_IMPORT_NAME ?= Исторические PDF
ZM_PATTERN ?=
ZM_BATCH ?=
ZM_FROM_TEXT ?=
ZM_RECURSIVE ?=
ZM_CONTINUE_ON_ERROR ?=

ZM_STAGE_ARGS := --workspace="$(ZM_WORKSPACE)" --name="$(ZM_IMPORT_NAME)"
ifneq ($(strip $(ZM_PATTERN)),)
	ZM_STAGE_ARGS += --pattern="$(ZM_PATTERN)"
endif
ifneq ($(strip $(ZM_FROM_TEXT)),)
	ZM_STAGE_ARGS += --from-text
endif
ifneq ($(strip $(ZM_RECURSIVE)),)
	ZM_STAGE_ARGS += --recursive
endif

ZM_APPLY_ARGS := --workspace="$(ZM_WORKSPACE)"
ifneq ($(strip $(ZM_CONTINUE_ON_ERROR)),)
	ZM_APPLY_ARGS += --continue-on-error
endif

##@ General
help: ## Show this help menu
	@printf "\nEnvironment: \033[36m%s\033[0m\nCompose files: %s\nEnv chain: \033[36m%s\033[0m\n\n" \
	  "$(ENV)" "$(COMPOSE_FILES)" "$(DOTENV_FOUND)"
	@awk -F ':.*##' -v default_goal='$(.DEFAULT_GOAL)' -v no_color='$(NO_COLOR)' '\
BEGIN{ c0=""; cB=""; cC=""; cM=""; cG=""; cR=""; if (!no_color){ c0="\033[0m"; cB="\033[1m"; cC="\033[36m"; cM="\033[35m"; cG="\033[32m"; cR="\033[31m"; } printf "Usage: make %s<target>%s\n", cC, c0; } \
/^##@/ { section=substr($$0,5); printf "\n%s%s%s\n", cB cM, section, c0; next } \
/^[A-Za-z0-9_%\-]+:.*##/ { name=$$1; gsub(/^[ \t]+|[ \t]+$$/,"",name); desc=$$2; gsub(/^[ \t]+|[ \t]+$$/,"",desc); danger=(name=="down-volumes"); tcol=danger?cR:cC; printf "%s%-22s%s %s\n", tcol, name, c0, desc; }' $(MAKEFILE_LIST)

##@ Bootstrap
init-env: ## Create local env files if missing
	@if [ -f .env.local ]; then \
		echo ".env.local already exists"; \
	else \
		SECRET=$$( \
			if command -v php >/dev/null 2>&1; then php -r 'echo bin2hex(random_bytes(32));'; \
			elif command -v openssl >/dev/null 2>&1; then openssl rand -hex 32; \
			else date +%s%N | sha256sum | cut -d" " -f1; fi \
		); \
		printf "APP_SECRET=%s\nHTTP_PORT=%s\n" "$$SECRET" "$(HTTP_PORT)" > .env.local; \
		echo "Created .env.local"; \
	fi
	@if [ "$(ENV)" = "dev" ]; then \
		if [ -f .env.dev.local ]; then \
			grep -q '^PG_LOCAL_PORT=' .env.dev.local || printf "PG_LOCAL_PORT=%s\n" "$(PG_LOCAL_PORT)" >> .env.dev.local; \
			grep -q '^POSTGRES_VERSION=' .env.dev.local || printf "POSTGRES_VERSION=%s\n" "$(POSTGRES_VERSION)" >> .env.dev.local; \
			echo ".env.dev.local already exists"; \
		else \
			printf "PG_LOCAL_PORT=%s\nPOSTGRES_VERSION=%s\nPOSTGRES_DB=%s\nPOSTGRES_USER=%s\nPOSTGRES_PASSWORD=%s\n" \
				"$(PG_LOCAL_PORT)" "$(POSTGRES_VERSION)" "$(POSTGRES_DB)" "$(POSTGRES_USER)" "$(POSTGRES_PASSWORD)" > .env.dev.local; \
			echo "Created .env.dev.local"; \
		fi; \
	fi

prepare-dev: ## Ensure local writable directories exist
	@mkdir -p var vendor
	@touch var/.gitkeep

init: init-env prepare-dev composer-install ## First-time dev setup

##@ Docker Compose
config: ## Show effective Docker Compose config
	$(COMPOSE) config

build: ## Build Docker images
	$(COMPOSE) build

up: init-env prepare-dev ## Start services in detached mode
	$(COMPOSE) up -d --remove-orphans

up-build: init-env prepare-dev ## Build images and start services
	$(COMPOSE) up --build -d --remove-orphans

down: ## Stop and remove containers and networks
	$(COMPOSE) down

down-volumes: ## Stop containers and remove named volumes
	$(COMPOSE) down --volumes

ps: ## Show service status
	$(COMPOSE) ps

logs: ## Tail logs from all services
	$(COMPOSE) logs -f --tail=200

logs-%: ## Tail logs from one service: make logs-php-web
	$(COMPOSE) logs -f --tail=200 $*

##@ PHP / Symfony
shell: ## Open shell in PHP container
	$(COMPOSE) exec $(PHP_CLI_SERVICE) bash

console: ## Run Symfony console command: make console ARGS="debug:router"
	$(COMPOSE) exec $(PHP_CLI_SERVICE) php bin/console $(ARGS)

cc: ## Clear Symfony cache
	$(COMPOSE) exec $(PHP_CLI_SERVICE) php bin/console cache:clear

migrate: ## Run Doctrine migrations
	$(COMPOSE) exec $(PHP_CLI_SERVICE) php bin/console doctrine:migrations:migrate --no-interaction

migration-status: ## Show Doctrine migration status
	$(COMPOSE) exec $(PHP_CLI_SERVICE) php bin/console doctrine:migrations:status

bootstrap-workspace: ## Create/update initial workspace and billing settings
	$(COMPOSE) exec $(PHP_CLI_SERVICE) php bin/console app:workspace:bootstrap $(ARGS)

send-statement-deliveries: ## Send queued account statement deliveries
	$(COMPOSE) exec $(PHP_CLI_SERVICE) php bin/console app:account-statement-deliveries:send $(ARGS)

demo-seed: ## Create/update demo dataset: make demo-seed ARGS="--size=small"
	$(COMPOSE) exec $(PHP_CLI_SERVICE) php bin/console app:demo:seed $(ARGS)

create-admin: ## Create first admin user: make create-admin ARGS="email@example.test"
	$(COMPOSE) exec $(PHP_CLI_SERVICE) php bin/console app:user:create-admin $(ARGS)

zm-stage: ## Stage Zavety Michurina PDF batch: make zm-stage ZM_WORKSPACE=demo ZM_IMPORT_PATH=local/imports/zavety-michurina
	$(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console app:zm:stage-electricity-statements $(ZM_STAGE_ARGS) "$(ZM_IMPORT_PATH)"

zm-apply: ## Apply staged Zavety Michurina batch: make zm-apply ZM_WORKSPACE=demo ZM_BATCH=<uuid>
	@test -n "$(strip $(ZM_BATCH))" || { echo "Usage: make zm-apply ZM_WORKSPACE=demo ZM_BATCH=<batch-uuid>"; exit 2; }
	$(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console app:zm:apply-statement-import-batch $(ZM_APPLY_ARGS) "$(ZM_BATCH)"

composer-install: prepare-dev ## Install Composer dependencies
	$(COMPOSE) run --rm --no-deps $(COMPOSER_SERVICE) install --no-interaction --prefer-dist

composer-update: prepare-dev ## Update Composer dependencies
	$(COMPOSE) run --rm --no-deps $(COMPOSER_SERVICE) update --no-interaction --prefer-dist

composer: ## Run Composer command: make composer ARGS="show"
	$(COMPOSE) run --rm --no-deps $(COMPOSER_SERVICE) $(ARGS)

db-shell: ## Open psql in dev PostgreSQL container
	$(COMPOSE) exec database psql -U $(POSTGRES_USER) -d $(POSTGRES_DB)

##@ Tests
test-db-create: ## Create test database if missing
	$(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console doctrine:database:create --env=test --if-not-exists

test-db-migrate: test-db-create ## Run migrations against test database
	$(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console doctrine:migrations:migrate --env=test --no-interaction

test-db-drop: ## Drop test database
	$(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console doctrine:database:drop --env=test --force --if-exists

test: test-db-migrate ## Run PHPUnit tests: make test ARGS="tests/AdminAccountControllerTest.php"
	$(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/phpunit $(ARGS)

e2e-smoke-seed: ## Prepare Playwright demo smoke user and workspace
	$(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console app:user:create-admin "$(E2E_EMAIL)" --password="$(E2E_PASSWORD)" --if-exists=skip
	$(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console app:demo:seed \
		--workspace-code="$(E2E_WORKSPACE_CODE)" \
		--workspace-name="$(E2E_WORKSPACE_NAME)" \
		--size="$(E2E_DEMO_SIZE)" \
		--as-of="$(E2E_DEMO_AS_OF)" \
		--grant-admin-email="$(E2E_EMAIL)" \
		--grant-subscriber-email="$(E2E_EMAIL)" \
		--reset \
		--confirm=demo

e2e-smoke: ## Run Playwright demo smoke against running app
	$(COMPOSE) run --rm e2e 'npm ci --no-audit --no-fund && npm run test:e2e:smoke -- $(ARGS)'

e2e-smoke-full: e2e-smoke-seed e2e-smoke ## Prepare demo smoke data and run Playwright smoke

fresh-install-smoke: prepare-dev ## Recreate isolated dev stack from empty volumes and run smoke checks
	set -eu
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" $(COMPOSE) down --volumes --remove-orphans
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" $(COMPOSE) up --build -d --remove-orphans --wait
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" $(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console doctrine:migrations:migrate --no-interaction
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" $(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console app:workspace:bootstrap "$(FRESH_SMOKE_WORKSPACE_CODE)" "$(FRESH_SMOKE_WORKSPACE_NAME)" --no-interaction
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" $(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console app:user:create-admin "$(FRESH_SMOKE_ADMIN_EMAIL)" --password="$(FRESH_SMOKE_ADMIN_PASSWORD)" --if-exists=skip --no-interaction
	curl -fsS -m 15 "http://127.0.0.1:$(FRESH_SMOKE_HTTP_PORT)/healthz"
	curl -fsS -m 15 -o /tmp/snt-fresh-smoke-login.html "http://127.0.0.1:$(FRESH_SMOKE_HTTP_PORT)/login"
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" $(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console doctrine:database:create --env=test --if-not-exists
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" $(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console doctrine:migrations:migrate --env=test --no-interaction
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" $(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/phpunit
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" E2E_EMAIL="$(E2E_EMAIL)" E2E_PASSWORD="$(E2E_PASSWORD)" E2E_WORKSPACE_NAME="$(E2E_WORKSPACE_NAME)" $(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console app:user:create-admin "$(E2E_EMAIL)" --password="$(E2E_PASSWORD)" --if-exists=skip
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" E2E_EMAIL="$(E2E_EMAIL)" E2E_PASSWORD="$(E2E_PASSWORD)" E2E_WORKSPACE_NAME="$(E2E_WORKSPACE_NAME)" $(COMPOSE) exec -T $(PHP_CLI_SERVICE) php bin/console app:demo:seed --workspace-code="$(E2E_WORKSPACE_CODE)" --workspace-name="$(E2E_WORKSPACE_NAME)" --size="$(E2E_DEMO_SIZE)" --as-of="$(E2E_DEMO_AS_OF)" --grant-admin-email="$(E2E_EMAIL)" --grant-subscriber-email="$(E2E_EMAIL)" --reset --confirm=demo
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" E2E_EMAIL="$(E2E_EMAIL)" E2E_PASSWORD="$(E2E_PASSWORD)" E2E_WORKSPACE_NAME="$(E2E_WORKSPACE_NAME)" $(COMPOSE) run --rm e2e 'npm ci --no-audit --no-fund && npm run test:e2e:smoke --'
	printf "\nFresh install smoke OK: http://127.0.0.1:%s/login\nAdmin: %s / %s\n" "$(FRESH_SMOKE_HTTP_PORT)" "$(FRESH_SMOKE_ADMIN_EMAIL)" "$(FRESH_SMOKE_ADMIN_PASSWORD)"

fresh-install-smoke-down: ## Remove isolated fresh install smoke containers and volumes
	COMPOSE_PROJECT_NAME="$(FRESH_SMOKE_PROJECT)" HTTP_PORT="$(FRESH_SMOKE_HTTP_PORT)" PG_LOCAL_PORT="$(FRESH_SMOKE_PG_LOCAL_PORT)" $(COMPOSE) down --volumes --remove-orphans

xdebug-on: ## Restart PHP with Xdebug enabled
	XDEBUG_MODE=debug,develop $(COMPOSE) up -d $(PHP_WEB_SERVICE) $(PHP_CLI_SERVICE)

xdebug-off: ## Restart PHP with Xdebug disabled
	XDEBUG_MODE=off $(COMPOSE) up -d $(PHP_WEB_SERVICE) $(PHP_CLI_SERVICE)

.PHONY: help init-env prepare-dev init config build up up-build down down-volumes ps logs logs-% \
        shell console cc migrate migration-status bootstrap-workspace send-statement-deliveries demo-seed create-admin zm-stage zm-apply composer-install composer-update composer db-shell \
        test-db-create test-db-migrate test-db-drop test e2e-smoke-seed e2e-smoke e2e-smoke-full fresh-install-smoke fresh-install-smoke-down xdebug-on xdebug-off
