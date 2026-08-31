<?php

/**
 * MenuPromotion Store Request — plan-019, endpoint #B2.
 *
 * SAFE TO EDIT - Plan-019 only.
 */

namespace App\Http\Requests;

use App\Omnify\Modules\MenuPromotion\Requests\MenuPromotionStoreRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MenuPromotionStoreRequest extends MenuPromotionStoreRequestBase
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id + brand_id are derived from branch_id (which
        // itself comes from ResolveShopFromSlug → branch_id attribute).
        unset($rules['organization_id'], $rules['brand_id']);

        // branch_id comes from request attributes; do not accept from body.
        unset($rules['branch_id']);

        // Translatable name + description.
        $rules['name'] = ['nullable', 'string', 'max:255'];
        $rules['description'] = ['nullable', 'string'];
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["{$locale}.description"] = ['sometimes', 'nullable', 'string'];
            $rules["name:{$locale}"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["description:{$locale}"] = ['sometimes', 'nullable', 'string'];
        }

        $rules['discount_percent'] = ['required', 'numeric', 'between:0.01,100'];
        $rules['applies_to'] = ['required', Rule::in(['all_items', 'categories', 'products', 'mixed'])];
        $rules['daily_time_from'] = ['nullable', 'date_format:H:i,H:i:s'];
        $rules['daily_time_to'] = ['nullable', 'date_format:H:i,H:i:s', 'required_with:daily_time_from'];
        $rules['weekdays'] = ['nullable', 'array'];
        $rules['weekdays.*'] = ['integer', 'between:1,7'];
        $rules['valid_from'] = ['required', 'date'];
        $rules['valid_until'] = ['required', 'date', 'after:valid_from'];
        $rules['stacking_mode'] = ['nullable', Rule::in(['exclusive_with_coupons', 'stackable_with_coupons'])];
        $rules['is_active'] = ['nullable', 'boolean'];

        $rules['applicable_category_ids'] = ['sometimes', 'array'];
        $rules['applicable_category_ids.*'] = ['uuid', 'exists:categories,id'];
        $rules['applicable_product_ids'] = ['sometimes', 'array'];
        $rules['applicable_product_ids.*'] = ['uuid', 'exists:products,id'];

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
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

                // Scope coherence: applies_to must align with which
                // pivot arrays carry values.
                $appliesTo = $this->input('applies_to');
                $cats = $this->input('applicable_category_ids', []);
                $prods = $this->input('applicable_product_ids', []);

                if ($appliesTo === 'categories' && (! is_array($cats) || $cats === [])) {
                    $v->errors()->add('applicable_category_ids', 'applies_to=categories requires at least one category.');
                }
                if ($appliesTo === 'products' && (! is_array($prods) || $prods === [])) {
                    $v->errors()->add('applicable_product_ids', 'applies_to=products requires at least one product.');
                }
                if ($appliesTo === 'mixed' && ((! is_array($cats) || $cats === []) && (! is_array($prods) || $prods === []))) {
                    $v->errors()->add('applies_to', 'applies_to=mixed requires at least one category or product.');
                }

                // Brand coherence on category/product whitelists.
                $brandId = request()->attributes->get('brand_id');
                if ($brandId && is_array($cats) && $cats !== []) {
                    $outside = \DB::table('categories')
                        ->whereIn('id', $cats)
                        ->where(function ($q) use ($brandId) {
                            $q->where('brand_id', '!=', $brandId)->orWhereNull('brand_id');
                        })
                        ->count();
                    if ($outside > 0) {
                        $v->errors()->add('applicable_category_ids', 'Some categories are outside this brand.');
                    }
                }
                if ($brandId && is_array($prods) && $prods !== []) {
                    $outside = \DB::table('products')
                        ->whereIn('id', $prods)
                        ->where(function ($q) use ($brandId) {
                            $q->where('brand_id', '!=', $brandId)->orWhereNull('brand_id');
                        })
                        ->count();
                    if ($outside > 0) {
                        $v->errors()->add('applicable_product_ids', 'Some products are outside this brand.');
                    }
                }
            },
        ];
    }
}
