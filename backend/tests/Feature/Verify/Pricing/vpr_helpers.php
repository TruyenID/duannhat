<?php

/**
 * Shared fixtures for the Verify/Pricing money-bug suite (E1–E8).
 *
 * Prefix: vpr*. Everything builds REAL rows and drives REAL production
 * services. Nothing about the pricing/payment/closing path is mocked.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use Illuminate\Support\Str;

/**
 * org + brand + branch + shop_order_settings.
 *
 * @param  string|null  $branchCurrency  value written to branches.currency (E3 reads this)
 * @return array{org_id: string, brand: Brand, branch: Branch, setting: ShopOrderSetting}
 */
function vprTenant(
    string $settingCurrency = 'USD',
    ?string $branchCurrency = null,
    string $roundingMode = 'auto',
    float $serviceChargeRate = 0.0,
): array {
    $orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);

    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);

    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
        'currency' => $branchCurrency, // NULL by default — E3's whole point
    ]);

    $setting = ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $orgId,
        'currency_code' => $settingCurrency,
        'split_bill_rounding_mode' => $roundingMode,
        'service_charge_rate' => $serviceChargeRate,
        'service_charge_tax_rate' => 0,
        'prices_include_tax' => false,
    ]);

    return ['org_id' => $orgId, 'brand' => $brand, 'branch' => $branch, 'setting' => $setting];
}

/**
 * A CHECKOUT-status order with explicit money columns (payment paths read these).
 */
function vprOrder(array $t, float $total, float $paid = 0.0, float $subtotal = 0.0): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $t['org_id'],
        'brand_id' => $t['brand']->id,
        'branch_id' => $t['branch']->id,
        'subtotal' => $subtotal ?: $total,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'service_charge' => 0,
        'total_amount' => $total,
        'paid_amount' => $paid,
        'status' => CustomerOrderStatusEnum::Checkout->value,
        'guest_count' => 1,
        'is_tax_included' => false,
    ]);
}

/**
 * Add a line to an order with an explicit snapshot tax_rate (plan-043).
 */
function vprItem(CustomerOrder $order, float $unitPrice, int $qty = 1, ?float $taxRate = 10.0): CustomerOrderItem
{
    return CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => ProductSku::factory()->create()->id,
        'quantity' => $qty,
        'unit_price' => $unitPrice,
        'subtotal' => $unitPrice * $qty,
        'topping_subtotal' => 0,
        'tax_rate' => $taxRate,
        'status' => 'pending',
    ]);
}

function vprCashMethod(string $orgId): PaymentMethod
{
    return PaymentMethod::factory()->cash()->create(['organization_id' => $orgId]);
}

/**
 * A POS actor with org-admin access (SSO auth for the /pos/* namespace).
 */
function vprActor(array $t): User
{
    $user = User::factory()->create(['console_organization_id' => $t['org_id']]);
    grantOrgAccess($user, $t['org_id']);

    return $user;
}

/**
 * Open a cashier shift on the branch's MAIN till — ResolveOpenTillSession
 * (plan-030) hard-locks POST /pos/orders/{id}/payments behind one.
 */
function vprOpenShift(array $t): TillSession
{
    $till = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $t['branch']->id,
        'brand_id' => $t['brand']->id,
        'organization_id' => $t['org_id'],
    ]);
    $session = TillSession::factory()->create([
        'status' => 'open',
        'till_id' => $till->id,
        'branch_id' => $t['branch']->id,
        'brand_id' => $t['brand']->id,
        'organization_id' => $t['org_id'],
    ]);
    $till->update(['current_session_id' => $session->id]);

    return $session;
}
