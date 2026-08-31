<?php

/*
 * Shared bootstrap + real-production-path drivers for the Verify\Invoice suite.
 * Everything here goes over real HTTP routes with real auth and real services —
 * no hand-built end states.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Payment\Orchestration\ValueObjects\OrderRef;
use Illuminate\Support\Str;

if (! function_exists('vinOrderRef')) {
    /**
     * #962 (7b) — `PosReturnInvoiceService::issueForReversal` takes the four
     * order anchors, not the model. Reads the order fresh so a test that broke
     * something on purpose (a deleted branch row) still hands over the real ids.
     */
    function vinOrderRef(string $orderId): OrderRef
    {
        $order = CustomerOrder::findOrFail($orderId);

        return new OrderRef(
            (string) $order->id,
            (string) $order->organization_id,
            $order->brand_id === null ? null : (string) $order->brand_id,
            (string) $order->branch_id,
        );
    }
}

if (! function_exists('vinBootstrap')) {
    /** Org + brand + branch(slug) + manager + open shift + cash method + a ¥1,000 SKU. */
    function vinBootstrap(object $ctx, string $slug = 'vin-shop'): void
    {
        $ctx->orgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $ctx->orgId,
            'console_organization_id' => $ctx->orgId,
        ]);
        $ctx->brand = Brand::factory()->create(['console_organization_id' => $ctx->orgId]);
        $ctx->shop = Branch::factory()->create([
            'console_organization_id' => $ctx->orgId,
            'console_brand_id' => $ctx->brand->console_brand_id,
            'slug' => $slug,
            'is_active' => true,
        ]);

        Warehouse::factory()->create([
            'organization_id' => $ctx->orgId,
            'branch_id' => $ctx->shop->id,
            'is_active' => true,
            'auto_approve_stock_out' => true,
        ]);

        $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
        $ctx->manager = User::factory()->create(['console_organization_id' => $ctx->orgId]);
        $ctx->manager->assignRole($role, $ctx->orgId);
        grantOrgAccess($ctx->manager, $ctx->orgId);

        $ctx->till = Till::factory()->create([
            'branch_id' => $ctx->shop->id,
            'till_code' => 'MAIN',
            'brand_id' => $ctx->brand->id,
            'organization_id' => $ctx->orgId,
        ]);
        $ctx->session = TillSession::factory()->open()->create([
            'till_id' => $ctx->till->id,
            'branch_id' => $ctx->shop->id,
            'brand_id' => $ctx->brand->id,
            'organization_id' => $ctx->orgId,
            'default_currency_code' => 'JPY',
            'opening_float_amount' => 0,
        ]);
        $ctx->till->update(['current_session_id' => $ctx->session->id]);

        $ctx->customer = Customer::factory()->create([
            'organization_id' => $ctx->orgId,
            'brand_id' => $ctx->brand->id,
            'branch_id' => $ctx->shop->id,
        ]);

        $ctx->cash = PaymentMethod::factory()->cash()->create(['organization_id' => $ctx->orgId]);

        $pt = ProductType::factory()->create(['organization_id' => $ctx->orgId, 'brand_id' => $ctx->brand->id]);
        $product = Product::factory()->create([
            'organization_id' => $ctx->orgId,
            'brand_id' => $ctx->brand->id,
            'product_type_id' => $pt->id,
            // #902 — a SKU is only sellable when its product is ACTIVE. The
            // factory default leaves it draft, so every order in this suite was
            // rejected with product_not_sellable before the finding under test
            // could even run.
            'status' => 'active',
        ]);
        $ctx->sku = ProductSku::factory()->create([
            'product_id' => $product->id,
            'selling_price' => 1_000,
            'is_active' => true,
        ]);
    }
}

if (! function_exists('vinPost')) {
    function vinPost(object $ctx, string $url, array $body = [], ?string $slug = null)
    {
        return test()->actingAs($ctx->manager)
            ->withHeader('X-Shop-Slug', $slug ?? $ctx->shop->slug)
            ->postJson($url, $body);
    }
}

if (! function_exists('vinPaidOrder')) {
    /**
     * Real POS HTTP: create order → add item → checkout → cash pay (auto-closes).
     *
     * @return array{0: CustomerOrder, 1: OrderPayment, 2: float}
     */
    function vinPaidOrder(object $ctx, int $qty = 1, float $tip = 0): array
    {
        $orderId = vinPost($ctx, '/api/v1/pos/orders', [
            'order_type' => 'dine_in',
            'customer_id' => $ctx->customer->id,
            'guest_count' => 1,
        ])->assertCreated()->json('data.id');

        vinPost($ctx, "/api/v1/pos/orders/{$orderId}/items", [
            'items' => [['product_sku_id' => $ctx->sku->id, 'quantity' => $qty]],
        ])->assertSuccessful();

        vinPost($ctx, "/api/v1/pos/orders/{$orderId}/checkout")->assertSuccessful();

        $order = CustomerOrder::findOrFail($orderId);
        $total = (float) $order->total_amount;

        $paymentId = vinPost($ctx, "/api/v1/pos/orders/{$orderId}/payments", [
            'payment_method_id' => $ctx->cash->id,
            'amount' => $total,
            'tip_amount' => $tip,
            'tendered_amount' => $total + $tip,
        ])->assertCreated()->json('data.id');

        return [$order->fresh(), OrderPayment::findOrFail($paymentId), $total];
    }
}

if (! function_exists('vinSplitPaidOrder')) {
    /**
     * Two guests, one table (#1225 shape): a 2 × ¥1,000 order settled in two
     * halves, so the order carries two succeeded payments to invoice per guest.
     *
     * @return array{0: CustomerOrder, 1: OrderPayment, 2: OrderPayment, 3: float}
     */
    function vinSplitPaidOrder(object $ctx): array
    {
        $orderId = vinPost($ctx, '/api/v1/pos/orders', [
            'order_type' => 'dine_in',
            'customer_id' => $ctx->customer->id,
            'guest_count' => 2,
        ])->assertCreated()->json('data.id');

        vinPost($ctx, "/api/v1/pos/orders/{$orderId}/items", [
            'items' => [['product_sku_id' => $ctx->sku->id, 'quantity' => 2]],
        ])->assertSuccessful();

        vinPost($ctx, "/api/v1/pos/orders/{$orderId}/checkout")->assertSuccessful();

        $order = CustomerOrder::findOrFail($orderId);
        $total = (float) $order->total_amount;
        $shareA = round($total / 2, 2);

        $pay = fn (float $amount) => vinPost($ctx, "/api/v1/pos/orders/{$orderId}/payments", [
            'payment_method_id' => $ctx->cash->id,
            'amount' => $amount,
            'tendered_amount' => $amount,
        ])->assertCreated()->json('data.id');

        $paymentA = OrderPayment::findOrFail($pay($shareA));
        $paymentB = OrderPayment::findOrFail($pay(round($total - $shareA, 2)));

        return [$order->fresh(), $paymentA, $paymentB, $shareA];
    }
}

if (! function_exists('vinIssueInvoice')) {
}

if (! function_exists('vinIssueSplitInvoice')) {
    /** #1225 — one invoice for ONE guest's payment, with that guest's own buyer. */
}
