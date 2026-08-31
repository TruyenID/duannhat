<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\TaxType;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Models\VoidReason;
use App\Models\Zone;
use App\Services\Customer\CustomerOrderService;
use App\Services\Pos\TillSessionService;
use App\Services\Shop\EffectiveOrderPolicyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'settings-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $pt = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);

    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 500,
        'is_active' => true,
    ]);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);
});

// =========================================================================
//  GET settings
// =========================================================================

it('returns order settings with default null (pending)', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.default_order_item_status', null)
        ->assertJsonPath('data.enable_quick_order', false)
        ->assertJsonPath('data.available_statuses.0.value', 'pending')
        ->assertJsonPath('data.available_statuses.1.value', 'preparing')
        ->assertJsonPath('data.available_statuses.2.value', 'ready')
        ->assertJsonPath('data.available_statuses.3.value', 'served');
});

it('returns the three consumption-tax fields in the GET payload (BUG-3 regression)', function () {
    // These were writable via PATCH but absent from the read payload, so
    // admin-web's Settings Tax section reset to off/0 after a reload. They must
    // round-trip. Also assert the dropped legacy `tax_rate` key is gone (BUG-6).
    $type = TaxType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        [
            'organization_id' => $this->orgId,
            'default_tax_type_id' => $type->id,
            'prices_include_tax' => true,
            'service_charge_tax_rate' => 7,
        ],
    );

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.default_tax_type_id', $type->id)
        ->assertJsonPath('data.prices_include_tax', true)
        ->assertJsonPath('data.service_charge_tax_rate', '7.00')
        ->assertJsonMissingPath('data.tax_rate');
});

it('returns enable_quick_order=true when branch has it enabled', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['enable_quick_order' => true, 'organization_id' => $this->orgId],
    );

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.enable_quick_order', true)
        ->assertJsonPath('data.default_order_item_status', null);
});

// =========================================================================
//  PATCH settings
// =========================================================================

it('updates default_order_item_status to preparing', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'default_order_item_status' => 'preparing',
        ])
        ->assertOk()
        ->assertJsonPath('data.default_order_item_status', 'preparing');

    $setting = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($setting->default_order_item_status)->toBe('preparing');
});

it('resets default_order_item_status to null (back to system default)', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['default_order_item_status' => 'ready', 'organization_id' => $this->orgId],
    );

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'default_order_item_status' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.default_order_item_status', null);

    $setting = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($setting->default_order_item_status)->toBeNull();
});

it('rejects invalid status value', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'default_order_item_status' => 'invalid_status',
        ])
        ->assertUnprocessable();
});

it('rejects voided as default status', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'default_order_item_status' => 'voided',
        ])
        ->assertUnprocessable();
});

// =========================================================================
//  PATCH enable_quick_order (BR-SOS04)
// =========================================================================

it('toggles enable_quick_order to true and persists', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'enable_quick_order' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.enable_quick_order', true);

    $setting = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($setting->enable_quick_order)->toBeTrue();
});

it('round-trips allow_item_edit_any_status via PATCH + GET', function () {
    // Defaults to false in the GET payload when the branch has no row / value.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.allow_item_edit_any_status', false);

    // PATCH true → persisted + echoed.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'allow_item_edit_any_status' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.allow_item_edit_any_status', true);

    expect(ShopOrderSetting::where('branch_id', $this->shop->id)->value('allow_item_edit_any_status'))->toBeTrue();

    // GET reflects the saved value (round-trip closed).
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.allow_item_edit_any_status', true);
});

it('round-trips print_table_paid via PATCH + GET (#1306 — the switch the workstation read but nobody could set)', function () {
    // Defaults to true in the GET payload, matching the fallback auto_print.go has
    // always used, so a shop that never touches this keeps printing as before.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.print_table_paid', true);

    // PATCH false → persisted + echoed. Asserting the JSON alone would NOT catch a
    // shadowed $fillable (the controller can echo back request input it never
    // saved), which is exactly how this class of bug hides — so assert the DB row.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'print_table_paid' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.print_table_paid', false);

    expect(ShopOrderSetting::where('branch_id', $this->shop->id)->value('print_table_paid'))->toBeFalse();

    // GET reflects the saved value (round-trip closed) — the OFF branch is now
    // reachable end to end: admin → Cloud column → feed → shop_settings → Go.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.print_table_paid', false);
});

