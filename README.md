# Tupay Ledger & Settlement Engine

A security-first Laravel backend for NGN-to-CNY transfer orchestration. The implementation combines immutable double-entry accounting, action-bound step-up authorization, exact subunit arithmetic, deterministic distributed locking, PostgreSQL row locking, idempotent client retries, and asynchronous settlement processing.

## Stack

- PHP 8.2+; CI uses PHP 8.3
- Laravel 11.55 and Sanctum
- Scramble-generated OpenAPI 3.1 documentation
- PostgreSQL 16
- Redis 7 for cache, queues, distributed locks, idempotency serialization, and one-time EAT state
- BCMath for arbitrary-precision conversion math
- PHPUnit 11
- Larastan/PHPStan level 8
- Laravel Pint

No wallet contains a mutable `balance` column. All financial amounts are signed 64-bit integer subunits: kobo for NGN and fen for CNY.

## Framework-version disclosure

The assessment explicitly requires Laravel 10 or 11, so this repository pins Laravel 11.55. Laravel 11 reached security end-of-life on March 12, 2026. Composer therefore reports known advisories for the mandated framework line.

The advisories are not hidden: CI runs `composer audit --format=summary` and leaves its report visible. It is non-blocking only because moving to Laravel 12+ would violate the assessment's stated stack. A real production deployment should upgrade to a supported Laravel release before launch.

## Quick start

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app php artisan migrate:fresh --seed --force
```

The API is available at `http://localhost:8000`. The worker consumes both `settlements` and `default` queues.

Seeded assessment user:

```text
Email: candidate@tupay.test
Password: password
TOTP secret: JBSWY3DPEHPK3PXP
Initial NGN balance: 100,000,000 kobo (₦1,000,000)
```

The fixed password and TOTP secret exist only for deterministic local and CI verification.

## API documentation and versioning

The canonical contract is versioned under `/api/v1`:

- Interactive documentation: `GET /docs/api`
- OpenAPI 3.1 JSON: `GET /docs/api.json`
- Exported specification: `composer docs:export`

Scramble derives request schemas from Form Requests, response schemas from API Resources, route authentication from middleware, and explicitly declared financial/webhook headers from controller attributes. CI exports the specification and fails when the OpenAPI version or required paths are missing.

The original assessment paths under `/api/*` remain available as compatibility aliases. They return `Deprecation`, `Sunset`, and successor-version `Link` headers. New integrations must use `/api/v1/*`.

## Canonical API flow

1. `POST /api/v1/login` returns a Sanctum bearer token and current wallet balances.
2. `POST /api/v1/2fa/challenge` verifies TOTP and the exact financial `action_payload`.
3. The server returns a signed, single-use Elevated Action Token valid for 60 seconds.
4. `POST /api/v1/swap` requires `X-Elevated-Action-Token` and `Idempotency-Key`.
5. The NGN leg is posted immediately and a pending settlement reference is returned.
6. `POST /api/v1/webhooks/settlement` accepts timestamp-bound, HMAC-signed provider events.
7. `GET /api/v1/ledger/{walletId}` returns cursor-paginated immutable history and a calculated balance.

See [`api-test.http`](api-test.http) for executable examples.

## API contract standards

### Stable resources and envelopes

Successful controller responses use Laravel API Resources and a stable top-level `data` envelope. Ledger pagination is explicitly represented as:

```json
{
  "data": {
    "wallet": {},
    "entries": [],
    "pagination": {
      "next_cursor": null,
      "per_page": 20
    }
  }
}
```

Direct Eloquent paginator/model serialization is not exposed as the public contract.

### Standardized errors

API errors use an `application/problem+json` response with stable machine-readable codes:

```json
{
  "type": "https://api.tupay.test/problems/validation-failed",
  "title": "Validation failed",
  "status": 422,
  "detail": "One or more request fields are invalid.",
  "code": "VALIDATION_FAILED",
  "request_id": "uuid",
  "errors": {
    "amount_subunits": ["The amount subunits field is required."]
  }
}
```

Authentication, authorization, validation, missing resources, rate limits, idempotency conflicts, EAT failures, lock contention, invalid swaps, provider outages, HTTP exceptions, and unexpected exceptions are rendered centrally from `bootstrap/app.php`.

### Correlation and security headers

Every API response includes `X-Request-ID`. A valid incoming identifier is propagated; otherwise a UUID is generated and added to the logging context.

