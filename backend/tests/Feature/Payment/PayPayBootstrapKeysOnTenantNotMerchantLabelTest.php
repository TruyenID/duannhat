<?php

/**
 * #3075 — the PayPay bootstrap must find an org's connection by the TENANT,
 * never by the merchant label written into it.
 *
 * This is the same defect #3070 fixed on the Stripe path, sitting unexploded on
 * the PayPay path. `ensureOrgConnection` keyed its lookup on
 * `merchant_account_id`, a column that carries a synthetic label today and is
 * meant to carry the PSP's real merchant id tomorrow — #2893 ruled the
 * connection has to carry it so payout reconciliation stays unambiguous when a
 * PSP account is shared, and that ruling names no provider. The day it is
 * applied to PayPay, the lookup matches nothing and a SECOND connection is
 * created; attempts move to the new row while provider events stay on the old.
 *
 * Why this file exists instead of one more case in the Stripe guard: a test
 * written against one class says nothing about the other. That is the #2860
 * lesson — two hand-written validators sharing exactly one value lived for
 * months with nothing red.
 *
 * Measured while fixing (issue task 3): the PayPay path has NO fail-open net.
 * `LegacyStripeWebhookBridge` is typed on `Stripe\PaymentIntent` / `Stripe\Charge`
 * and never sees a PayPay event; an unmatchable PayPay notification lands on
 * `paypay_no_matching_attempt` / `paypay_qr_notification_unbookable`, which is a
 * `MoneyOrchestrationLog::error` plus a dispatched notification. So the same bug
 * here would be LOUD, not silent. Louder is better — but it means the failure
 * mode is a customer standing at the counter, not a ledger quietly splitting.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Orchestration\Internal\PayPayCustomerWebBootstrap;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use Illuminate\Support\Str;

uses()->group('payment');

// `assertConfigured()` chặn ngay ở cửa nếu deployment chưa cấu hình PayPay —
// đúng, nhưng bài này đo khoá TRA chứ không đo cổng cấu hình.
beforeEach(function (): void {
    config([
        'services.paypay.api_key' => 'a_key',
        'services.paypay.api_secret' => 'a_secret',
        'services.paypay.merchant_id' => '885700000000000001',
    ]);
});

/**
 * Tên hàm mang tiền tố `paypayBootstrap` chứ không dùng lại helper của file
 * Stripe: helper khai trong MỘT file test mà file khác gọi thì chạy tuần tự
 * không sao, chạy song song là chết — đúng thứ từng chặn `pest --parallel`
 * (#2778). Helper dùng chung sống ở `tests/Pest.php`, không sống ở đây.
 */
function paypayBootstrapOrder(): CustomerOrder
{
    $consoleOrganizationId = (string) Str::uuid();

    $organization = Organization::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
    ]);
    $brand = Brand::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
    ]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $brand->console_brand_id,
    ]);

    return CustomerOrder::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => 'open',
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);
}

function paypayBootstrapRef(CustomerOrder $order): OrderRef
{
    return new OrderRef(
        orderId: (string) $order->id,
        organizationId: (string) $order->organization_id,
        brandId: $order->brand_id === null ? null : (string) $order->brand_id,
        branchId: (string) $order->branch_id,
    );
}

it('reuses the org connection after a real merchant id is stamped on it', function () {
    $order = paypayBootstrapOrder();
    $bootstrap = app(PayPayCustomerWebBootstrap::class);

    $first = $bootstrap->resolveForOrder(paypayBootstrapRef($order));

    // Exactly what #2893 does on the Stripe path, applied to PayPay: replace the
    // synthetic label with the PSP's own merchant id. Nothing else changes.
    PaymentGatewayConnection::query()
        ->whereKey($first['connectionId'])
        ->update(['merchant_account_id' => '885700000000000001']);

    $second = $bootstrap->resolveForOrder(paypayBootstrapRef($order));

    expect($second['connectionId'])->toBe($first['connectionId'],
        'Đóng dấu định danh merchant thật đã đẻ ra connection THỨ HAI — đúng #3070, '
        .'lần này ở đường PayPay. Attempt đi một đường, provider event đi đường khác.'
    );

    $connections = PaymentGatewayConnection::query()
        ->where('organization_id', (string) $order->organization_id)
        ->count();

    expect($connections)->toBe(1, 'Tổ chức này phải có ĐÚNG một connection PayPay.');
});

it('still gives two different orgs two different connections', function () {
    // Đây là thứ khoá cũ đang mua được, và bản vá không được bán nó đi: đổi sổ
    // tách đôi lấy sổ dùng chung thì tệ hơn hẳn — tiền của org này rơi vào cổng
    // của org kia.
    $orderA = paypayBootstrapOrder();
    $orderB = paypayBootstrapOrder();

    $bootstrap = app(PayPayCustomerWebBootstrap::class);
    $refsA = $bootstrap->resolveForOrder(paypayBootstrapRef($orderA));
    $refsB = $bootstrap->resolveForOrder(paypayBootstrapRef($orderB));

    expect($refsA['connectionId'])->not->toBe($refsB['connectionId'],
        'Hai tổ chức dùng chung một connection PayPay: tiền của org này ghi vào cổng của org kia.'
    );
});

// ĐÃ GỠ #3074 — bài "giải ra hàng gốc khi hàng TRÙNG còn tồn tại" không dựng
// lại được nữa: `payment_gateway_connections` nay UNIQUE trên khoá tự nhiên
// (provider · environment · organization · brand · owner_scope ·
// owner_branch_key), nên hàng trùng KHÔNG THỂ ra đời.
//
// Nó từng là guard tạm cho cửa sổ giữa lúc phát hiện #3070 và lúc dọn xong dữ
// liệu production. Ràng buộc ở tầng DB thay thế nó hoàn toàn, và thay thế bằng
// thứ không phụ thuộc việc ai đó nhớ tra bằng cột nào — xem
// `ConnectionTenantKeyIsUniqueTest`.
