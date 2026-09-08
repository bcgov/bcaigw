<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RotateMachineCredentialRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'overlap_seconds' => [
                'required',
                'integer',
                'min:0',
                'max:'.config('machine-auth.max_rotation_overlap_seconds'),
            ],
        ];
    }
}
