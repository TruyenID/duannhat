<?php

/**
 * Product Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\Product;
use App\Omnify\Modules\Product\Requests\ProductUpdateRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation rules for `PUT /hq/{brandSlug}/products/{product}`.
 *
 * Slug uniqueness is scoped per brand and excludes the current product.
 */
class ProductUpdateRequest extends ProductUpdateRequestBase
{
    use HasOrganizationContext;

    public function rules(): array
    {
        $rules = $this->schemaRules();

        unset(
            $rules['organization_id'],
            $rules['created_by_id'],
            $rules['approved_by_id'],
            $rules['approved_at'],
            $rules['rejected_by_id'],
            $rules['rejected_at'],
            $rules['rejection_reason'],
        );

        $organizationId = $this->getOrganizationId();
        $productId = $this->resolveProductId();

        $rules['name'] = ['sometimes', 'nullable', 'string', 'max:255'];
        $rules['description'] = ['sometimes', 'nullable', 'string'];

        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["{$locale}.description"] = ['sometimes', 'nullable', 'string'];
        }
        $rules['slug'] = [
            'sometimes', 'nullable', 'string', 'max:191',
            Rule::unique('products', 'slug')
                ->where(fn ($q) => $q->where('organization_id', $organizationId))
                ->ignore($productId)
                ->whereNull('deleted_at'),
        ];
        // brand_id stays where it is — never editable post-create.
        unset($rules['brand_id']);
        $rules['product_type_id'] = [
            'sometimes', 'uuid',
            Rule::exists('product_types', 'id')
                ->where(fn ($q) => $q->where('organization_id', $organizationId)),
        ];
        $rules['status'] = ['sometimes', 'string', Rule::in(['draft', 'active', 'inactive'])];
        // plan-043 FOUNDATION: validate the consumption-tax classification
        $rules['is_hidden'] = ['sometimes', 'boolean'];
        // plan-043 — tax_type_id must belong to the same brand and be active
        // × reduced-tax-type 422 cross-check lives in after().
        $brandId = $this->attributes->get('brand_id');
        $rules['tax_type_id'] = [
            'sometimes', 'nullable', 'uuid',
            Rule::exists('tax_types', 'id')
                ->where(fn ($q) => $q->where('organization_id', $organizationId)
                    ->where('brand_id', $brandId)
                    ->where('is_active', true)),
        ];
        $rules['category_ids'] = ['sometimes', 'nullable', 'array'];
        $rules['category_ids.*'] = [
            'uuid',
            Rule::exists('categories', 'id')
                ->where(fn ($q) => $q->where('organization_id', $organizationId)),
        ];

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                if (! $this->hasAny(['ja', 'en', 'vi'])) {
                    return;
                }
                $ja = trim((string) $this->input('ja.name', ''));
                $en = trim((string) $this->input('en.name', ''));
                $vi = trim((string) $this->input('vi.name', ''));
                if ($ja === '' && $en === '' && $vi === '') {
                    $v->errors()->add('ja.name', 'The product name is required in at least one language (ja, en, or vi).');
                }
            },
        ];
    }

    private function routeProduct(): ?Product
    {
        $product = $this->route('product');

        if ($product instanceof Product) {
            return $product;
        }

        return is_string($product) ? Product::find($product) : null;
    }

    public function messages(): array
    {
        return [
            'product_type_id.exists' => 'The selected product type does not exist.',
            'slug.unique' => 'This slug is already in use.',
            'status.in' => 'Status must be draft, active, or inactive.',
            'category_ids.*.exists' => 'The selected category does not exist.',
        ];
    }

    private function resolveProductId(): ?string
    {
        $product = $this->route('product');

        if (is_object($product)) {
            return $product->id ?? null;
        }

        return is_string($product) ? $product : null;
    }
}
