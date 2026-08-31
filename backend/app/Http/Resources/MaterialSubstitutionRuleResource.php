<?php

/**
 * MaterialSubstitutionRule Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\MaterialSubstitutionRule\Resources\MaterialSubstitutionRuleResourceBase;
use Illuminate\Http\Request;

/**
 * MaterialSubstitutionRuleResource — adds a lightweight `substitute_material`
 * projection ({id, sku, name}) the material detail card reads to label the
 * substitute without a second round-trip.
 */
class MaterialSubstitutionRuleResource extends MaterialSubstitutionRuleResourceBase
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'substitute_material' => $this->whenLoaded('substituteMaterial', fn () => [
                'id' => $this->substituteMaterial->id,
                'sku' => $this->substituteMaterial->sku,
                'name' => $this->substituteMaterial->name,
            ]),
        ]);
    }
}
