SHELL := /bin/bash

# === Variables ===
AURORA        = .
PHP_BIN       = php
CONSOLE       = $(PHP_BIN) bin/console
COMPOSER      = composer
PNPM          = pnpm
PHP_CS_FIXER  = $(PHP_BIN) $(AURORA)/tools/php-cs-fixer/vendor/bin/php-cs-fixer
TWIG_CS_FIXER = $(PHP_BIN) $(AURORA)/tools/twig-cs-fixer/vendor/bin/twig-cs-fixer
PHPSTAN       = $(PHP_BIN) $(AURORA)/tools/phpstan/vendor/bin/phpstan
RECTOR        = $(PHP_BIN) $(AURORA)/tools/rector/vendor/bin/rector
RECTOR_CONFIG = $(if $(wildcard rector.php),rector.php,$(AURORA)/tools/rector/rector.php)

# === Build Commands ===
pnpm-setup: ## Setup pnpm via corepack (usage: make pnpm-setup VERSION=10.11.0)
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: Please specify a version. Usage: make pnpm-setup VERSION=x.y.z"; \
		exit 1; \
	fi
	corepack enable
	corepack prepare pnpm@$(VERSION) --activate
	@echo "PNPM $(VERSION) has been activated via corepack"

build: ## Build assets for production
	$(PNPM) --dir=$(AURORA) run build

dev: ## Start Vite dev server
	$(PNPM) --dir=$(AURORA) run dev

production: ## Install + build for production
	$(PNPM) --dir=$(AURORA) install --frozen-lockfile
	$(PNPM) --dir=$(AURORA) run build

# === Install & Update ===
setup-dirs: ## Create required runtime directories
	@mkdir -p var/cache var/log
	@echo "✅ Runtime directories created"

install-dev: ## Install for local development
	$(COMPOSER) install --working-dir=$(AURORA)
	$(COMPOSER) install --working-dir=$(AURORA)/tools/php-cs-fixer
	$(COMPOSER) install --working-dir=$(AURORA)/tools/twig-cs-fixer
	$(COMPOSER) install --working-dir=$(AURORA)/tools/rector
	$(COMPOSER) install --working-dir=$(AURORA)/tools/phpstan
	$(PNPM) --dir=$(AURORA) install
	make setup-dirs
	make migrate
	make sync-params
	make sync-menus
	make translation
	make dev

install-prod: ## Install for production
	$(COMPOSER) install --no-dev --optimize-autoloader --working-dir=$(AURORA)
	$(PNPM) --dir=$(AURORA) install --frozen-lockfile
	make setup-dirs
	make migrate-f
	make translation
	make build
	make cc-prod

deploy-prod: ## Deploy to production (requires a git tag on HEAD)
	@APP_VERSION=$$(git describe --exact-match --tags HEAD 2>/dev/null); \
	if [ -z "$$APP_VERSION" ]; then \
		echo "❌ HEAD has no exact git tag. Run: make tag VERSION=x.y.z"; \
		exit 1; \
	fi; \
	echo "🚀 Deploying version $$APP_VERSION..."; \
	echo "$$APP_VERSION" > VERSION; \
	$(COMPOSER) install --no-dev --optimize-autoloader --working-dir=$(AURORA); \
	$(PNPM) --dir=$(AURORA) install --frozen-lockfile; \
	$(CONSOLE) doctrine:migrations:migrate --no-interaction; \
	$(CONSOLE) aurora:application-parameter; \
	$(CONSOLE) aurora:menus:sync; \
	$(CONSOLE) aurora:privileges:sync; \
	$(CONSOLE) app:translations:dump-js; \
	$(PNPM) --dir=$(AURORA) run build; \
	APP_ENV=prod APP_DEBUG=0 $(CONSOLE) cache:clear --env=prod; \
	echo "✅ Deployed $$APP_VERSION"

update: ## Update all dependencies
	$(COMPOSER) update --working-dir=$(AURORA)
	$(COMPOSER) update --working-dir=$(AURORA)/tools/php-cs-fixer
	$(COMPOSER) update --working-dir=$(AURORA)/tools/twig-cs-fixer
	$(COMPOSER) update --working-dir=$(AURORA)/tools/rector
	$(COMPOSER) update --working-dir=$(AURORA)/tools/phpstan

