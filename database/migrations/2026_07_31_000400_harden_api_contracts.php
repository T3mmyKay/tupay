<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('swaps', function (Blueprint $table): void {
            $table->string('idempotency_key', 100)->nullable()->after('user_id');
            $table->char('request_hash', 64)->nullable()->after('idempotency_key');
            $table->unique(['user_id', 'idempotency_key'], 'swaps_user_idempotency_unique');
        });

        Schema::table('settlement_webhook_events', function (Blueprint $table): void {
            $table->uuid('event_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('settlement_webhook_events', function (Blueprint $table): void {
            $table->dropUnique(['event_id']);
            $table->dropColumn('event_id');
        });

        Schema::table('swaps', function (Blueprint $table): void {
            $table->dropUnique('swaps_user_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });
    }
};
