<?php

/**
 * Coupon Store Request — plan-019.
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Models\Brand;
use App\Omnify\Modules\Coupon\Requests\CouponStoreRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CouponStoreRequest extends CouponStoreRequestBase
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id + brand_id come from request attributes
        // (ResolveBrandFromSlug). They are NEVER accepted from the body.
        unset($rules['organization_id'], $rules['brand_id']);

        // times_used is server-managed; reject any attempt to set it.
        unset($rules['times_used']);

        $brandId = (string) request()->attributes->get('brand_id', '');

        // Code: required, uppercase A-Z 0-9 _- only, unique per brand+org.
        $rules['code'] = [
            'required', 'string', 'max:50',
            'regex:/^[A-Z0-9_\-]+$/',
            Rule::unique('coupons', 'code')
                ->where(fn ($q) => $q
                    ->where('brand_id', $brandId)
                    ->whereNull('deleted_at')),
        ];

        // Translatable name — top-level nullable, per-locale validated below.
        $rules['name'] = ['nullable', 'string', 'max:255'];
        $rules['description'] = ['nullable', 'string'];
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["{$locale}.description"] = ['sometimes', 'nullable', 'string'];
        }
        // Astrotomic-flat alias: allow both "name:ja" and "ja.name".
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules["name:{$locale}"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["description:{$locale}"] = ['sometimes', 'nullable', 'string'];
        }

        $rules['discount_type'] = ['required', Rule::in(['fixed', 'percent'])];
        $rules['discount_value'] = ['required', 'numeric', 'min:0.01'];
        $rules['max_discount_cap'] = ['nullable', 'numeric', 'min:0'];
        $rules['min_order_subtotal'] = ['nullable', 'numeric', 'min:0'];
        $rules['usage_limit_total'] = ['nullable', 'integer', 'min:1'];
        $rules['usage_limit_per_customer'] = ['nullable', 'integer', 'min:0'];
        $rules['valid_from'] = ['required', 'date'];
        $rules['valid_until'] = ['required', 'date', 'after:valid_from'];
        $rules['status'] = ['nullable', Rule::in(['draft', 'paused'])];

        // M2M whitelist of branches the coupon applies to.
        // Empty / absent = brand-wide.
        $rules['applicable_branch_ids'] = ['sometimes', 'array'];
        $rules['applicable_branch_ids.*'] = ['uuid', 'exists:branches,id'];

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                // Require name in at least one locale.
                $hasLocaleName =
                    filled($this->input('ja.name'))
                    || filled($this->input('en.name'))
                    || filled($this->input('vi.name'))
                    || filled($this->input('name:ja'))
                    || filled($this->input('name:en'))
                    || filled($this->input('name:vi'))
                    || filled($this->input('name'));
                if (! $hasLocaleName) {
                    $v->errors()->add('name', 'The name field is required in at least one language.');
                }

                // max_discount_cap only meaningful when discount_type = percent.
                if ($this->input('discount_type') === 'fixed' && filled($this->input('max_discount_cap'))) {
                    $v->errors()->add('max_discount_cap', 'max_discount_cap is only allowed when discount_type is percent.');
                }

                // For percent type, discount_value must be 0 < x <= 100.
                if ($this->input('discount_type') === 'percent') {
                    $value = (float) $this->input('discount_value', 0);
                    if ($value <= 0 || $value > 100) {
                        $v->errors()->add('discount_value', 'discount_value must be > 0 and ≤ 100 when discount_type is percent.');
                    }
                }

                // applicable_branch_ids must all belong to the same brand.
                // The `branches` table has `console_brand_id` (FK to SSO
                // console), not `brand_id` — the middleware sets the LOCAL
                // brand UUID under `brand_id`, so we have to resolve the
                // brand's console_brand_id first to compare against the row.
                $brand = request()->attributes->get('brand');
                $consoleBrandId = $brand?->console_brand_id
                    ?? Brand::whereKey(request()->attributes->get('brand_id'))
                        ->value('console_brand_id');
                $branchIds = $this->input('applicable_branch_ids', []);
                if ($consoleBrandId && is_array($branchIds) && $branchIds !== []) {
                    $countOutside = \DB::table('branches')
                        ->whereIn('id', $branchIds)
                        ->where(function ($q) use ($consoleBrandId) {
                            $q->where('console_brand_id', '!=', $consoleBrandId)
                                ->orWhereNull('console_brand_id');
                        })
                        ->count();
                    if ($countOutside > 0) {
                        $v->errors()->add('applicable_branch_ids', 'Some branch IDs do not belong to this brand.');
                    }
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        // Match the design: coupon codes are uppercase by convention.
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper((string) $this->input('code'))]);
        }
    }
}
