# Thin wrappers over the `docker compose` commands. Nothing here is required:
# every target prints as the plain command it runs, and the README lists them all.
#
#   make           list the targets
#   make up        start app, db and scheduler
#   make test      run the test suite
#   make lint      check formatting
#   make build     build front-end assets
#
# Everything runs inside the containers, so the host needs only Docker.

COMPOSE := docker compose
EXEC    := $(COMPOSE) exec app

.DEFAULT_GOAL := help
.PHONY: help dev up down logs install test lint format build watch migrate fresh shell db check

help: ## List the available targets
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

## --- Running -------------------------------------------------------------

dev: ## Start everything in the foreground and follow the logs
	$(COMPOSE) up

up: ## Start everything in the background
	$(COMPOSE) up -d

down: ## Stop everything (keeps the database volume)
	$(COMPOSE) down

logs: ## Follow the logs of all three services
	$(COMPOSE) logs -f

## --- Checks --------------------------------------------------------------

install: ## Install PHP and Node dependencies in the container
	$(EXEC) composer install
	$(EXEC) npm ci

test: ## Run the Pest suite against the MySQL test database
	$(EXEC) php artisan test

lint: ## Check formatting with Pint (fails on anything unformatted)
	$(EXEC) ./vendor/bin/pint --test

format: ## Fix formatting with Pint
	$(EXEC) ./vendor/bin/pint

build: ## Build the front-end assets with Vite
	$(EXEC) npm run build

check: lint test build ## Everything CI would run

## --- Day to day ----------------------------------------------------------

watch: ## Rebuild the assets whenever a file changes (then refresh the browser)
	$(EXEC) npm run watch

migrate: ## Run pending migrations
	$(EXEC) php artisan migrate

fresh: ## Drop every table and migrate from scratch
	$(EXEC) php artisan migrate:fresh

shell: ## Open a shell in the app container
	$(EXEC) bash

db: ## Open a MySQL prompt on the db container
	$(COMPOSE) exec db sh -c 'mysql -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" "$$MYSQL_DATABASE"'
