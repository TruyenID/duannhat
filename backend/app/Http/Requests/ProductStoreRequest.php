<?php

/**
 * Product Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Http\Controllers\Traits\HasOrganizationContext;
use App\Models\ProductType;
use App\Omnify\Modules\Product\Requests\ProductStoreRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation rules for `POST /hq/{brandSlug}/products`.
 *
 * The brand (organization) is resolved upstream by the
 * `ResolveBrandFromSlug` middleware and exposed as `brand` on the request.
 */
class ProductStoreRequest extends ProductStoreRequestBase
{
    use HasOrganizationContext;

    public function rules(): array
    {
        $rules = $this->schemaRules();

        // Remove fields the client may not set; the controller / service
        // injects organization_id and the workflow audit fields.
        // Review aggregate columns are maintained by ProductReviewService::submit()
        // when customers post reviews — clients never set these on create.
        unset(
            $rules['organization_id'],
            $rules['created_by_id'],
            $rules['approved_by_id'],
            $rules['approved_at'],
            $rules['rejected_by_id'],
            $rules['rejected_at'],
            $rules['rejection_reason'],
            $rules['review_up_count'],
            $rules['review_total_count'],
            $rules['review_rating_sum'],
        );

        $organizationId = $this->getOrganizationId();

        // name is the top-level mirror (ja→en→vi priority). The locale keys
        // carry the source of truth; at least one must be non-empty (enforced
        // in after()). The flat field is nullable so FE can omit it safely.
        $rules['name'] = ['nullable', 'string', 'max:255'];

        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules["{$locale}.description"] = ['sometimes', 'nullable', 'string'];
        }
        $rules['description'] = ['nullable', 'string'];
        $rules['slug'] = [
            'nullable', 'string', 'max:191',
            Rule::unique('products', 'slug')
                ->where(fn ($q) => $q->where('organization_id', $organizationId))
                ->whereNull('deleted_at'),
        ];
        // brand_id is auto-injected by the controller from the resolved
        // {brandSlug}; clients never set it directly.
        unset($rules['brand_id']);
        $rules['product_type_id'] = [
            'required', 'string', 'uuid',
            Rule::exists('product_types', 'id')
                ->where(fn ($q) => $q->where('organization_id', $organizationId)),
        ];
        // New products must start as Draft and go through the approval
        // workflow (submit → pending → approve/reject → approved → activate).
        // See ProductService::submitForApproval / approve / reject / activate.
        $rules['status'] = ['nullable', 'string', Rule::in(['draft'])];
        $rules['is_hidden'] = ['nullable', 'boolean'];
        // FORBIDDEN) lives in after(); tax_type_id must belong to the same
        // brand and be active (only active types are assignable).
        $brandId = $this->attributes->get('brand_id');
        $rules['tax_type_id'] = [
            'nullable', 'uuid',
            Rule::exists('tax_types', 'id')
                ->where(fn ($q) => $q->where('organization_id', $organizationId)
                    ->where('brand_id', $brandId)
                    ->where('is_active', true)),
        ];
        $rules['category_ids'] = ['nullable', 'array'];
        $rules['category_ids.*'] = [
            'uuid',
            Rule::exists('categories', 'id')
                ->where(fn ($q) => $q->where('organization_id', $organizationId)),
        ];

        // -- Gallery images (staged temp uploads → permanent on product create) --
        // Client uploads images via POST /files/upload (returns temp File UUIDs),
        // then passes the ordered UUID list here. The service attaches them as
        // permanent files in the `gallery` collection, in the given order.
        $rules['gallery_file_ids'] = ['nullable', 'array', 'max:20'];
        $rules['gallery_file_ids.*'] = [
            'uuid',
            Rule::exists('files', 'id')
                ->where(fn ($q) => $q->where('organization_id', $organizationId)),
        ];

        // -- Nested options + skus (Shopify-style full create) -----------------
        // Both arrays are optional. When present, the controller / service
        // wraps the entire create in a DB transaction so the product, its
        // options, its option values, and its SKUs are all-or-nothing.