API responses also apply:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: no-referrer`
- restrictive `Permissions-Policy`
- restrictive `Content-Security-Policy`
- `Cache-Control: no-store, private`
- HSTS when the request is served over HTTPS

CORS origins are allow-listed through `CORS_ALLOWED_ORIGINS`; credentials and wildcard origins are disabled.

### Rate limiting

Rate limits are separated by risk:

- login: per email/IP fingerprint plus an hourly IP ceiling;
- financial writes: per authenticated user;
- ledger reads: higher per-user read budget;
- provider webhooks: per source IP.

Exceeded limits are returned through the same standardized problem contract.

## Swap idempotency

`POST /api/v1/swap` requires an `Idempotency-Key` containing 8–100 safe characters.

The server:

1. hashes the exact action payload;
2. serializes competing requests for the same user/key with a Redis lock;
3. stores the key and request hash on the swap row under a unique database constraint;
4. returns the original swap when the same key and payload are retried; and
5. returns `409 IDEMPOTENCY_CONFLICT` when the key is reused with different parameters.

An idempotent replay returns `Idempotent-Replayed: true`. The idempotency lookup happens before EAT consumption, allowing a client that lost the first HTTP response to retrieve the completed result without requiring a fresh financial posting. A new idempotency key still requires a valid unused EAT.

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

Associative keys are recursively sorted, encoded as canonical JSON, and hashed with SHA-256. The EAT contains `jti`, `sub`, `action_hash`, `iat`, and `exp`, then is signed with HMAC-SHA256 using a dedicated key.

Redis stores `eat:{jti}` for 60 seconds. Consumption uses Redis `GETDEL`, making retrieval and invalidation atomic. The authenticated subject and recomputed action hash are checked before consumption. Replay, changed parameters, expiration, or an invalid signature returns `401`.

## Ledger architecture and database guardrails

Every completed ledger transaction contains at least two signed entries. Outflows are negative and inflows are positive. A completed transaction must sum to zero independently for each represented currency.

PostgreSQL enforces the core invariants with native and deferred constraint triggers:

- Entry currency must equal wallet currency.
- Ledger entries cannot be updated or deleted; corrections require compensating transactions.
- Completed transactions require at least two entries.
- Every completed transaction/currency subtotal must equal zero.
- A user wallet's calculated subtotal cannot become negative.

The application repeats the balanced-posting check, but PostgreSQL remains the final authority. System treasury, clearing, and liquidity wallets are platform control accounts and may carry negative accounting positions.

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
2. Sort and acquire every lock non-blockingly.
3. Resolve the FX quote before opening the SQL transaction.
4. Start a PostgreSQL `REPEATABLE READ` transaction.
5. Sort participating wallet UUIDs and acquire `FOR UPDATE` locks in that order.
6. Increment `lock_version` to force an explicit conflicting write.
7. Recalculate the source balance from immutable entries.
8. Insert balanced postings and commit.
9. Release Redis locks in reverse order inside `finally`.

A failed Redis lock returns `409 RESOURCE_BUSY`. A request that continues after another transaction consumed the funds returns `422 INSUFFICIENT_FUNDS`.

## Exact FX and slippage math

The rate is a decimal string representing CNY per NGN. Since NGN and CNY each use 100 subunits, `source_kobo × rate` produces fen directly. Multiplication and division use BCMath strings only; the result is converted to integer fen with explicit `ROUND_HALF_EVEN` banker's rounding.

The documented progressive spread is:

- Up to and including ₦1,000,000: `0%`
- Above ₦1,000,000 through ₦1,500,000: `0.5%`
- Each started ₦500,000 tier after ₦1,500,000: an additional `0.1%`

Spread is stored as integer basis points. Boundary behavior is covered by unit tests.

## Stale-while-revalidate FX cache

`FxRateService` stores a Redis document containing the decimal-string rate and fetch timestamp.

- Fresh values return immediately.
- Stale-but-usable values return while one Redis-guarded job refreshes in the background.
- Hard-expired or missing values refresh synchronously.
- Provider calls have a timeout and retry budget.

Decimal rates remain strings so floating-point values never enter domain math. `FX_RATE_STATIC` is a deterministic local/CI override.

## Settlement webhook reliability

The provider sends:

- `X-Tupay-Timestamp`: current Unix timestamp;
- `X-Tupay-Signature`: HMAC-SHA256 over `timestamp + "." + exact raw body`;
- `event_id`: unique provider UUID;
- `provider_reference`, `status`, and `occurred_at` in the JSON body.

The server rejects timestamps outside `SETTLEMENT_WEBHOOK_TOLERANCE_SECONDS` before processing. `event_id` prevents an identifier from being reused with a different payload. A separate hash of `provider_reference|status` preserves semantic idempotency for duplicate state delivery.

Status ranks allow legitimate progression from `INITIATED` to `PROCESSING` to `COMPLETED`, but prevent regression. The swap row and event are pessimistically locked, and `settlement_ledger_transaction_id` is unique as a final double-credit guard.

`FAILED` is terminal. Automatic refunding is deliberately not inferred because the assessment does not define the provider's recoverability contract; production should use an explicit compensating transaction workflow.

## Ledger pagination and indexes

Ledger history uses cursor pagination ordered by descending entry ID. The composite B-tree index `ledger_entries_wallet_pagination_idx (wallet_id, id)` supports the equality filter and reverse index scan. Additional transaction/currency and swap/user indexes support invariant checks and settlement lookup without mutable balances.

## Verification

Run the standard suite:

```bash
make verify
```

Export the OpenAPI specification:

```bash
composer docs:export
```

Run the required external HTTP race test:

```bash
make concurrency
```

The parallel test creates ten distinct EATs and ten distinct idempotency keys for the same action, then fires ten requests concurrently. It asserts:

- exactly one `200` response;
- exactly nine `409` or `422` responses;
- source balance exactly zero;
- no negative user wallet;
- exactly one swap record; and
- every completed ledger transaction/currency group sums to zero.

GitHub Actions performs locked dependency installation, advisory reporting, PostgreSQL migrations and seeders, Pint, PHPStan level 8, unit/integration tests, OpenAPI 3.1 export/contract validation, and the multi-worker concurrency test against PostgreSQL and Redis.

## Important directories

```text
app/Domain/Ledger       posting and dynamic balance services
app/Domain/Security     action hashing and one-time EAT handling
app/Domain/Swap         FX, locks, idempotency, and swap orchestration
app/Http/Resources      stable public response contracts
app/Http/Middleware     request IDs, security, deprecation, webhook HMAC
app/Http/Support        standardized problem responses
app/Jobs                FX refresh and settlement processing
database/migrations     schema, checks, triggers, indexes, idempotency
tests/Feature           API contract and financial regression tests
tests/Concurrency       mandatory ten-request external stress test
```
