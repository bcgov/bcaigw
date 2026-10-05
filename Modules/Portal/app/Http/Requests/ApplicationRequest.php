<?php

namespace Modules\Portal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\PublicModelAlias;

class ApplicationRequest extends FormRequest
{
    /** @var array<string, array<int, string>> */
    private array $capabilitiesByModel = [];

    public function authorize(): bool
    {
        $application = $this->route('application');

        if ($application === null) {
            return $this->user() !== null;
        }

        return $this->user()?->can('update', $application) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $application = $this->route('application');

        $activeAliases = PublicModelAlias::query()
            ->where('status', 'active')
            ->get(['model_id', 'capabilities']);
        $activeModelIds = $activeAliases->pluck('model_id')->all();
        $this->capabilitiesByModel = $activeAliases
            ->mapWithKeys(fn (PublicModelAlias $alias) => [$alias->model_id => $alias->capabilities ?? []])
            ->all();

        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'api_directory_client_id' => [
                'required',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9._:-]+$/',
                Rule::unique('applications', 'api_directory_client_id')->ignore($application?->id),
            ],
            'ministry_organization' => ['required', 'string', 'max:180'],
            'purpose_use_case' => ['required', 'string', 'min:20', 'max:5000'],
            'primary_contact_name' => ['required', 'string', 'max:150'],
            'primary_contact_email' => ['required', 'email:rfc', 'max:254'],
            'technical_contact_name' => ['nullable', 'string', 'max:150'],
            'technical_contact_email' => ['nullable', 'email:rfc', 'max:254'],
            'environments' => ['required', 'array', 'min:1'],
            'environments.*' => ['required', 'string', Rule::in(array_keys(config('gateway.environments'))), 'distinct'],
            'expected_requests_per_minute' => ['required', 'integer', 'min:1', 'max:1000000'],
            'expected_tokens_per_month' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'data_classification' => ['required', 'string', Rule::in(array_keys(config('gateway.classifications')))],
            'requested_models' => ['required', 'array', 'min:1'],
            'requested_models.*' => ['required', 'string', Rule::in($activeModelIds), 'distinct'],
            'requested_capabilities' => ['required', 'array', 'min:1'],
        ];

        if ($application !== null) {
            $rules['status_version'] = ['required', 'integer', 'min:0'];
        }

        return $rules;
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $models = (array) $this->input('requested_models', []);
            $selectedByModel = (array) $this->input('requested_capabilities', []);

            foreach ($models as $modelId) {
                $selected = array_values(array_filter((array) ($selectedByModel[$modelId] ?? [])));

                if ($selected === []) {
                    $validator->errors()->add("requested_capabilities.{$modelId}", 'Select at least one capability for this model.');

                    continue;
                }

                $allowed = $this->capabilitiesByModel[$modelId] ?? [];
                if (array_diff($selected, $allowed) !== []) {
                    $validator->errors()->add("requested_capabilities.{$modelId}", 'One or more capabilities are not available for this model.');
                }
            }
        });
    }
}