it('round-trips counter_pay_enabled + counter_pay_show_qr via PATCH + GET (#2806)', function () {
    // #3206 — hai công tắc nay trả lời KHÁC NHAU cho chi nhánh chưa có hàng:
    // kênh vẫn được MỜI, còn QR thì TẮT (chủ dự án chốt 2026-08-18).
    //
    // Ghi chú cũ ở đây nói "cả hai mặc định TRUE nên phơi công tắc ra không đổi
    // gì cho ai" — điều đó đúng lúc #2806 ship, và chính nó là lý do yêu cầu
    // "bỏ QR" chưa bao giờ đạt: cơ chế đã dựng xong mà mặc định vẫn bật.
    //
    // Mặc định của `counter_pay_enabled` KHÔNG đổi: đó là câu trả lời chủ dự án
    // đã yêu cầu sau ba lần lật, và giấu QR với gỡ cả kênh là hai quyết định
    // khác nhau — lý do #2806 tách làm hai cột.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.counter_pay_enabled', true)
        ->assertJsonPath('data.counter_pay_show_qr', false);

    // Asserting the JSON alone would NOT catch a shadowed $fillable — the
    // controller echoes back request input it may never have saved, which is
    // exactly how that class of bug hides. So assert the DB row too.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'counter_pay_enabled' => false,
            'counter_pay_show_qr' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.counter_pay_enabled', false)
        ->assertJsonPath('data.counter_pay_show_qr', false);

    $row = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($row->counter_pay_enabled)->toBeFalse();
    expect($row->counter_pay_show_qr)->toBeFalse();

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.counter_pay_enabled', false)
        ->assertJsonPath('data.counter_pay_show_qr', false);
});

it('turns the QR off while KEEPING the counter channel on (#2806 — two switches, not one)', function () {
    // The whole point of two columns. A shop that wants staff to read the
    // `#xxxx` order code instead of scanning must not lose the channel doing so.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'counter_pay_show_qr' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.counter_pay_enabled', true)
        ->assertJsonPath('data.counter_pay_show_qr', false);

    $row = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($row->counter_pay_enabled)->toBeTrue();
    expect($row->counter_pay_show_qr)->toBeFalse();
});

it('rejects a non-boolean counter-pay switch', function () {
    // #2622 — validate() STRIPS every key it has no rule for, so a missing rule
    // does not 422, it silently drops the write while every layer looks fine.
    // A 422 here is therefore evidence the rule exists at all.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'counter_pay_enabled' => 'sometimes',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['counter_pay_enabled']);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'counter_pay_show_qr' => 'maybe',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['counter_pay_show_qr']);
});

it('rejects a non-boolean print_table_paid', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'print_table_paid' => 'yes-please',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['print_table_paid']);
});

it('round-trips print_label_locale via PATCH + GET (regression: missing from $fillable silently dropped the write)', function () {
    // Defaults to null (→ admin-web shows "Theo mặc định chi nhánh").
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.print_label_locale', null);

    // PATCH "vi" → persisted + echoed. Asserting the JSON response alone is not
    // enough to catch a shadowed $fillable (the controller could echo back the
    // request input without having actually saved it) — assert the DB row too.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'print_label_locale' => 'vi',
        ])
        ->assertOk()
        ->assertJsonPath('data.print_label_locale', 'vi');

    expect(ShopOrderSetting::where('branch_id', $this->shop->id)->value('print_label_locale'))->toBe('vi');

    // GET reflects the saved value after a fresh request (round-trip closed —
    // this is what a page reload in admin-web does).
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.print_label_locale', 'vi');

    // PATCH null clears the pin back to "Theo mặc định chi nhánh".
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'print_label_locale' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.print_label_locale', null);

    expect(ShopOrderSetting::where('branch_id', $this->shop->id)->value('print_label_locale'))->toBeNull();
});

it('rejects an unsupported print_label_locale value', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'print_label_locale' => 'fr',
        ])
        ->assertStatus(422);
});

it('toggles enable_quick_order back to false', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['enable_quick_order' => true, 'organization_id' => $this->orgId],
    );

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'enable_quick_order' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.enable_quick_order', false);

    $setting = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($setting->enable_quick_order)->toBeFalse();
});

it('leaves enable_quick_order unchanged when PATCH omits the key', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['enable_quick_order' => true, 'organization_id' => $this->orgId],
    );

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'default_order_item_status' => 'preparing',
        ])
        ->assertOk()
        ->assertJsonPath('data.enable_quick_order', true)
        ->assertJsonPath('data.default_order_item_status', 'preparing');

    $setting = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($setting->enable_quick_order)->toBeTrue();
    expect($setting->default_order_item_status)->toBe('preparing');
});

