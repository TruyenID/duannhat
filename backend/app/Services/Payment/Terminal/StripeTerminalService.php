<?php

namespace App\Services\Payment\Terminal;

use App\Models\Branch;
use App\Models\PeripheralDevice;
use App\Services\Customer\OrderPaymentService;
use App\Services\Order\Contracts\BranchCurrency;
use App\Services\Order\Contracts\OrderSnapshot;
use App\Services\Payment\Gateway\Stripe\StripePaymentGateway;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use App\Support\ZeroDecimalCurrency;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * #1088 — Stripe Terminal runtime (server-driven card_present).
 *
 * NOT the Verifone P400: that is a LAN client-driven terminal the
 * kiosk/workstation drives over ws:// (VescaJS). A Stripe Terminal smart
 * reader is registered with Stripe and driven from the SERVER — Cloud
 * creates a card_present PaymentIntent and pushes it to the reader over
 * Stripe's API; nobody on the LAN talks to the device.
 *
 * Money flow deliberately REUSES the #1125 async lifecycle: the charge
 * hands the intent to the reader and ledgers an awaiting-async PENDING row;
 * the ordinary `payment_intent.succeeded` webhook flips that row and settles
 * the order (metadata.order_id resolution — the customer-web pointer is
 * never touched), and `payment_intent.canceled` fails it. One lifecycle,
 * every async money path.
 *
 * Reader registry: PeripheralDevice rows of type `payment_terminal` with
 * `metadata.provider = stripe_terminal` (+ stripe_reader_id / location /
 * device_type). LAN P400 rows keep their metadata.host contract untouched.
 *
 * #962 — cạnh `Payments → PlatformIntegration` (`PeripheralDevice`) CỐ Ý Ở LẠI.
 * PlatformIntegration publish theo TỪNG CLASS, nên trả cạnh này đòi sửa
 * `config/modules.php` cho một model mà Payments **đăng ký và sở hữu vòng đời**
 * (registerReader tạo hàng, gán stripe_reader_id, gỡ khi thu hồi) — không phải
 * một model nó chỉ đọc nhờ. Còn cách rẻ tiền — bỏ type-hint ở closure — thì
 * deptrac xanh trong khi runtime vẫn đọc y nguyên model: đó là **gian lận phép
 * đo**, không phải trả nợ. Cạnh này đi khi PlatformIntegration công bố một cổng
 * đăng ký thiết bị ngoại vi thật.
 *
 * Connection scope: the LEGACY global platform connection — the same scope
 * whose webhook secret verifies `payment_intent.*` events today, so Terminal
 * settlement rides the existing intake path. Connect merchant routing joins
 * when the capability certifies (plan-048 Gate 2); the catalog row stays
 * Disabled until then and `payments.stripe_terminal.enabled` fails closed.
 */
class StripeTerminalService
{
    public const PROVIDER = 'stripe_terminal';

    public function __construct(
        private readonly StripePaymentGateway $gateway,
        private readonly OrderPaymentService $orderPayments,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('payments.stripe_terminal.enabled', false);
    }

    public static function abortUnlessEnabled(): void
    {
        if (! self::enabled()) {
            abort(response()->json([
                'message' => 'Stripe Terminal is not enabled for this environment.',
                'code' => 'STRIPE_TERMINAL_DISABLED',
            ], 409));
        }
    }

    // =========================================================================
    //  Reader registry
    // =========================================================================

    /**
     * Register a physical (or simulated) reader for a branch using the code
     * displayed on the device, and persist it as a PeripheralDevice row.
     */
    public function registerReader(Branch $branch, string $registrationCode, string $name): PeripheralDevice
    {
        $connection = $this->connection();
        $locationId = $this->ensureLocationId($branch, $connection);

        $reader = $this->gateway->registerTerminalReader([
            'registration_code' => $registrationCode,
            'label' => $name,
            'location' => $locationId,
            'metadata' => ['tempo_branch_id' => (string) $branch->id],
        ], $connection);

        // Re-registering the same physical reader (Stripe returns the same
        // reader id) updates the existing row instead of minting a duplicate.
        $existing = PeripheralDevice::query()
            ->where('branch_id', $branch->id)
            ->where('type', 'payment_terminal')
            ->where('metadata->stripe_reader_id', (string) $reader->id)
            ->first();

        $metadata = [
            'provider' => self::PROVIDER,
            'stripe_reader_id' => (string) $reader->id,
            'stripe_location_id' => $locationId,
            'device_type' => (string) $reader->device_type,
            'serial_number' => (string) ($reader->serial_number ?? ''),
        ];

        if ($existing !== null) {
            $existing->update(['name' => $name, 'metadata' => $metadata, 'is_active' => true]);

            return $existing->refresh();
        }

        return PeripheralDevice::query()->create([
            'name' => $name,
            'type' => 'payment_terminal',
            'is_active' => true,
            'metadata' => $metadata,
            'organization_id' => $branch->console_organization_id,
            'branch_id' => $branch->id,
        ]);
    }

