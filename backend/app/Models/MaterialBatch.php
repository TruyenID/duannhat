<?php

/**
 * MaterialBatch Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MaterialBatch\Models\MaterialBatchBaseModel;
use Database\Factories\MaterialBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MaterialBatch — add project-specific model logic here.
 */
class MaterialBatch extends MaterialBatchBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MaterialBatchFactory
    {
        return MaterialBatchFactory::new();
    }

    /**
     * Output material this batch produces. material_id is declared as a raw
     * Uuid in the schema (not an Association) so omnify doesn't generate
     * the relation method — define it here so list/detail eager-loads can
     * hydrate the material name for the shop UI table.
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }
}
