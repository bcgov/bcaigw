<?php

namespace App\Http\Requests;

final class UpdateApplicationRequest extends ApplicationDataRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('application')) ?? false;
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
