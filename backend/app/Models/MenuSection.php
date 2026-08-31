<?php

/**
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MenuSection\Models\MenuSectionBaseModel;
use App\Traits\AuditsActivity;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\MenuSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MenuSection extends MenuSectionBaseModel
{
    use AuditsActivity;

    /** @use HasFactory<MenuSectionFactory> */
    use HasFactory;

    use PreservesTranslatableColumns;

    protected $useTranslationFallback = true;

    protected static function newFactory(): MenuSectionFactory
    {
        return MenuSectionFactory::new();
    }
}
