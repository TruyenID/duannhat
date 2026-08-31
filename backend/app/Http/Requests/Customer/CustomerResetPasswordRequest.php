<?php

namespace App\Http\Requests\Customer;

use App\Rules\StrongCustomerPassword;
use Illuminate\Foundation\Http\FormRequest;

class CustomerResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * #1783 — token + địa chỉ + mật khẩu mới.
     *
     * `email` KHÔNG kiểm `exists`: kho token của Laravel khoá theo địa chỉ, nên
     * địa chỉ sai sẽ tự trượt ở bước kiểm token và trả về CÙNG một thông điệp
     * với token sai. Tách hai trường hợp đó ra là để lộ ai đã đăng ký.
     *
     * `confirmed` bắt buộc có `password_confirmation`: gõ nhầm mật khẩu mới ở
     * luồng này không sửa lại được — token dùng một lần, khách phải xin thư mới.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            // Dùng ĐÚNG luật của đăng ký (`StrongCustomerPassword`: 10 ký tự,
            // hoa, chữ-và-số, ký tự đặc biệt), KHÔNG phải `Password::defaults()`.
            //
            // Bản đầu của file này dùng `defaults()` (tối thiểu 8) và đo được:
            // `abcd1234` qua endpoint đặt lại, HTTP 200. Tức luồng sinh ra để
            // CỨU một tài khoản lại là đường hạ cấp mật khẩu của chính nó — và
            // là đường mà kẻ vừa chiếm được hòm thư sẽ đi.
            'password' => ['required', 'string', new StrongCustomerPassword, 'confirmed'],
        ];
    }
}
