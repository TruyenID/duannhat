<?php

/**
 * Customer Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Contracts\Notifiable as InboxRecipient;
use App\Models\Concerns\ReceivesNotifications;
use App\Notifications\Customer\VerifyCustomerEmail;
use App\Omnify\Modules\Customer\Models\CustomerBaseModel;
use App\Services\Customer\EmailVerificationCodeService;
use Database\Factories\CustomerFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Customer — domain record + self-service auth (merged from customer_accounts).
 *
 * Extends CustomerBaseModel (Omnify-generated) and layers on Laravel auth
 * via traits so the Sanctum `customer` guard can authenticate against this model.
 */
class Customer extends CustomerBaseModel implements AuthenticatableContract, CanResetPasswordContract, InboxRecipient, MustVerifyEmailContract
{
    // #1783 — `CanResetPassword` cho kho token đặt lại mật khẩu của Laravel.
    // Trait chỉ cấp `getEmailForPasswordReset()`; việc TRA hàng nào ứng với một
    // địa chỉ thì `CustomerAuthService` tự làm, vì `customers` dùng chung
    // đa-tenant và `email` KHÔNG unique (xem chú thích trong `login()`).
    use AuthenticatableTrait, CanResetPasswordTrait, HasApiTokens, HasFactory, MustVerifyEmailTrait, Notifiable;

    // plan-008 inbox relations — override Laravel `Notifiable` trait's
    // `unreadNotifications` so $customer->notificationInbox() /
    // ->unreadNotifications() point at OUR notification_recipients table
    // (recall-affected-customer notifications land here).
    use ReceivesNotifications {
        ReceivesNotifications::unreadNotifications insteadof Notifiable;
    }

    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    /**
     * Append auth-related columns to the Omnify-generated $fillable list.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->fillable = array_merge($this->fillable, [
            'password',
        ]);

        $this->hidden = array_merge($this->hidden, [
            'password',
            'remember_token',
        ]);
    }

    /**
     * Casts do Omnify sinh (`birthday` => date, `gender` => enum,
     * `email_verified_at` => datetime) nằm ở base model, nên override ở đây
     * PHẢI trải `parent::casts()` — trả về mảng trần sẽ nuốt sạch chúng và
     * mọi cột mới thêm về sau sẽ lặng lẽ ra kiểu string.
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'password' => 'hashed',
        ];
    }

    /**
     * Full display name: "First Last" or just "First" when last_name is absent.
     */
    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: fn () => trim("{$this->first_name} ".($this->last_name ?? '')),
        );
    }

    /**
     * Short name accessor (used by auth responses).
     */
    public function getNameAttribute(): string
    {
        return $this->first_name;
    }

    /**
     * Inbox rows addressed to this customer (#1561 — declared here, not in
     * `ReceivesNotifications`, so SharedKernel never names a Notifications
     * model).
     */
    public function notificationInbox(): MorphMany
    {
        return $this->morphMany(NotificationRecipient::class, 'recipient');
    }

    /**
     * #1680 — thư xác nhận của KHÁCH đi bằng bản dịch được, không phải bản
     * tiếng Anh cứng của framework. `MustVerifyEmail` gọi hàm này ở cả hai
     * đường: sự kiện `Registered` lúc đăng ký và endpoint gửi lại.
     *
     * Mã được PHÁT ở đây chứ không ở trong notification: framework gọi hàm này
     * không tham số, nên đây là chỗ duy nhất cả hai đường cùng đi qua. Phát mã
     * bên trong `toMail()` thì mỗi kênh gửi lại sinh một mã khác — và `issue()`
     * ghi đè mã cũ, nên mã in ra trong thư sẽ không phải mã đang được lưu.
     */
    public function sendEmailVerificationNotification(): void
    {
        $codes = app(EmailVerificationCodeService::class);

        $this->notify(new VerifyCustomerEmail($codes->issue($this), $codes->ttlMinutes()));
    }
}
