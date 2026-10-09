# NNTmux docker/ stack lifecycle. Run from anywhere: make -C docker <target>
# Design: docs/2026-10-08-nntmux-docker-stack-design.md
.DEFAULT_GOAL := help
SHELL := /bin/bash

DC       := docker compose --project-directory $(CURDIR) -f $(CURDIR)/docker-compose.yml
APP_RUN  := $(DC) run --rm --no-deps web
ARTISAN  := $(DC) exec -T web php artisan
REPO     := $(abspath $(CURDIR)/..)
-include $(CURDIR)/.env
DB_EXEC   = $(DC) exec -T mariadb mariadb -uroot -p"$(DB_ROOTPASSWORD)" $(DB_DATABASE)
s        ?=
cmd      ?=

DATA_DIRS := data/storage data/install data/mariadb data/redis data/manticore data/caddy/data data/caddy/config \
             data/prometheus data/grafana logs/mariadb logs/manticore logs/caddy backups

.PHONY: help
help: ## Show targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n",$$1,$$2}'

.PHONY: env
env: ## Generate docker/.env and config/app.env (secrets, NNTP creds); safe to re-run
	@$(CURDIR)/bin/generate-env

.PHONY: dirs
dirs: ## Create bind-mount directories
	@cd $(CURDIR) && mkdir -p $(DATA_DIRS)

.PHONY: build
build: ## Build nntmux/base:local (root Dockerfile) and nntmux/app:local (overlay)
	docker build -t nntmux/base:local -f $(REPO)/Dockerfile $(REPO)
	docker build -t nntmux/app:local -f $(CURDIR)/app/Dockerfile $(REPO)

.PHONY: init
init: env dirs build ## First-time install: build, migrate+seed, indexes, groups, tuning, predb seed, start
	$(DC) up -d --wait mariadb redis manticore mailpit
	@if [ -f $(CURDIR)/data/install/install.lock ]; then echo "install.lock present; skipping nntmux:deploy-init"; \
	else $(APP_RUN) php artisan nntmux:deploy-init --no-interaction; fi
	$(APP_RUN) php artisan monitoring:install --sail --no-interaction
	$(MAKE) -C $(CURDIR) seed-db
	$(APP_RUN) php artisan predb:import-feed --pages=20
	$(DC) up -d
	@echo "NNTmux: http://localhost:$(or $(APP_PORT),8080)  (admin password: grep ADMIN_PASS docker/config/app.env)"

.PHONY: seed-db
seed-db: ## Apply config/seed/groups.sql + tuning.sql and clear the settings cache
	$(DB_EXEC) < $(CURDIR)/config/seed/groups.sql
	$(DB_EXEC) < $(CURDIR)/config/seed/tuning.sql
	$(APP_RUN) php artisan cache:clear

.PHONY: seed
seed: seed-db ## Re-seed groups/tuning and restart the indexer to pick it up
	$(DC) restart indexer

.PHONY: up
up: ## Start the whole stack
	$(DC) up -d

.PHONY: down
down: ## Stop the stack (tmux session stops cleanly)
	$(DC) down

.PHONY: restart
restart: ## Restart app services (s=service for one)
	$(DC) restart $(or $(s),web horizon scheduler indexer)

.PHONY: ps
ps: ## Service status / health
	$(DC) ps

.PHONY: logs
logs: ## Follow logs (s=service)
	$(DC) logs -f --tail=200 $(s)

.PHONY: tmux
tmux: ## Attach to the indexer tmux session (detach: Ctrl-a d)
	$(DC) exec indexer tmux attach -t nntmux

.PHONY: shell
shell: ## Shell in the web container
	$(DC) exec web bash

.PHONY: artisan
artisan: ## Run artisan: make artisan cmd="predb:import-feed --dry-run"
	$(DC) exec web php artisan $(cmd)

.PHONY: mysql
mysql: ## MariaDB client as root
	$(DC) exec mariadb mariadb -uroot -p"$(DB_ROOTPASSWORD)" $(DB_DATABASE)

.PHONY: backup
backup: ## Dump MariaDB to backups/ (keeps 7)
	@mkdir -p $(CURDIR)/backups
	$(DC) exec -T mariadb mariadb-dump -uroot -p"$(DB_ROOTPASSWORD)" --single-transaction --quick --routines $(DB_DATABASE) \
		| gzip > $(CURDIR)/backups/nntmux-$$(date +%Y%m%d-%H%M%S).sql.gz
	@ls -1t $(CURDIR)/backups/nntmux-*.sql.gz | tail -n +8 | xargs -r rm --
	@ls -lh $(CURDIR)/backups | tail -n 3

.PHONY: status
status: ## Indexing progress snapshot (groups, binaries, releases, predb)
	@echo "SELECT COUNT(*) active_groups, SUM(last_record>0) scanned FROM usenet_groups WHERE active=1; \
	SELECT (SELECT COUNT(*) FROM collections) collections, (SELECT COUNT(*) FROM binaries) binaries, (SELECT COUNT(*) FROM releases) releases; \
	SELECT source, COUNT(*) pres FROM predb GROUP BY source ORDER BY 2 DESC LIMIT 10;" | $(DB_EXEC) -t
