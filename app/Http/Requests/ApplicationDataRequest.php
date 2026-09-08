<?php

namespace App\Http\Requests;

use App\Enums\ApplicationEnvironment;
use App\Enums\DataClassification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class ApplicationDataRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $application = $this->route('application');

        return [
            'name' => ['required', 'string', 'max:150'],
            'api_directory_client_id' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9._:-]+$/',
                Rule::unique('applications', 'api_directory_client_id')->ignore($application?->id),
            ],
            'ministry_organization' => ['required', 'string', 'max:180'],
            'purpose_use_case' => ['required', 'string', 'min:20', 'max:5000'],
            'primary_contact_name' => ['required', 'string', 'max:150'],
            'primary_contact_email' => ['required', 'email:rfc', 'max:254'],
            'technical_contact_name' => ['nullable', 'string', 'max:150'],
            'technical_contact_email' => ['nullable', 'email:rfc', 'max:254'],
            'environments' => ['required', 'array', 'min:1'],
            'environments.*' => ['required', Rule::enum(ApplicationEnvironment::class), 'distinct'],
            'expected_requests_per_minute' => ['required', 'integer', 'min:1', 'max:1000000'],
            'expected_tokens_per_month' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'data_classification' => ['required', Rule::enum(DataClassification::class)],
            'requested_models' => ['required', 'array', 'min:1'],
            'requested_models.*' => ['required', 'string', Rule::in(array_keys(config('gateway.requested_models'))), 'distinct'],
            'requested_capabilities' => ['required', 'array', 'min:1'],
            'requested_capabilities.*' => ['required', 'string', Rule::in(array_keys(config('gateway.capabilities'))), 'distinct'],
            'status_version' => ['sometimes', 'integer', 'min:0'],
            'status' => ['prohibited'],
            'prompt_response_retention_enabled' => ['prohibited'],
            'rate_limit_per_minute' => ['prohibited'],
            'token_rate_per_minute' => ['prohibited'],
            'token_budget_daily' => ['prohibited'],
            'token_budget_monthly' => ['prohibited'],
            'cost_budget_daily' => ['prohibited'],
            'cost_budget_monthly' => ['prohibited'],
            'budget_currency' => ['prohibited'],
            'configuration_ready' => ['prohibited'],
        ];
    }
}