it('rejects non-boolean enable_quick_order', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'enable_quick_order' => 'maybe',
        ])
        ->assertUnprocessable();
});

// =========================================================================
//  PATCH currency_code (BR-SOS06) — mid-shift guard
// =========================================================================

it('blocks currency change when any till at the branch has an open shift', function () {
    // Seed initial currency on the shop.
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'organization_id' => $this->orgId],
    );

    // Create a Till + open TillSession to simulate a cashier mid-shift.
    $till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);
    $session = TillSession::create([
        'session_code' => 'SESS-001',
        'status' => 'open',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 50000,
        'opened_at' => now(),
        'opened_by_id' => $this->manager->id,
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $till->update(['current_session_id' => $session->id]);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'currency_code' => 'VND',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT');

    // Setting must remain unchanged.
    expect(ShopOrderSetting::where('branch_id', $this->shop->id)->value('currency_code'))
        ->toBe('JPY');
});

it('allows currency change after the open shift is closed', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'organization_id' => $this->orgId],
    );

    $till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);
    // No current_session_id → no open shift.

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'currency_code' => 'VND',
        ])
        ->assertOk()
        ->assertJsonPath('data.currency_code', 'VND');

    expect(ShopOrderSetting::where('branch_id', $this->shop->id)->value('currency_code'))
        ->toBe('VND');
});

it('blocks currency change while a chain awaits continuation after a handover (plan-046 R8)', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'organization_id' => $this->orgId],
    );

    $till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);
    // A handover SETTLES the shift (clears current_session_id) but keeps the chain
    // open — R8 must still block a currency flip until the chain's final close.
    TillSession::create([
        'session_code' => 'SESS-HANDOVER',
        'status' => 'settled',
        'settlement_kind' => 'handover',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 50000,
        'opened_at' => now()->subHour(),
        'closed_at' => now(),
        'opened_by_id' => $this->manager->id,
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'chain_id' => (string) Str::uuid(),
        'chain_sequence' => 1,
    ]);
    // current_session_id is null (handover cleared it) — the plain open-shift
    // guard would MISS this; branchHasOpenChain closes the window.

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'currency_code' => 'VND',
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT');

    expect(ShopOrderSetting::where('branch_id', $this->shop->id)->value('currency_code'))
        ->toBe('JPY');
});

it('allows currency change after a final close ends the chain (plan-046 R8 boundary)', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'organization_id' => $this->orgId],
    );

    $till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);
    // A FINAL close ends the chain — settlement_kind=final does NOT block.
    TillSession::create([
        'session_code' => 'SESS-FINAL',
        'status' => 'settled',
        'settlement_kind' => 'final',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 50000,
        'opened_at' => now()->subHour(),
        'closed_at' => now(),
        'opened_by_id' => $this->manager->id,
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'chain_id' => (string) Str::uuid(),
        'chain_sequence' => 1,
    ]);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'currency_code' => 'VND',
        ])
        ->assertOk()
        ->assertJsonPath('data.currency_code', 'VND');
});

it('allows other field updates while a shift is open (only currency is blocked)', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'organization_id' => $this->orgId],
    );

    $till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);
    $session = TillSession::create([
        'session_code' => 'SESS-002',
        'status' => 'open',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 0,
        'opened_at' => now(),
        'opened_by_id' => $this->manager->id,
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $till->update(['current_session_id' => $session->id]);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'enable_quick_order' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.enable_quick_order', true);
});

it('allows PATCH with the same currency (no-op) while a shift is open', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'organization_id' => $this->orgId],
    );

    $till = Till::create([
        'till_code' => 'MAIN',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);
    $session = TillSession::create([
        'session_code' => 'SESS-003',
        'status' => 'open',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 0,
        'opened_at' => now(),
        'opened_by_id' => $this->manager->id,
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $till->update(['current_session_id' => $session->id]);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'currency_code' => 'JPY',
        ])
        ->assertOk();
});

// =========================================================================
//  Integration: setting affects order item creation
// =========================================================================

it('creates order items with default status=preparing when shop is configured', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['default_order_item_status' => 'preparing', 'organization_id' => $this->orgId],
    );

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'order_type' => 'takeaway',
        ])
        ->assertCreated();

    $order = CustomerOrder::latest()->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $item = $order->items()->first();

    expect($item->status->value ?? $item->status)->toBe('preparing');
});

