<?php

/**
 * TaxType Update Request
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Requests;

use App\Omnify\Enums\TaxClassificationEnum;
use App\Omnify\Modules\TaxType\Requests\TaxTypeUpdateRequestBase;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * TaxTypeUpdateRequest — add project-specific authorization and validation here.
 *
 * Inherited from base:
 *   - authorize(): bool  (returns true — override for auth checks)
 *   - rules(): array     (returns schemaRules() — override to add custom rules)
 *   - attributes(): array (returns schemaAttributes() — override to rename fields)
 */
class TaxTypeUpdateRequest extends TaxTypeUpdateRequestBase
{
    public function rules(): array
    {
        $rules = $this->schemaRules();

        // organization_id and brand_id are resolved server-side — never accept from client.
        unset($rules['organization_id'], $rules['brand_id']);

        // code is immutable after creation — reject explicitly so the client
        // gets a clear error (matches ProductTypeUpdateRequest).
        $rules['code'] = ['prohibited'];

        // All fields optional on update.
        $rules['name'] = ['sometimes', 'string', 'max:100'];
        $rules['rate'] = ['sometimes', 'numeric', 'between:0,100', 'decimal:0,2'];
        $rules['is_default'] = ['sometimes', 'boolean'];
        $rules['is_active'] = ['sometimes', 'boolean'];

        // 'sometimes' prevents validated() from including these as null when
        // a locale is absent from the request — avoids Astrotomic inserting a
        // translation row with name=null (NOT NULL constraint violation).
        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:100'];
        }

        // #1367 — trục phân loại. Generator phát `['nullable','string']` — quá
        // lỏng: nó nhận cả `'khong_chiu_thue'` gõ tay, mà cả điểm của trục này
        // là thoát khỏi chuỗi tự do. `Rule::enum` ràng vào đúng bốn giá trị.
        //
        // Vẫn NULLABLE: dòng cũ `rate = 0` không suy được là 非課税/不課税/免税,
        // chỉ người vận hành từng brand mới biết. NULL = CHƯA PHÂN LOẠI.
        $rules['classification'] = ['nullable', Rule::enum(TaxClassificationEnum::class)];

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (! $this->hasAny(['ja', 'en', 'vi'])) {
                return;
            }
            // plan-043 BUG-4 — coerce non-scalars to '' so a mis-shaped locale
            // payload degrades to 422 instead of a 500 "Array to string" cast.
            $ja = $this->scalarInput('ja.name');
            $en = $this->scalarInput('en.name');
            $vi = $this->scalarInput('vi.name');
            if ($ja === '' && $en === '' && $vi === '') {
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
