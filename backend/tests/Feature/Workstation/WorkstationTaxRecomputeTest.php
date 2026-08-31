<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use Illuminate\Support\Str;

/**
 * plan-043 BUG-1/2/7/8 regression — the workstation order-lifecycle replay must
 * recompute per-rate tax through the engine on EVERY mutation (not just
 * addItems), honour the menu-item tax override, snapshot the 総額表示 mode at
 * creation, and re-resolve unstamped legacy lines. Proof fixture: bentō ¥1000
 * (軽減 8% takeaway) + beer ¥500 (標準 10%, alcohol).
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'currency_code' => 'JPY',
        'service_charge_rate' => 0,
        'prices_include_tax' => false,
        'service_charge_tax_rate' => 0,
    ]);

    $this->std = TaxType::factory()->standard()->asDefault()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);
    $this->red = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);
    $this->exempt = TaxType::factory()->exempt()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => Organization::where('console_organization_id', $this->orgId)->value('id'),
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation', 'status' => 'active', 'device_token' => $this->wsToken,
        'organization_id' => Organization::where('console_organization_id', $this->orgId)->value('id'),
        'branch_id' => $this->branch->id,
    ]);
});

/** Build a menu SKU with a specific tax type + alcohol flag; returns [sku, menuProduct]. */
function recomputeSku(float $price, TaxType $type): array
{
    $sku = ProductSku::factory()->create(['selling_price' => $price]);
    $sku->product->update([
        'tax_type_id' => $type->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $mp = MenuProduct::factory()->create(['menu_id' => test()->menu->id, 'product_id' => $sku->product_id, 'is_active' => true]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $mp->id, 'product_sku_id' => $sku->id, 'is_active' => true, 'selling_price' => $price,
    ]);

    return [$sku, $mp];
}

function recomputeOrder(string $type = 'takeaway'): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'WS-'.Str::random(5), 'order_type' => $type, 'status' => 'open',
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0, 'opened_at' => now(),
        'branch_id' => test()->branch->id, 'brand_id' => test()->brand->id, 'organization_id' => test()->orgId,
    ]);
}

function taxRecomputeHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->wsToken];
}