it('creates order items with default status=served when shop is configured (self-service)', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['default_order_item_status' => 'served', 'organization_id' => $this->orgId],
    );

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'order_type' => 'takeaway',
        ])
        ->assertCreated();

    $order = CustomerOrder::latest()->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 2],
            ],
        ])
        ->assertCreated();

    $item = $order->items()->first();

    expect($item->status->value ?? $item->status)->toBe('served');
    expect($item->served_at)->not->toBeNull();
});

it('creates order items with default status=pending when shop has no setting', function () {
    // default_order_item_status is null (not configured)
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'order_type' => 'takeaway',
        ])
        ->assertCreated();

    $order = CustomerOrder::latest()->first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    $item = $order->items()->first();

    expect($item->status->value ?? $item->status)->toBe('pending');
});

// =========================================================================
//  PATCH split_bill_rounding_mode (BR-SOS07)
// =========================================================================

it('updates split_bill_rounding_mode to auto and persists', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'split_bill_rounding_mode' => 'auto',
        ])
        ->assertOk()
        ->assertJsonPath('data.split_bill_rounding_mode', 'auto');

    $setting = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($setting->split_bill_rounding_mode)->toBe('auto');
});

it('accepts each valid rounding mode', function (string $mode) {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'split_bill_rounding_mode' => $mode,
        ])
        ->assertOk()
        ->assertJsonPath('data.split_bill_rounding_mode', $mode);

    $setting = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($setting->split_bill_rounding_mode)->toBe($mode);
})->with(['integer', 'two_decimals', 'none']);

it('rejects invalid split_bill_rounding_mode', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'split_bill_rounding_mode' => 'banana',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('split_bill_rounding_mode');
});

it('leaves split_bill_rounding_mode unchanged when PATCH omits the key', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['split_bill_rounding_mode' => 'integer', 'organization_id' => $this->orgId],
    );

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'default_order_item_status' => 'preparing',
        ])
        ->assertOk()
        ->assertJsonPath('data.split_bill_rounding_mode', 'integer');

    $setting = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($setting->split_bill_rounding_mode)->toBe('integer');
});

it('defaults split_bill_rounding_mode to auto on GET when no setting row', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.split_bill_rounding_mode', 'auto');
});

// =========================================================================
//  Customer branch endpoint exposes split_bill_rounding_mode
// =========================================================================

it('exposes split_bill_rounding_mode on customer branches endpoint', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['split_bill_rounding_mode' => 'two_decimals', 'organization_id' => $this->orgId],
    );

    $this->getJson('/api/v1/customer/branches')
        ->assertOk()
        ->assertJsonFragment([
            'slug' => 'settings-shop',
            'split_bill_rounding_mode' => 'two_decimals',
        ]);
});

it('defaults split_bill_rounding_mode to auto on customer branches when no setting', function () {
    // No ShopOrderSetting row for this branch
    $this->getJson('/api/v1/customer/branches')
        ->assertOk()
        ->assertJsonFragment([
            'slug' => 'settings-shop',
            'split_bill_rounding_mode' => 'auto',
        ]);
});

// =========================================================================
//  Integration: setting affects order item creation
// =========================================================================

it('addItem also respects shop default item status', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['default_order_item_status' => 'ready', 'organization_id' => $this->orgId],
    );

    // Create order first (header only)
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'order_type' => 'takeaway',
        ])
        ->assertCreated();

    $order = CustomerOrder::latest()->first();

    // Add items via addItems endpoint
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ])
        ->assertCreated();

    // Add another item
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 2],
            ],
        ])
        ->assertCreated();

    $newItem = $order->items()->latest('id')->first();
    expect($newItem->status->value ?? $newItem->status)->toBe('ready');
});

// =========================================================================
//  Plan 032 T6.5 — Cross-plan currency-guard release paths.
//  After plan-032's exit doors flip till.current_session_id to NULL, the
//  plan-031 currency guard should release.
// =========================================================================

it('allows currency change after force-abandon releases the till', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'organization_id' => $this->orgId],
    );

    $till = Till::create([
        'till_code' => 'MAIN', 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId, 'default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0,
    ]);
    $session = TillSession::create([
        'session_code' => 'SESS-FA-T65', 'status' => 'open',
        'business_date' => now()->toDateString(), 'default_currency_code' => 'JPY',
        'opening_float_amount' => 0, 'opened_at' => now(), 'opened_by_id' => $this->manager->id,
        'till_id' => $till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $till->update(['current_session_id' => $session->id]);

    // First confirm guard blocks.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['currency_code' => 'VND'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT');

    // Force-abandon directly via service (manager-driven exit door).
    app(TillSessionService::class)->forceAbandon(
        $session, 'cashier_forgot_to_close', null, $this->manager,
    );

    // Now currency PATCH succeeds.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['currency_code' => 'VND'])
        ->assertOk();
    expect(ShopOrderSetting::where('branch_id', $this->shop->id)->value('currency_code'))->toBe('VND');
});

