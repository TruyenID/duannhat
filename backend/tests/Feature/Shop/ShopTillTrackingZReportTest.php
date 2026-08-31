<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\ProductSku;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Services\Shop\ShopTillTrackingService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/*
 * Plan 036 — Z-report PDF endpoint
 * (GET /shops/{slug}/till/sessions/{id}/z-report.pdf).
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'till-zreport-shop',
    ]);

    Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'level' => 30]);

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->manager, $this->orgId);

    $this->staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->staff->assignRole('staff', $this->orgId);

    $this->till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 100,
        'is_active' => true,
    ]);
});

it('returns a PDF binary for a settled session', function () {
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $response = $this->actingAs($this->manager)
        ->get("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}/z-report.pdf")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))
        ->toContain("z-report-{$session->session_code}.pdf");
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('rejects open sessions with 422 Z_REPORT_NOT_READY', function () {
    $session = TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}/z-report.pdf")
        ->assertStatus(422)
        ->assertJsonPath('code', 'Z_REPORT_NOT_READY');
});

it('rejects unauthenticated', function () {
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->getJson("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}/z-report.pdf")
        ->assertUnauthorized();
});

it('rejects staff with 403', function () {
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->staff)
        ->get("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}/z-report.pdf")
        ->assertForbidden();
});

it('renders per-rate 課税売上/消費税 rows for a session with mixed-rate orders', function () {
    // plan-043 T4.3 — the Z-report PDF ALWAYS carries the per-rate breakdown
    // (audit document, Decision 8). Prove the rendered HTML groups the
    // session's orders' per-line snapshots by rate.
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
    ]);

    $cash = PaymentMethod::factory()->cash()->create();

    // One takeaway order: bentō ¥1,000 @ 8% (tax ¥80) + beer ¥500 @ 10% (tax ¥50).
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'closed',
        'subtotal' => 1_500,
        'tax_amount' => 130,
        'total_amount' => 1_630,
    ]);

    $bento = ProductSku::factory()->create();
    $beer = ProductSku::factory()->create();

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $bento->id,
        'quantity' => 1,
        'unit_price' => 1_000,
        'subtotal' => 1_000,
        'tax_rate' => 8,
        'tax_amount' => 80,
        'status' => 'served',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $beer->id,
        'quantity' => 1,
        'unit_price' => 500,
        'subtotal' => 500,
        'tax_rate' => 10,
        'tax_amount' => 50,
        'status' => 'served',
    ]);

    // Voided line must NOT show up in the breakdown.
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $beer->id,
        'quantity' => 9,
        'unit_price' => 9_999,
        'subtotal' => 89_991,
        'tax_rate' => 10,
        'tax_amount' => 8_999,
        'status' => 'voided',
    ]);

    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'payment_method_id' => $cash->id,
        'till_session_id' => $session->id,
        'amount' => 1_630,
    ]);

    $payload = app(ShopTillTrackingService::class)->buildZReportPayload($session);

    // Payload carries the derived per-rate breakdown, non-voided only.
    expect($payload['tax_breakdown']['by_rate'])->toHaveCount(2);
    expect($payload['tax_breakdown']['net'])->toBe(1_500.0);
    expect($payload['tax_breakdown']['tax'])->toBe(130.0);
    expect($payload['tax_breakdown']['gross'])->toBe(1_630.0);

    $html = View::make('till.z-report', $payload)->render();

    expect($html)->toContain('消費税内訳 / Consumption Tax by Rate');
    expect($html)->toContain('8%対象');
    expect($html)->toContain('10%対象');
    // ¥1,000 taxable + ¥80 tax @8% ; ¥500 taxable + ¥50 tax @10%.
    expect($html)->toContain('¥1,000');
    expect($html)->toContain('¥80');
    expect($html)->toContain('¥50');
});

it('renders the manager-intervention block for force-abandoned sessions', function () {
    $session = TillSession::factory()->abandoned()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'force_abandoned' => true,
        'force_abandoned_by_id' => $this->manager->id,
        'force_abandon_reason_code' => 'pos_device_failure',
        'force_abandon_reason_detail' => 'Device frozen mid-shift',
    ]);

    $response = $this->actingAs($this->manager)
        ->get("/api/v1/shops/{$this->shop->slug}/till/sessions/{$session->id}/z-report.pdf")
        ->assertOk();

    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

/*
 * #1645 — khối thuế THỨ HAI trong `sales_summary` đã bị xoá.
 *
 * `buildTaxBreakdown()` cũ dựng một bracket duy nhất và lấy thuế suất từ
 * `shop_order_settings.tax_rate` — cột đã bị **DROP** ở plan-043 T6.2. Query
 * builder không ném cho cột thiếu ở đây, nó trả `null`, `?? 0` biến thành
 * `0.0`, và `taxRateLabel(0.0)` trả **`非課税 対象`**.
 *
 * Kết quả: payload của một chứng từ kiểm toán khai TOÀN BỘ doanh thu chịu thuế
 * là **miễn thuế**, trong khi vẫn kèm số thuế khác 0 — tự mâu thuẫn, và im
 * lặng. Không ai đọc khoá đó (template dùng khối per-rate cấp cao nhất; không
 * frontend nào đọc `sales_summary`), nên nó là đầu ra chết mang một lời khai sai.
 */
it('#1645 — sales_summary KHÔNG còn khối thuế thứ hai, và không chỗ nào khai 非課税 khi thuế > 0', function () {
    $session = TillSession::factory()->settled()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
    ]);

    $cash = PaymentMethod::factory()->cash()->create();

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'closed',
        'subtotal' => 1_000,
        'tax_amount' => 100,
        'total_amount' => 1_100,
    ]);

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => ProductSku::factory()->create()->id,
        'quantity' => 1,
        'unit_price' => 1_000,
        'subtotal' => 1_000,
        'tax_rate' => 10,
        'tax_amount' => 100,
        'status' => 'served',
    ]);

    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'payment_method_id' => $cash->id,
        'till_session_id' => $session->id,
        'amount' => 1_100,
    ]);

    $payload = app(ShopTillTrackingService::class)->buildZReportPayload($session);

    // 1. Khoá chết đã đi. Dựng lại nó là dựng lại lời khai sai.
    expect(array_key_exists('tax_breakdown', $payload['sales_summary']))->toBeFalse(
        'sales_summary.tax_breakdown quay lại rồi — nó lấy rate từ một cột KHÔNG TỒN TẠI '
        .'(shop_order_settings.tax_rate, drop ở plan-043 T6.2), nên luôn ra 0.0 ⇒ 非課税.'
    );

    // 2. Khối ĐÚNG vẫn ở đó và vẫn nói đúng suất.
    expect($payload['tax_breakdown']['tax'])->toBe(100.0);
    expect($payload['tax_breakdown']['by_rate'])->toHaveCount(1);
    expect($payload['tax_breakdown']['by_rate'][0]['rate'])->toBe(10.0);

    // 3. Bất biến thật sự đáng giữ, không phụ thuộc hình dạng payload: có thuế
    //    khác 0 thì KHÔNG chỗ nào trong payload được khai là miễn thuế.
    expect($payload['sales_summary']['tax'])->toBeGreaterThan(0);
    expect(json_encode($payload, JSON_UNESCAPED_UNICODE))->not->toContain('非課税');
});