autoload: ## Regenerate autoloading according to PSR4
	$(COMPOSER) dump-autoload --working-dir=$(AURORA)

autoload-opti: ## Optimize autoloading for caching
	$(COMPOSER) dump-autoload --optimize --working-dir=$(AURORA)

outdated: ## Show outdated packages
	$(COMPOSER) outdated --working-dir=$(AURORA)

# === Release ===
tag: ## Create and push a new version tag (usage: make tag VERSION=1.2.3)
	@test -n "$(VERSION)" || (echo "❌ Usage: make tag VERSION=1.2.3" && exit 1)
	@git tag -a "$(VERSION)" -m "Release $(VERSION)"
	@git push origin "$(VERSION)"
	@echo "✅ Tag $(VERSION) pushed"

# === Symfony Cache ===
cc: ## Clear cache (dev)
	$(CONSOLE) cache:clear

cc-dev: ## Clear cache (dev)
	$(CONSOLE) cache:clear

cc-prod: ## Clear and warm up production cache
	@echo "Clearing and regenerating production cache..."
	APP_ENV=prod APP_DEBUG=0 $(CONSOLE) cache:clear --env=prod
	@APP_ENV=prod APP_DEBUG=0 $(CONSOLE) about --env=prod >/dev/null 2>&1 || (echo "❌ Cache verification failed: application could not boot" && exit 1)
	@echo "✅ Production cache regenerated successfully"

warmup: ## Warm up cache
	$(CONSOLE) cache:warmup