        $rules['options'] = ['nullable', 'array', 'max:3'];
        $rules['options.*.key'] = ['required_with:options', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/', 'distinct:strict'];
        $rules['options.*.name'] = ['required_with:options', 'string', 'max:120'];
        $rules['options.*.position'] = ['required_with:options', 'integer', Rule::in([1, 2, 3]), 'distinct:strict'];
        $rules['options.*.is_active'] = ['nullable', 'boolean'];
        $rules['options.*.values'] = ['required_with:options', 'array', 'min:1'];
        $rules['options.*.values.*.value'] = ['required', 'string', 'max:60', 'regex:/^[a-z0-9_-]+$/'];
        $rules['options.*.values.*.label'] = ['required', 'string', 'max:120'];
        $rules['options.*.values.*.position'] = ['nullable', 'integer', 'min:1'];
        $rules['options.*.values.*.is_active'] = ['nullable', 'boolean'];

        // When `options` are sent, at least one SKU is required — otherwise
        // we'd end up with an "orphan" product that has options but no SKUs
        // (which would block approval anyway per ProductService::approve()).
        $rules['skus'] = ['nullable', 'array', 'required_with:options', 'min:1'];

        // value_indices is a flat array whose length equals the number of
        // options. Each element is the 0-based index into the corresponding
        // option's values array. Example for 2 options × 2 values each:
        //   options[0] = Size  with values [S, M]
        //   options[1] = Color with values [Red, Blue]
        //   skus[0].value_indices = [0, 0]  → S × Red
        //   skus[1].value_indices = [0, 1]  → S × Blue
        // The service resolves these into option_value1_id / option_value2_id /
        // option_value3_id after the values have been persisted.
        //
        // Only meaningful when `options` are sent (Shopify-style nested create).
        // Quick-create / single-SKU flows send `skus` without options and must
        // not be forced to provide it.
        $optionsCount = count((array) $this->input('options', []));
        $rules['skus.*.value_indices'] = [
            'required_with:options',
            'array',
            function (string $attribute, mixed $value, \Closure $fail) use ($optionsCount): void {
                if ($optionsCount === 0) {
                    return;
                }
                if (! is_array($value) || count($value) !== $optionsCount) {
                    $fail(sprintf(
                        'value_indices must have exactly %d element(s) to match the number of options.',
                        $optionsCount,
                    ));
                }
            },
        ];
        $rules['skus.*.value_indices.*'] = ['integer', 'min:0'];
        $rules['skus.*.sku'] = ['nullable', 'string', 'max:50'];
        $rules['skus.*.name'] = ['nullable', 'string', 'max:255'];
        // selling_price is the menu price operators actually enter (issue #875).
        // cost_price defaults to 0 and is auto-computed later from recipe/material
        // (is_cost_override=false), so the create form only ever sends a price
        // here. Without this rule `validated()` would strip it and the mirror in
        // ProductService::createSku would fall back to selling_price = cost_price.
        $rules['skus.*.selling_price'] = ['nullable', 'numeric', 'min:0'];
        $rules['skus.*.cost_price'] = ['nullable', 'numeric', 'min:0'];
        $rules['skus.*.is_cost_override'] = ['nullable', 'boolean'];
        $rules['skus.*.is_active'] = ['nullable', 'boolean'];

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $v) {
                $ja = trim((string) $this->input('ja.name', ''));
                $en = trim((string) $this->input('en.name', ''));
                $vi = trim((string) $this->input('vi.name', ''));
                $flat = trim((string) $this->input('name', ''));
                if ($flat === '' && $ja === '' && $en === '' && $vi === '') {
                    $v->errors()->add('ja.name', 'The product name is required in at least one language (ja, en, or vi).');
                }
            },
            function (Validator $v) {
                $typeId = $this->input('product_type_id');
                if (! $typeId || $v->errors()->has('product_type_id')) {
                    return;
                }
                $type = ProductType::find($typeId);
                if ($type && ! $type->is_active) {
                    $v->errors()->add('product_type_id', 'The selected product type is inactive.');
                }
            },
            function (Validator $v) {
                foreach ((array) $this->input('options', []) as $optionIndex => $option) {
                    $slugs = [];
                    $positions = [];
                    foreach ((array) ($option['values'] ?? []) as $valueIndex => $value) {
                        $slug = (string) ($value['value'] ?? '');
                        if ($slug !== '' && isset($slugs[$slug])) {
                            $v->errors()->add("options.{$optionIndex}.values.{$valueIndex}.value", 'Option value slugs must be unique within each option.');
                        }
                        $slugs[$slug] = true;

                        $position = $value['position'] ?? ($valueIndex + 1);
                        if (isset($positions[$position])) {
                            $v->errors()->add("options.{$optionIndex}.values.{$valueIndex}.position", 'Option value positions must be unique within each option.');
                        }
                        $positions[$position] = true;
                    }
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'product_type_id.required' => 'The product type field is required.',
            'product_type_id.exists' => 'The selected product type does not exist.',
            'slug.unique' => 'This slug is already in use.',
            'status.in' => 'Status must be draft on creation. Use the approval workflow to transition to active.',
            'category_ids.*.exists' => 'The selected category does not exist.',
            'options.max' => 'A product may have at most 3 options.',
            'options.*.key.required_with' => 'The option key is required.',
            'options.*.key.regex' => 'The option key may only contain lowercase letters, digits, and underscores.',
            'options.*.name.required_with' => 'The option name is required.',
            'options.*.position.in' => 'The option position must be 1, 2, or 3.',
            'options.*.values.required_with' => 'Each option must have at least one value.',
            'options.*.values.*.value.regex' => 'The option value slug may only contain lowercase letters, digits, underscores, and hyphens.',
            'skus.required_with' => 'At least one SKU is required when options are specified.',
            'skus.min' => 'At least one SKU is required.',
            'skus.*.value_indices.required_with' => 'value_indices is required for each SKU when options are present.',
            'skus.*.selling_price.min' => 'The selling price must be at least 0.',
            'skus.*.cost_price.min' => 'The cost price must be at least 0.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing([
            'status' => 'draft',
            'is_hidden' => false,
        ]);
    }
}
