SHELL := /bin/bash

# === Variables ===
AURORA        = vendor/axelraboit/aurora
PHP_BIN       = php
CONSOLE       = $(PHP_BIN) bin/console
COMPOSER      = composer
PNPM          = pnpm
PHP_CS_FIXER  = $(PHP_BIN) $(AURORA)/tools/php-cs-fixer/vendor/bin/php-cs-fixer
TWIG_CS_FIXER = $(PHP_BIN) $(AURORA)/tools/twig-cs-fixer/vendor/bin/twig-cs-fixer
PHPSTAN       = $(PHP_BIN) $(AURORA)/tools/phpstan/vendor/bin/phpstan
RECTOR        = $(PHP_BIN) $(AURORA)/tools/rector/vendor/bin/rector
RECTOR_CONFIG = $(if $(wildcard rector.php),rector.php,$(AURORA)/tools/rector/rector.php)

# AURORA_CLIENT_DIR is read by aurora's vite.config.js / app.js to scan this
# project's custom Vue components, Overrides and locales. It points at the
# client project root since 0.5 - aurora-core scans src/Module/<X>/assets/,
# src/Overrides/ and src/locales/ via the @client alias (mirrors aurora-core's
# own co-located layout).
# NODE_PATH lets Node resolve packages (vue, vue-i18n, …) from aurora's
# node_modules even when the importing file lives outside vendor/aurora.
AURORA_NODE   = $(CURDIR)/$(AURORA)/node_modules
AURORA_ENV    = AURORA_CLIENT_DIR="$(CURDIR)" NODE_PATH="$(AURORA_NODE)"

# Marker file written by `aurora-update`, read by `pull-update` to refuse
# the redundant `aurora-update && pull-update` chain (pull-update right
# after aurora-update is a no-op at best, and at worst confuses teammates
# by silently downgrading the lock if they swap the order in their head).
AURORA_UPDATE_MARKER = var/.aurora-update-marker

