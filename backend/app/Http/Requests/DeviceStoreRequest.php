<?php

/**
 * Device Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Enums\DeviceTypeEnum;
use App\Omnify\Modules\Device\Requests\DeviceStoreRequestBase;
use Illuminate\Validation\Rule;

/**
 * DeviceStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class DeviceStoreRequest extends DeviceStoreRequestBase
{
    /**
     * Only expose user-supplied fields. Auto-generated fields (organization_id,
     * pairing_code, pairing_expires_at, device_token, paired_at, last_seen_at,
     * device_info, status) are set by the service layer.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('devices', 'name')
                    ->where('branch_id', $this->input('branch_id') ?? $this->attributes->get('branch_id')),
            ],
            'type' => ['required', 'string', Rule::in(DeviceTypeEnum::values())],
            // #845 — scope branch_id to the caller's console organization so a
            // tenant cannot pair a device to another tenant's branch (which the
            // KDS/customer order endpoints scope by branch_id). The resolving
            // middleware (ResolveBrandFromSlug / ResolvesShopContext) stamps the
            // caller's local Organization onto the request; branches are keyed
            // by console_organization_id. When no org context is present the
            // where clause matches nothing, failing closed.
            'branch_id' => [
                'sometimes', 'uuid',
                Rule::exists('branches', 'id')->where(
                    'console_organization_id',
                    $this->attributes->get('organization')?->console_organization_id,
                ),
            ],
            'notes' => ['nullable', 'string'],
        ];
    }
}