it('releases the SESSION\'s own till on force-abandon, not just MAIN (multi-till regression)', function () {
    // Bug: close/abandon/forceAbandon/expire/manualSettle locked the branch's
    // default MAIN till, so a shift opened on a non-MAIN till (REG1) was
    // abandoned on the session but REG1.current_session_id was never cleared.
    // The stale pointer then wedged the currency + tax-mode guards ("0 open
    // shifts" yet 409) and blocked reopening a shift on REG1.
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'prices_include_tax' => false, 'organization_id' => $this->orgId],
    );
    // A MAIN till exists but is idle; the shift lives on REG1.
    Till::create([
        'till_code' => 'MAIN', 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId, 'default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0,
    ]);
    $reg1 = Till::create([
        'till_code' => 'REG1', 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId, 'default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0,
    ]);
    $session = TillSession::create([
        'session_code' => 'SESS-REG1', 'status' => 'open',
        'business_date' => now()->toDateString(), 'default_currency_code' => 'JPY',
        'opening_float_amount' => 0, 'opened_at' => now(), 'opened_by_id' => $this->manager->id,
        'till_id' => $reg1->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $reg1->update(['current_session_id' => $session->id]);

    app(TillSessionService::class)->forceAbandon(
        $session, 'cashier_forgot_to_close', null, $this->manager,
    );

    // REG1 (the session's OWN till) is released — not left dangling.
    expect($reg1->fresh()->current_session_id)->toBeNull()
        ->and($session->fresh()->status->value)->toBe('abandoned');

    // Both guards release (no genuinely-open shift remains).
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['currency_code' => 'VND'])
        ->assertOk();
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['prices_include_tax' => true])
        ->assertOk();
});

it('the shift guard ignores a stale current_session_id pointing to a terminal session', function () {
    // Defensive: even if a till somehow still points at an abandoned/settled
    // session (legacy data from before the release fix), the guard keys off the
    // session STATUS, so an operator seeing "0 open shifts" is not blocked.
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'prices_include_tax' => false, 'organization_id' => $this->orgId],
    );
    $till = Till::create([
        'till_code' => 'REG9', 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId, 'default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0,
    ]);
    $session = TillSession::create([
        'session_code' => 'SESS-STALE', 'status' => 'abandoned',
        'business_date' => now()->toDateString(), 'default_currency_code' => 'JPY',
        'opening_float_amount' => 0, 'opened_at' => now(), 'abandoned_at' => now(),
        'till_id' => $till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    // Stale pointer: terminal session still referenced (the old bug's residue).
    $till->update(['current_session_id' => $session->id]);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['prices_include_tax' => true])
        ->assertOk();
});

it('the shift guard ignores an orphaned open/closing session no till references', function () {
    // A shift can end up status=closing while its till's current_session_id was
    // already released (the plan-032 reaper handles the stuck record). The till
    // dashboard shows "0 quầy đang mở" for it, so the guard must agree: an
    // active-status session that NO till points to does not occupy a till and
    // must NOT block a tax-mode / currency change.
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'prices_include_tax' => false, 'organization_id' => $this->orgId],
    );
    $till = Till::create([
        'till_code' => 'REG7', 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId, 'default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0,
        'current_session_id' => null, // till is FREE
    ]);
    TillSession::create([
        'session_code' => 'SESS-ORPHAN', 'status' => 'closing', // stuck record, no till points here
        'business_date' => now()->toDateString(), 'default_currency_code' => 'JPY',
        'opening_float_amount' => 0, 'opened_at' => now(),
        'till_id' => $till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['prices_include_tax' => true, 'service_charge_tax_rate' => 12])
        ->assertOk()
        ->assertJsonPath('data.prices_include_tax', true)
        ->assertJsonPath('data.service_charge_tax_rate', '12.00');
});

