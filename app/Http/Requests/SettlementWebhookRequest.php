<?php

namespace App\Http\Requests;

use App\Enums\SwapStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class SettlementWebhookRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->is('api/v1/*')) {
            $this->merge([
                'event_id' => $this->input('event_id', (string) Str::uuid()),
                'occurred_at' => $this->input('occurred_at', now()->toAtomString()),
            ]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'provider_reference' => ['required', 'string', 'max:100'],
            'status' => [
                'required',
                'string',
                Rule::in([
                    SwapStatus::INITIATED->value,
                    SwapStatus::PROCESSING->value,
                    SwapStatus::COMPLETED->value,
                    SwapStatus::FAILED->value,
                ]),
            ],
            'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
        ];
    }
}
