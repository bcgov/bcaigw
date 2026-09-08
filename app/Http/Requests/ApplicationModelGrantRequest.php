<?php

namespace App\Http\Requests;

use App\Enums\ModelCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApplicationModelGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    public function rules(): array
    {
        return [
            'application_public_id' => ['required', 'string', 'exists:applications,public_id'],
            'model_alias_public_id' => ['required', 'string', 'exists:public_model_aliases,public_id'],
            'capabilities' => ['required', 'array', 'min:1'],
            'capabilities.*' => ['required', Rule::enum(ModelCapability::class), 'distinct'],
            'enabled' => ['required', 'boolean'],
            'configuration_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
