<?php

namespace App\Http\Requests\Workstation;

use App\Models\PeripheralDevice;
use App\Services\PeripheralDevice\PeripheralDeviceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a workstation editing one of its branch's peripheral devices.
 *
 * Metadata rules apply only when `metadata` is part of the request so a partial
 * update (e.g. toggling is_active) is not blocked — mirrors the shop path.
 */
class WorkstationPeripheralDeviceUpdateRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::in(PeripheralDeviceService::ALLOWED_TYPES)],
            'metadata' => ['nullable', 'array'],
            'secret' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($this->has('metadata')) {
            $effectiveType = $this->input('type') ?? $this->existingDeviceType();
            $rules = array_merge($rules, PeripheralDeviceService::metadataRulesFor(
                $effectiveType,
                $this->attributes->get('device')?->organization_id,
            ));
        }

        return $rules;
    }

    private function existingDeviceType(): ?string
    {
        $id = (string) $this->route('peripheral_device');

        return $id !== ''
            ? PeripheralDevice::withTrashed()->whereKey($id)->value('type')
            : null;
    }
}
