# Tupay Ledger & Settlement Engine

A security-first Laravel 11 backend for NGN-to-CNY transfer orchestration. The implementation uses immutable double-entry entries, signed action-bound step-up authorization, exact subunit arithmetic, deterministic distributed locks, PostgreSQL row locks, and idempotent asynchronous settlement processing.

## Stack

- PHP 8.2+; CI uses PHP 8.3
- Laravel 11 and Sanctum
- PostgreSQL 16
- Redis 7 for cache, queues, distributed locks, and one-time EAT state
- BCMath for all conversion math
- PHPUnit 11
- Larastan/PHPStan level 8
- Laravel Pint

No wallet contains a mutable `balance` column. All amounts are signed 64-bit integer subunits: kobo for NGN and fen for CNY.

## Quick start

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate --force
docker compose exec app php artisan migrate:fresh --seed --force
```

The API is available at `http://localhost:8000`.

Seeded assessment user:

```text
Email: candidate@tupay.test
Password: password
TOTP secret: JBSWY3DPEHPK3PXP
Initial NGN balance: 100,000,000 kobo (₦1,000,000)
```

The fixed password and TOTP secret exist only for deterministic assessment verification. They must not be used outside a local or CI environment.

## API flow

1. `POST /api/login` returns a Sanctum bearer token and the user's NGN/CNY wallet IDs.
2. `POST /api/2fa/challenge` verifies TOTP and an exact `action_payload`.
3. The server returns a signed, single-use EAT valid for 60 seconds.
4. `POST /api/swap` reconstructs the action payload and requires the EAT in `X-Elevated-Action-Token`.
5. The NGN leg is posted immediately to the ledger and a pending settlement reference is returned.
6. `POST /api/webhooks/settlement` receives signed provider status transitions. `COMPLETED` posts the CNY leg.
7. `GET /api/ledger/{walletId}` returns a paginated wallet history and dynamically calculated balance.

See [`api-test.http`](api-test.http) for executable request examples.

## Step-up security design

The challenge payload is validated into these exact fields:

```json
{
  "action": "swap",
  "source_wallet_id": "uuid",
  "destination_wallet_id": "uuid",
  "amount_subunits": 100000000
}
```

Associative keys are recursively sorted, encoded as canonical JSON, and hashed with SHA-256. The EAT includes `jti`, `sub`, `action_hash`, `iat`, and `exp`; it is signed with HMAC-SHA256 using a dedicated key.

Redis stores `eat:{jti}` for 60 seconds. Consumption uses the Redis `GETDEL` command, so validation and invalidation cannot race. The subject and recomputed action hash are checked before the atomic read. A replay, changed amount, changed wallet, expired token, or invalid signature returns `401`.

## Ledger architecture and database guardrails

Each completed `ledger_transaction` has at least two signed entries. Debit/outflow entries are negative; credit/inflow entries are positive. A completed transaction must sum to zero independently for every currency represented in that transaction.

PostgreSQL enforces the invariants with native triggers and deferred constraint triggers:

- Entry currency must equal wallet currency.
- Ledger entries cannot be updated or deleted; corrections require compensating postings.
- Completed transactions require at least two entries.
- Each completed transaction/currency subtotal must equal zero.
- A user wallet's calculated ledger subtotal cannot become negative.

The application repeats the balanced-posting check before insertion, but the database remains the final authority. System treasury/clearing wallets may be negative because they represent platform control accounts; the non-negative constraint applies to user wallets.

A swap uses separate balanced transactions for each currency because NGN and CNY cannot be meaningfully added together:

```text
Swap debit, NGN:
  User NGN wallet       -source kobo
  Platform NGN clearing +source kobo

Settlement, CNY:
  Platform CNY liquidity -destination fen
  User CNY wallet        +destination fen
```

## Concurrency and deadlock prevention

The write path performs these operations in order:

1. Build Redis lock keys for the user, source wallet, and destination wallet.
2. Sort keys lexicographically and acquire all locks non-blockingly.
3. Calculate/fetch the quote before opening the SQL transaction.
4. Open a PostgreSQL transaction and set `REPEATABLE READ` before the first query.
5. Resolve every participating ledger wallet, sort wallet UUIDs, and lock rows with `FOR UPDATE`.
6. Increment `lock_version` to create an explicit conflicting row write.
7. Recalculate the source balance from ledger entries.
8. Insert balanced postings and commit.
9. Release acquired Redis locks in reverse order inside `finally`.

