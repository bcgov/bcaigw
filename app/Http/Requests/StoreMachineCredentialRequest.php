<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreMachineCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manageCredentials', $this->route('application')) === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('expires_at') === '') {
            $this->merge(['expires_at' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', Rule::in(array_keys(config('machine-auth.abilities')))],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
