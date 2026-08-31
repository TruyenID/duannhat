<?php

namespace App\Traits;

/**
 * Ensures translatable attributes also persist to AND serialize from the
 * main table.
 *
 * Astrotomic's Translatable trait strips translatable keys from the main
 * attribute bag during fill(), so the INSERT/UPDATE leaves the base column
 * NULL. This trait captures a flat value for each translatable attribute
 * and re-sets it on $this->attributes so the base column always carries a
 * sensible "default" value.
 *
 * Priority when the caller did NOT pass a flat string:
 *   1. nested `ja.<attr>` → 2. nested `en.<attr>` → 3. nested `vi.<attr>`
 *
 * The first non-empty value wins. Pair with Astrotomic's
 * use_property_fallback = true so reads still resolve the right locale.
 *
 * The read-path half: Astrotomic's attributesToArray() ALWAYS overwrites
 * every translatedAttributes key with getAttributeOrFallback() — which
 * resolves ONLY through the `translations` relation (current locale, then
 * translatable.fallback_locale) and returns null if that relation has zero
 * rows, even though the base column fill() above just guaranteed holds a
 * value. A row created via ProductMutationFacade/CategoryService (has
 * translations) never hits this; a row created by a seeder that bypasses
 * those services and writes the base column directly (no translations)
 * serializes name=null to every API response despite `$product->name`
 * itself resolving correctly — see backend/app/Models/Product.php's
 * com-tam seeding bug and Branch's 新宿店 pull-DOWN regression (workstation
 * printed "Store" because BranchController's JSON carried name=null).
 */
trait PreservesTranslatableColumns
{
    /**
     * Locale priority used to derive the base-column value when the caller
     * did not pass a flat top-level string for a translatable attribute.
     *
     * @var array<int, string>
     */
    protected static array $translatableDefaultLocalePriority = ['ja', 'en', 'vi'];

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): static
    {
        $preserved = [];

        foreach ($this->translatedAttributes ?? [] as $attr) {
            $flat = $attributes[$attr] ?? null;
            if (is_string($flat) && trim($flat) !== '') {
                $preserved[$attr] = $flat;

                continue;
            }

            foreach (static::$translatableDefaultLocalePriority as $locale) {
                $nested = $attributes[$locale][$attr] ?? null;
                if (is_string($nested) && trim($nested) !== '') {
                    $preserved[$attr] = $nested;
                    break;
                }
            }
        }

        parent::fill($attributes);

        foreach ($preserved as $attr => $value) {
            $this->attributes[$attr] = $value;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $array = parent::attributesToArray();

        foreach ($this->translatedAttributes ?? [] as $attr) {
            if (($array[$attr] ?? null) !== null) {
                continue;
            }
            $base = $this->getRawOriginal($attr);
            if (is_string($base) && trim($base) !== '') {
                $array[$attr] = $base;
            }
        }

        return $array;
    }
}