    /**
     * The branch's Stripe readers with their LIVE Stripe status attached
     * (online/offline + in-flight action) — the row alone can't know that.
     *
     * @return list<array<string, mixed>>
     */
    public function listReaders(Branch $branch, bool $withLiveStatus = true): array
    {
        $connection = $this->connection();

        return $this->readerRows($branch)
            ->map(function (PeripheralDevice $row) use ($connection, $withLiveStatus): array {
                $entry = [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'is_active' => (bool) $row->is_active,
                    'stripe_reader_id' => (string) data_get($row->metadata, 'stripe_reader_id'),
                    'device_type' => (string) data_get($row->metadata, 'device_type'),
                ];

                if ($withLiveStatus) {
                    try {
                        $reader = $this->gateway->retrieveTerminalReader($entry['stripe_reader_id'], $connection);
                        $entry['status'] = (string) $reader->status;
                        $entry['action'] = $reader->action !== null ? (string) $reader->action->type : null;
                    } catch (\Throwable $e) {
                        $entry['status'] = 'unknown';
                        $entry['action'] = null;
                    }
                }

                return $entry;
            })
            ->values()
            ->all();
    }

    // =========================================================================
    //  Charge / cancel
    // =========================================================================

    /**
     * #1643 — nhận ẢNH CHỤP đơn (`OrderSnapshot`, Ordering công bố từ #1544).
     * Đo được: method này chỉ đọc sáu vô hướng — `id · order_code ·
     * organization_id · branch_id · total_amount · paid_amount` — và cả sáu đều
     * có trên hợp đồng. Chỗ gọi duy nhất là controller (một SURFACE, không phải
     * module) nên nó lấy ảnh chụp qua `OrderQueryPort`.
     */
    public function charge(OrderSnapshot $order, PeripheralDevice $readerRow): array
    {
        $this->assertStripeReader($readerRow, $order->branchId());

        $currency = $this->resolveOrderCurrency($order->branchId());
        $outstanding = round($order->totalAmount() - $order->paidAmount(), 2);

        if ($outstanding <= 0) {
            throw ValidationException::withMessages(['order' => __('Order has no outstanding balance.')]);
        }

        $connection = $this->connection();

        $intent = $this->gateway->createPaymentIntent([
            'amount' => $this->toMinorUnits($outstanding, $currency),
            'currency' => strtolower($currency),
            // Terminal is the ONE integration where an explicit
            // payment_method_types is correct (card_present is reader-only).
            'payment_method_types' => ['card_present'],
            'capture_method' => 'automatic',
            'metadata' => [
                'order_id' => $order->aggregateId(),
                'order_code' => $order->orderCode(),
                'branch_id' => $order->branchId(),
                'organization_id' => $order->organizationId(),
                'flow' => 'full',
                'order_currency' => strtolower($currency),
                'channel' => 'pos_stripe_terminal',
                'peripheral_device_id' => (string) $readerRow->id,
            ],
        ], $connection, 'terminal_'.$order->aggregateId().'_'.$readerRow->id.'_'.$this->toMinorUnits($outstanding, $currency));

        $readerId = (string) data_get($readerRow->metadata, 'stripe_reader_id');

        try {
            $reader = $this->gateway->processTerminalPaymentIntent($readerId, (string) $intent->id, $connection);
        } catch (\Throwable $e) {
            // The reader refused (offline, busy, unsupported) — no money can
            // move on this intent, so kill it rather than leaving a live
            // intent the guest could still complete against a dead charge UI.
            try {
                $this->gateway->cancelPaymentIntent((string) $intent->id, $connection);
            } catch (\Throwable) {
                // Best effort — an uncancelable intent converges via webhook.
            }

            Log::channel('payment_orchestration')->warning('stripe_terminal_process_failed', [
                'order_id' => $order->aggregateId(),
                'payment_intent_id' => (string) $intent->id,
                'reader_id' => $readerId,
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'reader' => __('The card reader did not accept the payment: :reason', ['reason' => $e->getMessage()]),
            ]);
        }

        $this->orderPayments->recordAsyncPendingPayment(
            $order->aggregateId(),
            (string) $intent->id,
            $outstanding,
            asyncMethod: 'card_present',
            intentStatus: (string) $intent->status,
        );

        Log::channel('payment_orchestration')->info('stripe_terminal_charge_started', [
            'order_id' => $order->aggregateId(),
            'payment_intent_id' => (string) $intent->id,
            'reader_id' => $readerId,
            'amount' => $outstanding,
            'currency' => $currency,
        ]);

        return [
            'payment_intent_id' => (string) $intent->id,
            'reader_status' => (string) $reader->status,
            'amount' => $outstanding,
            'currency' => $currency,
        ];
    }

