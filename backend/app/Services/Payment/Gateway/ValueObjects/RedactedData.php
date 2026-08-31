<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

/** Immutable, explicitly redacted diagnostic data safe for persistence/logging. */
final readonly class RedactedData implements JsonSerializable
{
    private const ALLOWED_KEYS = [
        // #3138 — SỐ TIỀN của giao dịch, đơn vị nhỏ nhất, kèm mã ISO-4217.
        //
        // Không phải bí mật: nó đúng loại thông tin mà danh sách này đã cho qua
        // cho `order_code` và `provider_payment_reference` — thứ người vận hành
        // cần để tìm ra giao dịch ở CẢ HAI đầu lúc đối soát. Không phải PAN,
        // không phải CVV, không phải khoá.
        //
        // Cái giá của việc thiếu nó đo được ở #3115: để trả lời đúng một câu —
        // "có giao dịch ¥1.340 nào không" — phải hỏi ngược lên cổng **32 lần**.
        // Một sự cố tiền thật mà thời gian điều tra phụ thuộc vào một hệ thống
        // bên ngoài, đúng lúc quán đang cần câu trả lời.
        //
        // Tên giữ trung lập với provider và khớp cách repo lưu tiền ở chỗ khác
        // (`payment_attempts.amount_minor`). Đây là TỪ VỰNG BIÊN dùng chung —
        // adapter nào thấy thiếu tên thì sửa ở đây, không tự đặt tên riêng.
        'amount_minor',
        'capture_method',
        'connect_account_scope',
        'currency',
        'event_type',
        'merchant_account_reference',
        // Our own operation id sent to the provider, and the ids the provider
        // minted back. Neither is a secret — they are the references an operator
        // needs to find the transaction on both sides during reconciliation.
        // Kept provider-neutral on purpose: this allowlist is shared boundary
        // vocabulary, so no adapter may introduce a name of its own.
        'merchant_reference',
        'message_code',
        'order_code',
        'outcome',
        'provider_code',
        'provider_idempotency_key',
        'provider_payment_reference',
        'provider_refund_reference',
        'raw_status',
        'reason_code',
        'recovery_source',
    ];

    /** @var array<string, array<array-key, mixed>|bool|float|int|string|null> */
    private array $values;

    /** @param array<string, array<array-key, mixed>|bool|float|int|string|null> $values */
    public function __construct(array $values = [])
    {
        $this->assertSafe($values);
        $this->values = $values;
    }

    /** @return array<string, array<array-key, mixed>|bool|float|int|string|null> */
    public function jsonSerialize(): array
    {
        return $this->values;
    }

    /** @param array<array-key, mixed> $values */
    private function assertSafe(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new InvalidArgumentException("Field [{$key}] is not allowlisted for redacted data.");
            }

            if (! is_null($value) && ! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw new InvalidArgumentException('Redacted data accepts only top-level strings, integers, booleans, and null.');
            }

            // #3138 — hai khoá tiền có LUẬT RIÊNG, vì luật chung không đủ.
            //
            // Luật chung nhận cả `'1340'` lẫn `1340`, cả `'jpy'` lẫn `'JPY'` —
            // và một sổ mà cùng một số tiền nằm dưới hai hình dạng là một sổ tra
            // không ra. Đó đúng là hình dạng #2860 (bảy cách viết cho ba khái
            // niệm, sống nhiều tháng, không gì đỏ), chỉ khác là lần này chặn
            // được ngay từ lượt ghi đầu tiên.
            //
            // Có cổng gửi số tiền dưới dạng CHUỖI, nên chỗ ép kiểu nằm ở
            // adapter — cố ý: ép ngầm ở đây sẽ nhận luôn cả `'không phải số'`.
            if ($key === 'amount_minor' && ! is_null($value) && ! is_int($value)) {
                throw new InvalidArgumentException('Field [amount_minor] must be an integer in the currency minor unit.');
            }

            if ($key === 'currency' && ! is_null($value) && preg_match('/^[A-Z]{3}$/', (string) $value) !== 1) {
                throw new InvalidArgumentException('Field [currency] must be an ISO-4217 alphabetic code.');
            }

            if (is_string($value)) {
                if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/', $value) !== 1
                    || preg_match('/(?:sk|pk)_(?:live|test)_|whsec_|bearer|credential|password|secret/i', $value) === 1) {
                    throw new InvalidArgumentException("Field [{$key}] is not a safe diagnostic code.");
                }
            }
        }
    }
}
