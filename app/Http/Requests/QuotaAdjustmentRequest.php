<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class QuotaAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    public function rules(): array
    {
        return [
            'token_adjustment' => ['nullable', 'required_without:cost_adjustment', 'integer', 'between:-1000000000,1000000000', 'not_in:0'],
            'cost_adjustment' => ['nullable', 'required_without:token_adjustment', 'numeric', 'between:-1000000,1000000', 'not_in:0', 'regex:/^-?\d{1,7}(\.\d{1,6})?$/'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
