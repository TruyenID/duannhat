<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CustomerResendVerificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Chỉ kiểm dạng địa chỉ, KHÔNG kiểm `exists:customers,email` (#1680):
     * một lỗi 422 "email không tồn tại" trên endpoint công khai này chính là
     * câu trả lời phân biệt được ai đã đăng ký — đúng thứ mà controller cố ý
     * không nói. Địa chỉ không có tài khoản thì service lặng lẽ không gửi gì.
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