it('allows currency change after scheduler expire releases the till', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'organization_id' => $this->orgId],
    );

    $till = Till::create([
        'till_code' => 'MAIN', 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId, 'default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0,
    ]);
    $session = TillSession::create([
        'session_code' => 'SESS-EXP-T65', 'status' => 'open',
        'business_date' => now()->subDays(3)->toDateString(), 'default_currency_code' => 'JPY',
        'opening_float_amount' => 0, 'opened_at' => now()->subHours(72),
        'till_id' => $till->id, 'branch_id' => $this->shop->id, 'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $till->update(['current_session_id' => $session->id]);

    app(TillSessionService::class)->expire($session, 'no_activity', 48, 6);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['currency_code' => 'VND'])
        ->assertOk();
});

// =============================================================================
//  plan-043 T1.12 — consumption-tax settings + mid-shift guard + till stamp
// =============================================================================

function sos_openShift(object $test): void
{
    $till = Till::create([
        'till_code' => 'TAX-1', 'till_name' => 'Till TAX',
        'branch_id' => $test->shop->id, 'brand_id' => $test->brand->id,
        'organization_id' => $test->orgId, 'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);
    $session = TillSession::create([
        'session_code' => 'SESS-TAX', 'status' => 'open', 'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY', 'opening_float_amount' => 0, 'opened_at' => now(),
        'opened_by_id' => $test->manager->id, 'till_id' => $till->id,
        'branch_id' => $test->shop->id, 'brand_id' => $test->brand->id, 'organization_id' => $test->orgId,
    ]);
    $till->update(['current_session_id' => $session->id]);
}

it('persists the new consumption-tax settings', function () {
    $type = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'default_tax_type_id' => $type->id,
            'prices_include_tax' => true,
            'service_charge_tax_rate' => 10,
            'close_report_tax_breakdown' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.prices_include_tax', true)
        ->assertJsonPath('data.default_tax_type_id', $type->id)
        ->assertJsonPath('data.close_report_tax_breakdown', false);

    $setting = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect((bool) $setting->prices_include_tax)->toBeTrue()
        ->and((string) $setting->service_charge_tax_rate)->toBe('10.00');
});

it('blocks flipping prices_include_tax while a cashier shift is open (409)', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['prices_include_tax' => false, 'organization_id' => $this->orgId],
    );
    sos_openShift($this);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['prices_include_tax' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT');

    expect((bool) ShopOrderSetting::where('branch_id', $this->shop->id)->value('prices_include_tax'))
        ->toBeFalse();
});

it('allows flipping prices_include_tax when no shift is open (200)', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['prices_include_tax' => false, 'organization_id' => $this->orgId],
    );

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['prices_include_tax' => true])
        ->assertOk()
        ->assertJsonPath('data.prices_include_tax', true);
});

it('allows changing the default tax type mid-shift — snapshots protect data (Q6)', function () {
    $a = TaxType::factory()->reduced()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    $b = TaxType::factory()->standard()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['default_tax_type_id' => $a->id, 'organization_id' => $this->orgId],
    );
    sos_openShift($this);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['default_tax_type_id' => $b->id])
        ->assertOk()
        ->assertJsonPath('data.default_tax_type_id', $b->id);
});

it('rejects a default_tax_type_id from another brand (422)', function () {
    $otherBrand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $foreign = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId, 'brand_id' => $otherBrand->id,
    ]);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['default_tax_type_id' => $foreign->id])
        ->assertStatus(422);
});

it('rejects an out-of-range service_charge_tax_rate (422)', function () {
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", ['service_charge_tax_rate' => 101])
        ->assertStatus(422);
});

// =========================================================================
//  plan-045 — tax rounding round-trip (regression: the editable ShopOrderSetting
//  model shadows $fillable, and tax_rounding_mode/decimals were missing from it,
//  so PATCH returned 200 but the value never persisted → reload reverted to the
//  default. This asserts the full PATCH → DB → GET round-trip.)
// =========================================================================

it('persists tax_rounding_mode + tax_rounding_decimals across PATCH → DB → GET', function () {
    // PATCH a non-default rounding rule.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'tax_rounding_mode' => 'ceil',
            'tax_rounding_decimals' => 2,
        ])
        ->assertOk()
        ->assertJsonPath('data.tax_rounding_mode', 'ceil')
        ->assertJsonPath('data.tax_rounding_decimals', 2);

    // It must actually be written to the row (the $fillable bug lived here).
    $setting = ShopOrderSetting::where('branch_id', $this->shop->id)->first();
    expect($setting->tax_rounding_mode)->toBe('ceil')
        ->and((int) $setting->tax_rounding_decimals)->toBe(2);

    // A fresh GET (what the page does on reload) must return the saved value.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.tax_rounding_mode', 'ceil')
        ->assertJsonPath('data.tax_rounding_decimals', 2);
});

