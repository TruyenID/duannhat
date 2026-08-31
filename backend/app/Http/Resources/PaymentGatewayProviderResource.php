<?php

/**
 * PaymentGatewayProvider Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\PaymentGatewayProvider\Resources\PaymentGatewayProviderResourceBase;
use BackedEnum;
use Illuminate\Http\Request;

/**
 * PaymentGatewayProviderResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class PaymentGatewayProviderResource extends PaymentGatewayProviderResourceBase
{
    /**
     * A provider name is a LABEL, never null.
     *
     * `translatable.use_fallback` is false application-wide, so a translatable
     * attribute returns null the moment the requested locale has no row — and
     * the seeded provider translations are patchy in exactly that way:
     * `paypay` has only `en`, `stripe` has only `vi`. Asking for the connection
     * list with `Accept-Language: vi` therefore returned `provider.name = null`
     * for PayPay, and the admin table rendered an empty cell wrapped in an empty
     * link — unclickable, unidentifiable (#F7). Asking with `ja` would have done
     * the same to Stripe.
     *
     * Rather than flipping the global fallback (which would change every
     * translatable model in the app at once), resolution is pinned here, where
     * the value is rendered: requested locale → configured fallback → any
     * translation the row actually has → the provider code. That last step is
     * what makes it total — `code` is NOT NULL, so this cannot return null even
     * for a provider carrying no translation rows at all.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'name' => $this->resolveTranslatable('name') ?? $this->providerCode(),
            'description' => $this->resolveTranslatable('description'),
        ]);
    }

    /** `code` is cast to an enum, so a bare (string) cast throws — same trap as `Device::type`. */
    private function providerCode(): string
    {
        $code = $this->resource->code;

        return $code instanceof BackedEnum ? (string) $code->value : (string) $code;
    }

    /** First non-empty value for `$attribute` across requested → fallback → any locale. */
    private function resolveTranslatable(string $attribute): ?string
    {
        $direct = $this->{$attribute};
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        $candidates = array_filter(
            [app()->getLocale(), config('translatable.fallback_locale'), config('app.fallback_locale')],
            static fn (mixed $locale): bool => is_string($locale) && $locale !== '',
        );

        foreach ($candidates as $locale) {
            $value = $this->resource->translate($locale)?->{$attribute};
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        foreach ($this->resource->translations as $translation) {
            $value = $translation->{$attribute};
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
