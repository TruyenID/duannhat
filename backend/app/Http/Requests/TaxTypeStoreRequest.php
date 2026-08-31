<?php

/**
 * TaxType Store Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Enums\TaxClassificationEnum;
use App\Omnify\Modules\TaxType\Requests\TaxTypeStoreRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * TaxTypeStoreRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class TaxTypeStoreRequest extends TaxTypeStoreRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id and brand_id are resolved server-side:
        // - organization_id from the authenticated user (HasOrganizationContext)
        // - brand_id from the {brandSlug} route param (ResolveBrandFromSlug
        //   middleware writes it to $request->attributes)
        // The client must NOT send these.
        unset($rules['organization_id'], $rules['brand_id']);

        // code is required, unique per brand (422 on duplicate; same code on
        // another brand is allowed — plan-043 §2 / ProductType idiom).
        $brandId = $this->attributes->get('brand_id');
        $rules['code'] = [
            'required', 'string', 'max:50',
            Rule::unique('tax_types', 'code')
                ->where(fn ($q) => $q->where('brand_id', $brandId)),
        ];

        // name is now derived from translations (ja→en→vi); top-level mirror is
        // optional — translations carry the source of truth.
        $rules['name'] = ['nullable', 'string', 'max:100'];

        // 'sometimes' prevents validated() from including these as null when
        // a locale is absent from the request — avoids Astrotomic inserting a
        // translation row with name=null (NOT NULL constraint violation).
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:100'];
        }

        // Rates: required, 0–100, max 2 decimal places.
        $rules['rate'] = ['required', 'numeric', 'between:0,100', 'decimal:0,2'];

        // #1367 — trục phân loại. Generator phát `['nullable','string']` — quá
        // lỏng: nó nhận cả `'khong_chiu_thue'` gõ tay, mà cả điểm của trục này
        // là thoát khỏi chuỗi tự do. `Rule::enum` ràng vào đúng bốn giá trị.
        //
        // Vẫn NULLABLE: dòng cũ `rate = 0` không suy được là 非課税/不課税/免税,
        // chỉ người vận hành từng brand mới biết. NULL = CHƯA PHÂN LOẠI.
        $rules['classification'] = ['nullable', Rule::enum(TaxClassificationEnum::class)];

        // Flags: optional (defaults applied server-side in the service).
        $rules['is_default'] = ['nullable', 'boolean'];
        $rules['is_active'] = ['nullable', 'boolean'];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            // plan-043 BUG-4 — a client that mis-sends `name` as an object
            // (e.g. {"name":{"ja":"x"}}) blew up the `(string)` cast with an
            // "Array to string conversion" 500. Coerce non-scalars to '' so the
            // request degrades to a normal 422 (the string rules on
            // `name`/`*.name` already reject the bad shape).
            $ja = $this->scalarInput('ja.name');
            $en = $this->scalarInput('en.name');
            $vi = $this->scalarInput('vi.name');
            $flat = $this->scalarInput('name');
            if ($ja === '' && $en === '' && $vi === '' && $flat === '') {
                $v->errors()->add('ja.name', 'The name field is required in at least one language (ja, en, or vi).');
            }
        });
    }

    /** Trim a request input to string, treating any non-scalar (array/object) as empty. */
    private function scalarInput(string $key): string
    {
        $value = $this->input($key);

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
