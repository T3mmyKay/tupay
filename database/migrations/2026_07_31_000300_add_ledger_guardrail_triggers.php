<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION reject_ledger_entry_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'Ledger entries are immutable; post a compensating transaction instead'
        USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER ledger_entries_immutable_guard
BEFORE UPDATE OR DELETE ON ledger_entries
FOR EACH ROW
EXECUTE FUNCTION reject_ledger_entry_mutation();

CREATE OR REPLACE FUNCTION validate_ledger_entry_currency()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    wallet_currency char(3);
BEGIN
    SELECT currency INTO wallet_currency
    FROM wallets
    WHERE id = NEW.wallet_id;

    IF wallet_currency IS NULL OR wallet_currency <> NEW.currency THEN
        RAISE EXCEPTION 'Ledger entry currency must match wallet currency'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER ledger_entry_currency_guard
BEFORE INSERT OR UPDATE ON ledger_entries
FOR EACH ROW
EXECUTE FUNCTION validate_ledger_entry_currency();

CREATE OR REPLACE FUNCTION assert_ledger_transaction_balanced(transaction_uuid uuid)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    transaction_status varchar(20);
    entry_count bigint;
BEGIN
    SELECT status INTO transaction_status
    FROM ledger_transactions
    WHERE id = transaction_uuid;

    IF transaction_status <> 'COMPLETED' THEN
        RETURN;
    END IF;

    SELECT COUNT(*) INTO entry_count
    FROM ledger_entries
    WHERE ledger_transaction_id = transaction_uuid;

    IF entry_count < 2 THEN
        RAISE EXCEPTION 'Completed ledger transaction % requires at least two entries', transaction_uuid
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM ledger_entries
        WHERE ledger_transaction_id = transaction_uuid
        GROUP BY currency
        HAVING SUM(amount_subunits) <> 0
    ) THEN
        RAISE EXCEPTION 'Completed ledger transaction % is not balanced by currency', transaction_uuid
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION enforce_ledger_entry_balance()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    transaction_uuid uuid;
BEGIN
    transaction_uuid := CASE WHEN TG_OP = 'DELETE' THEN OLD.ledger_transaction_id ELSE NEW.ledger_transaction_id END;
    PERFORM assert_ledger_transaction_balanced(transaction_uuid);

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER ledger_entries_balance_guard
AFTER INSERT OR UPDATE OR DELETE ON ledger_entries
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION enforce_ledger_entry_balance();

CREATE OR REPLACE FUNCTION enforce_ledger_transaction_balance()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM assert_ledger_transaction_balanced(NEW.id);
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER ledger_transaction_status_balance_guard
AFTER INSERT OR UPDATE OF status ON ledger_transactions
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION enforce_ledger_transaction_balance();

CREATE OR REPLACE FUNCTION enforce_non_negative_user_wallet()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    affected_wallet_id uuid;
    affected_wallet_type varchar(20);
    calculated_balance numeric;
BEGIN
    affected_wallet_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.wallet_id ELSE NEW.wallet_id END;

    SELECT type INTO affected_wallet_type
    FROM wallets
    WHERE id = affected_wallet_id;

    IF affected_wallet_type = 'USER' THEN
        SELECT COALESCE(SUM(amount_subunits), 0) INTO calculated_balance
        FROM ledger_entries
        WHERE wallet_id = affected_wallet_id;

        IF calculated_balance < 0 THEN
            RAISE EXCEPTION 'User wallet % cannot have a negative balance', affected_wallet_id
                USING ERRCODE = '23514';
        END IF;
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER user_wallet_non_negative_guard
AFTER INSERT OR UPDATE OR DELETE ON ledger_entries
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION enforce_non_negative_user_wallet();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS user_wallet_non_negative_guard ON ledger_entries;
DROP FUNCTION IF EXISTS enforce_non_negative_user_wallet();
DROP TRIGGER IF EXISTS ledger_transaction_status_balance_guard ON ledger_transactions;
DROP FUNCTION IF EXISTS enforce_ledger_transaction_balance();
DROP TRIGGER IF EXISTS ledger_entries_balance_guard ON ledger_entries;
DROP FUNCTION IF EXISTS enforce_ledger_entry_balance();
DROP FUNCTION IF EXISTS assert_ledger_transaction_balanced(uuid);
DROP TRIGGER IF EXISTS ledger_entry_currency_guard ON ledger_entries;
DROP FUNCTION IF EXISTS validate_ledger_entry_currency();
DROP TRIGGER IF EXISTS ledger_entries_immutable_guard ON ledger_entries;
DROP FUNCTION IF EXISTS reject_ledger_entry_mutation();
SQL);
    }
};
