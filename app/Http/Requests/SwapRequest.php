<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class SwapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'source_wallet_id' => ['required', 'uuid'],
            'destination_wallet_id' => ['required', 'uuid', 'different:source_wallet_id'],
            'amount_subunits' => ['required', 'integer', 'min:1'],
        ];
    }

    public function idempotencyKey(): string
    {
        $key = $this->header('Idempotency-Key');

        if (! is_string($key) || preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key) !== 1) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The Idempotency-Key header is required and must contain 8 to 100 safe characters.'],
            ]);
        }

        return $key;
    }

    public function requestHash(): string
    {
        return hash('sha256', json_encode($this->actionPayload(), JSON_THROW_ON_ERROR));
    }

    /** @return array{action: string, source_wallet_id: string, destination_wallet_id: string, amount_subunits: int} */
    public function actionPayload(): array
    {
        return [
            'action' => 'swap',
            'source_wallet_id' => (string) $this->validated('source_wallet_id'),
            'destination_wallet_id' => (string) $this->validated('destination_wallet_id'),
            'amount_subunits' => (int) $this->validated('amount_subunits'),
        ];
    }
}
