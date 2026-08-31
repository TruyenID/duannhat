<?php

/**
 * #3084 — hai cột quyền sở hữu không được BỊA.
 *
 * `brand_owner_org_unit_id` / `operator_org_unit_id` là câu trả lời cho *"tiền
 * này thuộc về ai về mặt pháp lý"*, và schema khai chúng đến từ Identity. Hai
 * bootstrap customer-web từng ghi `Str::uuid()` vào đó — không phải ô trống mà
 * là một câu trả lời SAI trông y hệt dữ liệu thật.
 *
 * Bài này ghim **tính TẤT ĐỊNH**, không ghim giá trị cụ thể. Giá trị đổi được
 * (nó là sentinel, không phải hợp đồng với ai); thứ không được đổi là: hai lần
 * dựng connection cho hai tenant khác nhau phải cho cùng một giá trị, vì đó là
 * điều kiện để tra lại được, để mang được khoá, và để ngày Platform lên nguồn
 * thật thì tìm ra đúng những hàng cần phân giải lại.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Orchestration\Internal\PaymentGatewayOrchestrationBootstrap;
use App\Services\Payment\Orchestration\Internal\PayPayCustomerWebBootstrap;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use App\Services\Payment\Policy\UnresolvedOwnership;
use App\Services\Payment\ProviderEvent\LegacyGlobalStripeConnection;
use Illuminate\Support\Str;

uses()->group('payment');

beforeEach(function () {
    config([
        'services.paypay.api_key' => 'a_key',
        'services.paypay.api_secret' => 'a_secret',
        'services.paypay.merchant_id' => '991602796635988897',
        'services.paypay.environment' => 'sandbox',
    ]);
});

function ownershipFixtureRef(): OrderRef
{
    $consoleOrganizationId = (string) Str::uuid();

    $organization = Organization::factory()->create(['console_organization_id' => $consoleOrganizationId]);
    $brand = Brand::factory()->create(['console_organization_id' => $consoleOrganizationId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $brand->console_brand_id,
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => 'open',
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);

    return new OrderRef(
        orderId: (string) $order->id,
        organizationId: (string) $order->organization_id,
        brandId: (string) $order->brand_id,
        branchId: (string) $order->branch_id,
    );
}

it('bootstrap Stripe đóng dấu CHƯA PHÂN GIẢI, không bịa một UUID mới', function () {
    $refs = app(PaymentGatewayOrchestrationBootstrap::class)
        ->resolveStripeCustomerWebForOrder(ownershipFixtureRef());

    $connection = PaymentGatewayConnection::query()->findOrFail($refs['connectionId']);

    expect(UnresolvedOwnership::marks(
        (string) $connection->brand_owner_org_unit_id,
        (string) $connection->operator_org_unit_id,
    ))->toBeTrue(
        'Connection mang một giá trị quyền sở hữu KHÔNG phải sentinel. Đường '
        .'customer-web không biết ai sở hữu — nguồn là Identity, và nguồn đọc '
        .'còn chưa có. Ghi một UUID vào đó là bịa câu trả lời cho câu hỏi tiền '
        .'thuộc về ai.'
    );
});

it('bootstrap PayPay cũng vậy — hai đường, một luật', function () {
    // Cùng bài học #2860: test viết cho class này KHÔNG nói gì về class kia, kể
    // cả khi hai class có cùng chữ ký và cùng lỗi.
    $refs = app(PayPayCustomerWebBootstrap::class)->resolveForOrder(ownershipFixtureRef());

    $connection = PaymentGatewayConnection::query()->findOrFail($refs['connectionId']);

    expect(UnresolvedOwnership::marks(
        (string) $connection->brand_owner_org_unit_id,
        (string) $connection->operator_org_unit_id,
    ))->toBeTrue();
});

it('hai tenant khác nhau nhận CÙNG dấu — đó là điều kiện để tra lại được', function () {
    // Đây mới là tính chất bài này bảo vệ. Giá trị cụ thể đổi được; tính tất
    // định thì không: `WHERE operator_org_unit_id = <sentinel>` phải liệt kê
    // được MỌI hàng chưa phân giải, vào ngày Platform công bố endpoint.
    //
    // Với `Str::uuid()` thì câu truy vấn đó không tồn tại — 4 hàng trên
    // production hôm nay mang 4 giá trị khác nhau, không tra ngược được về đâu.
    $bootstrap = app(PaymentGatewayOrchestrationBootstrap::class);

    $a = PaymentGatewayConnection::query()->findOrFail(
        $bootstrap->resolveStripeCustomerWebForOrder(ownershipFixtureRef())['connectionId']
    );
    $b = PaymentGatewayConnection::query()->findOrFail(
        $bootstrap->resolveStripeCustomerWebForOrder(ownershipFixtureRef())['connectionId']
    );

    expect((string) $a->operator_org_unit_id)->toBe((string) $b->operator_org_unit_id,
        'Hai connection nhận hai giá trị khác nhau ⇒ không câu SQL nào tìm lại được chúng.'
    )
        ->and((string) $a->brand_owner_org_unit_id)->toBe((string) $b->brand_owner_org_unit_id)
        ->and((string) $a->id)->not->toBe((string) $b->id, 'Fixture phải dựng HAI tenant khác nhau, nếu không bài này đo rỗng.');
});

it('sentinel không trùng với hàng tổng hợp legacy', function () {
    // Hai khái niệm khác nhau ở hai chỗ khác nhau: `LegacyGlobalStripeConnection`
    // là một CONNECTION tổng hợp đã ngưng dùng; đây là dấu trên một connection
    // THẬT còn đang chạy. Trùng giá trị là hai thứ đó lẫn vào nhau khi ai đó grep.
    expect(UnresolvedOwnership::BRAND_OWNER_ORG_UNIT_ID)
        ->not->toBe(LegacyGlobalStripeConnection::BRAND_OWNER_ORG_UNIT_ID)
        ->and(UnresolvedOwnership::OPERATOR_ORG_UNIT_ID)
        ->not->toBe(LegacyGlobalStripeConnection::OPERATOR_ORG_UNIT_ID);
});
