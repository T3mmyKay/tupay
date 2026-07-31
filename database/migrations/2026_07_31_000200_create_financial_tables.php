<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->string('type', 20);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'currency']);
            $table->index(['type', 'currency']);
        });

        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_currency_check CHECK (currency IN ('NGN', 'CNY'))");
        DB::statement("ALTER TABLE wallets ADD CONSTRAINT wallets_type_check CHECK (type IN ('USER', 'TREASURY', 'CLEARING', 'LIQUIDITY'))");
        DB::statement('CREATE UNIQUE INDEX wallets_system_type_currency_unique ON wallets (type, currency) WHERE user_id IS NULL');

        Schema::create('ledger_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 40);
            $table->string('status', 20);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_status_check CHECK (status IN ('PENDING', 'COMPLETED', 'FAILED'))");
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_type_check CHECK (type IN ('FUNDING', 'SWAP_DEBIT', 'SETTLEMENT_CREDIT'))");

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('ledger_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('wallet_id')->constrained()->restrictOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('amount_subunits');
            $table->timestamps();
            $table->index(['wallet_id', 'id'], 'ledger_entries_wallet_pagination_idx');
            $table->index(['ledger_transaction_id', 'currency']);
        });

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_currency_check CHECK (currency IN ('NGN', 'CNY'))");
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_non_zero_check CHECK (amount_subunits <> 0)');

        Schema::create('swaps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('source_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignUuid('destination_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->bigInteger('source_amount_subunits');
            $table->bigInteger('destination_amount_subunits');
            $table->string('quoted_rate', 64);
            $table->unsignedSmallInteger('spread_basis_points');
            $table->string('provider_reference', 100)->unique();
            $table->string('status', 20);
            $table->foreignUuid('debit_ledger_transaction_id')->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->foreignUuid('settlement_ledger_transaction_id')->nullable()->unique()->constrained('ledger_transactions')->restrictOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE swaps ADD CONSTRAINT swaps_status_check CHECK (status IN ('PENDING', 'INITIATED', 'PROCESSING', 'COMPLETED', 'FAILED'))");
        DB::statement('ALTER TABLE swaps ADD CONSTRAINT swaps_positive_amounts_check CHECK (source_amount_subunits > 0 AND destination_amount_subunits > 0)');
        DB::statement('ALTER TABLE swaps ADD CONSTRAINT swaps_distinct_wallets_check CHECK (source_wallet_id <> destination_wallet_id)');

        Schema::create('settlement_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider_reference', 100);
            $table->string('status', 20);
            $table->string('idempotency_key', 64)->unique();
            $table->jsonb('payload');
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
            $table->index(['provider_reference', 'created_at']);
        });

        DB::statement("ALTER TABLE settlement_webhook_events ADD CONSTRAINT settlement_events_status_check CHECK (status IN ('INITIATED', 'PROCESSING', 'COMPLETED', 'FAILED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_webhook_events');
        Schema::dropIfExists('swaps');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('wallets');
    }
};
