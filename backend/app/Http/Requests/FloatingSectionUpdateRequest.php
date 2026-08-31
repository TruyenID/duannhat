<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class FloatingSectionUpdateRequest extends FormRequest
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
        // Same translatable-name shape as the store request (Product standard).
        // Update may touch only is_active/priority, so name stays optional; the
        // after() guard only fires when the request actually sends a name block
        // and leaves every language blank.
        $rules = [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
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
                // Only guard when the client actually sent a name/locale block —
                // an is_active-only update must not be forced to carry a name.
                $sentName = $this->has('name') || $this->has('ja') || $this->has('en') || $this->has('vi');
                if (! $sentName) {
                    return;
                }
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
