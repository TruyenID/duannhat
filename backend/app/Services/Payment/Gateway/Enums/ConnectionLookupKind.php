<?php

namespace App\Services\Payment\Gateway\Enums;

/**
 * #2938 — CÁCH tra một connection từ một sự kiện webhook.
 *
 * Từ vựng này cố ý mô tả **phép tra**, không mô tả nhà cung cấp. Adapter nói
 * "tra theo định danh merchant" hoặc "tra theo mã tham chiếu của chính ta";
 * `WebhookConnectionResolver` biết
 * chạy từng phép đó trên DB. Nhờ vậy nhà cung cấp thứ tư chỉ cần thêm adapter,
 * không phải sửa file dùng chung — đúng thứ `PaymentGatewayRegistry` sinh ra
 * để tránh.
 */
enum ConnectionLookupKind: string
{
    /**
     * `payment_gateway_connections.merchant_account_id` — định danh do NHÀ CUNG
     * CẤP cấp. Rào `payment_gateway_connections_merchant_unique`
     * (provider_id, environment, merchant_account_id) đảm bảo không nhập nhằng.
     */
    case MerchantAccount = 'merchant_account';

    /**
     * Mã tham chiếu do CHÍNH TA sinh, đã đóng dấu lên
     * `payment_attempts.provider_object_id` — nên nó chỉ đích danh một
     * connection kể cả khi định danh merchant không phân biệt được tenant.
     */
    case ProviderObjectReference = 'provider_object_reference';

    /** Connection ĐANG BẬT duy nhất của nhà cung cấp (tiện lợi sandbox/đơn tenant). */
    case SoleActiveConnection = 'sole_active_connection';

    /**
     * Một hàng connection ĐÍCH DANH theo id — lưới cuối, luôn kèm cảnh báo.
     * Không phải đường phân giải bình thường: rơi vào đây nghĩa là tiền sắp
     * được quy cho một chủ sở hữu mà ta chỉ đoán được bằng cấu hình.
     */
    case Designated = 'designated';
}
