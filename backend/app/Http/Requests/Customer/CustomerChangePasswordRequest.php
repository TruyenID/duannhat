<?php

namespace App\Http\Requests\Customer;

use App\Rules\StrongCustomerPassword;
use Illuminate\Foundation\Http\FormRequest;

class CustomerChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:customer'],
            // #1780 — cùng một chính sách với lúc đăng ký. Nếu chỉ siết ở đăng
            // ký thì khách vẫn hạ được mật khẩu của mình xuống 8 ký tự ngay sau
            // đó, tức luật mới chỉ tồn tại trong đúng một màn hình.
            // Mật khẩu ĐANG dùng không bị đụng tới — chỉ mật khẩu mới phải đạt.
            'password' => ['required', 'string', new StrongCustomerPassword, 'confirmed', 'different:current_password'],
            'logout_other_devices' => ['nullable', 'boolean'],
        ];
    }
}
