<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Str;

/*
 * #555 M2 — Cash tips physically stay in the drawer (change = tendered −
 * amount − tip), so expected_cash must include face-cash tips. Before the fix
 * cash_sales = SUM(amount) dropped the tip and every shift closed "over" by
 * exactly the cash tips. Card/QR tips never touch the drawer and must NOT be
 * counted.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->till = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $this->session = TillSession::factory()->open()->create([
        'till_id' => $this->till->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 100_000,
    ]);
});

function makeCashTipPayment(string $orgId, string $brandId, string $branchId, string $sessionId, string $methodId, float $amount, float $tip): void
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-T'.random_int(1000, 9999),
        'order_type' => 'dine_in',
        'status' => 'closed',
        'subtotal' => $amount,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $amount,
        'paid_amount' => $amount,
        'total_tip' => $tip,
        'opened_at' => now(),
        'branch_id' => $branchId,
        'brand_id' => $brandId,
        'organization_id' => $orgId,
    ]);

    OrderPayment::factory()->succeeded()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $methodId,
        'amount' => $amount,
        'tip_amount' => $tip,
        'till_session_id' => $sessionId,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $orgId,
        'branch_id' => $branchId,
        'brand_id' => $brandId,
    ]);
}

it('adds cash tips to expected_cash so the drawer reconciles flat', function () {
    $cash = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);

    // 5,000 sale + 800 cash tip → drawer holds 5,800 on top of the float.
    makeCashTipPayment($this->orgId, $this->brand->id, $this->branch->id, $this->session->id, $cash->id, 5_000, 800);

    $recon = app(TillSessionService::class)->reconcile($this->session);

    expect($recon['cash']['cash_sales'])->toBe(5_000.0)
        ->and($recon['cash']['cash_tips'])->toBe(800.0)
        // 100,000 float + 5,000 sale + 800 tip
        ->and($recon['cash']['expected_cash'])->toBe(105_800.0);
});

it('does not count card tips toward expected_cash', function () {
    $card = PaymentMethod::factory()->card()->create(['organization_id' => $this->orgId]);

    // Card sale with a tip — the tip is charged on the card, never in the drawer.
    makeCashTipPayment($this->orgId, $this->brand->id, $this->branch->id, $this->session->id, $card->id, 5_000, 800);

    $recon = app(TillSessionService::class)->reconcile($this->session);

    expect($recon['cash']['cash_tips'])->toBe(0.0)
        // Only the opening float — no cash sale, no cash tip.
        ->and($recon['cash']['expected_cash'])->toBe(100_000.0);
});
