# Tupay Ledger & Settlement Engine

A security-first Laravel 11 backend implementing immutable double-entry accounting, action-bound step-up authentication, deterministic distributed locking, exact subunit arithmetic, and idempotent settlement processing.

## Current implementation status

The repository is being built in vertical slices. The committed foundation includes the Laravel application scaffold, PostgreSQL and Redis configuration, static-analysis configuration, and local container topology. Ledger schema, security, swap orchestration, settlement processing, and concurrency verification are added in subsequent commits.

## Local prerequisites

- Docker with Compose v2
- Git

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

The API is exposed at `http://localhost:8000`.
