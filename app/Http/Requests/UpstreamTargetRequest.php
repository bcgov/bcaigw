<?php

namespace App\Http\Requests;

use App\Enums\ApplicationEnvironment;
use App\Enums\ControlPlaneStatus;
use App\Enums\ModelCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpstreamTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    public function rules(): array
    {
        return [
            'provider_public_id' => ['required', 'string', 'exists:provider_accounts,public_id'],
            'name' => ['required', 'string', 'max:150'],
            'environment' => ['required', Rule::enum(ApplicationEnvironment::class)],
            'region' => ['nullable', 'string', 'max:100'],
            'base_url' => ['required', 'url', 'max:2048'],
            'provider_model_identifier' => ['required', 'string', 'max:255'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', Rule::enum(ModelCapability::class), 'distinct'],
            'context_window' => ['required', 'integer', 'min:1', 'max:10000000'],
            'max_input_tokens' => ['required', 'integer', 'min:1', 'lte:context_window'],
            'max_output_tokens' => ['required', 'integer', 'min:1', 'lte:context_window'],
            'status' => ['required', Rule::enum(ControlPlaneStatus::class)],
            'timeout_seconds' => ['required', 'integer', 'min:1', 'max:120'],
            'verify_tls' => ['required', 'accepted'],
            'max_connections' => ['required', 'integer', 'min:1', 'max:100'],
            'configuration_version' => [$this->isMethod('PATCH') ? 'required' : 'sometimes', 'integer', 'min:1'],
        ];
    }
}
