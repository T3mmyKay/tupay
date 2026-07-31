<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