# === Safety guards ===
# Refuse destructive targets when APP_ENV=prod. Checks the shell env first,
# then falls back to grepping .env.local (Symfony's loaded layer). Default
# to dev when nothing is set, so fresh checkouts still work.
_require-dev-env:
	@detected_env="$${APP_ENV:-}"; \
	if [ -z "$$detected_env" ] && [ -f .env.local ]; then \
		detected_env=$$(grep "^APP_ENV=" .env.local | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'"); \
	fi; \
	if [ -z "$$detected_env" ]; then detected_env="dev"; fi; \
	if [ "$$detected_env" = "prod" ]; then \
		echo "❌ Refused: target is destructive (drops DB / wipes data via fixtures) and APP_ENV=prod."; \
		echo "   For prod, use 'make install-prod' or run the SQL/migrations manually."; \
		exit 1; \
	fi

# Refuse pull-update when aurora-update was run recently (< 5 min ago).
# pull-update right after aurora-update is at best a no-op (the lock is
# already past the team's version) and at worst a sign the user got the
# order wrong. The correct order is `pull-update && aurora-update`.
_no-recent-aurora-update:
	@if [ -f $(AURORA_UPDATE_MARKER) ]; then \
		ago=$$(($$(date +%s) - $$(stat -c %Y $(AURORA_UPDATE_MARKER) 2>/dev/null || stat -f %m $(AURORA_UPDATE_MARKER)))); \
		if [ "$$ago" -lt 300 ]; then \
			echo "❌ Refused: 'aurora-update' was run $${ago}s ago."; \
			echo "   Running 'pull-update' right after is redundant - composer.lock is already past the team's version."; \
			echo "   The correct order is 'pull-update && aurora-update' (sync to team, then bump on top)."; \
			echo "   If you really want to revert to the team's lock, run:"; \
			echo "      git checkout composer.lock && make pull-update"; \
			exit 1; \
		fi \
	fi

.PHONY: _require-dev-env _no-recent-aurora-update

# === Build Commands ===
pnpm-setup: ## Setup pnpm via corepack (usage: make pnpm-setup VERSION=10.11.0)
	@if [ -z "$(VERSION)" ]; then \
		echo "Error: Please specify a version. Usage: make pnpm-setup VERSION=x.y.z"; \
		exit 1; \
	fi
	corepack enable
	corepack prepare pnpm@$(VERSION) --activate
	@echo "PNPM $(VERSION) has been activated via corepack"

aurora-vendor-guard: ## Restore aurora-core's own vendor/ if composer wiped it
	@# aurora-core's package.json depends on its OWN nested vendor
	@# ("@symfony/ux-vue": "file:vendor/symfony/ux-vue/assets"), which composer
	@# deletes whenever it re-extracts the package. `make aurora-update` puts it
	@# back, but a bare `composer update axelraboit/aurora` doesn't - and the
	@# breakage only surfaces later, as an opaque ENOENT from pnpm. Cheap to
	@# check, so build/dev repair it themselves rather than failing.
	@if [ ! -d "$(AURORA)/vendor/symfony/ux-vue/assets" ]; then \
		echo "⚠️  aurora-core's nested vendor/ is missing (bare composer update?) - restoring"; \
		$(COMPOSER) install --working-dir=$(AURORA) --no-scripts; \
	fi
	@# The linters live in their own nested installs and are wiped by the same
	@# re-extraction. Restored here too: `make lint-php` otherwise dies on
	@# "Could not open input file", which reads like a broken checkout rather
	@# than a missing dependency.
	@for tool in php-cs-fixer twig-cs-fixer rector phpstan; do \
		if [ ! -d "$(AURORA)/tools/$$tool/vendor" ]; then \
			echo "⚠️  aurora-core's $$tool is missing - restoring"; \
			$(COMPOSER) install --working-dir=$(AURORA)/tools/$$tool --no-interaction; \
		fi; \
	done

build: aurora-vendor-guard ## Build assets for production
	$(AURORA_ENV) $(PNPM) --dir=$(AURORA) run build

build-prod: ## Build assets on a production server (no dev-only vendor guard)
	# Same build as `build`, minus `aurora-vendor-guard`. That guard restores
	# aurora-core's linters (php-cs-fixer, phpstan, rector, twig-cs-fixer) when
	# they are missing, which on a prod server means installing dev tooling
	# nobody asked for. install-prod / deploy-prod restore the one thing the
	# build actually needs - aurora-core's nested vendor/ - themselves.
	#
	# $(AURORA_ENV) is NOT optional. It carries AURORA_CLIENT_DIR, which
	# aurora-core's `prebuild` hook (bin/dump-translations) reads to decide
	# whose console to run. Without it the hook runs aurora-core's OWN console,
	# booting its kernel from its nested vendor - installed --no-dev here, so
	# the dev bundles its config/bundles.php expects are gone and the build dies
	# on `Class "Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle" not found`.
	$(AURORA_ENV) $(PNPM) --dir=$(AURORA) run build

dev: aurora-vendor-guard ## Start Vite dev server
	$(AURORA_ENV) $(PNPM) --dir=$(AURORA) run dev

production: ## Install + build for production
	$(PNPM) --dir=$(AURORA) install --frozen-lockfile
	$(PNPM) --dir=$(AURORA) run build

# === Install & Update ===
setup-dirs: ## Create required runtime directories
	@mkdir -p var/cache var/log
	@echo "✅ Runtime directories created"

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
tag: ## Superseded - releases are published from master by .github/workflows/release.yml
	@echo "❌ 'make tag' ne sert plus, et pouvait nuire."
	@echo ""
	@echo "   Il créait un tag sans release et sans préfixe 'v', à côté du flux."
	@echo ""
	@echo "   Pour publier une version : merger develop sur master."
	@echo "   Le workflow calcule le numéro depuis les commits conventionnels"
	@echo "   (feat -> mineure, rupture -> majeure, sinon patch), tague et publie."
	@exit 1

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

# === Docker ===
docker-up: ## Start database container
	docker compose up -d database

docker-down: ## Stop database container
	docker compose stop database

# === Symfony ===
start: ## Start dev server + Vite dev server
	@docker compose up -d database 2>/dev/null || true
	symfony server:start -d
	@[ -d "$(AURORA)/vendor" ] || $(COMPOSER) install --working-dir=$(AURORA) --no-scripts
	@[ -d "$(AURORA)/node_modules" ] || $(PNPM) --dir=$(AURORA) install
	$(AURORA_ENV) $(PNPM) --dir=$(AURORA) run dev

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

routes: ## List all registered routes
	$(CONSOLE) debug:router --show-controllers

sf: ## Run any Symfony console command (usage: make sf CMD="debug:container")
	$(CONSOLE) $(CMD)

about: ## Show app info
	$(CONSOLE) about

# === Fixtures & Dev ===
fixtures: _require-dev-env ## Drop DB, schema:create from entities, load fixtures and sync all (DEV ONLY)
	$(CONSOLE) doctrine:database:drop --force --if-exists
	$(CONSOLE) doctrine:database:create --if-not-exists
	# schema:create + mark all migrations applied - workaround for the
	# multi-namespace Doctrine Migrations interleave (see install-dev for
	# rationale, or vendor/axelraboit/aurora/docs/aurora-client/dev/database.md).
	$(CONSOLE) doctrine:schema:create
	$(CONSOLE) doctrine:migrations:sync-metadata-storage --no-interaction
	$(CONSOLE) doctrine:migrations:version --add --all --no-interaction
	# messenger_messages isn't a Doctrine entity, so schema:create above
	# skips it, and marking migrations as applied never runs the migration
	# that creates it - messenger:consume would fail with "relation
	# messenger_messages does not exist" otherwise.
	$(CONSOLE) messenger:setup-transports
	# aurora:install seeds the structure the demo fixtures build on, and
	# --append keeps doctrine:fixtures:load from purging it back out:
	# the default purger empties every table, including the one holding
	# the post types the fixtures are about to look up.
	$(CONSOLE) aurora:install
	$(CONSOLE) doctrine:fixtures:load --no-interaction --append
	$(CONSOLE) aurora:application-parameter
	$(CONSOLE) aurora:privileges:sync
	@echo "✅ Fixtures loaded"

demo: _require-dev-env ## Load demo fixtures (DemoFixtures group) + run all syncs (DEV ONLY)
	# aurora:install seeds the structure the demo fixtures build on, and
	# --append keeps doctrine:fixtures:load from purging it back out:
	# the default purger empties every table, including the one holding
	# the post types the fixtures are about to look up.
	$(CONSOLE) aurora:install
	$(CONSOLE) doctrine:fixtures:load --group=demo --no-interaction --append
	$(CONSOLE) aurora:application-parameter
	$(CONSOLE) aurora:privileges:sync
	@echo "✅ Demo data loaded"

fixtures-load: _require-dev-env ## Load fixtures without dropping DB - purges tables before re-inserting (DEV ONLY)
	# aurora:install seeds the structure the demo fixtures build on, and
	# --append keeps doctrine:fixtures:load from purging it back out:
	# the default purger empties every table, including the one holding
	# the post types the fixtures are about to look up.
	$(CONSOLE) aurora:install
	$(CONSOLE) doctrine:fixtures:load --no-interaction --append

fixtures-append: ## Append fixtures without dropping DB (safe in any env)
	# aurora:install seeds the structure the demo fixtures build on, and
	# --append keeps doctrine:fixtures:load from purging it back out:
	# the default purger empties every table, including the one holding
	# the post types the fixtures are about to look up.
	$(CONSOLE) aurora:install
	$(CONSOLE) doctrine:fixtures:load --append --no-interaction

# === Database ===
db-create: ## Create the database
	$(CONSOLE) doctrine:database:create --if-not-exists

db-drop: _require-dev-env ## Drop the database (DEV ONLY)
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

# Both targets pin ClientMigrations. Two namespaces are registered - aurora's
# own (vendor/axelraboit/aurora/migrations) and the project's - and doctrine
# picks the first when it is not told, so an unqualified diff writes the
# client's migration inside vendor/, where it is read-only, unversioned and
# wiped by the next composer update.
migration-generate: ## Generate a blank migration
	$(CONSOLE) doctrine:migrations:generate --namespace=ClientMigrations

migration-diff: ## Generate a migration from entity changes
	$(CONSOLE) doctrine:migrations:diff --namespace=ClientMigrations

sync-params: ## Synchronise application parameters (creates missing, deletes obsolete)
	$(CONSOLE) aurora:application-parameter

install-data: ## Create every module's seed data - locales, theme, post types, taxonomies, menus (idempotent)
	$(CONSOLE) aurora:install

sync-privileges: ## Purge obsolete privileges from users after module changes
	$(CONSOLE) aurora:privileges:sync

module-sync: ## After scaffolding a new module: sync privileges + seed data + params, dump JSON translations, rebuild Vite bundle. Run once after `aurora:make:module`.
	make sync-privileges
	make install-data
	make sync-params
	make translation
	make build

translation: ## Dump Symfony YAML translations to src/Core/assets/locales/generated/*.json + clear cache so changes show up immediately in dev
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
	$(if $(wildcard vitest.config.js),$(PNPM) run test,$(PNPM) --dir=$(AURORA) run test)

test-e2e: ## Run end-to-end tests (Playwright)
	$(PNPM) --dir=$(AURORA) run test:e2e

coverage: db-test ## Generate PHP code coverage report (requires php8.4-pcov)
	$(PHP_BIN) -d pcov.enabled=1 $(AURORA)/bin/phpunit --coverage

db-test: _require-dev-env ## Create test database with fresh schema - schema:create avoids cross-namespace migration ordering issues (DEV ONLY)
	$(CONSOLE) doctrine:database:drop --env=test --force --if-exists
	$(CONSOLE) doctrine:database:create --env=test
	$(CONSOLE) doctrine:schema:create --env=test
	$(CONSOLE) doctrine:migrations:sync-metadata-storage --env=test
	$(CONSOLE) doctrine:migrations:version --env=test --add --all --no-interaction

# === Code Quality ===
stan: aurora-vendor-guard ## Run PHPStan
	$(PHPSTAN) analyse -c $(AURORA)/tools/phpstan/phpstan.neon --memory-limit 1G

lint-php: aurora-vendor-guard ## Check PHP code style (dry-run)
	$(PHP_CS_FIXER) fix --dry-run --config=$(AURORA)/.php-cs-fixer.dist.php

lint-js: ## Check JS code style
	$(AURORA)/node_modules/.bin/eslint --config $(AURORA)/eslint.config.cjs .

lint-twig: aurora-vendor-guard ## Check Twig code style
	$(TWIG_CS_FIXER)

rector: aurora-vendor-guard ## Run Rector (dry-run)
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
	# First, mirroring CI: `composer validate` is its opening step, so a stale
	# lock fails the pipeline before any other check runs. Renaming the package
	# invalidates the lock's content hash, and without this the only way to
	# find out is a red pipeline.
	$(COMPOSER) validate
	make fix-js
	make fix-twig
	make fix-rector
	make fix-php
	make stan

fd: ## Fix code and build dev assets
	make fix && make dev

ft: ## Fix code and run all tests
	make fix && make test && make migrate-check

# === Setup ===
setup-env: ## Create .env.local from .env.local.example template, with APP_SECRET + Aurora keys auto-generated
	@if [ -f .env.local ]; then \
		echo "⚠️  .env.local already exists. Overwrite? (yes/no)"; \
		read -p "" confirm && [ "$$confirm" = "yes" ] || (echo "❌ Cancelled." && exit 1); \
	fi
	cp .env.local.example .env.local
	@php -r '$$c = file_get_contents(".env.local"); $$c = preg_replace("/^APP_SECRET=.*/m", "APP_SECRET=" . bin2hex(random_bytes(16)), $$c, 1); file_put_contents(".env.local", $$c);'
	@php -r '$$c = file_get_contents(".env.local"); $$c = preg_replace("/^AURORA_MOUNT_POINT_KEY=.*/m", "AURORA_MOUNT_POINT_KEY=" . base64_encode(random_bytes(32)), $$c, 1); file_put_contents(".env.local", $$c);'
	@php -r '$$c = file_get_contents(".env.local"); $$c = preg_replace("/^AURORA_ENCRYPTION_KEY=.*/m", "AURORA_ENCRYPTION_KEY=" . base64_encode(random_bytes(32)), $$c, 1); file_put_contents(".env.local", $$c);'
	@echo "✅ .env.local created - APP_SECRET + Aurora keys generated automatically."
	@echo "   Review DATABASE_URL before running 'make install-dev'."

.PHONY: help
help: ## Show this help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-30s\033[0m %s\n", $$1, $$2}'
# === Client overrides ===
# These targets replace aurora's defaults with client-specific behaviour.

install: install-dev ## Install the project (alias for install-dev)

install-dev: _require-dev-env ## Install for local development - full reset: drops DB, schema:create, fixtures (DEV ONLY)
	$(COMPOSER) install --no-scripts
	$(COMPOSER) install --working-dir=$(AURORA) --no-scripts
	$(COMPOSER) install --working-dir=$(AURORA)/tools/php-cs-fixer
	$(COMPOSER) install --working-dir=$(AURORA)/tools/twig-cs-fixer
	$(COMPOSER) install --working-dir=$(AURORA)/tools/rector
	$(COMPOSER) install --working-dir=$(AURORA)/tools/phpstan
	$(PNPM) --dir=$(AURORA) install
	$(PNPM) install
	make setup-dirs
	# Wipe + recreate DB unconditionally - install-dev is "from scratch" by
	# contract (fixtures-load below would purge anyway).
	$(CONSOLE) doctrine:database:drop --force --if-exists
	$(CONSOLE) doctrine:database:create
	# schema:create + mark all migrations applied - workaround for the
	# multi-namespace Doctrine Migrations interleave issue. `make migrate`
	# would plant here on fresh DB (ClientMigrations + DoctrineMigrations
	# don't merge strictly by timestamp). Cf.
	# vendor/axelraboit/aurora/docs/aurora-client/dev/database.md
	# ("DB fresh : make migrate ne marche pas").
	$(CONSOLE) doctrine:schema:create
	$(CONSOLE) doctrine:migrations:sync-metadata-storage --no-interaction
	$(CONSOLE) doctrine:migrations:version --add --all --no-interaction
	# messenger_messages isn't a Doctrine entity, so schema:create above
	# skips it, and marking migrations as applied never runs the migration
	# that creates it - messenger:consume would fail with "relation
	# messenger_messages does not exist" otherwise.
	$(CONSOLE) messenger:setup-transports
	# Mandatory data (locales, built-in post types, …) before the fixtures,
	# which now build sample content on top of it instead of creating it.
	$(CONSOLE) aurora:install
	$(CONSOLE) doctrine:fixtures:load --no-interaction --append
	$(CONSOLE) aurora:application-parameter
	$(CONSOLE) aurora:privileges:sync
	make dev
	@echo "✅ Admin user: admin@aurora.app / password"

install-prod: ## Install for production - first install on a fresh server (empty DB)
	$(COMPOSER) install --no-dev --optimize-autoloader
	# aurora-core's package.json points at its OWN nested vendor
	# ("@symfony/ux-vue": "file:vendor/symfony/ux-vue/assets"), which the
	# composer install above just wiped by re-extracting the package. Without
	# this restore, the pnpm install below dies on an opaque ENOENT on a path
	# nobody wrote by hand. Same guard as `aurora-vendor-guard`, minus the
	# linters - they are dev tooling and have no business on a prod server.
	$(COMPOSER) install --working-dir=$(AURORA) --no-dev --no-scripts --no-interaction
	$(PNPM) --dir=$(AURORA) install --frozen-lockfile
	make setup-dirs
	make db-install-prod
	# Without this a production install has no locale - every frontend URL
	# answers 404 - and no post type, so no content can be created at all.
	# It used to come from fixtures, which never run here.
	$(CONSOLE) aurora:install
	$(CONSOLE) aurora:application-parameter
	make build-prod
	make cc-prod

db-install-prod: ## Initial prod schema - schema:create + mark every migration applied
	# NOT `make migrate-f`. On a virgin database the migration chain plants:
	# Doctrine Migrations 3.x walks namespaces in declaration order rather than
	# strictly by version, so a ClientMigrations entry extending a core table
	# runs before the AuroraMigrations entry that creates it. Symptom:
	# `relation "core_<table>" does not exist`. Same reason `install-dev` uses
	# schema:create. Cf. vendor/axelraboit/aurora/docs/aurora-client/dev/database.md
	# ("DB fresh : make migrate ne marche pas").
	#
	# `deploy-prod` keeps `migrations:migrate` - on an already-installed server
	# the chain is incremental and the ordering issue does not arise.
	$(CONSOLE) doctrine:schema:create
	$(CONSOLE) doctrine:migrations:sync-metadata-storage --no-interaction
	$(CONSOLE) doctrine:migrations:version --add --all --no-interaction
	# messenger_messages is created by a migration, and the line above marked
	# that migration applied without running it. The transport DSN carries
	# auto_setup=0, so nothing else would ever create the table: the worker
	# would start and fail on every message.
	$(CONSOLE) messenger:setup-transports

deploy-prod: ## Deploy to production (requires a git tag on HEAD)
	@APP_VERSION=$$(git describe --exact-match --tags HEAD 2>/dev/null); \
	if [ -z "$$APP_VERSION" ]; then \
		echo "❌ HEAD has no exact git tag. Run: make tag VERSION=x.y.z"; \
		exit 1; \
	fi; \
	echo "🚀 Deploying version $$APP_VERSION..."; \
	echo "$$APP_VERSION" > VERSION; \
	set -e; \
	$(COMPOSER) install --no-dev --optimize-autoloader; \
	$(COMPOSER) install --working-dir=$(AURORA) --no-dev --no-scripts --no-interaction; \
	$(PNPM) --dir=$(AURORA) install --frozen-lockfile; \
	$(CONSOLE) doctrine:migrations:migrate --no-interaction; \
	$(CONSOLE) aurora:application-parameter; \
	$(CONSOLE) aurora:install; \
	make build-prod; \
	make cc-prod; \
	echo "✅ Deployed $$APP_VERSION"

sync-jsconfig: ## Regenerate jsconfig.json from aurora module aliases
	node $(AURORA)/bin/sync-client-jsconfig

sync-env: ## Add any missing `###> aurora/* ###` block to .env (never overwrites existing values)
	@bash $(AURORA)/bin/sync-client-env

sync-readme: ## Sync the aurora-canonical block of README.md (between markers); preserves title + 'Spécifique à ce projet'
	@bash $(AURORA)/bin/sync-client-readme

make-frontend: ## Scaffold a new frontend module (header, footer, layout, home). Usage: make make-frontend name=MyModule
	@if [ -z "$(name)" ]; then \
		read -p "Module name (PascalCase, e.g. Billing): " name && \
		$(AURORA)/bin/make-frontend "$$name"; \
	else \
		$(AURORA)/bin/make-frontend "$(name)"; \
	fi

sync-security: ## Sync security.yaml from aurora vendor (routes change with each aurora release)
	cp $(AURORA)/config/packages/security.yaml config/packages/security.yaml
	@echo "✅ security.yaml synced from aurora"

pull-update: _no-recent-aurora-update ## After a regular `git pull`: install deps from lock + migrate + cache + syncs
	# Use THIS after pulling a teammate's PR - preserves your local data
	# (unlike `make install-dev` which wipes the DB via fixtures-load).
	# Covers composer.lock changes, package.json changes, new migrations,
	# and config drift (jsconfig / security / CLAUDE.md / Makefile syncs).
	# Safe to run unconditionally - every step is idempotent.
	$(COMPOSER) install
	$(COMPOSER) install --working-dir=$(AURORA) --no-scripts
	$(COMPOSER) install --working-dir=$(AURORA)/tools/php-cs-fixer
	$(COMPOSER) install --working-dir=$(AURORA)/tools/twig-cs-fixer
	$(COMPOSER) install --working-dir=$(AURORA)/tools/rector
	$(COMPOSER) install --working-dir=$(AURORA)/tools/phpstan
	$(PNPM) install
	$(PNPM) --dir=$(AURORA) install
	$(CONSOLE) cache:clear
	make migrate-f
	make sync-jsconfig
	make sync-env
	make sync-readme
	make sync-security
	make sync-claude-md
	make sync-makefile
	@echo "✅ Synced with latest pull. Run 'make ft' to verify."

pull-and-bump: pull-update aurora-update ## Combo: sync to team lock, THEN bump aurora-core on top (the canonical order)
	@echo "✅ Synced with team + bumped aurora-core. Run 'make ft' to verify."

aurora-update: ## Bump aurora-core to its latest tag (composer update + all sub-installs + syncs)
	# Use THIS only when you explicitly want a newer aurora-core than the
	# one in composer.lock. For routine teammate-PR pulls, use `make pull-update`
	# instead - it honours the lock and avoids surprise upstream upgrades.
	$(COMPOSER) update axelraboit/aurora
	$(COMPOSER) install --working-dir=$(AURORA) --no-scripts
	$(COMPOSER) install --working-dir=$(AURORA)/tools/php-cs-fixer
	$(COMPOSER) install --working-dir=$(AURORA)/tools/twig-cs-fixer
	$(COMPOSER) install --working-dir=$(AURORA)/tools/rector
	$(COMPOSER) install --working-dir=$(AURORA)/tools/phpstan
	$(PNPM) --dir=$(AURORA) install
	$(PNPM) install
	$(CONSOLE) cache:clear
	make migrate-f
	$(CONSOLE) aurora:privileges:sync
	make sync-jsconfig
	make sync-env
	make sync-readme
	make sync-security
	make sync-claude-md
	make sync-makefile
	# Refresh the vue-i18n JSON dumps + production bundle. Without this,
	# new translation keys shipped by the bumped aurora-core would still
	# render as raw `backend.foo.bar` until the next manual `make build`.
	# (Dev mode with `make dev` running picks up the new JSON via Vite
	# HMR - but a stale `public/build/` would still serve stale strings.)
	make translation
	make build
	@mkdir -p var && touch $(AURORA_UPDATE_MARKER)
	@echo "✅ aurora-core bumped. Run 'make ft' to verify."

sync-claude-md: ## Symlink CLAUDE.md + .claude/memory + .claude/skills (shared) ; seed README.md once
	@if [ -f $(AURORA)/.claude/client_template/CLAUDE.md ]; then \
		ln -sfn $(AURORA)/.claude/client_template/CLAUDE.md CLAUDE.md; \
		echo "✅ CLAUDE.md symlinked from vendor"; \
	else \
		echo "⚠️  $(AURORA)/.claude/client_template/CLAUDE.md not found - skipping"; \
	fi
	@# README.md is intentionally NOT symlinked - it's the client's own
	@# project README, free to customise. If a previous install left a
	@# vendor symlink in place, replace it with a real file copied from
	@# the template so the client can edit it. A real file already there
	@# is left alone.
	@if [ -L README.md ]; then rm -f README.md; fi
	@if [ ! -f README.md ] && [ -f $(AURORA)/.claude/client_template/README.md ]; then \
		cp $(AURORA)/.claude/client_template/README.md README.md; \
		echo "✅ README.md seeded from aurora template (now editable - never overwritten by sync)"; \
	elif [ -f README.md ]; then \
		echo "ℹ️  README.md kept as-is (client-owned, won't be overwritten)"; \
	fi
	@mkdir -p .claude/memory
	@rm -rf .claude/memory/aurora-core .claude/memory/aurora-client .claude/memory/aurora-shared
	@ln -sfn ../../$(AURORA)/.claude/memory/aurora-core .claude/memory/aurora-core
	@ln -sfn ../../$(AURORA)/.claude/memory/aurora-client .claude/memory/aurora-client
	@ln -sfn ../../$(AURORA)/.claude/memory/aurora-shared .claude/memory/aurora-shared
	@echo "✅ .claude/memory/aurora-core + aurora-client + aurora-shared symlinked from vendor"
	@mkdir -p .claude/skills
	@for skill_dir in $(AURORA)/.claude/skills/*/; do \
		skill_dir=$${skill_dir%/}; \
		skill_name=$$(basename "$$skill_dir"); \
		if grep -q '^scope: shared' "$$skill_dir/SKILL.md" 2>/dev/null; then \
			rm -rf ".claude/skills/$$skill_name"; \
			ln -sfn "../../$$skill_dir" ".claude/skills/$$skill_name"; \
			echo "✅ .claude/skills/$$skill_name symlinked from vendor (scope: shared)"; \
		fi \
	done
	@rm -rf docs/aurora-core docs/aurora-client docs/aurora-shared 2>/dev/null; \
	if [ -d docs ] && [ -z "$$(ls -A docs 2>/dev/null)" ]; then rmdir docs; fi
	@echo "ℹ️  Aurora docs live in $(AURORA)/docs/{aurora-core,aurora-client,aurora-shared}/ - no local copy/symlink."
	@if [ ! -f .claude/settings.json ] && [ -f $(AURORA)/.claude/client_template/.claude/settings.json ]; then \
		mkdir -p .claude; \
		cp $(AURORA)/.claude/client_template/.claude/settings.json .claude/settings.json; \
		echo "✅ .claude/settings.json created from aurora template"; \
	elif [ -f .claude/settings.json ]; then \
		echo "ℹ️  .claude/settings.json already exists - not overwritten"; \
	fi

sync-makefile: ## Refresh Makefile from aurora-core template (auto-generated, do not edit by hand)
	@if [ ! -f $(AURORA)/.claude/client_template/Makefile ]; then \
		echo "⚠️  $(AURORA)/.claude/client_template/Makefile not found - skipping"; \
		exit 0; \
	fi; \
	if cmp -s $(AURORA)/.claude/client_template/Makefile Makefile; then \
		echo "✅ Makefile already up-to-date with aurora-core"; \
		exit 0; \
	fi; \
	if [ "$(FORCE)" != "1" ] && git rev-parse --git-dir > /dev/null 2>&1; then \
		if ! git diff --quiet -- Makefile 2>/dev/null || ! git diff --cached --quiet -- Makefile 2>/dev/null; then \
			echo "❌ Makefile has uncommitted local edits."; \
			echo "   The sync would overwrite them without saving."; \
			echo "   Options:"; \
			echo "     1. Move custom targets to Makefile.local (never overwritten)."; \
			echo "     2. Commit / stash your changes, then re-run."; \
			echo "     3. Force overwrite anyway: make sync-makefile FORCE=1"; \
			exit 1; \
		fi; \
	fi; \
	cp $(AURORA)/.claude/client_template/Makefile Makefile; \
	echo "✅ Makefile updated from aurora-core - re-run 'make aurora-update' if needed"

# ─────────────────────────────────────────────────────────────────────
# Makefile.local - client-specific targets
#
# Anything you want to add that is SPECIFIC to this client project
# (not a candidate for upstreaming to aurora-core) goes in Makefile.local.
# That file is NEVER overwritten by `sync-makefile`. Create it next to
# this Makefile if needed; targets defined there are available via
# `make <target>` exactly like the ones above.
# ─────────────────────────────────────────────────────────────────────
-include Makefile.local