purge: ## Remove all cache and log files
	rm -rf var/cache/* var/logs/*

purge-uploads: ## Remove all stored files under var/uploads/ (keeps .gitignore)
	find var/uploads -mindepth 1 -not -name '.gitignore' -delete 2>/dev/null || true

# === Docker ===
docker-up: ## Start all local services (mailpit + docTR)
	docker compose up -d mailer
	docker compose --profile ocr up -d --build doctr

docker-down: ## Stop all local services (mailpit + docTR)
	docker compose stop mailer
	docker compose --profile ocr stop doctr

# === Mailpit ===
mailpit-up: ## Start mailpit
	docker compose up -d mailer

mailpit-down: ## Stop mailpit
	docker compose stop mailer

mailpit-logs: ## Tail mailpit logs
	docker compose logs -f mailer

# === Symfony ===
start: ## Start dev server + Vite dev server
	@docker compose up -d mailer 2>/dev/null || true
	symfony server:start -d
	$(PNPM) --dir=$(AURORA) run dev

start-no-tls: ## Start dev server without TLS
	symfony server:start --no-tls -d

start-d: ## Start dev server in background
	symfony server:start -d

stop: ## Stop dev server
	symfony server:stop
	@docker compose stop database 2>/dev/null || true

start-dev-worker: ## Start the messenger worker (async + scheduler)
	@touch var/.messenger-dev-worker-running
	@trap 'rm -f var/.messenger-dev-worker-running; exit' INT TERM EXIT; \
	while true; do $(CONSOLE) messenger:consume async scheduler_main -vv --time-limit=3600 --memory-limit=512M || sleep 1; done

# === OCR (docTR microservice) ===
ocr-up: ## Build & start the docTR microservice (port 8001)
	docker compose --profile ocr up -d --build doctr

ocr-down: ## Stop the docTR microservice
	docker compose --profile ocr stop doctr

ocr-logs: ## Tail docTR logs
	docker compose --profile ocr logs -f doctr

ocr-restart: ## Restart docTR
	docker compose --profile ocr restart doctr

ocr-rebuild: ## Stop, rebuild & start the docTR microservice (use after editing tools/docker/doctr)
	docker compose --profile ocr down doctr
	docker compose --profile ocr up -d --build doctr

routes: ## List all registered routes
	$(CONSOLE) debug:router --show-controllers

sf: ## Run any Symfony console command (usage: make sf CMD="debug:container")
	$(CONSOLE) $(CMD)

about: ## Show app info
	$(CONSOLE) about

# === Fixtures & Dev ===
fixtures: ## Drop DB, re-run migrations and load fixtures
	$(CONSOLE) doctrine:database:drop --force --if-exists
	$(CONSOLE) doctrine:database:create --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate --no-interaction
	$(CONSOLE) doctrine:fixtures:load --no-interaction
	@echo "✅ Fixtures loaded"

demo: purge-uploads ## Purge var/uploads/ then load demo fixtures + run all syncs
	$(CONSOLE) doctrine:fixtures:load --group=demo --no-interaction
	$(CONSOLE) aurora:application-parameter
	$(CONSOLE) aurora:menus:sync
	$(CONSOLE) aurora:privileges:sync
	@echo "✅ Demo data loaded"

fixtures-load: ## Load fixtures without dropping DB
	$(CONSOLE) doctrine:fixtures:load --no-interaction

fixtures-append: ## Append fixtures without dropping DB
	$(CONSOLE) doctrine:fixtures:load --append --no-interaction

# === Database ===
db-create: ## Create the database
	$(CONSOLE) doctrine:database:create --if-not-exists

db-drop: ## Drop the database
	$(CONSOLE) doctrine:database:drop --force --if-exists

migration: ## Generate a new migration
	$(CONSOLE) make:migration

migrate: ## Run pending migrations
	$(CONSOLE) doctrine:migrations:migrate

migrate-f: ## Run migrations without interaction
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

migrate-prev: ## Rollback last migration
	$(CONSOLE) doctrine:migrations:migrate prev

migrate-check: ## Warn loud if the dev DB has pending migrations (called by `make ft`)
	@if ! $(CONSOLE) doctrine:migrations:up-to-date --no-interaction >/dev/null 2>&1; then \
		echo ""; \
		printf '\033[33;1m⚠️  PENDING MIGRATIONS DETECTED ON DEV DB ⚠️\033[0m\n'; \
		$(CONSOLE) doctrine:migrations:up-to-date --no-interaction || true; \
		printf '\033[36mRun \033[1mmake migrate\033[0;36m to sync the dev DB with the code.\033[0m\n'; \
		echo ""; \
	fi

migration-generate: ## Generate a blank migration
	$(CONSOLE) doctrine:migrations:generate

migration-diff: ## Generate a migration from entity changes
	$(CONSOLE) doctrine:migrations:diff

sync: ## Run all sync commands (params, menus, privileges)
	make sync-params
	make sync-menus
	make sync-privileges

sync-params: ## Synchronise application parameters (creates missing, deletes obsolete)
	$(CONSOLE) aurora:application-parameter

sync-menus: ## Create missing menus for registered locations (primary, footer, …)
	$(CONSOLE) aurora:menus:sync

sync-privileges: ## Purge obsolete privileges from users after module changes
	$(CONSOLE) aurora:privileges:sync

sync-sequences: ## Resync all PostgreSQL sequences to MAX(id)+1 (run after fixture loads or data imports)
	$(CONSOLE) aurora:sequences:resync

module-sync: ## After scaffolding a new module: sync privileges + menus + params, dump JSON translations, rebuild Vite bundle. Run once after the `/add-module` skill finishes.
	make sync-privileges
	make sync-menus
	make sync-params
	make translation
	make build

translation: ## Dump Symfony YAML translations to src/Core/Frontend/locales/generated/*.json + clear cache so changes show up immediately in dev
	$(CONSOLE) app:translations:dump-js
	$(CONSOLE) cache:clear

schema-validate: ## Validate the Doctrine schema
	$(CONSOLE) doctrine:schema:validate -vvv

# === Tests ===
test: test-frontend test-backend ## Run all tests (frontend + backend)

test-backend: db-test ## Run all backend tests (PHPUnit)
	$(PHP_BIN) $(AURORA)/bin/phpunit --testdox

test-backend-unit: ## Run backend unit tests
	$(PHP_BIN) $(AURORA)/bin/phpunit --testdox --testsuite=Unit

test-backend-integration: db-test ## Run backend integration tests
	$(PHP_BIN) $(AURORA)/bin/phpunit --testdox --testsuite=Integration

test-frontend: translation ## Run frontend unit tests (Vitest)
	$(PNPM) --dir=$(AURORA) run test

test-e2e: ## Run end-to-end tests (Playwright)
	$(PNPM) --dir=$(AURORA) run test:e2e

coverage: db-test ## Generate PHP code coverage report (requires php8.4-pcov)
	$(PHP_BIN) -d pcov.enabled=1 $(AURORA)/bin/phpunit --coverage

db-test: ## Create and migrate the test database
	$(CONSOLE) doctrine:database:create --env=test --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate --env=test --no-interaction

# === Code Quality ===
stan: ## Run PHPStan
	$(PHPSTAN) analyse -c $(AURORA)/tools/phpstan/phpstan.neon --memory-limit 1G

lint-php: ## Check PHP code style (dry-run)
	$(PHP_CS_FIXER) fix --dry-run --config=$(AURORA)/.php-cs-fixer.dist.php

lint-js: ## Check JS code style
	$(AURORA)/node_modules/.bin/eslint --config $(AURORA)/eslint.config.cjs .

lint-twig: ## Check Twig code style
	$(TWIG_CS_FIXER)

rector: ## Run Rector (dry-run)
	$(RECTOR) process --dry-run -c $(RECTOR_CONFIG)

fix-php: ## Fix PHP code style
	$(PHP_CS_FIXER) fix --config=$(AURORA)/.php-cs-fixer.dist.php

fix-js: ## Fix JS code style
	$(AURORA)/node_modules/.bin/eslint --config $(AURORA)/eslint.config.cjs --fix .

fix-twig: ## Fix Twig code style
	$(TWIG_CS_FIXER) --fix

fix-rector: ## Apply Rector suggestions
	$(RECTOR) process -c $(RECTOR_CONFIG)

fix: ## Run all fixers + stan
	make translation
	make fix-js
	make fix-twig
	make fix-rector
	make fix-php
	make stan

fd: ## Fix code and build dev assets
	make fix && make dev

ft: ## Fix, test, build assets, then migrate-check
	make fix && make test && make build && make migrate-check

ftl: ## Light: fix + test + migrate-check (no asset build)
	make fix && make test && make migrate-check

# === Packaging / Monorepo split ===
split-module: ## Split + push one module to its own GitHub repo (usage: make split-module REPO=aurora-crm)
	@test -n "$(REPO)" || (echo "❌ Usage: make split-module REPO=aurora-crm (or aurora-commerce, aurora-tools, …)" && exit 1)
	bin/split-modules.sh "$(REPO)"

split-modules: ## Split + push every module to its own GitHub repo (the 12 module packages)
	bin/split-modules.sh

# === Claude Memory ===
sync-claude-memory: ## Sync .claude/memory/ + docs/aurora-{core,client}/ into the global Claude memory for this project
	@DEST="$(HOME)/.claude/projects/$$(pwd | sed 's|/|-|g')/memory"; \
	mkdir -p "$$DEST"; \
	rsync -a --delete --include="*.md" --include="*/" --exclude="*" .claude/memory/ "$$DEST/"; \
	mkdir -p "$$DEST/docs"; \
	rsync -a --delete --include="*.md" --include="*/" --exclude="*" docs/aurora-core/ "$$DEST/docs/aurora-core/"; \
	rsync -a --delete --include="*.md" --include="*/" --exclude="*" docs/aurora-client/ "$$DEST/docs/aurora-client/"; \
	rsync -a --delete --include="*.md" --include="*/" --exclude="*" docs/aurora-shared/ "$$DEST/docs/aurora-shared/"; \
	MEM=$$(find .claude/memory -name '*.md' | wc -l | tr -d ' '); \
	DOC=$$(find docs/aurora-core docs/aurora-client docs/aurora-shared -name '*.md' | wc -l | tr -d ' '); \
	echo "✅ $$MEM fichiers mémoire + $$DOC fichiers docs synchronisés → $$DEST"

# === Setup ===
setup-env: ## Create .env.local from .env.local.example template
	@if [ -f .env.local ]; then \
		echo "⚠️  .env.local already exists. Overwrite? (yes/no)"; \
		read -p "" confirm && [ "$$confirm" = "yes" ] || (echo "❌ Cancelled." && exit 1); \
	fi
	cp .env.local.example .env.local
	@echo "✅ .env.local created from .env.local.example — edit it with your local values"

.PHONY: help
help: ## Show this help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-30s\033[0m %s\n", $$1, $$2}'
