<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

final class ChatCompletionRequest extends GatewayRequest
{
    public function rules(): array
    {
        return [
            'model' => ['required', 'string', 'max:128'],
            'messages' => ['required', 'array', 'min:1', 'max:'.config('gateway-data.max_messages')],
            'messages.*' => ['required', 'array'],
            'messages.*.role' => ['required', Rule::in(['system', 'user', 'assistant', 'tool'])],
            'messages.*.content' => ['required'],
            'messages.*.name' => ['sometimes', 'string', 'max:64'],
            'temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'top_p' => ['sometimes', 'numeric', 'between:0,1'],
            'max_tokens' => ['sometimes', 'integer', 'min:1'],
            'max_completion_tokens' => ['sometimes', 'integer', 'min:1'],
            'stream' => ['sometimes', 'boolean'],
            'stop' => ['sometimes'],
            ...$this->metadataRules(),
        ];
    }

    protected function supportedParameters(): array
    {
        return [
            'model',
            'messages',
            'temperature',
            'top_p',
            'max_tokens',
            'max_completion_tokens',
            'stream',
            'stop',
            'metadata',
        ];
    }
}
