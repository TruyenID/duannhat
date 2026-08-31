<?php

namespace App\Http\Requests\Workstation;

use App\Services\PeripheralDevice\PeripheralDeviceService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a workstation registering a peripheral device on Cloud.
 *
 * The branch/organization are taken from the authed device token (not the
 * body), so this request only validates the device's own attributes. Metadata
 * rules for LAN devices (P400 / 釣銭機) are shared with the shop path via
 * PeripheralDeviceService::metadataRulesFor().
 */
class WorkstationPeripheralDeviceStoreRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            // Workstation supplies its local uuid so an offline-created row keeps
            // the SAME id after sync UP (Cloud upserts by it) — no local↔cloud remap.
            'id' => ['sometimes', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(PeripheralDeviceService::ALLOWED_TYPES)],
            'secret' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ], PeripheralDeviceService::metadataRulesFor(
            $this->input('type'),
            $this->attributes->get('device')?->organization_id,
        ));
    }
}
