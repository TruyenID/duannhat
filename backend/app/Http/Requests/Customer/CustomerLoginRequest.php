<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class CustomerLoginRequest extends FormRequest
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
            // #1782 — `identifier` là trường MỚI (email hoặc SĐT). `email` giữ
            // lại cho mọi client đang chạy: đổi tên trường là làm hỏng chúng
            // cùng một lúc, và bản customer-web mới thì deploy sau backend.
            //
            // `required_without` hai chiều ⇒ thiếu cả hai mới báo lỗi, gửi cả
            // hai cũng hợp lệ (service ưu tiên `identifier`).
            'identifier' => ['required_without:email', 'string', 'max:255'],
            'email' => ['required_without:identifier', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string'],
        ];
    }
}
