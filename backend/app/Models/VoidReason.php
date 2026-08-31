<?php

/**
 * VoidReason Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\VoidReason\Models\VoidReasonBaseModel;
use Database\Factories\VoidReasonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * VoidReason — add project-specific model logic here.
 */
class VoidReason extends VoidReasonBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): VoidReasonFactory
    {
        return VoidReasonFactory::new();
    }

    /**
     * plan-051 — locale-resolved label with the same fallback walk as
     * Product::localizedName (current locale → vi → ja → en → any non-empty
     * translation). Used to snapshot a human-readable label into the item's
     * `void_reason` text column so history stays self-contained even if the
     * reason row is later deactivated or relabelled.
     */
    public function localizedLabel(?string $locale = null): ?string
    {
        $locale = $locale ?: app()->getLocale();

        foreach (array_values(array_unique([$locale, 'vi', 'ja', 'en'])) as $loc) {
            $value = $this->translate($loc)?->label;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        foreach ($this->translations as $translation) {
            if (is_string($translation->label) && trim($translation->label) !== '') {
                return $translation->label;
            }
        }

        return null;
    }
}
