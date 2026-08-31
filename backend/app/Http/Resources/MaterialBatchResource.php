<?php

/**
 * MaterialBatch Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\MaterialBatch\Resources\MaterialBatchResourceBase;
use Illuminate\Http\Request;

/**
 * MaterialBatchResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class MaterialBatchResource extends MaterialBatchResourceBase
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->schemaArray($request);

        $data['items_count'] = $this->whenCounted('items');

        // material_id is a raw Uuid in the schema (not an Association), so
        // omnify's base resource never emits the related Material. Surface it
        // here when the caller has eager-loaded it (list + detail both do).
        $data['material'] = $this->whenLoaded(
            'material',
            fn () => new MaterialResource($this->material)
        );

        return $data;
    }
}
