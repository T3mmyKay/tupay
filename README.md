# Tupay Ledger & Settlement Engine

A security-first Laravel backend for NGN-to-CNY transfer orchestration. The implementation uses immutable double-entry ledger entries, signed action-bound step-up authorization, exact subunit arithmetic, deterministic distributed locks, PostgreSQL row locks, and idempotent asynchronous settlement processing.

## Stack

- PHP 8.2+; CI uses PHP 8.3
- Laravel 11.55 and Sanctum
- PostgreSQL 16
- Redis 7 for cache, queues, distributed locks, and one-time EAT state
- BCMath for arbitrary-precision conversion math
- PHPUnit 11
- Larastan/PHPStan level 8
- Laravel Pint

No wallet contains a mutable `balance` column. All financial amounts are stored and calculated as signed 64-bit integer subunits: kobo for NGN and fen for CNY.

## Framework-version disclosure

The assessment explicitly requires Laravel 10 or 11, so this repository pins Laravel 11.55. Laravel 11 reached security end-of-life on March 12, 2026. Composer therefore reports known advisories for the mandated framework line.

The advisories are not hidden: CI runs `composer audit --format=summary` and leaves its report visible. It is non-blocking only because every currently installable Laravel 11 release is outside security support while moving to Laravel 12+ would violate the assessment's stated stack. A real production deployment should upgrade to a currently supported Laravel release before launch.

## Quick start

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan migrate:fresh --seed --force
```

The app bootstrap installs Composer dependencies, generates an application key when needed, and runs migrations. The API is available at `http://localhost:8000`; the worker consumes both `settlements` and `default` queues.

Seeded assessment user:

```text
Email: candidate@tupay.test
Password: password
TOTP secret: JBSWY3DPEHPK3PXP
Initial NGN balance: 100,000,000 kobo (₦1,000,000)
```

The fixed password and TOTP secret exist only for deterministic assessment verification. They must not be used outside local or CI environments.

## API flow

1. `POST /api/login` returns a Sanctum bearer token and the user's NGN/CNY wallet IDs.
2. `POST /api/2fa/challenge` verifies TOTP together with an exact `action_payload`.
3. The server returns a signed, single-use Elevated Action Token valid for 60 seconds.
4. `POST /api/swap` reconstructs the intended action and requires the EAT in `X-Elevated-Action-Token`.
5. The NGN leg is posted immediately and a pending settlement reference is returned.
6. `POST /api/webhooks/settlement` receives signed provider state transitions; `COMPLETED` posts the CNY leg asynchronously.
7. `GET /api/ledger/{walletId}` returns paginated history and a dynamically calculated balance.

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

Associative keys are recursively sorted, encoded as canonical JSON, and hashed with SHA-256. The EAT contains `jti`, `sub`, `action_hash`, `iat`, and `exp`, then is signed with HMAC-SHA256 using a dedicated signing key.

Redis stores `eat:{jti}` for 60 seconds. Consumption uses Redis `GETDEL`, so retrieval and invalidation are atomic. The authenticated subject and recomputed action hash are checked before consumption. Replay, changed parameters, expiration, or an invalid signature produces `401`.

## Ledger architecture and database guardrails

Every completed ledger transaction contains at least two signed entries. Outflows are negative and inflows are positive. A completed transaction must sum to zero independently for each currency represented in it.

PostgreSQL enforces the core invariants with native triggers and deferred constraint triggers:

- Entry currency must equal wallet currency.
- Ledger entries cannot be updated or deleted; corrections require compensating transactions.
- Completed transactions require at least two entries.
- Every completed transaction/currency subtotal must equal zero.
- A user wallet's calculated ledger subtotal cannot become negative.

The application repeats the balanced-posting check before insertion, but PostgreSQL remains the final authority. System treasury, clearing, and liquidity wallets are platform control accounts and may carry negative accounting positions; the non-negative constraint applies to user wallets.

A swap uses separate balanced transactions because NGN and CNY are different units:

