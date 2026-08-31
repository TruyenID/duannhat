<?php

/**
 * Warehouse Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Warehouse\Resources\WarehouseResourceBase;
use Illuminate\Http\Request;

/**
 * WarehouseResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class WarehouseResource extends WarehouseResourceBase
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->schemaArray($request);

        $data['members_count'] = $this->whenCounted('members');

        return $data;
    }
}
