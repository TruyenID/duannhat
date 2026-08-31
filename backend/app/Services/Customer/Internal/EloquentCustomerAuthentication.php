<?php

declare(strict_types=1);

namespace App\Services\Customer\Internal;

use App\Models\Customer;
use App\Services\Customer\Commands\LoginCustomerCommand;
use App\Services\Customer\Contracts\CustomerAuthenticationPort;
use App\Services\Customer\Results\AuthenticatedCustomerEvidence;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Xác thực thông tin đăng nhập của TÀI KHOẢN TOÀN CỤC (#1550).
 *
 * Docblock của cổng nói rõ ràng buộc: *"Validates the global account credential
 * without mutating Customer or token storage."* — nó KHÔNG cấp token, không ghi
 * gì. Việc cấp token chạy sau, dưới thẩm quyền vừa chứng minh được.
 *
 * ## Thông điệp lỗi CỐ Ý không phân biệt "sai email" với "sai mật khẩu"
 *
 * Phân biệt hai ca đó biến màn đăng nhập thành máy dò tài khoản: kẻ tấn công
 * gửi một danh sách email và đọc thông điệp để biết địa chỉ nào có tài khoản.
 *
 * ## Vẫn băm mật khẩu khi không có tài khoản
 *
 * Thoát sớm làm thời gian phản hồi của "email không tồn tại" ngắn hơn hẳn "sai
 * mật khẩu", và chênh lệch đó đọc được từ ngoài — cùng một phép dò, chỉ bằng
 * đồng hồ thay vì bằng chữ.
 */
final class EloquentCustomerAuthentication implements CustomerAuthenticationPort
{
    public function authenticate(LoginCustomerCommand $command): AuthenticatedCustomerEvidence
    {
        $account = Customer::query()
            ->whereNull('organization_id')
            ->whereNotNull('password')
            ->where('email', $command->email)
            ->first();

        $hash = $account?->password ?? '$2y$12$'.str_repeat('x', 53);
        $ok = Hash::check($command->password->reveal(), $hash);

        if ($account === null || ! $ok) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return new AuthenticatedCustomerEvidence(
            (string) $account->id,
            'login-'.Str::uuid()->toString(),
        );
    }
}
