<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class FloatingSectionStoreRequest extends FormRequest
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
        // Follows the Product API standard for translatable name:
        //  - top-level `name` is the NULLABLE mirror (ja→en→vi priority),
        //  - the ja/en/vi blocks carry the source of truth,
        //  - "at least one language" is enforced in after() (cross-field),
        //  - unknown 2-letter locale keys are prohibited so a typo can't
        //    silently drop a translation.
        $rules = [
            'name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'priority' => ['integer', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];

        foreach (['ja', 'en', 'vi'] as $locale) {
            $rules[$locale] = ['sometimes', 'nullable', 'array'];
            $rules["{$locale}.name"] = ['sometimes', 'nullable', 'string', 'max:255'];
        }
        foreach (array_keys($this->all()) as $key) {
            if (is_string($key) && preg_match('/\A[a-z]{2}(?:-[A-Z]{2})?\z/', $key) === 1 && ! in_array($key, ['ja', 'en', 'vi'], true)) {
                $rules[$key] = ['prohibited'];
            }
        }

        return $rules;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $v) {
                $ja = trim((string) $this->input('ja.name', ''));
                $en = trim((string) $this->input('en.name', ''));
                $vi = trim((string) $this->input('vi.name', ''));
                $flat = trim((string) $this->input('name', ''));
                if ($flat === '' && $ja === '' && $en === '' && $vi === '') {
                    $v->errors()->add('ja.name', 'The floating section name is required in at least one language (ja, en, or vi).');
                }
            },
        ];
    }
}
