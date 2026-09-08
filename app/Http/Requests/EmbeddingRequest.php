<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class EmbeddingRequest extends GatewayRequest
{
    public function rules(): array
    {
        return [
            'model' => ['required', 'string', 'max:128'],
            'input' => ['required'],
            'encoding_format' => ['sometimes', Rule::in(['float', 'base64'])],
            'dimensions' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.config('gateway-data.max_embedding_dimensions'),
            ],
        ];
    }

    protected function supportedParameters(): array
    {
        return ['model', 'input', 'encoding_format', 'dimensions'];
    }
}
