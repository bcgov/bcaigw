<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RevealCallContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // A reason is mandatory: revealing content is an audited disclosure.
            'reason' => ['required', 'string', 'min:5', 'max:200'],
        ];
    }
}
