<?php

namespace App\Http\Resources\Admin;

use App\Models\NotificationAudience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationAudience
 */
class AudienceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'rule' => $this->rule,
            'brand_id' => $this->brand_id,
            'organization_id' => $this->organization_id,
            'is_system' => (bool) $this->is_system,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
