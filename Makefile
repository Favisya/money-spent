EXEC = docker compose exec php-fpm
CONSOLE = $(EXEC) php bin/console

.PHONY: up down logs sh test-db test test-unit stan cs cs-fix check rates webhook poll app

up:
	docker compose up -d --build

down:
	docker compose down

logs:
	docker compose logs -f php-fpm worker

sh:
	$(EXEC) sh

test-db:
	$(CONSOLE) doctrine:database:create --env=test --if-not-exists
	$(CONSOLE) doctrine:migrations:migrate --env=test --no-interaction

test: test-db
	$(EXEC) composer test

test-unit:
	$(EXEC) composer test:unit

stan:
	$(EXEC) composer stan

cs:
	$(EXEC) composer cs

cs-fix:
	$(EXEC) composer cs:fix

check: cs stan test

rates:
	$(CONSOLE) app:rates:fetch --days=7

webhook:
	$(CONSOLE) app:telegram:set-webhook $(URL)

poll:
	docker compose --profile polling up -d poller

# macOS app with PHP and PostgreSQL inside (no Docker needed to run it): build/Traty-1.0.0.dmg
app:
	bash packaging/macos/build.sh
