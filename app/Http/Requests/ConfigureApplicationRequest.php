<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfigureApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    public function rules(): array
    {
        return [
            'status_version' => ['required', 'integer', 'min:0'],
            'prompt_response_retention_enabled' => ['required', 'boolean'],
            // Optional per-application ceiling. It can only lower the global
            // limit, so it is validated against the configured maximum.
            'retention_max_content_bytes' => [
                'nullable',
                'integer',
                'min:1024',
                'max:'.max(
                    (int) config('telemetry.content.max_request_bytes'),
                    (int) config('telemetry.content.max_response_bytes'),
                ),
            ],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'token_rate_per_minute' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'token_budget_daily' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'token_budget_monthly' => ['nullable', 'integer', 'min:1', 'max:100000000000'],
            'cost_budget_daily' => ['nullable', 'numeric', 'min:0.01', 'max:100000000', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'cost_budget_monthly' => ['nullable', 'numeric', 'min:0.01', 'max:100000000', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'budget_currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'configuration_ready' => ['required', 'boolean'],
        ];
    }
}
