<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * #1514 — tạo một phần thưởng đổi điểm.
 *
 * Quyền do policy quyết định ở controller (`authorize('create', ...)`), nên
 * `authorize()` ở đây trả true — hai chỗ cùng gác một cửa thì sớm muộn sẽ
 * lệch nhau.
 */
class PointRewardStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Tên là bản dịch, không phải cột — bắt buộc ít nhất một ngôn ngữ,
            // kiểm ở `withValidator()`.
            'ja' => ['sometimes', 'array'],
            'ja.name' => ['nullable', 'string', 'max:255'],
            'ja.description' => ['nullable', 'string'],
            'en' => ['sometimes', 'array'],
            'en.name' => ['nullable', 'string', 'max:255'],
            'en.description' => ['nullable', 'string'],
            'vi' => ['sometimes', 'array'],
            'vi.name' => ['nullable', 'string', 'max:255'],
            'vi.description' => ['nullable', 'string'],

            // > 0: phần thưởng 0 điểm là hàng phát không giới hạn cho bất kỳ
            // ai đăng nhập — gần như luôn là gõ nhầm.
            'cost_points' => ['required', 'integer', 'min:1'],

            'discount_type' => ['required', 'in:fixed,percent'],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_discount_cap' => ['nullable', 'numeric', 'min:0'],
            'min_order_subtotal' => ['nullable', 'numeric', 'min:0'],
            'valid_days' => ['nullable', 'integer', 'min:1', 'max:3650'],

            // null = không giới hạn. 0 hợp lệ: "tạo trước, chưa mở bán".
            'stock_quantity' => ['nullable', 'integer', 'min:0'],

            'service_condition' => ['nullable', 'in:dine_in,takeaway,both'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'image_file_id' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $hasName = collect(['ja', 'en', 'vi'])
                ->contains(fn (string $l): bool => filled($this->input("{$l}.name")));

            if (! $hasName) {
                $v->errors()->add('ja.name', 'A name is required in at least one language.');
            }

            // BR-PR04 — trần giảm giá chỉ có nghĩa với percent. Từ chối thẳng
            // thay vì âm thầm bỏ qua: người nhập một con số rồi thấy nó biến
            // mất sẽ nhập lại lần nữa.
            if ($this->input('discount_type') === 'fixed' && filled($this->input('max_discount_cap'))) {
                $v->errors()->add('max_discount_cap', 'A cap only applies to percent discounts.');
            }

            if ($this->input('discount_type') === 'percent') {
                $value = (float) $this->input('discount_value');
                if ($value > 100) {
                    $v->errors()->add('discount_value', 'A percent discount cannot exceed 100.');
                }
            }
        });
    }
}
