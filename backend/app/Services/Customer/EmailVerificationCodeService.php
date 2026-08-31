<?php

namespace App\Services\Customer;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;

/**
 * Mã xác nhận email 6 chữ số cho khách — thay cho link có chữ ký (#1680).
 *
 * Vì sao là mã chứ không phải link: khách đăng ký trên điện thoại ở trong quán,
 * bấm link trong Gmail sẽ MỞ MỘT TRÌNH DUYỆT KHÁC (webview của Gmail), tức là
 * một origin khác, không có gì trong `localStorage`, và khách bị bỏ lại ở một
 * tab lạ trong khi tab đăng ký vẫn đang mở ở tab cũ. Gõ 6 chữ số thì ở nguyên
 * chỗ đang đứng.
 *
 * Mã sống trong CACHE, không phải trong một cột mới:
 *
 * - `CACHE_STORE=database` ở repo này, nên nó vẫn bền qua restart — không phải
 *   trạng thái nằm trong RAM một tiến trình.
 * - Nó là dữ liệu CÓ HẠN theo đúng nghĩa (hết hạn thì phải biến mất). TTL của
 *   cache làm việc đó; một cột `code_expires_at` thì cần một cron dọn dẹp mà
 *   không ai viết, và mã hết hạn nằm lại trong bảng khách hàng mãi mãi.
 * - Mọi thay đổi schema ở repo này phải đi qua `schemas/*.yaml` + `omnify:gen`,
 *   và regen chạm ~650 file backend + một submodule. Thêm hai cột chỉ để giữ
 *   một chuỗi sống 10 phút không đáng cái giá đó.
 *
 * Mã được BĂM trước khi lưu (`Hash::make`), cùng lý do với mật khẩu: 6 chữ số
 * là không gian 10^6, nên một bản sao cache đọc được mà lưu plaintext là trao
 * thẳng quyền xác nhận email của người khác. Băm bcrypt cũng làm việc dò offline
 * trở nên vô nghĩa về mặt kinh tế cho một thứ chỉ sống 10 phút.
 *
 * Ba lớp chặn dò mã (10^6 là không gian nhỏ — phải chặn ở nhiều tầng):
 *
 * 1. Route throttle (`throttle:10,1`) — chặn theo IP.
 * 2. `MAX_ATTEMPTS` — 5 lần sai là mã CHẾT hẳn, phải xin mã mới. Chặn theo
 *    tài khoản, nên đổi IP cũng không lách được.
 * 3. TTL ngắn (10 phút) — thu hẹp cửa sổ tấn công.
 */
class EmailVerificationCodeService
{
    /** Số lần gõ sai tối đa trước khi mã bị huỷ. */
    public const MAX_ATTEMPTS = 5;

    /** Mã hợp lệ, đã dùng xong và đã bị xoá. */
    public const RESULT_OK = 'ok';

    /** Không có mã nào đang sống (chưa xin, đã hết hạn, hoặc đã dùng). */
    public const RESULT_EXPIRED = 'expired';

    /** Có mã đang sống nhưng khách gõ sai. */
    public const RESULT_INVALID = 'invalid';

    /** Sai quá `MAX_ATTEMPTS` lần — mã đã bị huỷ, phải xin mã mới. */
    public const RESULT_TOO_MANY_ATTEMPTS = 'too_many_attempts';

    /**
     * Phát một mã mới cho khách và trả về bản PLAINTEXT để gửi vào thư.
     *
     * Mã cũ (nếu còn) bị ghi đè: khách bấm "gửi lại" là đang nói mã cũ không
     * tới nơi, nên giữ nó sống thêm chỉ mở rộng cửa sổ dò mã mà không giúp ai.
     * Bộ đếm số lần sai cũng reset theo — nếu không thì 5 lần gõ nhầm sẽ khoá
     * chết tài khoản kể cả khi khách đã xin mã mới.
     */
    public function issue(Customer $customer): string
    {
        // `random_int` chứ không phải `rand`/`mt_rand`: đây là bí mật xác thực,
        // và một PRNG đoán được biến 10^6 khả năng thành gần như 1.
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $expiresAt = now()->addMinutes($this->ttlMinutes());

        Cache::put(
            $this->cacheKey($customer),
            [
                'hash' => Hash::make($code),
                'attempts' => 0,
                // Hạn nằm TRONG payload chứ không chỉ ở TTL của cache: sau mỗi
                // lần gõ sai phải ghi lại bản ghi, và `Cache::put` không có
                // kiểu "giữ nguyên hạn cũ". Hỏi driver còn bao lâu thì không
                // portable (`DatabaseStore` không có `ttl()`), nên tự mang theo.
                'expires_at' => $expiresAt->getTimestamp(),
            ],
            $expiresAt,
        );

        return $code;
    }

    /**
     * Đối chiếu mã khách gõ.
     *
     * Trả về một trong bốn hằng `RESULT_*` — KHÔNG phải bool: "chưa xin mã /
     * đã hết hạn" và "gõ sai" đòi hai hành động khác nhau ở phía khách (một
     * cái phải bấm gửi lại, một cái chỉ cần gõ lại), và gộp chúng thành `false`
     * là ép giao diện đoán.
     *
     * Không tự đóng dấu `email_verified_at` — việc ghi vào `customers` chỉ có
     * một người làm được (`CustomerService::verifyEmail`, khai trong
     * `domain-mutation-guard`), và service này cố tình không phải người đó.
     */
    public function verify(Customer $customer, string $code): string
    {
        $key = $this->cacheKey($customer);

        /** @var array{hash: string, attempts: int, expires_at: int}|null $entry */
        $entry = Cache::get($key);

        if ($entry === null) {
            return self::RESULT_EXPIRED;
        }

        if (Hash::check($code, $entry['hash'])) {
            // Xoá NGAY khi khớp: mã dùng một lần. Để lại thì cùng một mã còn
            // xác nhận được lần nữa trong suốt phần TTL còn lại.
            Cache::forget($key);

            return self::RESULT_OK;
        }

        $attempts = $entry['attempts'] + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::forget($key);

            return self::RESULT_TOO_MANY_ATTEMPTS;
        }

        // Ghi lại với hạn CŨ, không phải TTL đầy đủ — nếu không thì mỗi lần gõ
        // sai lại gia hạn mã, và một vòng lặp dò mã sẽ giữ nó sống mãi.
        Cache::put(
            $key,
            ['hash' => $entry['hash'], 'attempts' => $attempts, 'expires_at' => $entry['expires_at']],
            Carbon::createFromTimestamp($entry['expires_at']),
        );

        return self::RESULT_INVALID;
    }

    /** Huỷ mã đang sống (dùng sau khi email đã được xác nhận bằng đường khác). */
    public function invalidate(Customer $customer): void
    {
        Cache::forget($this->cacheKey($customer));
    }

    /** Phút một mã còn sống — hiện ra trong thư nên khách biết mình có bao lâu. */
    public function ttlMinutes(): int
    {
        return max(1, (int) Config::get('customer.verification.code_ttl_minutes', 10));
    }

    private function cacheKey(Customer $customer): string
    {
        return 'customer:verify-code:'.$customer->getKey();
    }
}
