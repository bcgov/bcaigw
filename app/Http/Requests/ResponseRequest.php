<?php

namespace App\Http\Requests;

final class ResponseRequest extends GatewayRequest
{
    public function rules(): array
    {
        return [
            'model' => ['required', 'string', 'max:128'],
            'input' => ['required'],
            'instructions' => ['sometimes', 'string', 'max:'.config('gateway-data.max_text_characters')],
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'top_p' => ['sometimes', 'numeric', 'between:0,1'],
            'max_output_tokens' => ['sometimes', 'integer', 'min:1'],
            'stream' => ['sometimes', 'boolean'],
            ...$this->metadataRules(),
        ];
    }

    protected function supportedParameters(): array
    {
        return [
            'model',
            'input',
            'instructions',
            'temperature',
            'top_p',
            'max_output_tokens',
            'stream',
            'metadata',
        ];
    }
}
