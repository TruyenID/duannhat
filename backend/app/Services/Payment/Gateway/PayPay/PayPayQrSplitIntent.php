<?php

namespace App\Services\Payment\Gateway\PayPay;

use App\Services\Order\Enums\OrderSplitMode;
/**
 * Carries the split-bill intent a dine-in guest declared at mint time through to
 * the ledger write, which happens somewhere else entirely.
 *
 * Stripe does not need this: its split metadata rides on the PaymentIntent, so
 * the webhook reads it back off the provider. PayPay's create call carries no
 * metadata field we control, and `payment_attempts` has no free-form column to
 * park it in — `next_action` and `redacted_summary` are both written by the
 * orchestrator at finalize and would clobber anything we left there.
 *
 * So it is held here, keyed by merchant payment id, which is the one identifier
 * every settlement funnel has: the customer's own poll
 * (`PayPayPaymentService::syncStatus`), the webhook recovery path
 * (`ProviderRetrievalRecoveryService`) and the stale-attempt sweeper all reach
 * `recordPayPayPayment` with it.
 *
 * **Losing this costs attribution, never money.** The amount the code collects
 * is committed to the PaymentAttempt under the order lock and re-read from
 * PayPay at settlement; nothing here participates in that. A cache eviction
 * degrades a by-items payment to "an amount was paid" — the same shape a
 * pay-in-full has.
 *
 * The TTL is deliberately far longer than the ~5 minute life of a QR: the
 * webhook is usually seconds behind the scan, but the sweeper can reconcile an
 * abandoned attempt hours later, and that reconciliation writes the same ledger
 * row.
 */
use Illuminate\Support\Facades\Cache;

final class PayPayQrSplitIntent
{
    private const TTL_HOURS = 24;

    /** The split strategies a customer surface may declare. */
    /** #2860 — từ vựng canonical, sinh từ enum để không trôi khỏi hai validator. */
    public const TYPES = ['even', 'by_items', 'by_amount'];

    /**
     * Normalize a request payload into the intent we are willing to store.
     *
     * Returns null when there is nothing worth remembering, so callers can treat
     * "pay in full" and "a split with no usable detail" identically.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array{split_type: string, split_count: int|null, item_allocations: list<array{item_id: string, units: int}>}|null
     */
    public static function normalize(?array $payload): ?array
    {
        $type = $payload['split_type'] ?? null;

        // #2865 — chuẩn hoá TRƯỚC khi đối chiếu, y như đường Stripe song sinh
        // (`CustomerOrderController:199`). Thiếu bước này thì một bundle
        // customer-web CŨ gửi tên cũ của chế độ chia-theo-số-tiền sẽ qua được
        // validate (luật đã nới để nhận tên cũ) rồi **rơi xuống null ở đây, im
        // lặng**: QR vẫn mint, tiền vẫn thu, nhưng dòng tiền không mang chế độ
        // chia và `/split-status` không khoá cứng. Tiền đúng, attribution mất,
        // không dấu vết.
        //
        // Đúng loại lệch-hai-đường mà #2860 sinh ra để giết — và docblock của
        // `fromWire()` đã cảnh báo chính nó.
        //
        // Vá luôn `recall()`: nó đi qua đây, nên intent cache mint TRƯỚC deploy
        // mang tên cũ vẫn phân giải được lúc settle.
        $type = is_string($type) ? OrderSplitMode::canonicalWire($type) : null;

        if ($type === null || ! in_array($type, self::TYPES, true)) {
            return null;
        }

        $count = $payload['split_count'] ?? null;
        $count = is_numeric($count) && (int) $count >= 2 ? (int) $count : null;

        $allocations = [];

        foreach (is_array($payload['item_allocations'] ?? null) ? $payload['item_allocations'] : [] as $allocation) {
            if (! is_array($allocation)) {
                continue;
            }

            $itemId = (string) ($allocation['item_id'] ?? '');
            $units = (int) ($allocation['units'] ?? 0);

            if ($itemId !== '' && $units > 0) {
                $allocations[] = ['item_id' => $itemId, 'units' => $units];
            }
        }

        // A `by_items` split with no usable allocation is not a by-items split.
        // Storing it would stamp `split_mode: by_items` on the ledger row with
        // nothing to attribute, which locks every later payer out of the
        // by-items flow (`splitByItemsPreview` refuses to mix strategies) while
        // crediting no item at all.
        if ($type === 'by_items' && $allocations === []) {
            return null;
        }

        return [
            'split_type' => $type,
            'split_count' => $count,
            'item_allocations' => $allocations,
        ];
    }

