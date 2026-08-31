<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CustomerVerifyEmailCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Cùng lý do với `CustomerResendVerificationRequest`: KHÔNG kiểm
     * `exists:customers,email`. Endpoint này công khai, và một lỗi 422 "email
     * không tồn tại" chính là câu trả lời phân biệt được ai đã đăng ký ở quán
     * này. Địa chỉ lạ đi tiếp và nhận đúng câu "mã không đúng" như một mã sai.
     *
     * Mã là CHUỖI 6 chữ số, không phải `integer`: `integer` sẽ nuốt số 0 đứng
     * đầu (`012345` → `12345`), tức mọi mã bắt đầu bằng 0 — khoảng 10% số mã —
     * sẽ trượt mà không ai hiểu tại sao.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }

    /**
     * Khách gõ mã vào 6 ô riêng và giao diện ghép lại; dán từ Gmail dễ kéo theo
     * khoảng trắng ở hai đầu. Cắt ở đây thay vì để `regex` từ chối một mã đúng.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => trim($this->input('code'))]);
        }
    }
}
