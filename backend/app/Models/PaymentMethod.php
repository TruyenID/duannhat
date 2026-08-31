<?php

/**
 * PaymentMethod Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PaymentMethod\Models\PaymentMethodBaseModel;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PaymentMethod — add project-specific model logic here.
 */
class PaymentMethod extends PaymentMethodBaseModel
{
    use HasFactory;
    use PreservesTranslatableColumns;

    public const GLOBAL_SCOPE_KEY = 'global';

    /**
     * Keep `scope_key` in step with `branch_id` on every write.
     *
     * `scope_key` backs `payment_methods_org_scope_code_unique`, which is what
     * stops two global (branch-less) methods sharing a code — a plain unique
     * over `branch_id` cannot, because SQL treats every NULL as distinct.
     *
     * The column used to be a generated one, so the database derived this
     * itself. Production refused to index a generated column (ERROR 1901,
     * see the T1.10a migration), so it is now an ordinary column and the
     * derivation lives here. `scope_key` is absent from `$fillable`, so this
     * is the only path that sets it — a caller cannot forge a scope.
     */
    protected static function booted(): void
    {
        static::saving(function (self $method): void {
            $method->scope_key = $method->branch_id ?? self::GLOBAL_SCOPE_KEY;
        });
    }

    /**
     * Enable Astrotomic fallback: missing translation → fallback_locale (en)
     * → base column (`payment_methods.name`). PreservesTranslatableColumns
     * populates the base column from ja → en → vi priority on write, so the
     * last-resort property fallback always resolves to a sensible non-null
     * value even if the user entered only one language.
     *
     * Pair with config('translatable.use_property_fallback') = true.
     */
    protected $useTranslationFallback = true;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentMethodFactory
    {
        return PaymentMethodFactory::new();
    }
}
