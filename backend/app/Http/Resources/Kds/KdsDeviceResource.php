<?php

namespace App\Http\Resources\Kds;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * KdsDeviceResource — KDS-scoped device representation.
 *
 * Hides DB-internal FK columns (organization_id, console_*_id, created_by_id,
 * updated_by_id, device_token, pairing_code, pairing_expires_at, branch_id) and
 * exposes only semantic fields relevant to the KDS display context.
 */
class KdsDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'name' => $this->name,
            'status' => $this->status,
            'branch' => $this->whenLoaded('branch', fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
            ]),
            'paired_at' => $this->paired_at?->toIso8601String(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'capabilities' => [
                'supports_offline' => true,
                'supports_wake_lock' => true,
                'supports_audio' => true,
            ],
        ];
    }
}
