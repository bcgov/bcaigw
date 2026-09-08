<?php

namespace App\Http\Requests;

use App\Enums\ControlPlaneStatus;
use App\Enums\ModelCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PublicModelAliasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    public function rules(): array
    {
        $alias = $this->route('publicModelAlias');

        return [
            'model_id' => [
                'required',
                'string',
                'max:128',
                'regex:/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/',
                Rule::unique('public_model_aliases', 'model_id')->ignore($alias?->id),
            ],
            'display_name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', Rule::enum(ModelCapability::class), 'distinct'],
            'target_public_id' => ['nullable', 'string', 'exists:upstream_targets,public_id'],
            'status' => ['required', Rule::enum(ControlPlaneStatus::class)],
            'configuration_version' => [$this->isMethod('PATCH') ? 'required' : 'sometimes', 'integer', 'min:1'],
        ];
    }
}
