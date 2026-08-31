<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Services\Payment\Gateway\Enums\ConnectionLookupKind;

/**
 * #2938 — MỘT phép tra connection, do adapter mô tả và resolver thi hành.
 *
 * VO thuần: không Eloquent, không I/O. Adapter là nơi biết payload của nhà cung
 * cấp mình mang định danh gì; resolver là nơi biết những định danh đó nằm ở cột
 * nào. Tách vậy để kiến thức riêng của nhà cung cấp không rò ra file dùng chung.
 */
final class ConnectionLookup
{
    /**
     * @param  list<string>  $values  các định danh ứng viên, THEO THỨ TỰ ưu tiên
     * @param  array<string, mixed>  $warningContext
     */
    private function __construct(
        public readonly ConnectionLookupKind $kind,
        public readonly array $values = [],
        public readonly ?PaymentGatewayEnvironmentEnum $environment = null,
        public readonly bool $haltWhenOnlyInactiveMatches = false,
        public readonly ?string $warningEvent = null,
        public readonly array $warningContext = [],
    ) {}

    /**
     * Tra theo `merchant_account_id`.
     *
     * `$haltWhenOnlyInactiveMatches` là vế #1109: merchant BIẾT nhưng đã tắt
     * (`is_active=false`) phải làm CẢ locator dừng lại và từ chối, KHÔNG được
     * rơi tiếp xuống phép tra sau. Tắt kích hoạt là công tắc chặn thu, không
     * phải một gợi ý để hệ thống đi tìm đường khác.
     *
     * @param  list<string>  $accountIds
     */
    public static function merchantAccount(
        array $accountIds,
        ?PaymentGatewayEnvironmentEnum $environment = null,
        bool $haltWhenOnlyInactiveMatches = false,
    ): self {
        return new self(
            ConnectionLookupKind::MerchantAccount,
            array_values($accountIds),
            $environment,
            $haltWhenOnlyInactiveMatches,
        );
    }

    /**
     * Tra qua `payment_attempts.provider_object_id` → `connection_id`.
     *
     * @param  list<string>  $references
     */
    public static function providerObjectReference(array $references): self
    {
        return new self(ConnectionLookupKind::ProviderObjectReference, array_values($references));
    }

    /** Connection đang bật DUY NHẤT của nhà cung cấp, hoặc không gì cả. */
    public static function soleActiveConnection(): self
    {
        return new self(ConnectionLookupKind::SoleActiveConnection);
    }

    /**
     * Lưới cuối trỏ đích danh một hàng connection, BẮT BUỘC kèm cảnh báo: mỗi
     * lần rơi vào đây là một bản ghi tiền có nguy cơ quy sai chủ, nên nó phải
     * kêu chứ không được im.
     *
     * @param  array<string, mixed>  $warningContext
     */
    public static function designated(string $connectionId, string $warningEvent, array $warningContext = []): self
    {
        return new self(
            ConnectionLookupKind::Designated,
            [$connectionId],
            warningEvent: $warningEvent,
            warningContext: $warningContext,
        );
    }
}
