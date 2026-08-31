<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CustomerForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * #1783 — chỉ kiểm DẠNG địa chỉ, KHÔNG `exists:customers,email`.
     *
     * Cùng lý do với `CustomerResendVerificationRequest` (#1680): một lỗi 422
     * "email không tồn tại" trên endpoint công khai chính là câu trả lời phân
     * biệt được ai đã đăng ký — đúng thứ controller cố ý không nói.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
        ];
    }
}
