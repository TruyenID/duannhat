<?php

declare(strict_types=1);

namespace App\Services\Payment\Internal;

use App\Models\OrderPaymentIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Con trỏ từ một đơn hàng tới đối tượng intent ở cổng thanh toán (#1611).
 *
 * ## Vấn đề là CHỖ ĐẶT DỮ LIỆU, không phải chỗ đặt code
 *
 * `customer_orders.stripe_payment_intent_id` là **dữ liệu của Payments nằm trên
 * bảng của Ordering**. Nó không phải thuộc tính của một đơn hàng — nó là con trỏ
 * tới một đối tượng ở cổng thanh toán, và tên cột còn nhúng luôn tên MỘT gateway
 * cụ thể. Mai có PayPay, Terminal, konbini thì thêm cột nữa?
 *
 * Vì thế mọi cách "cho Payments đọc nó" đều xấu theo một kiểu khác nhau: thêm
 * `stripePaymentIntentId()` vào `OrderSnapshot` là đưa tên một GATEWAY vào hợp
 * đồng công bố của Ordering; dựng cổng riêng trong Ordering là cùng cái rò, chỉ
 * đổi chỗ.
 *
 * `order_payment_intents` (#1637) là chỗ đúng: bảng của Payments, khoá theo
 * `(provider, intent_id)` nên nhiều gateway sống chung được, và `customer_order_id`
 * unique nên một đơn chỉ có một intent đang hiệu lực.
 *
 * ## Giai đoạn: GHI CẢ HAI, ĐỌC CHỖ MỚI
 *
 * Đây là bước *migrate* của expand → migrate → contract:
 *
 *   expand   #1637 — bảng + model, dữ liệu cũ chép sang một lần rồi thôi
 *   migrate  #1611 — bản này: ghi cả hai chỗ, đọc chỗ mới
 *   contract sau  — thôi ghi cột cũ, rồi drop nó
 *
 * Cột cũ VẪN được ghi ở giai đoạn này, cố ý: một bản deploy quay lui (hoặc một
 * consumer chưa ai tìm ra) vẫn đọc được. Bỏ dual-write cùng lúc với chuyển đọc
 * là gộp hai bước có thể hỏng độc lập vào một lần deploy.
 */
final class OrderPaymentIntentPointer
{
    public const PROVIDER_STRIPE = 'stripe';

    /** Intent id đang gắn với đơn này, hoặc null. */
    public function forOrder(string $orderId, string $provider = self::PROVIDER_STRIPE): ?string
    {
        $id = OrderPaymentIntent::query()
            ->where('customer_order_id', $orderId)
            ->where('provider', $provider)
            ->value('intent_id');

        return $id === null ? null : (string) $id;
    }

    /** Đơn nào đang gắn với intent này — chiều NGƯỢC, dùng bởi webhook. */
    public function orderIdFor(string $intentId, string $provider = self::PROVIDER_STRIPE): ?string
    {
        $id = OrderPaymentIntent::query()
            ->where('intent_id', $intentId)
            ->where('provider', $provider)
            ->value('customer_order_id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Đóng dấu con trỏ. Idempotent trên `(customer_order_id, provider)`.
     *
     * Một đơn chỉ có MỘT intent đang hiệu lực, nên mint lại thì con trỏ đi theo
     * cái mới — đúng ngữ nghĩa unique `customer_order_id` mà #1637 đặt ra.
     */
    public function stamp(
        string $orderId,
        string $organizationId,
        string $intentId,
        string $provider = self::PROVIDER_STRIPE,
    ): void {
        DB::transaction(function () use ($orderId, $organizationId, $intentId, $provider): void {
            $existing = OrderPaymentIntent::query()
                ->where('customer_order_id', $orderId)
                ->where('provider', $provider)
                ->first();

            if ($existing !== null) {
                if ((string) $existing->intent_id !== $intentId) {
                    $existing->intent_id = $intentId;
                    $existing->save();
                }

                return;
            }

            OrderPaymentIntent::query()->create([
                'id' => (string) Str::uuid(),
                'organization_id' => $organizationId,
                'customer_order_id' => $orderId,
                'provider' => $provider,
                'intent_id' => $intentId,
            ]);
        });
    }
}
