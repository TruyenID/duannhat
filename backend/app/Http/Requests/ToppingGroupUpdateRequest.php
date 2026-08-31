<?php

namespace App\Http\Requests;

use App\Models\ToppingGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ToppingGroupUpdateRequest extends FormRequest
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
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'price_strategy' => ['sometimes', 'nullable', 'string', 'in:flat,free_up_to_n'],
            'free_quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'selection_type' => ['sometimes', 'nullable', 'string', 'in:single,multiple'],
            'modifier_type' => ['sometimes', 'nullable', 'string', 'in:add,remove'],
            'min_select' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_select' => ['sometimes', 'nullable', 'integer'],
            'max_qty_per_item' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'sort_order' => ['sometimes', 'nullable', 'integer'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
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
            /** @var ToppingGroup|null $group */
            $group = $this->route('group');

            // Resolve effective values (patch merges with existing).
            $maxSelect = $this->has('max_select') ? $this->input('max_select') : $group?->max_select;
            $minSelect = $this->has('min_select') ? $this->input('min_select') : $group?->min_select;
            $selectionType = $this->has('selection_type')
                ? $this->input('selection_type')
                : ($group?->selection_type?->value ?? null);
            $modifierType = $this->has('modifier_type')
                ? $this->input('modifier_type')
                : ($group?->modifier_type?->value ?? null);
            $priceStrategy = $this->has('price_strategy')
                ? $this->input('price_strategy')
                : ($group?->price_strategy?->value ?? 'flat');
            $freeQuantity = $this->has('free_quantity')
                ? $this->input('free_quantity')
                : $group?->free_quantity;

            // max_select >= min_select khi cả hai non-null.
            if ($maxSelect !== null && $minSelect !== null && (int) $maxSelect < (int) $minSelect) {
                $v->errors()->add('max_select', 'max_select must be greater than or equal to min_select.');
            }

            // selection_type=single → max_select phải là 1.
            if ($selectionType === 'single' && $maxSelect !== null && (int) $maxSelect !== 1) {
                $v->errors()->add('max_select', 'max_select must be 1 when selection_type is "single".');
            }

            // REMOVE groups cannot enforce a minimum selection.
            if ($modifierType === 'remove' && (int) ($minSelect ?? 0) > 0) {
                $v->errors()->add('min_select', 'REMOVE groups cannot require a minimum selection (min_select must be 0).');
            }

            // free_up_to_n yêu cầu free_quantity non-null.
            if ($priceStrategy === 'free_up_to_n' && empty($freeQuantity)) {
                $v->errors()->add('free_quantity', 'free_quantity is required when price_strategy is "free_up_to_n".');
            }

            // Nếu patch có name field, ít nhất 1 locale phải non-empty.
            if ($this->has('name') || $this->hasAny(['ja', 'en', 'vi'])) {
                $flat = trim((string) $this->input('name', ''));
                $ja = trim((string) $this->input('ja.name', ''));
                $en = trim((string) $this->input('en.name', ''));
                $vi = trim((string) $this->input('vi.name', ''));

                if ($flat === '' && $ja === '' && $en === '' && $vi === '') {
                    $v->errors()->add('ja.name', 'Name is required in at least one language (ja, en, or vi).');
                }
            }
        });
    }
}