```text
Swap debit, NGN:
  User NGN wallet        -source kobo
  Platform NGN clearing  +source kobo

Settlement, CNY:
  Platform CNY liquidity -destination fen
  User CNY wallet        +destination fen
```

## Concurrency and deadlock prevention

The swap write path executes in this order:

1. Build Redis lock keys for the user, source wallet, and destination wallet.
2. Sort the keys lexicographically and acquire every lock non-blockingly.
3. Resolve the FX quote before opening the SQL transaction.
4. Start a PostgreSQL transaction and set `REPEATABLE READ` before its first query.
5. Sort all participating wallet UUIDs and acquire `FOR UPDATE` row locks in that order.
6. Increment `lock_version` to force an explicit conflicting row write.
7. Recalculate the source balance from immutable ledger entries.
8. Insert balanced postings and commit.
9. Release acquired Redis locks in reverse order inside `finally`.

A failed Redis lock acquisition returns `409 Conflict`. A request that proceeds after a prior swap has committed but no longer has enough funds returns `422 Unprocessable Entity`.

## Exact FX and slippage math

The rate is a decimal string representing CNY per NGN. Since NGN and CNY each use 100 subunits, `source_kobo × rate` produces fen directly. Multiplication and division use BCMath strings only; the result is reduced to integer fen with explicit `ROUND_HALF_EVEN` banker's rounding.

The documented interpretation of progressive spread is:

- Up to and including ₦1,000,000: `0%`
- Above ₦1,000,000 through ₦1,500,000: `0.5%`
- Each started ₦500,000 tier after ₦1,500,000: an additional `0.1%`

Spread is stored as integer basis points and applied to the destination rate. Boundary behavior is covered by unit tests.

## Stale-while-revalidate rate cache

`FxRateService` stores a Redis document containing the decimal-string rate and fetch timestamp.

- Fresh values are returned immediately.
- Stale-but-usable values are returned while one Redis-guarded job refreshes in the background.
- Hard-expired or missing values are refreshed synchronously.
- Provider calls have a timeout and retry budget.

The mock endpoint may return either `{"rate":"0.00450000"}` or `{"data":{"rate":"0.00450000"}}`. Decimal rates should be strings so floating-point values never enter domain math. `FX_RATE_STATIC` is a deterministic local/CI override; leave it empty to exercise the configured provider.

## Settlement webhook reliability

`X-Tupay-Signature` is verified as HMAC-SHA256 over the exact raw request body. The controller stores a unique SHA-256 idempotency key derived from `provider_reference|status`, then dispatches a job to the `settlements` queue.

This allows legitimate progression from `INITIATED` to `PROCESSING` to `COMPLETED`, while duplicate delivery of the same state is ignored. Status ranks prevent regression, so a late `INITIATED` event cannot overwrite `COMPLETED`. The swap row and event are pessimistically locked, and `settlement_ledger_transaction_id` is unique as a final double-credit guard.

`FAILED` is treated as terminal. Automatic refunding is deliberately not inferred because the assessment does not define whether provider failure is final or recoverable; a production provider contract should define an explicit compensating/reversal workflow.

## Pagination indexes

Ledger history uses descending entry IDs for one wallet. The composite B-tree index `ledger_entries_wallet_pagination_idx (wallet_id, id)` supports the equality filter and reverse index scan. Additional transaction/currency and swap/user indexes support invariant checks, settlement lookup, and user history without introducing mutable balances.

## Verification

Run the standard suite:

```bash
make verify
```

Run the required external HTTP concurrency test:

```bash
make concurrency
```

The parallel test creates ten distinct EATs for the same action, then fires ten requests concurrently. Reusing one EAT would only test replay protection and would not prove double-spend safety. It asserts:

- exactly one `200` response;
- exactly nine `409` or `422` responses;
- source balance exactly zero;
- no negative user wallet;
- exactly one swap record; and
- every completed ledger transaction/currency group sums to zero.

GitHub Actions runs Composer installation and advisory reporting, PostgreSQL migrations and seeders, Pint, PHPStan level 8, all unit/integration tests, and the multi-worker parallel test against PostgreSQL and Redis.

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
