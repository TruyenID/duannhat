<?php

/**
 * Peripheral Device Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Services\PeripheralDevice\PeripheralDeviceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PeripheralDeviceStoreRequest — validation for admin registration.
 */
class PeripheralDeviceStoreRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('peripheral_devices', 'name')
                    ->where('branch_id', $this->input('branch_id') ?? $this->attributes->get('branch_id')),
            ],
            'type' => ['required', 'string', Rule::in($this->allowedTypes())],
            'metadata' => ['nullable', 'array'],
            'secret' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['sometimes', 'uuid',
                Rule::exists('branches', 'id')->where('console_organization_id', $this->attributes->get('organization')?->console_organization_id),
            ],
        ];

        // LAN devices (P400 / 釣銭機) must carry a reachable network address so the
        // workstation can drive them. See PeripheralDeviceService::NETWORK_TYPES.
        // #1156 — the org id scopes `metadata.accepts` validation to the org's
        // tender vocabulary.
        return array_merge($rules, PeripheralDeviceService::metadataRulesFor(
            $this->input('type'),
            $this->attributes->get('organization')?->id,
        ));
    }

    private function allowedTypes(): array
    {
        return PeripheralDeviceService::ALLOWED_TYPES;
    }
}