it('stamps the saved rounding rule onto a NEW order (so pos-web/workstation apply it)', function () {
    // Save floor/2 on the shop.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'tax_rounding_mode' => 'floor',
            'tax_rounding_decimals' => 2,
        ])
        ->assertOk();

    // An order created through the service funnel (the same path POS / workstation
    // sync use) must snapshot the shop's SAVED rule, not the default — this is the
    // link that makes the rounding config actually apply downstream.
    $order = app(CustomerOrderService::class)->create([
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'created_by_id' => $this->manager->id,
    ]);

    expect($order->fresh()->tax_rounding_mode)->toBe('floor')
        ->and((int) $order->fresh()->tax_rounding_decimals)->toBe(2);
});

// =============================================================================
// #1160 — prep minutes per item (shop override of the brand default)
// =============================================================================

it('round-trips prep_minutes_per_item and reports the resolved value', function () {
    // GET before any write: no override, no brand default → the constant.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.prep_minutes_per_item', null)
        ->assertJsonPath(
            'data.effective_prep_minutes_per_item',
            EffectiveOrderPolicyService::DEFAULT_PREP_MINUTES_PER_ITEM,
        );

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'prep_minutes_per_item' => 8,
        ])
        ->assertOk()
        ->assertJsonPath('data.prep_minutes_per_item', 8)
        ->assertJsonPath('data.effective_prep_minutes_per_item', 8);

    // The GET must agree with the PATCH echo — the round-trip bug class that
    // silently reset tax fields to 0 after a reload (plan-043 BUG-3).
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.prep_minutes_per_item', 8);

    expect(ShopOrderSetting::where('branch_id', $this->shop->id)->value('prep_minutes_per_item'))
        ->toBe(8);
});

it('clears the shop override back to "inherit HQ" with null', function () {
    BrandOrderPolicy::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_prep_minutes_per_item' => 12,
    ]);
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['prep_minutes_per_item' => 3, 'organization_id' => $this->orgId],
    );

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'prep_minutes_per_item' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.prep_minutes_per_item', null)
        // Falls back to the brand default, not to the system constant.
        ->assertJsonPath('data.effective_prep_minutes_per_item', 12);
});

it('accepts 0 but rejects a negative or over-cap prep time', function () {
    // 0 is legitimate — a shop handing over pre-made goods promises no wait.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'prep_minutes_per_item' => 0,
        ])
        ->assertOk()
        ->assertJsonPath('data.prep_minutes_per_item', 0);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'prep_minutes_per_item' => -1,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prep_minutes_per_item']);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'prep_minutes_per_item' => 121,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prep_minutes_per_item']);
});

it('round-trips the plan-051 void matrix + stock timing through PATCH → DB → GET (#1149 #1150)', function () {
    // GET before any config: raw null, effective = pending-only fallback.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.item_voidable_statuses', null)
        ->assertJsonPath('data.effective_item_voidable_statuses', ['pending'])
        ->assertJsonPath('data.stock_deduction_timing', 'on_close');

    // Partial matrix + timing persist; effective list unions the hard pending.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'item_voidable_statuses' => ['preparing', 'ready'],
            'stock_deduction_timing' => 'on_preparing',
        ])
        ->assertOk()
        ->assertJsonPath('data.effective_item_voidable_statuses', ['pending', 'preparing', 'ready'])
        ->assertJsonPath('data.stock_deduction_timing', 'on_preparing');

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/settings/order")
        ->assertOk()
        ->assertJsonPath('data.item_voidable_statuses', ['preparing', 'ready'])
        ->assertJsonPath('data.effective_item_voidable_statuses', ['pending', 'preparing', 'ready']);

    // Unknown status 422; voided (terminal) 422.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'item_voidable_statuses' => ['voided'],
        ])
        ->assertUnprocessable();

    // null clears back to the legacy-flag fallback.
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
            'item_voidable_statuses' => null,
        ])
        ->assertOk()
        ->assertJsonPath('data.item_voidable_statuses', null)
        ->assertJsonPath('data.effective_item_voidable_statuses', ['pending']);
});

