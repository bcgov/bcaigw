<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ModelPricingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdministrator() ?? false;
    }

    public function rules(): array
    {
        return [
            'effective_at' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'input_cost_per_million_tokens' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'output_cost_per_million_tokens' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'cached_input_cost_per_million_tokens' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }
}
