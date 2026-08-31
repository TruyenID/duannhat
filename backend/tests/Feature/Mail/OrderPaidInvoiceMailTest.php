<?php

use App\Mail\OrderPaidInvoiceMail;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Services\Customer\OrderPricingCalculator;
use Illuminate\Support\Str;

/*
 * plan-043 T4.5 — the OrderPaidInvoiceMail body + attached PDF must render
 * per-rate consumption-tax blocks (8%対象 / 10%対象), derived from the order's
 * immutable per-line snapshots via OrderPricingCalculator (NOT recomputed).
 */

function makePaidMixedRateOrder(): CustomerOrder
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
    ]);

    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'currency_code' => 'JPY',
        'service_charge_rate' => 0,
        'prices_include_tax' => false,
    ]);

    // Takeaway proof-case: bentō ¥1,000 @ 8% + beer ¥500 @ 10%.
    $order = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'status' => 'closed',
        'customer_locale' => 'ja',
        'is_tax_included' => false,
        'subtotal' => 1_500,
        'discount_amount' => 0,
        'service_charge' => 0,
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
        'topping_subtotal' => 0,
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
        'topping_subtotal' => 0,
        'tax_rate' => 10,
        'tax_amount' => 50,
        'status' => 'served',
    ]);

    return $order->fresh(['items', 'branch']);
}

it('renders per-rate blocks in the OrderPaidInvoiceMail body', function () {
    $order = makePaidMixedRateOrder();

    $html = (new OrderPaidInvoiceMail($order))->render();

    expect($html)->toContain('8%対象');
    expect($html)->toContain('10%対象');
    // per-rate tax figures from the frozen snapshots.
    expect($html)->toContain('80');
    expect($html)->toContain('50');
});

it('renders per-rate blocks in the attached invoice PDF blade', function () {
    $order = makePaidMixedRateOrder();

    // The mailer sets the customer's locale (ja here) before rendering.
    app()->setLocale('ja');

    // Render the PDF Blade to HTML (same view + view-data the mailer attaches)
    // so we can assert on the markup without decoding the PDF binary.
    $calculator = app(OrderPricingCalculator::class);
    $setting = ShopOrderSetting::where('branch_id', $order->branch_id)->first();
    $taxGroups = $calculator->forOrder($order, $setting)->groupsToArray();

    $html = view('emails.invoice_pdf', [
        'order' => $order,
        'taxGroups' => $taxGroups,
        'pricesIncludeTax' => false,
    ])->render();

    expect($html)->toContain('8%対象');
    expect($html)->toContain('10%対象');

    // And the mailer actually produces a PDF attachment binary.
    $attachments = (new OrderPaidInvoiceMail($order))->attachments();
    expect($attachments)->toHaveCount(1);
});
