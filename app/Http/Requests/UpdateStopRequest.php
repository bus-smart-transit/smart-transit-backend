<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class UpdateStopRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'stop_name' => 'sometimes|string|max:255',
            // Lowercase letters, digits and single hyphens; the public key in booking links.
            'stop_code' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                \Illuminate\Validation\Rule::unique('stops', 'stop_code')->ignore($this->route('stopId') ?? $this->route('stop'), 'stop_id')],
            'location'  => 'sometimes|nullable|string|max:255',
            'latitude'  => 'sometimes|nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180|required_with:latitude',
            'municipality' => ['sometimes', 'nullable', 'string', 'max:100', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && app(\App\Services\RegionService::class)->canonicalMunicipality($value) === null) {
                    $fail('The municipality must be one listed in the region configuration.');
                }
            }],
        ];
    }
}