it('serves the void-reason list on the POS namespace (LAN-parity Cloud fallback, plan-051)', function () {
    VoidReason::factory()->create([
        'organization_id' => Organization::where('console_organization_id', $this->orgId)->value('id'),
        'brand_id' => $this->brand->id,
        'stock_effect' => 'restock',
        'requires_note' => false,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/void-reasons')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.stock_effect', 'restock');
});

// =========================================================================
//  #1690 — số truy vấn của guard giữa-ca, bên trong cửa sổ khoá
// =========================================================================

/**
 * Bốn guard giữa-ca (plan-031 tiền tệ · plan-043 tax-included · #1129 phụ phí ·
 * plan-045 rounding) hỏi CÙNG một câu, và cả bốn chạy bên trong transaction
 * đang giữ `lockForUpdate` trên `shop_order_settings` — đúng hàng
 * `TillSessionService::open()` tranh chấp. Mỗi truy vấn thừa ở đây là thời gian
 * một thu ngân bị chặn mở ca.
 *
 * Đếm truy vấn thay vì đo thời gian: thời gian thì bấp bênh trên CI, còn hồi
 * quy thật ở đây là "ai đó bỏ memo hoá" hoặc "N+1 quay lại", và cả hai đều lộ
 * ra ở số truy vấn.
 *
 * Ngưỡng cố ý RỘNG — bài test này canh hình dạng (không phụ thuộc số till), chứ
 * không ghim một con số chính xác mà mọi lần thêm một cột lại phải sửa.
 */
it('#1690: số truy vấn của PATCH cài đặt không tăng theo số till', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        ['currency_code' => 'JPY', 'organization_id' => $this->orgId],
    );

    $made = 0;
    $makeTills = function (int $count) use (&$made): void {
        foreach (range(1, $count) as $_) {
            $i = ++$made;
            $till = Till::create([
                'till_code' => "T{$i}",
                'branch_id' => $this->shop->id,
                'brand_id' => $this->brand->id,
                'organization_id' => $this->orgId,
                'default_currency_code' => 'JPY',
                'variance_tolerance_amount' => 0,
            ]);

            // Mỗi till mang một ÍT lịch sử phiên đã kết thúc — đó là thứ bản
            // cũ nạp trọn cho từng till, một truy vấn mỗi till.
            foreach (range(1, 3) as $s) {
                TillSession::create([
                    'session_code' => "T{$i}-S{$s}",
                    'status' => 'settled',
                    'settlement_kind' => 'final',
                    'business_date' => now()->subDays($s)->toDateString(),
                    'default_currency_code' => 'JPY',
                    'opening_float_amount' => 0,
                    'opened_at' => now()->subDays($s),
                    'closed_at' => now()->subDays($s)->addHours(8),
                    'opened_by_id' => $this->manager->id,
                    'till_id' => $till->id,
                    'branch_id' => $this->shop->id,
                    'brand_id' => $this->brand->id,
                    'organization_id' => $this->orgId,
                ]);
            }
        }
    };

    // Guard chỉ chạy khi giá trị THẬT SỰ đổi — bản đầu của bài test này gửi
    // đúng giá trị đang có, nên không guard nào chạy và nó đo một con số không
    // liên quan. Vì thế mỗi lượt lật cả tiền tệ lẫn tax-included sang giá trị
    // khác, và lật ngược lại ở lượt sau.
    $flip = 0;
    $countQueriesForPatch = function () use (&$flip): int {
        $flip++;
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });

        $this->actingAs($this->manager)
            ->patchJson("/api/v1/shops/{$this->shop->slug}/settings/order", [
                'currency_code' => $flip % 2 === 1 ? 'USD' : 'JPY',
                'prices_include_tax' => $flip % 2 === 1,
            ])
            ->assertOk();

        expect($n)->toBeGreaterThan(0);

        return $n;
    };

    $makeTills(1);
    $withOneTill = $countQueriesForPatch();

    $makeTills(5);
    $withSixTills = $countQueriesForPatch();

    // Bản cũ: mỗi guard lặp mọi till + một truy vấn lịch sử cho từng till, nhân
    // với 4 guard. Thêm 5 till sẽ cộng hàng chục truy vấn. Bản mới không phụ
    // thuộc số till, nên chênh lệch phải quanh 0.
    expect($withSixTills - $withOneTill)->toBeLessThanOrEqual(
        4,
        'PATCH cài đặt tốn thêm '.($withSixTills - $withOneTill).' truy vấn khi chi nhánh có thêm 5 till '.
        "({$withOneTill} → {$withSixTills}). Guard giữa-ca lại phụ thuộc số till, và nó chạy bên trong ".
        'cửa sổ khoá mà việc mở ca tranh chấp.',
    );
});
