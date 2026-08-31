<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ToppingGroupStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['nullable', 'string', 'max:255'],
            'price_strategy' => ['nullable', 'string', 'in:flat,free_up_to_n'],
            'free_quantity' => ['nullable', 'integer', 'min:1'],
            'selection_type' => ['nullable', 'string', 'in:single,multiple'],
            'modifier_type' => ['nullable', 'string', 'in:add,remove'],
            'min_select' => ['required', 'integer', 'min:0'],
            'max_select' => ['nullable', 'integer'],
            'max_qty_per_item' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ];

        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $selectionType = $this->input('selection_type');
            $maxSelect = $this->input('max_select');
            $minSelect = $this->input('min_select');

            // max_select >= min_select khi cả hai non-null.
            if ($maxSelect !== null && $minSelect !== null && (int) $maxSelect < (int) $minSelect) {
                $v->errors()->add('max_select', 'max_select must be greater than or equal to min_select.');
            }

            // selection_type=single → max_select phải là 1.
            if ($selectionType === 'single' && $maxSelect !== null && (int) $maxSelect !== 1) {
                $v->errors()->add('max_select', 'max_select must be 1 when selection_type is "single".');
            }

            // modifier_type=remove → selection chỉ có nghĩa là "bỏ ra", max_select=null hoặc ≥1 OK,
            // nhưng min_select phải = 0 (không thể ép khách phải bỏ topping).
            if ($this->input('modifier_type') === 'remove' && (int) ($minSelect ?? 0) > 0) {
                $v->errors()->add('min_select', 'REMOVE groups cannot require a minimum selection (min_select must be 0).');
            }

            // free_up_to_n yêu cầu free_quantity non-null.
            $priceStrategy = $this->input('price_strategy', 'flat');
            if ($priceStrategy === 'free_up_to_n' && empty($this->input('free_quantity'))) {
                $v->errors()->add('free_quantity', 'free_quantity is required when price_strategy is "free_up_to_n".');
            }

            $ja = trim((string) $this->input('ja.name', ''));
            $en = trim((string) $this->input('en.name', ''));
            $vi = trim((string) $this->input('vi.name', ''));

            if ($ja === '' && $en === '' && $vi === '') {
                $v->errors()->add('ja.name', 'Name is required in at least one language (ja, en, or vi).');
            }
        });
    }
}
