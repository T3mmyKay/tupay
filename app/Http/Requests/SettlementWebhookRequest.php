<?php

namespace App\Http\Requests;

use App\Enums\SwapStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SettlementWebhookRequest extends FormRequest
{
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
