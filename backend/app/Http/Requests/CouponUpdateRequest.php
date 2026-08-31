<?php

/**
 * Coupon Update Request — plan-019.
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * Locked-fields enforcement (BR-COUP-LOCK) lives in CouponService::
 * assertNotLocked(), so the FE gets a structured 422
 * `coupon_field_locked` error instead of a generic validation message.
 * This request only handles the SHAPE of the payload + uniqueness +
 * cross-field invariants.
 */

namespace App\Http\Requests;

use App\Models\Brand;
use App\Omnify\Modules\Coupon\Requests\CouponUpdateRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CouponUpdateRequest extends CouponUpdateRequestBase
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset($rules['organization_id'], $rules['brand_id'], $rules['times_used']);

        $brandId = (string) request()->attributes->get('brand_id', '');
        $couponId = $this->route('coupon')?->id ?? $this->route('coupon');

        $rules['code'] = [
            'sometimes', 'required', 'string', 'max:50',
            'regex:/^[A-Z0-9_\-]+$/',
            Rule::unique('coupons', 'code')
                ->ignore($couponId)
                ->where(fn ($q) => $q
                    ->where('brand_id', $brandId)
                    ->whereNull('deleted_at')),
        ];

        $rules['name'] = ['nullable', 'string', 'max:255'];
        $rules['description'] = ['nullable', 'string'];
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["{$locale}.description"] = ['sometimes', 'nullable', 'string'];
            $rules["name:{$locale}"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["description:{$locale}"] = ['sometimes', 'nullable', 'string'];
        }

        $rules['discount_type'] = ['sometimes', 'required', Rule::in(['fixed', 'percent'])];
        $rules['discount_value'] = ['sometimes', 'required', 'numeric', 'min:0.01'];
        $rules['max_discount_cap'] = ['sometimes', 'nullable', 'numeric', 'min:0'];
        $rules['min_order_subtotal'] = ['sometimes', 'nullable', 'numeric', 'min:0'];
        $rules['usage_limit_total'] = ['sometimes', 'nullable', 'integer', 'min:1'];
        $rules['usage_limit_per_customer'] = ['sometimes', 'nullable', 'integer', 'min:0'];
        $rules['valid_from'] = ['sometimes', 'required', 'date'];
        $rules['valid_until'] = ['sometimes', 'required', 'date', 'after:valid_from'];
        $rules['status'] = ['sometimes', 'nullable', Rule::in(['draft', 'paused'])];

        $rules['applicable_branch_ids'] = ['sometimes', 'array'];
        $rules['applicable_branch_ids.*'] = ['uuid', 'exists:branches,id'];

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                if ($this->input('discount_type') === 'fixed' && filled($this->input('max_discount_cap'))) {
                    $v->errors()->add('max_discount_cap', 'max_discount_cap is only allowed when discount_type is percent.');
                }
                if ($this->input('discount_type') === 'percent' && $this->has('discount_value')) {
                    $value = (float) $this->input('discount_value', 0);
                    if ($value <= 0 || $value > 100) {
                        $v->errors()->add('discount_value', 'discount_value must be > 0 and ≤ 100 when discount_type is percent.');
                    }
                }

                // applicable_branch_ids must all belong to the same brand.
                // `branches` uses `console_brand_id` (FK to SSO console),
                // not `brand_id`. Resolve from the local Brand record set
                // by ResolveBrandFromSlug middleware.
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
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper((string) $this->input('code'))]);
        }
    }
}
