<?php

namespace Modules\Admin\Http\Requests;

use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplicationTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', Rule::in([
                Application::STATUS_APPROVED,
                Application::STATUS_REJECTED,
                Application::STATUS_ACTIVE,
                Application::STATUS_SUSPENDED,
            ])],
            'status_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
