<?php

namespace App\Http\Requests;

use App\Enums\TelemetryDeletionMode;
use App\Enums\TelemetryDeletionScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DeleteTelemetryContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdministrator();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::enum(TelemetryDeletionScope::class)],
            'mode' => ['required', Rule::enum(TelemetryDeletionMode::class)],
            // Deletion is irreversible; a recorded reason and an explicit
            // confirmation phrase are both required.
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['required', 'string', 'in:DELETE'],
            'application' => ['nullable', 'string', 'max:64'],
            'request_ids' => ['array', 'max:'.(int) config('telemetry.deletion.max_selected')],
            'request_ids.*' => ['string', 'max:64'],
            'range_from' => ['nullable', 'date'],
            'range_to' => ['nullable', 'date', 'after_or_equal:range_from'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirmation.in' => 'Type DELETE to confirm this irreversible action.',
        ];
    }
}
