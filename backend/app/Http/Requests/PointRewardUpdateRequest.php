<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * #1514 — cập nhật một phần thưởng đổi điểm.
 *
 * Cập nhật TỪNG PHẦN: không trường nào `required`, thiếu là giữ nguyên. Đặc
 * biệt `image_file_id` — vắng mặt nghĩa là "đừng đụng ảnh", gửi `null` nghĩa
 * là "gỡ ảnh"; service phân biệt bằng `array_key_exists`.
 */
class PointRewardUpdateRequest extends FormRequest
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
            'ja' => ['sometimes', 'array'],
            'ja.name' => ['nullable', 'string', 'max:255'],
            'ja.description' => ['nullable', 'string'],
            'en' => ['sometimes', 'array'],
            'en.name' => ['nullable', 'string', 'max:255'],
            'en.description' => ['nullable', 'string'],
            'vi' => ['sometimes', 'array'],
            'vi.name' => ['nullable', 'string', 'max:255'],
            'vi.description' => ['nullable', 'string'],

            'cost_points' => ['sometimes', 'integer', 'min:1'],
            'discount_type' => ['sometimes', 'in:fixed,percent'],
            'discount_value' => ['sometimes', 'numeric', 'min:0.01'],
            'max_discount_cap' => ['nullable', 'numeric', 'min:0'],
            'min_order_subtotal' => ['sometimes', 'numeric', 'min:0'],
            'valid_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'service_condition' => ['sometimes', 'in:dine_in,takeaway,both'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'image_file_id' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Chỉ kiểm khi request CÓ gửi `discount_type`. Không gửi thì loại
            // giảm giá hiện tại vẫn đúng, và service tự dọn cap khi cần.
            if ($this->input('discount_type') === 'fixed' && filled($this->input('max_discount_cap'))) {
                $v->errors()->add('max_discount_cap', 'A cap only applies to percent discounts.');
            }

            if ($this->input('discount_type') === 'percent' && (float) $this->input('discount_value') > 100) {
                $v->errors()->add('discount_value', 'A percent discount cannot exceed 100.');
            }
        });
    }
}
