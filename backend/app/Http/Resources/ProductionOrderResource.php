<?php

/**
 * ProductionOrder Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\ProductionOrder\Resources\ProductionOrderResourceBase;
use Illuminate\Http\Request;

/**
 * ProductionOrderResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class ProductionOrderResource extends ProductionOrderResourceBase
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

        return $data;
    }
}
