<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * Một hàng `device_signing_keys` đã tách khỏi Eloquent (#962).
 *
 * Các mốc thời gian là CHUỖI đúng như model in ra, vì chúng chỉ được dùng để
 * dựng câu thông báo từ chối — verifier không so sánh chúng (kết luận hiệu lực
 * đã nằm ở {@see self::$validAtSignature}).
 */
final class SigningKeyEvidence
{
    public function __construct(
        public readonly string $id,
        public readonly string $deviceId,
        public readonly string $publicKey,
        public readonly ?string $revokedAt,
        public readonly ?string $revokedReason,
        public readonly ?string $issuedAt,
        public readonly ?string $expiresAt,
        /** Khoá có hiệu lực tại thời điểm ký mà caller đã hỏi. */
        public readonly bool $validAtSignature,
    ) {}
}
