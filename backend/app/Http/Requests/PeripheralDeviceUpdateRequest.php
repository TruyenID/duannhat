<?php

/**
 * Peripheral Device Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Models\PeripheralDevice;
use App\Services\PeripheralDevice\PeripheralDeviceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PeripheralDeviceUpdateRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('peripheral_devices', 'name')
                    ->where('branch_id', $this->route('peripheral_device')?->branch_id ?? $this->input('branch_id'))
                    ->ignore($this->route('peripheral_device')),
            ],
            'type' => ['sometimes', 'string', Rule::in($this->allowedTypes())],
            'metadata' => ['nullable', 'array'],
            'secret' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['sometimes', 'uuid', Rule::exists('branches', 'id')],
        ];

        // When editing a LAN device's metadata (P400 / 釣銭機), the address must
        // stay valid. Only enforced when metadata is part of THIS request so a
        // partial update (e.g. toggling is_active) is not blocked. The route param
        // is the raw id (no model binding), so the existing type is looked up.
        $effectiveType = $this->input('type') ?? $this->existingDeviceType();
        if ($this->has('metadata')) {
            $rules = array_merge($rules, PeripheralDeviceService::metadataRulesFor(
                $effectiveType,
                $this->attributes->get('organization')?->id,
            ));
        }

        return $rules;
    }

    private function allowedTypes(): array
    {
        return PeripheralDeviceService::ALLOWED_TYPES;
    }

    /**
     * The stored type of the device being updated. The route parameter is the
     * raw id (this resource uses manual resolution, not model binding), so read
     * the type directly rather than off a would-be bound model.
     */
    private function existingDeviceType(): ?string
    {
        $id = $this->route('peripheral_device');

        if (is_object($id)) {
            return $id->type;
        }

        return PeripheralDevice::withTrashed()->whereKey((string) $id)->value('type');
    }
}
