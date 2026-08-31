<?php

/**
 * Plan-038 audit CRITICAL — GET /api/v1/shops/{shopSlug}/debts must only ever
 * surface debts of the resolved shop's branch. Before the fix the aggregation
 * query carried NO branch/org filter, so any caller who could reach one shop
 * saw every organization's customer names, tax codes and outstanding balances
 * (cross-org PII leak).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create one open on_account debt for a customer at the given branch/org and
 * return the customer.
 */
function seedOpenDebt(string $orgId, Brand $brand, Branch $branch, string $customerName): Customer
{
    $customer = Customer::factory()->create(['last_name' => $customerName]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'status' => 'closed',
    ]);

    $pm = PaymentMethod::factory()->create([
        'code' => 'debt-'.Str::random(4),
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
    ]);
    DB::table('payment_methods')->where('id', $pm->id)->update(['type' => 'on_account']);

    DB::table('order_payments')->insert([
        'id' => (string) Str::uuid(),
        'payment_code' => strtoupper(Str::random(10)),
        'amount' => 125000,
        'tip_amount' => 0,
        // #1822/#1921 — `succeeded`, KHÔNG phải `confirmed`. Từ vựng cũ đã bị
        // xoá cùng `PaymentStatusCompatibility`; `PaymentStatusEnum` không có
        // case `Confirmed` nào, nên một hàng `'confirmed'` là trạng thái BẤT
        // KHẢ mà production không sinh ra được. Fixture cũ ghi nó nên
        // `DebtController` (đã siết về enum) lọc mất và danh sách nợ ra rỗng.
        'status' => 'succeeded',
        'payment_method_id' => $pm->id,
        'customer_order_id' => $order->id,
        'branch_id' => $branch->id,
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $customer;
}

function makeOrgWithShop(string $slug): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $shop = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'slug' => $slug,
        'is_active' => true,
    ]);

    return ['orgId' => $orgId, 'brand' => $brand, 'shop' => $shop];
}

it('only returns debts of the resolved shop, never another organization', function () {
    $a = makeOrgWithShop('debt-org-a');
    $b = makeOrgWithShop('debt-org-b');

    $custA = seedOpenDebt($a['orgId'], $a['brand'], $a['shop'], 'Alice-A');
    $custB = seedOpenDebt($b['orgId'], $b['brand'], $b['shop'], 'Bob-B');

    $userA = User::factory()->create(['console_organization_id' => $a['orgId']]);
    grantOrgAccess($userA, $a['orgId']);

    $response = $this->actingAs($userA)
        ->getJson("/api/v1/shops/{$a['shop']->slug}/debts")
        ->assertOk();

    $customerIds = collect($response->json('data'))->pluck('customer_id');

    // Org A's own debt is present…
    expect($customerIds)->toContain($custA->id)
        // …and org B's debt is NOT leaked across the tenant boundary.
        ->not->toContain($custB->id);

    expect($response->json('data'))->toHaveCount(1);
});

it('scopes even when the caller filters by another orgs customer_id', function () {
    $a = makeOrgWithShop('debt-org-a2');
    $b = makeOrgWithShop('debt-org-b2');

    seedOpenDebt($a['orgId'], $a['brand'], $a['shop'], 'Alice-A');
    $custB = seedOpenDebt($b['orgId'], $b['brand'], $b['shop'], 'Bob-B');

    $userA = User::factory()->create(['console_organization_id' => $a['orgId']]);
    grantOrgAccess($userA, $a['orgId']);

    // Explicitly probing for org B's customer through org A's shop returns nothing.
    $this->actingAs($userA)
        ->getJson("/api/v1/shops/{$a['shop']->slug}/debts?customer_id={$custB->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
