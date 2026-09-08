<?php

namespace App\Http\Requests;

use App\Enums\ApplicationMemberRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApplicationMemberRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idir_username' => strtoupper((string) $this->input('idir_username')),
        ]);
    }

    public function authorize(): bool
    {
        return $this->user()?->can('manageMembers', $this->route('application')) ?? false;
    }

    public function rules(): array
    {
        return [
            'idir_username' => ['required', 'string', 'max:100', 'exists:users,idir_username'],
            'role' => ['required', Rule::enum(ApplicationMemberRole::class)],
        ];
    }
}
