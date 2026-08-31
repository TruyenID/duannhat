<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * #1680 — mật khẩu đúng, nhưng địa chỉ email chưa được xác nhận nên chưa phát
 * token.
 *
 * Tách khỏi `auth.failed` (422) một cách CỐ Ý: hai lỗi này đòi hai hành động
 * khác nhau ở phía khách. "Sai mật khẩu" thì gõ lại; "chưa xác nhận" thì phải
 * đi mở hộp thư — và nếu thư lạc thì phải có đường gửi lại. Gộp cả hai vào một
 * thông báo là dồn khách vào ngõ cụt: gõ đúng mật khẩu bao nhiêu lần cũng vẫn
 * "sai".
 *
 * Trả kèm `email` KHÔNG lộ gì thêm: tới được nhánh này nghĩa là người gọi vừa
 * đưa đúng cặp email + mật khẩu của chính tài khoản đó. Client cần nó để gọi
 * `POST /auth/email/resend` mà không bắt khách gõ lại địa chỉ.
 */
class EmailNotVerifiedException extends \RuntimeException
{
    public function __construct(
        private readonly string $email,
    ) {
        parent::__construct('Email address has not been verified yet.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'email_not_verified',
            'email' => $this->email,
        ], 403);
    }
}
