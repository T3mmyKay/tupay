.PHONY: up down install migrate seed test analyse format verify concurrency

up:
	docker compose up -d --build

down:
	docker compose down

install:
	docker compose exec app composer install
	docker compose exec app php artisan key:generate --force

migrate:
	docker compose exec app php artisan migrate --force

seed:
	docker compose exec app php artisan db:seed --force

test:
	docker compose exec app vendor/bin/phpunit --exclude-group concurrency

analyse:
	docker compose exec app composer analyse

format:
	docker compose exec app vendor/bin/pint

verify:
	docker compose exec app vendor/bin/pint --test
	docker compose exec app composer analyse
	docker compose exec app vendor/bin/phpunit --exclude-group concurrency

concurrency:
	docker compose exec app php artisan migrate:fresh --seed --force
	docker compose exec redis redis-cli FLUSHALL
	CONCURRENCY_BASE_URL=http://localhost:8000 vendor/bin/phpunit --group concurrency
