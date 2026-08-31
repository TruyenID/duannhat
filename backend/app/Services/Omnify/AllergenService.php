<?php

/**
 * Allergen Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Exceptions\AllergenInUseException;
use App\Models\Allergen;
use App\Omnify\Modules\Allergen\Services\AllergenServiceBase;

class AllergenService extends AllergenServiceBase
{
    /**
     * Block soft-delete when the allergen is still referenced by ≥1
     * Material via material_allergens. Surfaces the blocking materials
     * so the admin can detach before retrying. forceDelete inherits from
     * the base — admins can bypass the guard if they really mean it.
     */
    public function delete(Allergen $model): bool
    {
        $usedBy = $model->materials()
            ->select('materials.id', 'materials.name')
            ->limit(20)
            ->get()
            ->map(fn ($m) => ['id' => (string) $m->id, 'name' => $m->name])
            ->all();

        if (! empty($usedBy)) {
            throw new AllergenInUseException(
                'Cannot delete allergen: still in use by '.count($usedBy).' material(s).',
                $usedBy,
            );
        }

        return parent::delete($model);
    }
}
