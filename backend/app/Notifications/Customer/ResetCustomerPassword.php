<?php

namespace App\Notifications\Customer;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

/**
 * #1783 — thư đặt lại mật khẩu cho khách, theo ngôn ngữ khách đang dùng.
 *
 * Cùng lý do với {@see VerifyCustomerEmail} (#1680): bản của framework in tiếng
 * Anh cứng, mà đây là thư đứng chắn giữa khách và tài khoản của họ.
 *
 * ## Link trỏ về CUSTOMER-WEB, không phải về API
 *
 * Khác thư xác nhận: xác nhận là một hành động một-bấm nên link đi thẳng vào
 * route có chữ ký của API. Đặt lại mật khẩu thì cần khách GÕ mật khẩu mới, nên
 * link phải mở ra một form — tức một trang của customer-web, mang theo token và
 * email trong query.
 *
 * `locale` và `shop` đi kèm vì lúc khách bấm link, request đến từ ứng dụng mail
 * và không mang theo ngữ cảnh nào của phiên trước đó (#1680 đã trả giá cho bài
 * học này ở thư xác nhận).
 *
 * ## Không có URL thì KHÔNG gửi thư
 *
 * `customer.web_url` để trống là hợp lệ trên máy dev. Nhưng một thư đặt lại mật
 * khẩu chứa link hỏng thì tệ hơn không gửi: khách bấm, không tới đâu, và tin
 * rằng hệ thống hỏng chứ không nghĩ là cấu hình thiếu. `CustomerAuthService`
 * chặn ở tầng trên; đây là lớp chặn thứ hai, sát chỗ dựng URL nhất.
 */
class ResetCustomerPassword extends ResetPassword
{
    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) Config::get('auth.passwords.customer_accounts.expire', 60);

        return (new MailMessage)
            ->subject(__('emails.reset_password.subject'))
            ->greeting(__('emails.reset_password.greeting', ['name' => (string) $notifiable->name]))
            ->line(__('emails.reset_password.intro'))
            ->action(__('emails.reset_password.action'), self::customerWebResetUrl($notifiable, $this->token))
            ->line(__('emails.reset_password.expires', ['minutes' => $minutes]))
            ->line(__('emails.reset_password.outro'));
    }

    /**
     * URL của form đặt lại trên customer-web.
     *
     * Tên KHÔNG phải `resetUrl` dù đó là tên tự nhiên nhất: lớp cha
     * `Illuminate\Auth\Notifications\ResetPassword` đã có `resetUrl()` không
     * static, và PHP từ chối biến nó thành static ở lớp con — lỗi lúc NẠP LỚP,
     * không phải lúc gọi.
     *
     * Static để test khẳng định được hình dạng link mà không phải dựng cả một
     * MailMessage.
     */
    public static function customerWebResetUrl(mixed $notifiable, string $token): string
    {
        $base = (string) Config::get('customer.web_url', '');
        if ($base === '') {
            throw new \RuntimeException('customer.web_url chưa cấu hình — không dựng được link đặt lại mật khẩu.');
        }

        $query = [
            'token' => $token,
            // Email đi kèm vì kho token của Laravel khoá theo email: form phải
            // gửi lại đúng địa chỉ đó thì mới tra được token. KHÔNG phải là
            // chỗ để tin — bước xác minh vẫn so token với hàng trong DB.
            'email' => (string) $notifiable->getEmailForPasswordReset(),
            'locale' => App::getLocale(),
        ];

        $shopSlug = $notifiable->branch?->slug;
        if ($shopSlug !== null && $shopSlug !== '') {
            $query['shop'] = $shopSlug;
        }

        return $base.'/'.$query['locale'].'/reset-password?'.http_build_query($query);
    }
}
