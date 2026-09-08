<?php

namespace App\Http\Requests;

use App\Enums\CallAttemptStatus;
use App\Enums\GatewayOperation;
use Illuminate\Foundation\Http\FormRequest;

final class TelemetryFilterRequest extends FormRequest
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
            'application' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(CallAttemptStatus::cases(), 'value'))],
            'operation' => ['nullable', 'string', 'in:'.implode(',', array_column(GatewayOperation::cases(), 'value'))],
            'model' => ['nullable', 'string', 'max:200'],
            'error_category' => ['nullable', 'string', 'max:40'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->only(['application', 'status', 'operation', 'model', 'error_category', 'request_id', 'from', 'to']),
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }
}
