<?php

namespace App\Notifications\Customer;

use App\Services\Customer\EmailVerificationCodeService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * Thư xác nhận email cho khách — MÃ 6 CHỮ SỐ, không còn link (#1680).
 *
 * Trước đây thư mang một link có chữ ký. Bấm link trong Gmail trên điện thoại
 * mở webview riêng của Gmail: khác origin, không có gì trong `localStorage`,
 * và khách bị bỏ lại ở một tab lạ trong khi tab đăng ký vẫn mở ở chỗ cũ. Mã
 * gõ tay thì khách ở nguyên nơi họ đang đứng — đó là lý do đổi, không phải
 * chuyện thẩm mỹ.
 *
 * KHÔNG còn kế thừa `Illuminate\Auth\Notifications\VerifyEmail`: lớp đó tồn
 * tại để dựng URL có chữ ký, và ở đây không có URL nào. Route link cũ
 * (`auth/verify/{id}/{hash}`) vẫn sống để những thư đã gửi trước lần deploy
 * này không chết giữa chừng, nhưng không thư mới nào trỏ vào đó nữa.
 *
 * Locale lấy từ locale đang hoạt động của request, do `SetLocale` phân giải từ
 * `Accept-Language` mà `apiFetch` của Customer Web đóng dấu lên mọi lời gọi.
 * Thư gửi ĐỒNG BỘ trong request đăng ký / gửi lại nên locale đó còn nguyên;
 * nếu sau này đẩy notification xuống queue thì phải bọc `Notification::locale()`
 * hoặc cho Customer implement `HasLocalePreference`, vì worker không mang theo
 * ngữ cảnh HTTP.
 */
class VerifyCustomerEmail extends Notification
{
    /**
     * @param  string  $code  Mã plaintext — chỉ tồn tại trên đường đi tới hộp
     *                        thư; thứ được lưu lại là bản băm của nó.
     */
    public function __construct(
        private readonly string $code,
        private readonly int $expiresInMinutes,
    ) {}

    /**
     * @param  mixed  $notifiable
     * @return list<string>
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            // Mã nằm luôn trong tiêu đề: trên điện thoại, dòng preview của
            // Gmail thường đủ để khách đọc mã mà không cần mở thư.
            ->subject(__('emails.verify_email.subject', ['code' => $this->code]))
            ->greeting(__('emails.verify_email.greeting', ['name' => (string) $notifiable->name]))
            ->line(__('emails.verify_email.intro'))
            // `HtmlString` để markdown không escape thẻ. Giãn chữ + cỡ lớn vì
            // đây là thứ duy nhất khách cần lấy ra khỏi email này; một dòng
            // `<p>` cỡ thường giữa các dòng khác thì phải đi tìm.
            ->line(new HtmlString(
                '<div style="margin:24px 0;text-align:center;font-size:34px;font-weight:700;letter-spacing:10px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;">'
                .e($this->code)
                .'</div>'
            ))
            ->line(__('emails.verify_email.expires', ['minutes' => $this->expiresInMinutes]))
            ->line(__('emails.verify_email.attempts', ['attempts' => EmailVerificationCodeService::MAX_ATTEMPTS]))
            ->line(__('emails.verify_email.outro'));
    }
}
