<?php

namespace App\Http\Requests;

use App\Enums\ApplicationEnvironment;
use App\Enums\ControlPlaneStatus;
use App\Enums\ProviderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProviderAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::enum(ProviderType::class)],
            'environment' => ['required', Rule::enum(ApplicationEnvironment::class)],
            'region' => ['nullable', 'string', 'max:100'],
            'secret_reference' => ['nullable', 'string', 'max:500'],
            'configuration_json' => ['nullable', 'json', 'max:10000'],
            'sensitive_configuration_json' => ['nullable', 'json', 'max:10000'],
            'status' => ['required', Rule::enum(ControlPlaneStatus::class)],
            'configuration_version' => [$this->isMethod('PATCH') ? 'required' : 'sometimes', 'integer', 'min:1'],
        ];
    }
}