A failed non-blocking Redis acquisition returns `409`. A request that obtains the lock after another request has committed but no longer has sufficient funds returns `422`.

## Exact FX and slippage math

The rate is a decimal string representing CNY per NGN. Because NGN and CNY both have 100 subunits, `source_kobo × rate` produces fen directly. All multiplication/division uses BCMath strings. The result is reduced to integer fen with explicit `ROUND_HALF_EVEN` (banker's rounding).

The documented interpretation of the progressive spread is:

- Up to and including ₦1,000,000: `0%`
- Above ₦1,000,000 through ₦1,500,000: `0.5%`
- Each started ₦500,000 tier after ₦1,500,000: an additional `0.1%`

Spread is stored as integer basis points and applied to the destination rate. Boundary behavior is covered by unit tests.

## Stale-while-revalidate rate cache

`FxRateService` stores this Redis document:

```json
{
  "rate": "0.00450000",
  "fetched_at": 1785530000
}
```

- Fresh values are returned immediately.
- Stale-but-usable values are returned while a single Redis-guarded queue job refreshes in the background.
- Hard-expired/missing values are refreshed synchronously.
- Provider calls have a timeout and retry budget.

The external mock endpoint may return either `{"rate":"0.00450000"}` or `{"data":{"rate":"0.00450000"}}`. Decimal rates should be strings so no floating-point value enters domain math. `FX_RATE_STATIC` is a deterministic local/CI override; leave it empty to exercise the configured external provider.

## Settlement webhook reliability

`X-Tupay-Signature` is verified as HMAC-SHA256 over the exact raw request body. The webhook controller records a unique SHA-256 idempotency key derived from `provider_reference|status`, then dispatches a queue job.

This permits legitimate progression from `INITIATED` to `PROCESSING` to `COMPLETED`, while duplicate delivery of the same status is ignored. Status ranks prevent regression, so a later `INITIATED` event cannot overwrite an already processed `COMPLETED` event. The swap row and settlement event are pessimistically locked; `settlement_ledger_transaction_id` is also unique as a final double-credit guard.

`FAILED` is treated as terminal. Automatic refunding is deliberately not inferred because the assessment does not define whether provider failure is final or recoverable; a production provider contract should define a separate compensating/reversal workflow.

## Pagination indexes

Ledger history uses descending `id` pagination for a single wallet. The composite B-tree index `ledger_entries_wallet_pagination_idx (wallet_id, id)` supports the equality filter and reverse index scan. Transaction/currency and swap/user indexes support invariant checks, settlement lookup, and user history without adding mutable balance state.

## Verification

Run the normal verification suite:

```bash
docker compose exec app vendor/bin/pint --test
docker compose exec app composer analyse
docker compose exec app vendor/bin/phpunit --exclude-group concurrency
```

For the required external HTTP race test, reset the seeded state, run the app with multiple PHP CLI server workers, and execute:

```bash
php artisan migrate:fresh --seed --force
redis-cli FLUSHALL
PHP_CLI_SERVER_WORKERS=12 php artisan serve --host=127.0.0.1 --port=8000
CONCURRENCY_BASE_URL=http://127.0.0.1:8000 vendor/bin/phpunit --group concurrency
```

The test creates **ten distinct EATs** for the same action, then fires ten HTTP requests concurrently. Reusing one EAT would only test replay protection and would not prove double-spend safety. Assertions require:

- exactly one `200` response;
- exactly nine `409` or `422` responses;
- source balance exactly zero;
- no negative user wallet;
- one swap record only; and
- every completed ledger transaction/currency group sums to zero.

GitHub Actions runs Pint, PHPStan level 8, all unit/integration tests, and the multi-worker parallel test against PostgreSQL and Redis.

## Important directories

```text
app/Domain/Ledger       posting and dynamic balance services
app/Domain/Security     canonical action hashing and one-time EAT handling
app/Domain/Swap         rate cache, quote math, locks, and swap orchestration
app/Jobs                FX refresh and settlement processing
app/Http                API controllers, requests, and HMAC middleware
database/migrations     schema, checks, deferred triggers, and indexes
tests/Concurrency       mandatory ten-request external HTTP stress test
```
