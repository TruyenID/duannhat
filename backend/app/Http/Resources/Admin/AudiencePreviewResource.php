<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Thin resource for POST /audiences/preview — returns {count, sample[≤10]}
 * so the admin UI can render "resolves to N users" beside the rule builder.
 */
class AudiencePreviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'count' => $this->resource['count'] ?? 0,
            'sample' => $this->resource['sample'] ?? [],
        ];
    }
}
