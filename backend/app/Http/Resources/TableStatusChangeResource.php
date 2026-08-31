<?php

/**
 * TableStatusChange Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\TableStatusChange\Resources\TableStatusChangeResourceBase;
use BackedEnum;
use Illuminate\Http\Request;

class TableStatusChangeResource extends TableStatusChangeResourceBase
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_status' => $this->from_status instanceof BackedEnum
                ? $this->from_status->value
                : $this->from_status,
            'to_status' => $this->to_status instanceof BackedEnum
                ? $this->to_status->value
                : $this->to_status,
            'changed_by_id' => $this->changed_by_id,
            'changed_at' => $this->changed_at?->toISOString(),
            'note' => $this->note,
        ];
    }
}
