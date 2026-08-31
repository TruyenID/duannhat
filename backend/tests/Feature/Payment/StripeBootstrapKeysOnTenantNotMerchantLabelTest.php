<?php

/**
 * #3070 — the Stripe bootstrap must find an org's connection by the TENANT,
 * never by the merchant label written into it.
 *
 * What happened on production. #2893 ruled that the connection has to carry the
 * real Stripe account (`acct_…`) so payout reconciliation is unambiguous when a
 * PSP account is shared, and a one-off migration stamped it on 2026-08-15
 * 23:30 UTC. `ensureOrgConnection` keyed its lookup on exactly that column, so
 * from that moment it matched nothing and created a SECOND connection carrying
 * the old synthetic label. Stripe attempts moved to the new row while the
 * webhook's provider events stayed on the stamped one; `ProviderEventApplicator`
 * pairs them on `(connection_id, provider_object_id)`, so all seven payments of
 * 2026-08-16 missed and fell through to `LegacyStripeWebhookBridge`.
 *
 * That bridge is fail-open, which is why this cost nothing visible: every yen
 * was collected, every `order_payments` row matched, no test went red, no alert
 * fired. The only symptom was the ledger quietly splitting in two — the exact
 * condition #2893 had just spent a migration repairing.
 *
 * So the two cases below are not one property measured twice. The first is the
 * regression; the second is the property the OLD key was providing and which a
 * fix must not trade away. A guard that only proves the first would green-light
 * keying on something org-blind and break tenant separation instead.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Orchestration\Internal\PaymentGatewayOrchestrationBootstrap;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use Illuminate\Support\Str;

uses()->group('payment');

function stripeBootstrapOrder(): CustomerOrder
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

function stripeBootstrapRef(CustomerOrder $order): OrderRef
{
    return new OrderRef(
        orderId: (string) $order->id,
        organizationId: (string) $order->organization_id,
        brandId: $order->brand_id === null ? null : (string) $order->brand_id,
        branchId: (string) $order->branch_id,
    );
}

it('reuses the org connection after a real acct_ id is stamped on it', function () {
    $order = stripeBootstrapOrder();
    $bootstrap = app(PaymentGatewayOrchestrationBootstrap::class);

    $first = $bootstrap->resolveStripeCustomerWebForOrder(stripeBootstrapRef($order));

    // Exactly what the #2893 migration does to production: replace the synthetic
    // label with the real PSP account. Nothing else about the row changes.
    PaymentGatewayConnection::query()
        ->whereKey($first['connectionId'])
        ->update(['merchant_account_id' => 'acct_1LLeKFCUZcB5vP8B']);

    $second = $bootstrap->resolveStripeCustomerWebForOrder(stripeBootstrapRef($order));

    expect($second['connectionId'])->toBe($first['connectionId'],
        'Đóng dấu định danh PSP thật đã đẻ ra connection THỨ HAI. Đó là #3070: '
        .'attempt đi một đường, provider event đi đường khác, và cầu nối cũ '
        .'fail-open nên tiền vẫn đúng còn sổ thì tách đôi — không gì đỏ.'
    );

    $connections = PaymentGatewayConnection::query()
        ->where('organization_id', (string) $order->organization_id)
        ->count();

    expect($connections)->toBe(1, 'Tổ chức này phải có ĐÚNG một connection Stripe.');
});

it('still gives two different orgs two different connections', function () {
    // This is what keying on the per-org merchant label was buying. Trading it
    // away would swap a split ledger for a shared one — strictly worse, because
    // one tenant would then be settling into another tenant's connection.
    $orderA = stripeBootstrapOrder();
    $orderB = stripeBootstrapOrder();

    $bootstrap = app(PaymentGatewayOrchestrationBootstrap::class);
    $refsA = $bootstrap->resolveStripeCustomerWebForOrder(stripeBootstrapRef($orderA));
    $refsB = $bootstrap->resolveStripeCustomerWebForOrder(stripeBootstrapRef($orderB));

    expect($refsA['connectionId'])->not->toBe($refsB['connectionId'],
        'Hai tổ chức dùng chung một connection: tiền của org này ghi vào cổng của org kia.'
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
