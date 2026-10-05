<?php

namespace Modules\Portal\Http\Requests;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplicationMemberRequest extends FormRequest
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idir_username' => ['required', 'string', 'max:100', 'exists:users,idir_username'],
            'role' => ['required', 'string', Rule::in([Application::ROLE_OWNER, Application::ROLE_MEMBER])],
        ];
    }
}