    /**
     * @param  array{split_type: string, split_count: int|null, item_allocations: list<array{item_id: string, units: int}>}|null  $intent
     */
    public static function remember(string $merchantPaymentId, ?array $intent): void
    {
        if ($intent === null) {
            Cache::forget(self::cacheKey($merchantPaymentId));

            return;
        }

        Cache::put(self::cacheKey($merchantPaymentId), $intent, now()->addHours(self::TTL_HOURS));
    }

    /**
     * @return array{split_type: string, split_count: int|null, item_allocations: list<array{item_id: string, units: int}>}|null
     */
    public static function recall(string $merchantPaymentId): ?array
    {
        $cached = Cache::get(self::cacheKey($merchantPaymentId));

        return is_array($cached) && isset($cached['split_type']) ? self::normalize($cached) : null;
    }

    public static function forget(string $merchantPaymentId): void
    {
        Cache::forget(self::cacheKey($merchantPaymentId));
    }

    /**
     * Deliberately a different key from the display session
     * (`paypay:qr-session:{mpid}`, `PayPayPaymentService`): that one exists so a
     * page reload can be handed back the code it already had and expires with the
     * screen, while this one has to survive until the money settles.
     */
    private static function cacheKey(string $merchantPaymentId): string
    {
        return 'paypay:qr-split:'.$merchantPaymentId;
    }

    /**
     * A stable identity for "the same split intent".
     *
     * Used to decide whether a live code may be resumed. Amount alone is not
     * enough: two ¥500 dishes give the payer who switches their selection an
     * identical total, and resuming there would credit the dish they no longer
     * meant to pay for. Allocations are sorted so key order in the request body
     * cannot make two identical intents look different.
     *
     * @param  array{split_type: string, split_count: int|null, item_allocations: list<array{item_id: string, units: int}>}|null  $intent
     */
    public static function fingerprint(?array $intent): string
    {
        if ($intent === null) {
            return 'none';
        }

        $allocations = $intent['item_allocations'];
        usort($allocations, fn (array $a, array $b): int => strcmp($a['item_id'], $b['item_id']));

        return (string) json_encode([
            $intent['split_type'],
            $intent['split_count'],
            $allocations,
        ]);
    }

    /**
     * The `OrderPayment.metadata` shape this intent contributes.
     *
     * Mirrors what the Stripe funnel ends up storing — `split_count` /
     * `split_type` / `amount_per_person` for an even split, and
     * `split_mode: by_items` + expanded `item_allocations` for a per-dish one
     * (see `StripePaymentService::createSplitPaymentIntent` and
     * `normalizeIntentMetadata`). The readers are shared, so the shapes must be
     * too: `splitByItemsPreview` refuses an order whose existing rows do not
     * carry `split_mode === 'by_items'`, and `/split-status` reads `split_count`
     * off the first succeeded payment to turn a soft lock into a hard one.
     *
     * @param  array{split_type: string, split_count: int|null, item_allocations: list<array{item_id: string, units: int}>}|null  $intent
     * @return array<string, mixed>
     */
    public static function toPaymentMetadata(?array $intent, float $amount): array
    {
        if ($intent === null) {
            return [];
        }

        $metadata = [];

        if ($intent['split_type'] === OrderSplitMode::ByItems->value) {
            $metadata['split_mode'] = OrderSplitMode::ByItems->value;
            $metadata['item_allocations'] = $intent['item_allocations'];
        }

        if ($intent['split_count'] !== null) {
            $metadata['split_count'] = $intent['split_count'];
            $metadata['split_type'] = $intent['split_type'];
            $metadata['amount_per_person'] = $amount;
        }

        return $metadata;
    }
}
