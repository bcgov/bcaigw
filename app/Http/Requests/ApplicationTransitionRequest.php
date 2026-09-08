<?php

namespace App\Http\Requests;

use App\Enums\ApplicationTransition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApplicationTransitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'transition' => ['required', Rule::enum(ApplicationTransition::class)],
            'status_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
