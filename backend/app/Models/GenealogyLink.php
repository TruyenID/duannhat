<?php

/**
 * GenealogyLink Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\GenealogyLink\Models\GenealogyLinkBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\GenealogyLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * GenealogyLink — add project-specific model logic here.
 */
class GenealogyLink extends GenealogyLinkBaseModel
{
    use AuditsActivity;
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): GenealogyLinkFactory
    {
        return GenealogyLinkFactory::new();
    }

    //
}
