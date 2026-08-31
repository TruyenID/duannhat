<?php

/**
 * Peripheral Device Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Keep `secret` off the API surface for both shop management and replicas.
 */
class PeripheralDeviceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_active' => (bool) $this->is_active,
            'metadata' => $this->metadata,
            'registered_by_device_id' => $this->registered_by_device_id,
            'branch_id' => $this->branch_id,
            'organization_id' => $this->organization_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
