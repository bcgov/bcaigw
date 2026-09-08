<?php

namespace App\Http\Requests;

use App\Exceptions\GatewayException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class GatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    abstract protected function supportedParameters(): array;

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->all()), $this->supportedParameters());
            if ($unknown !== []) {
                $validator->errors()->add(
                    (string) reset($unknown),
                    'This parameter is not supported by BC AI Gateway.',
                );
            }
        });
    }

    protected function failedValidation(Validator $validator): never
    {
        $parameter = $validator->errors()->keys()[0] ?? null;
        throw new GatewayException(
            'invalid_request_error',
            str_contains((string) $validator->errors()->first(), 'not supported')
                ? 'unsupported_parameter'
                : 'validation_error',
            422,
            $validator->errors()->first(),
            $parameter,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadataRules(): array
    {
        return [
            'metadata' => ['sometimes', 'array', 'max:'.config('gateway-data.max_metadata_entries')],
            'metadata.*' => ['nullable', 'string', 'max:512'],
        ];
    }
}
