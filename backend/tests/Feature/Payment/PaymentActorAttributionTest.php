<?php

/**
 * #2863 — `order_payments.received_by_id` ghi actor khi CÓ, và để trống khi
 * KHÔNG có. Không bịa ra một cái id trông như thật.
 *
 * Cột này là "actor" đa hình theo quy ước, không phải "staff" như tên gợi ý:
 * khoản qua POS mang **user id** (`OrderPaymentController:124`), khoản qua
 * workstation/kiosk/handy mang **device id**. Khoản customer-web tự xác nhận —
 * webhook Stripe, ví PayPay, voucher Konbini/銀行振込 — không có cả hai, và
 * `schemas/Backend/Product/OrderPayment.yaml` đã khai `nullable: true` đúng vì ca
 * đó.
 *
 * Ba đường ghi ấy vẫn đóng một hằng `00000000-0000-0000-0000-000000000000`. Đo
 * trên production 2026-08-13: **145/414** khoản mang giá trị này, và **không một
 * hàng `users` nào** mang id đó. Nó không phải rác vô hại — nó trông như một
 * UUID hợp lệ, nên đọc lên thì tưởng có người thu tiền.
 *
 * File này phải chứng minh CẢ HAI CHIỀU. Một test chỉ khẳng định "NULL" không
 * phân biệt được "đã thôi bịa" với "đã xoá trắng cả cột" — mà xoá trắng thì mất
 * đúng thứ đường POS/workstation vẫn ghi đúng từ đầu.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\ShopOrderSetting;
use App\Models\User;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses()->group('payment');

const PAYMENT_SENTINEL_ACTOR_ID = '00000000-0000-0000-0000-000000000000';

function actorAttributionOrder(): CustomerOrder
{
    $organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $organizationId,
        'console_organization_id' => $organizationId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $organizationId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $organizationId,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $organizationId,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);

    // Rào currency của cả hai funnel đọc `shop_order_settings`, không đọc cột
    // `branches.currency`.
    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $organizationId,
        'currency_code' => 'JPY',
    ]);

    return $order;
}

// ─── Chiều 1: KHÔNG có ai thu ⇒ cột để trống ─────────────────────────────────

it('#2863 webhook Stripe tự xác nhận ghi received_by_id NULL, không phải UUID toàn số 0', function () {
    $order = actorAttributionOrder();

    $recorded = app(OrderPaymentService::class)
        ->recordStripeWebhookPayment($order, 'pi_actor_null', 3000, 'full');

    expect($recorded)->toBeTrue();

    $payment = OrderPayment::query()->where('reference_no', 'pi_actor_null')->sole();

    expect($payment->received_by_id)->toBeNull()
        ->and($payment->received_by_id)->not->toBe(PAYMENT_SENTINEL_ACTOR_ID);
});

it('#2863 ví PayPay ghi received_by_id NULL', function () {
    $order = actorAttributionOrder();

    $outcome = app(OrderPaymentService::class)
        ->recordPayPayPaymentByOrderId((string) $order->id, 'tempoqr-actor-null', 3000, 'JPY');

    expect($outcome['recorded'])->toBeTrue();

    $payment = OrderPayment::query()->where('reference_no', 'tempoqr-actor-null')->sole();

    expect($payment->received_by_id)->toBeNull();
});

it('#2863 voucher async (Konbini/銀行振込) ghi received_by_id NULL ngay ở dòng pending', function () {
    $order = actorAttributionOrder();

    $recorded = app(OrderPaymentService::class)->recordAsyncPendingPayment(
        (string) $order->id,
        'pi_async_actor_null',
        3000,
        'konbini',
        'requires_action',
    );

    expect($recorded)->toBeTrue();

    $payment = OrderPayment::query()->where('reference_no', 'pi_async_actor_null')->sole();

    // Dòng `pending` là dòng DUY NHẤT trong ba đường đi được tới confirm()/fail().
    // Nó không mang `payment_attempt_id`, nên `shouldRouteLegacyConfirm()` trả
    // false và `mutationContextFromPayment()` (`received_by_id ?? throw`) không
    // bao giờ nhìn thấy NULL này. Khẳng định luôn ở đây để lần sau ai gắn
    // attempt vào dòng này thì test đỏ, chứ không phải production đỏ.
    expect($payment->received_by_id)->toBeNull()
        ->and($payment->status)->toBe(PaymentStatusEnum::Pending)
        ->and($payment->payment_attempt_id)->toBeNull();
});

// ─── Chiều 2: CÓ người/thiết bị thu ⇒ cột phải giữ đúng id đó ─────────────────

it('#2863 khoản thu tại quầy vẫn ghi ĐÚNG actor — NULL không phải là xoá trắng cột', function () {
    $order = actorAttributionOrder();
    $order->forceFill(['status' => CustomerOrderStatusEnum::Checkout->value])->save();
    $operator = User::factory()->create([
        'console_organization_id' => Organization::query()
            ->whereKey($order->organization_id)->value('console_organization_id'),
    ]);
    $cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $order->organization_id,
        'branch_id' => $order->branch_id,
        'code' => 'cash',
        'type' => 'cash',
        'is_active' => true,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => (string) $order->id,
        'payment_method_id' => (string) $cash->id,
        'amount' => 3000.0,
        'tendered_amount' => 3000.0,
        'received_by_id' => (string) $operator->id,
        'organization_id' => (string) $order->organization_id,
        'brand_id' => (string) $order->brand_id,
        'branch_id' => (string) $order->branch_id,
        'orchestrator_transport' => 'pos',
        'idempotency_key' => 'actor-attribution-pos-1',
    ]);

    expect($payment->received_by_id)->toBe((string) $operator->id);
});

// ─── Dữ liệu cũ: 145 hàng trên production ────────────────────────────────────

it('#2863 migration dọn hàng sentinel cũ và KHÔNG chạm hàng có actor thật', function () {
    $order = actorAttributionOrder();
    $operator = User::factory()->create([
        'console_organization_id' => Organization::query()
            ->whereKey($order->organization_id)->value('console_organization_id'),
    ]);
    $method = PaymentMethod::factory()->create([
        'organization_id' => $order->organization_id,
        'branch_id' => $order->branch_id,
        'is_active' => true,
    ]);

    $common = [
        'customer_order_id' => (string) $order->id,
        'payment_method_id' => (string) $method->id,
        'organization_id' => (string) $order->organization_id,
        'brand_id' => (string) $order->brand_id,
        'branch_id' => (string) $order->branch_id,
        'amount' => 1500,
        'tip_amount' => 0,
        'status' => PaymentStatusEnum::Succeeded->value,
        'paid_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    // Chèn thẳng: đây là hình dạng dữ liệu CŨ, và không còn đường mã nào ghi ra
    // nó nữa — gọi service sẽ không dựng lại được ca cần chữa.
    DB::table('order_payments')->insert([
        $common + ['id' => (string) Str::uuid(), 'payment_code' => 'PAY-2863-A', 'received_by_id' => PAYMENT_SENTINEL_ACTOR_ID, 'reference_no' => 'legacy-sentinel'],
        $common + ['id' => (string) Str::uuid(), 'payment_code' => 'PAY-2863-B', 'received_by_id' => (string) $operator->id, 'reference_no' => 'real-actor'],
    ]);

    $migration = require base_path(
        'database/migrations/2026_08_15_120000_manual_migration_null_out_payment_sentinel_actor.php'
    );
    $migration->up();

    expect(DB::table('order_payments')->where('reference_no', 'legacy-sentinel')->value('received_by_id'))
        ->toBeNull()
        // Chiều còn lại của cùng một phép đo: một migration `UPDATE … SET NULL`
        // viết thiếu `WHERE` cũng làm vế trên xanh.
        ->and(DB::table('order_payments')->where('reference_no', 'real-actor')->value('received_by_id'))
        ->toBe((string) $operator->id);

    // Chạy lại không đổi gì thêm — `migrate --force` chạy không người trông.
    $migration->up();

    expect(DB::table('order_payments')->where('reference_no', 'real-actor')->value('received_by_id'))
        ->toBe((string) $operator->id);
});

// ─── Ratchet nguồn: đừng dựng lại hằng đã gỡ ─────────────────────────────────

it('#2863 không đường ghi nào trong app/ đóng sentinel vào received_by_id nữa', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        // Bám đúng phép GÁN, không bám riêng chuỗi sentinel: chuỗi đó còn dùng
        // hợp lệ ở nơi khác (`ProductSkuService` dùng làm "không khớp gì",
        // `OrderPaymentOrchestrationCompat` dùng làm actor của MutationContext —
        // cả hai đều KHÔNG chạm cột dữ liệu này).
        if (preg_match("/'received_by_id'\s*=>\s*'0{8}-0{4}-0{4}-0{4}-0{12}'/", $source) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});
