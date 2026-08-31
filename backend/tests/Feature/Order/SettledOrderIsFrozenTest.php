<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * A settled order is FROZEN — asserted at the write point, not inherited.
 *
 * Immutability of a billed order used to hold only TRANSITIVELY: every caller
 * that could re-resolve tax happened to be state-gated somewhere upstream. That
 * is a property of today's call graph, not of the code that does the writing —
 * a new caller inherits nothing, and the failure is silent (an invoice quietly
 * restated months later, discovered at audit).
 *
 * `reResolveOrderLines` is the one operation that genuinely rewrites history:
 * it re-stamps every live line's tax type + rate from the CURRENT chain. On a
 * closed order that rate is what the customer paid, what the 適格請求書 printed
 * and what the Z-report totalled. So the refusal lives there.
 *
 * #2188 — the two former escape hatches (the `orders:backfill-tax-snapshots`
 * command and the BUG-8 lazy re-stamp of a NULL-rate line) were REMOVED with
 * the legacy ruling: unstamped lines cannot exist post-reseed, so nothing may
 * rewrite a settled order at all. An unstamped line found at recompute is
 * dropped from the rate groups with a warning (#2067 pattern), never repaired.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
        'timezone' => 'UTC',
    ]);

    $this->standard = TaxType::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'code' => 'STANDARD', 'rate' => 10, 'is_default' => true,
    ]);

    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'default_tax_type_id' => $this->standard->id,
        'prices_include_tax' => false,
        'currency_code' => 'JPY',
    ]);

    $productType = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'product_type_id' => $productType->id, 'tax_type_id' => $this->standard->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id, 'selling_price' => 1000, 'is_active' => true,
    ]);

    $this->service = app(CustomerOrderService::class);
});

/** An order with one 10% line, billed and closed. */
function billedAndClosedOrder(object $ctx): CustomerOrder
{
    $order = $ctx->service->create([
        'order_type' => 'dine_in', 'status' => 'open',
        'branch_id' => $ctx->branch->id, 'brand_id' => $ctx->brand->id, 'organization_id' => $ctx->orgId,
    ]);
    $ctx->service->addItems($order, ['items' => [[
        'product_sku_id' => $ctx->sku->id, 'quantity' => 1,
    ]]]);

    $order->refresh();
    expect((float) $order->items->first()->tax_rate)->toBe(10.0);

    $order->update(['status' => CustomerOrderStatusEnum::Closed->value, 'closed_at' => now()]);

    return $order->refresh();
}

it('refuses to re-resolve tax on a CLOSED order even when the caller asks directly', function () {
    $order = billedAndClosedOrder($this);

    // The rate changes at the master AFTER the sale — the everyday case: the
    // shop switches a product to the reduced type next week.
    $this->standard->update(['rate' => 8]);

    // A workstation transport reaching a closed order is the realistic route:
    // a register that was offline replays items into an order Cloud already
    // settled. (A patch to the ORDER cannot get here at all: #1099 removed the
    // re-resolve a type flip used to trigger, because the type is not a tax
    // input any more.)
    expect(fn () => $this->service->syncWorkstationItems($order, [[
        'product_sku_id' => (string) $this->sku->id, 'quantity' => 1, 'unit_price' => 1000,
    ]], [1000.0]))->toThrow(HttpException::class);

    $order->refresh();
    expect((float) $order->items->first()->tax_rate)->toBe(
        10.0,
        'the closed order was re-rated to the new master rate — the customer paid 10%',
    );
});

it('names the reason in the refusal instead of failing anonymously', function () {
    $order = billedAndClosedOrder($this);

    try {
        $this->service->syncWorkstationItems($order, [[
            'product_sku_id' => (string) $this->sku->id, 'quantity' => 1, 'unit_price' => 1000,
        ]], [1000.0]);
        $this->fail('a settled order accepted a tax re-resolution');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409)
            ->and($e->getMessage())->toContain('settled order')
            ->and($e->getMessage())->toContain('closed');
    }
});

it('refuses on a VOIDED order too — a void is settled, not editable', function () {
    $order = billedAndClosedOrder($this);
    $order->update(['status' => CustomerOrderStatusEnum::Voided->value]);

    expect(fn () => $this->service->syncWorkstationItems($order->refresh(), [[
        'product_sku_id' => (string) $this->sku->id, 'quantity' => 1, 'unit_price' => 1000,
    ]], [1000.0]))->toThrow(HttpException::class);
});

it('still lets an OPEN order re-resolve — the freeze must not block normal trade', function () {
    $order = $this->service->create([
        'order_type' => 'dine_in', 'status' => 'open',
        'branch_id' => $this->branch->id, 'brand_id' => $this->brand->id, 'organization_id' => $this->orgId,
    ]);
    $this->service->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id, 'quantity' => 1,
    ]]]);

    $this->service->syncWorkstationItems($order->refresh(), [[
        'product_sku_id' => (string) $this->sku->id, 'quantity' => 2, 'unit_price' => 1000,
    ]], [1000.0]);

    expect((float) $order->refresh()->items->first()->tax_rate)->toBe(10.0);
});

it('#2188 — không bao giờ đóng dấu lại một dòng đã chốt, kể cả khi tỉ lệ trên dòng khác hẳn catalog', function () {
    // Ca cũ đặt `tax_rate => null` rồi đòi nó ở lại NULL. #2411 làm cột NOT NULL
    // nên chính bước dựng đó không chạy được nữa — và nó vốn đo bằng một hình
    // dạng mà ruling #2188 tuyên không tồn tại.
    //
    // Bất biến thật thì không đổi: KHÔNG gì được ghi lại thuế của một đơn đã
    // chốt. Đo bằng một tỉ lệ HỢP LỆ nhưng lệch catalog (5% trên đơn mà chuỗi
    // tầng giải ra 10%): re-resolve sẽ kéo nó về 10, đóng băng thì không. Chặt
    // hơn ca cũ — dòng NULL "ở lại NULL" cũng đúng khi engine bỏ qua dòng ấy vì
    // lý do khác.
    $order = billedAndClosedOrder($this);
    $order->items->first()->update(['tax_rate' => 5]);

    $this->service->refreshOrderTotals($order->refresh());

    expect((float) $order->refresh()->items->first()->tax_rate)->toBe(
        5.0,
        'nothing may rewrite tax on a settled order — the lazy re-stamp was removed by #2188',
    );
});
