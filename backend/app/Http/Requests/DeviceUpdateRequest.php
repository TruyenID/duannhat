<?php

/**
 * Device Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Enums\DeviceTypeEnum;
use App\Omnify\Modules\Device\Requests\DeviceUpdateRequestBase;
use Illuminate\Validation\Rule;

/**
 * DeviceUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class DeviceUpdateRequest extends DeviceUpdateRequestBase
{
    /**
     * Only allow updating user-editable fields.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('devices', 'name')
                    ->where('branch_id', $this->route('device')?->branch_id ?? $this->input('branch_id'))
                    ->ignore($this->route('device')),
            ],
            'type' => ['sometimes', 'string', Rule::in(DeviceTypeEnum::values())],
            'branch_id' => ['sometimes', 'uuid', 'exists:branches,id'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
