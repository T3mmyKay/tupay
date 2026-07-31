<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StepUpChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'totp_code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'action_payload' => ['required', 'array:action,source_wallet_id,destination_wallet_id,amount_subunits'],
            'action_payload.action' => ['required', 'string', 'in:swap'],
            'action_payload.source_wallet_id' => ['required', 'uuid'],
            'action_payload.destination_wallet_id' => ['required', 'uuid', 'different:action_payload.source_wallet_id'],
            'action_payload.amount_subunits' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function actionPayload(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->validated('action_payload');

        return $payload;
    }
}