    /**
     * Hand the order's outstanding balance to a reader. Ledgers an
     * awaiting-async PENDING row; the webhook settles it (#1125 lifecycle).
     *
     * @return array{payment_intent_id: string, reader_status: string, amount: float, currency: string}
     */
    /**
     * Abort the reader's in-flight action AND the intent it was collecting —
     * the pending row fails and the order returns to payable immediately
     * (no waiting for the payment_intent.canceled webhook).
     */
    public function cancel(PeripheralDevice $readerRow, string $paymentIntentId): void
    {
        $this->assertStripeReader($readerRow, $readerRow->branch_id);

        $connection = $this->connection();
        $readerId = (string) data_get($readerRow->metadata, 'stripe_reader_id');

        try {
            $this->gateway->cancelTerminalReaderAction($readerId, $connection);
        } catch (\Throwable $e) {
            // Reader may have already finished/cleared — the intent cancel
            // below is the authoritative money decision.
            Log::info('stripe_terminal_cancel_action_noop', ['reader_id' => $readerId, 'error' => $e->getMessage()]);
        }

        try {
            $this->gateway->cancelPaymentIntent($paymentIntentId, $connection);
        } catch (\Throwable $e) {
            // Already succeeded (guest tapped in the race) → the succeeded
            // webhook settles the pending row; do NOT fail it here.
            Log::channel('payment_orchestration')->info('stripe_terminal_intent_cancel_noop', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->orderPayments->failAsyncPendingPayment($paymentIntentId, 'terminal_canceled');
    }

    // =========================================================================
    //  Internals
    // =========================================================================

    /**
     * The Terminal Location for a branch — found by the tempo_branch_id
     * metadata stamp, created on first use. Stripe requires country + line1;
     * a branch without a structured address seeds from config.
     */
    public function ensureLocationId(Branch $branch, ?GatewayConnectionData $connection = null): string
    {
        $connection ??= $this->connection();

        foreach ($this->gateway->listTerminalLocations($connection)->data as $location) {
            if ((string) data_get($location->metadata, 'tempo_branch_id') === (string) $branch->id) {
                return (string) $location->id;
            }
        }

        $country = (string) config('payments.stripe_terminal.location_country', 'JP');
        $address = [
            'country' => $country,
            'line1' => (string) ($branch->address ?: $branch->name),
        ];

        $location = $this->gateway->createTerminalLocation([
            'display_name' => (string) $branch->name,
            // Verified against the live sandbox (2026-07-27): a JP account
            // REJECTS the plain `address` field for Terminal Locations —
            // Japanese addresses must go through address_kanji/address_kana.
            ...($country === 'JP' ? ['address_kanji' => $address] : ['address' => $address]),
            'metadata' => ['tempo_branch_id' => (string) $branch->id],
        ], $connection);

        return (string) $location->id;
    }

    private function assertStripeReader(PeripheralDevice $readerRow, ?string $branchId): void
    {
        $isStripeReader = $readerRow->type === 'payment_terminal'
            && data_get($readerRow->metadata, 'provider') === self::PROVIDER
            && is_string(data_get($readerRow->metadata, 'stripe_reader_id'))
            && (bool) $readerRow->is_active;

        if (! $isStripeReader || (string) $readerRow->branch_id !== (string) $branchId) {
            abort(response()->json([
                'message' => 'Not an active Stripe Terminal reader for this branch.',
                'code' => 'STRIPE_TERMINAL_READER_INVALID',
            ], 422));
        }
    }

    /** @return Collection<int, PeripheralDevice> */
    private function readerRows(Branch $branch)
    {
        return PeripheralDevice::query()
            ->where('branch_id', $branch->id)
            ->where('type', 'payment_terminal')
            ->where('metadata->provider', self::PROVIDER)
            ->orderBy('created_at')
            ->get();
    }

    /** #815 discipline — the branch's priced currency, never a global config. */
    private function resolveOrderCurrency(string $branchId): string
    {
        $code = app(BranchCurrency::class)->codeFor($branchId);

        return strtoupper((string) ($code ?: config('services.stripe.currency', 'jpy')));
    }

    private function connection(): GatewayConnectionData
    {
        return (new LegacyGlobalStripeConnection)->connectionData();
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        return ZeroDecimalCurrency::contains($currency)
            ? (int) round($amount)
            : (int) round($amount * 100);
    }
}
