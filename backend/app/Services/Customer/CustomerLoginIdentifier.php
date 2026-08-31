<?php

namespace App\Services\Customer;

/**
 * #1782 — một định danh đăng nhập của khách: email HOẶC số điện thoại.
 *
 * ## Vì sao chuẩn hoá số là bắt buộc, không phải tiện nghi
 *
 * Cùng một số được gõ ra nhiều kiểu: `090 1234 5678`, `090-1234-5678`,
 * `+84901234567`, `0901234567`. Nếu chỉ so chuỗi thô thì khách gõ đúng số của
 * mình vẫn bị báo sai mật khẩu — và họ không có cách nào đoán ra mình phải gõ
 * kiểu nào, vì kiểu "đúng" là kiểu ai đó đã nhập lúc tạo hồ sơ.
 *
 * ## Chuẩn hoá tới đâu, và dừng ở đâu
 *
 * Bỏ dấu phân cách, rồi quy `+84` / `+81` về `0` — hai nước repo này phục vụ.
 * KHÔNG cố đoán mã quốc gia từ một số không có dấu `+`: `84...` có thể là mã
 * quốc gia VN, mà cũng có thể là một số nội địa Nhật bắt đầu bằng 84. Đoán sai ở
 * đây nghĩa là đăng nhập trúng tài khoản NGƯỜI KHÁC, nên chỗ này thà nhận ít
 * biến thể hơn còn hơn nhận nhầm.
 *
 * Dữ liệu trong DB cũng ở dạng thô, nên phép tra sinh RA CÁC BIẾN THỂ tương
 * đương của số khách gõ rồi tra `whereIn` — thay vì chuẩn hoá cột trong SQL,
 * việc sẽ vô hiệu hoá mọi chỉ mục.
 */
final readonly class CustomerLoginIdentifier
{
    private function __construct(
        public bool $isEmail,
        public string $raw,
        /** @var list<string> biến thể tương đương để tra DB; rỗng khi là email */
        public array $phoneVariants,
    ) {}

    public static function parse(string $input): self
    {
        $trimmed = trim($input);

        if (str_contains($trimmed, '@')) {
            return new self(true, $trimmed, []);
        }

        return new self(false, $trimmed, self::phoneVariants($trimmed));
    }

    /**
     * Các dạng viết tương đương của một số, để tra dữ liệu thô trong DB.
     *
     * @return list<string>
     */
    public static function phoneVariants(string $input): array
    {
        // Bỏ mọi thứ không phải chữ số, giữ dấu `+` đầu nếu có.
        $plus = str_starts_with(trim($input), '+');
        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if ($digits === '') {
            return [];
        }

        $variants = [$digits];

        // `+84…` / `+81…` ⇔ `0…`. Chỉ khi có dấu `+` tường minh — xem docblock
        // đầu lớp về việc KHÔNG đoán mã quốc gia.
        foreach (['84', '81'] as $cc) {
            if ($plus && str_starts_with($digits, $cc)) {
                $variants[] = '0'.substr($digits, strlen($cc));
            }
            if (str_starts_with($digits, '0')) {
                $variants[] = $cc.substr($digits, 1);
                $variants[] = '+'.$cc.substr($digits, 1);
            }
        }

        if ($plus) {
            $variants[] = '+'.$digits;
        }

        // Giữ nguyên chuỗi khách gõ: DB có thể đang lưu đúng dạng có dấu cách.
        $variants[] = trim($input);

        return array_values(array_unique(array_filter($variants, static fn (string $v): bool => $v !== '')));
    }
}
