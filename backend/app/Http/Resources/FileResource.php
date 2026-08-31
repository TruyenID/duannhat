<?php

/**
 * File Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\File\Resources\FileResourceBase;
use Illuminate\Http\Request;

/**
 * FileResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class FileResource extends FileResourceBase
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'collection' => $this->collection,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'status' => $this->status?->value ?? $this->status,
            'url' => $this->getUrl(),
            'is_permanent' => $this->isPermanent(),
            'is_expired' => $this->isExpired(),
            'expires_at' => $this->expires_at?->toISOString(),
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
