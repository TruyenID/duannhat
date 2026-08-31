<?php

namespace App\Http\Resources\Admin;

use App\Models\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin NotificationTemplate
 */
class TemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'content' => $this->content,
            'default_channels' => $this->default_channels,
            'params_schema' => $this->params_schema,
            'brand_id' => $this->brand_id,
            'organization_id' => $this->organization_id,
            'is_system' => (bool) $this->is_system,
            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