function addLine(CustomerOrder $o, ProductSku $sku, int $qty = 1, ?array $toppings = null): string
{
    $id = (string) Str::uuid();
    $item = ['id' => $id, 'product_sku_id' => $sku->id, 'quantity' => $qty];
    if ($toppings) {
        $item['toppings'] = $toppings;
    }
    test()->withHeaders(taxRecomputeHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items", ['items' => [$item]])
        ->assertOk();

    return $id;
}

// ─────────────────────────────────────────────────────────────────────────

it('BUG-2a → #1099: an order_type flip NEVER changes the tax — a type is one number, context lives on the menu', function () {
    [$bento] = recomputeSku(1000, $this->red);
    [$beer] = recomputeSku(500, $this->std);
    $o = recomputeOrder('takeaway');
    $ib = addLine($o, $bento);
    addLine($o, $beer);
    // bentō REDUCED 8% (¥80) + beer STANDARD 10% (¥50) → tax ¥130, total ¥1,630.
    expect((float) $o->fresh()->tax_amount)->toBe(130.0);

    $this->withHeaders(taxRecomputeHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/update", ['order_type' => 'dine_in'])
        ->assertOk();

    // Same numbers after the flip: the bentō stays on its REDUCED menu line at
    // exactly 8% — nothing silently re-prices money the customer already saw.
    $o->refresh();
    expect((float) CustomerOrderItem::find($ib)->tax_rate)->toBe(8.0)
        ->and((float) $o->tax_amount)->toBe(130.0)
        ->and((float) $o->total_amount)->toBe(1630.0);
});

it('BUG-2b — updateItem quantity recomputes line tax + order totals', function () {
    [$bento] = recomputeSku(1000, $this->red);
    $o = recomputeOrder('takeaway');
    $ib = addLine($o, $bento);

    $this->withHeaders(taxRecomputeHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ib}", ['quantity' => 3])
        ->assertOk();

    expect((float) CustomerOrderItem::find($ib)->tax_amount)->toBe(240.0)
        ->and((float) $o->fresh()->total_amount)->toBe(3240.0);
});

it('BUG-2c — voidItem drops only its rate group and re-derives the total', function () {
    [$bento] = recomputeSku(1000, $this->red);
    [$beer] = recomputeSku(500, $this->std);
    $o = recomputeOrder('takeaway');
    addLine($o, $bento);
    $ir = addLine($o, $beer);

    $this->withHeaders(taxRecomputeHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/items/{$ir}/void", [])
        ->assertOk();

    $o->refresh();
    expect((float) $o->tax_amount)->toBe(80.0)
        ->and((float) $o->total_amount)->toBe(1080.0);
});

it('BUG-2e — apply-coupon splits the discount pro-rata and re-taxes the discounted base', function () {
    [$bento] = recomputeSku(1000, $this->red);
    [$beer] = recomputeSku(500, $this->std);
    $o = recomputeOrder('takeaway');
    addLine($o, $bento, 3); // 3000 @8%
    addLine($o, $beer, 4);  // 2000 @10%
    $coupon = Coupon::factory()->create([
        'code' => 'FLAT500', 'discount_type' => 'fixed', 'discount_value' => 500,
        'organization_id' => $this->orgId,
        'valid_from' => now()->subDay(), 'valid_until' => now()->addYear(),
        'times_used' => 0, 'usage_limit_total' => null,
    ]);

    $this->withHeaders(taxRecomputeHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/apply-coupon", ['code' => 'FLAT500'])
        ->assertOk();

    // discount 500 → 300/200; tax (2700×8%)+(1800×10%) = 216+180 = 396; total 4896.
    $o->refresh();
    expect((float) $o->tax_amount)->toBe(396.0)
        ->and((float) $o->total_amount)->toBe(4896.0);

    $this->withHeaders(taxRecomputeHeaders())
        ->postJson("/api/v1/workstation/orders/{$o->id}/release-coupon", [])
        ->assertOk();
    $o->refresh();
    expect((float) $o->discount_amount)->toBe(0.0)
        ->and((float) $o->tax_amount)->toBe(440.0);
});

it('BUG-7 — addItems honours the MenuProduct tax override', function () {
    [$bento, $mp] = recomputeSku(1000, $this->red);   // product = 軽減 8%
    $mp->update(['tax_type_id' => $this->std->id]);    // menu override = 標準 10%
    $o = recomputeOrder('takeaway');
    $ib = addLine($o, $bento);

    // Override (tier 1) wins over the product's 軽減 → line stamps 10%, not 8%.
    expect((float) CustomerOrderItem::find($ib)->tax_rate)->toBe(10.0);
});

/**
 * #2411 — thay cho ca "BUG-8: dòng legacy tax_rate NULL được re-stamp ở lượt
 * recompute kế".
 *
 * Ca đó dựng bằng tay một trạng thái mà ruling #2188 đã tuyên là không tồn tại
 * (`update(['tax_rate' => null])`), và nhánh lazy re-stamp nó đo đã bị gỡ cùng
 * ruling — nó chỉ còn xanh nhờ `reResolveOrderLines` chạy vì lý do khác. Cột nay
 * là NOT NULL nên chính lượt dựng đó không chạy được nữa. Câu hỏi đúng ở tầng
 * này không phải "dòng NULL có được vá không" mà là "có dòng nào ra đời thiếu
 * ảnh chụp không".
 */
it('#2411 — dòng sinh từ sync máy trạm mang ảnh chụp thuế NGAY tại lượt INSERT', function () {
    [$bento] = recomputeSku(1000, $this->red);
    $o = recomputeOrder('takeaway');

    // Chụp thuộc tính ở `creating` — seam giữa đường ghi và DB. Đọc lại dòng sau
    // khi hàm chạy xong KHÔNG phân biệt được "đóng dấu lúc sinh" với "sinh trống
    // rồi UPDATE ngay sau đó", mà đúng cái khác biệt ấy là thứ cột NOT NULL thấy
    // — và là lý do bản vá trước phải revert.
    $bornWith = [];
    CustomerOrderItem::creating(function (CustomerOrderItem $item) use (&$bornWith): void {
        $bornWith[] = $item->getAttributes();
    });

    $ib = addLine($o, $bento);

    expect($bornWith)->toHaveCount(1)
        ->and($bornWith[0])->toHaveKey('tax_rate')
        ->and((float) $bornWith[0]['tax_rate'])->toBe(8.0)
        ->and((float) CustomerOrderItem::find($ib)->tax_rate)->toBe(8.0)
        ->and((float) $o->fresh()->tax_amount)->toBe(80.0);
});

it('BUG-1 — the order snapshots the branch prices_include_tax at creation', function () {
    ShopOrderSetting::where('branch_id', $this->branch->id)->update(['prices_include_tax' => true]);
    [$bento] = recomputeSku(1080, $this->red);  // gross ¥1080 @8% → 内税 ¥80
    // Create through the workstation store endpoint so the stamp path runs.
    $resp = $this->withHeaders(taxRecomputeHeaders())
        ->postJson('/api/v1/workstation/orders', ['order_type' => 'takeaway'])
        ->assertCreated();
    $orderId = $resp->json('data.id');
    $o = CustomerOrder::find($orderId);
    expect((bool) $o->is_tax_included)->toBeTrue();

    addLine($o, $bento);
    $o->refresh();
    // included mode: total = gross 1080, extracted tax 80.
    expect((float) $o->total_amount)->toBe(1080.0)
        ->and((float) $o->tax_amount)->toBe(80.0);
});

it('BUG-2h — per-line tax_amount snapshots sum to the once-per-group order tax (¥333×3 @8% = 80, not 81)', function () {
    // 3 distinct reduced-rate lines of ¥333 in one takeaway order → one 8% group of 999.
    [$a] = recomputeSku(333, $this->red);
    [$b] = recomputeSku(333, $this->red);
    [$c] = recomputeSku(333, $this->red);
    $o = recomputeOrder('takeaway');
    $ia = addLine($o, $a);
    $ib = addLine($o, $b);
    $ic = addLine($o, $c);

    $o->refresh();
    // Order tax rounds ONCE per group: round(999 × 0.08 = 79.92) = 80.
    expect((float) $o->tax_amount)->toBe(80.0);

    $lineTaxes = CustomerOrderItem::whereIn('id', [$ia, $ib, $ic])
        ->pluck('tax_amount')->map(fn ($v) => (float) $v);

    // Per-line snapshots allocated by largest remainder → 27 + 27 + 26; their
    // SUM reconciles to the order tax and is NEVER the per-line-rounded 81.
    expect($lineTaxes->sum())->toBe(80.0)
        ->and($lineTaxes->sum())->not->toBe(81.0)
        ->and($lineTaxes->sort()->values()->all())->toBe([26.0, 27.0, 27.0]);
});
