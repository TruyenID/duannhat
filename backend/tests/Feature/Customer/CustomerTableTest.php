<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\Zone;

beforeEach(function () {
    $this->brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->zone = Zone::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'valid-qr-token-abc123',
        'is_active' => true,
        'name' => 'A-1',
    ]);
});

// =========================================================================
//  Happy path
// =========================================================================

it('returns table and zone info for a valid qr_token', function () {
    $response = $this->getJson('/api/v1/customer/tables/valid-qr-token-abc123');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['table' => ['id', 'number', 'seats', 'status', 'qr_token'], 'zone']]);
});

/**
 * #1447 — `weekly_hours` without `timezone` is unreadable: it is a wall clock at
 * the shop, so a consumer that gets one and not the other has no choice but to
 * fall back to its OWN clock. That is exactly what customer-web's dine-in menu
 * did, and a phone in Vietnam judged a Tokyo shop two hours early — the menu
 * grew a "Cửa hàng đã đóng cửa" banner while the shop was open. Take-away was
 * fine because it reads the branch off /customer/branches, which has shipped
 * `timezone` since #1160. The two payloads must not drift apart again.
 */
it('publishes the branch timezone alongside weekly_hours', function () {
    $this->branch->update([
        'timezone' => 'Asia/Tokyo',
        'weekly_hours' => ['mon' => ['open' => '11:00', 'close' => '22:00', 'closed' => false]],
    ]);

    $this->getJson('/api/v1/customer/tables/valid-qr-token-abc123')
        ->assertOk()
        ->assertJsonPath('data.branch.timezone', 'Asia/Tokyo')
        ->assertJsonPath('data.branch.weekly_hours.mon.open', '11:00');
});

it('publishes the branch timezone even when no hours are set', function () {
    $this->branch->update(['timezone' => 'Asia/Ho_Chi_Minh', 'weekly_hours' => null]);

    $this->getJson('/api/v1/customer/tables/valid-qr-token-abc123')
        ->assertOk()
        ->assertJsonPath('data.branch.timezone', 'Asia/Ho_Chi_Minh');
});

it('updates call_requested_at on call-staff', function () {
    $response = $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/call-staff');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['called_at', 'table_number', 'branch_id', 'brand_id', 'message']])
        ->assertJsonPath('data.branch_id', $this->branch->id)
        ->assertJsonPath('data.brand_id', $this->brand->id);

    $this->table->refresh();
    expect($this->table->call_requested_at)->not->toBeNull();
});

// =========================================================================
//  Edge cases
// =========================================================================

it('returns 404 for inactive table', function () {
    $this->table->update(['is_active' => false]);

    $this->getJson('/api/v1/customer/tables/valid-qr-token-abc123')->assertNotFound();
});

it('returns 404 for soft-deleted table', function () {
    $this->table->delete();

    $this->getJson('/api/v1/customer/tables/valid-qr-token-abc123')->assertNotFound();
});

// =========================================================================
//  Error handling
// =========================================================================

it('returns 404 for non-existent qr_token', function () {
    $this->getJson('/api/v1/customer/tables/nonexistent-token')->assertNotFound();
});

it('returns 404 for non-existent qr_token on call-staff', function () {
    $this->postJson('/api/v1/customer/tables/nonexistent-token/call-staff')->assertNotFound();
});

// =========================================================================
//  Occupy (free → occupied on QR scan confirmation)
// =========================================================================

it('transitions a free table to occupied on occupy', function () {
    $this->table->update(['status' => 'free']);

    $response = $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/occupy');

    $response->assertOk()
        ->assertJsonPath('data.table.status', 'occupied')
        ->assertJsonPath('data.table.qr_token', 'valid-qr-token-abc123');

    $this->table->refresh();
    expect($this->table->status->value)->toBe('occupied');
});

it('is idempotent when the table is already occupied', function () {
    $this->table->update(['status' => 'occupied']);

    $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/occupy')
        ->assertOk()
        ->assertJsonPath('data.table.status', 'occupied');
});

it('returns 409 when the table is cleaning', function () {
    $this->table->update(['status' => 'cleaning']);

    $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/occupy')
        ->assertStatus(409)
        ->assertJsonPath('status', 'cleaning');

    $this->table->refresh();
    expect($this->table->status->value)->toBe('cleaning');
});

it('returns 409 when the table is reserved', function () {
    $this->table->update(['status' => 'reserved']);

    $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/occupy')
        ->assertStatus(409)
        ->assertJsonPath('status', 'reserved');
});

it('returns 404 when occupying a non-existent qr_token', function () {
    $this->postJson('/api/v1/customer/tables/nonexistent-token/occupy')->assertNotFound();
});

it('returns 404 when occupying an inactive table', function () {
    $this->table->update(['is_active' => false, 'status' => 'free']);

    $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/occupy')->assertNotFound();
});

// =========================================================================
//  Release (occupied → free on "Đổi bàn")
// =========================================================================

it('transitions an occupied table to free on release', function () {
    $this->table->update(['status' => 'occupied']);

    $response = $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/release');

    $response->assertOk()
        ->assertJsonPath('data.table.status', 'free')
        ->assertJsonPath('data.table.qr_token', 'valid-qr-token-abc123');

    $this->table->refresh();
    expect($this->table->status->value)->toBe('free');
});

it('is idempotent when releasing an already-free table', function () {
    $this->table->update(['status' => 'free']);

    $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/release')
        ->assertOk()
        ->assertJsonPath('data.table.status', 'free');
});

it('returns 409 when releasing a cleaning table', function () {
    $this->table->update(['status' => 'cleaning']);

    $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/release')
        ->assertStatus(409)
        ->assertJsonPath('status', 'cleaning');

    $this->table->refresh();
    expect($this->table->status->value)->toBe('cleaning');
});

it('returns 409 when releasing a reserved table', function () {
    $this->table->update(['status' => 'reserved']);

    $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/release')
        ->assertStatus(409)
        ->assertJsonPath('status', 'reserved');
});

it('returns 404 when releasing a non-existent qr_token', function () {
    $this->postJson('/api/v1/customer/tables/nonexistent-token/release')->assertNotFound();
});

it('returns 404 when releasing an inactive table', function () {
    $this->table->update(['is_active' => false, 'status' => 'occupied']);

    $this->postJson('/api/v1/customer/tables/valid-qr-token-abc123/release')->assertNotFound();
});

// =========================================================================
//  #1778 — hai endpoint không được nói ngược nhau về 総額表示
// =========================================================================

it('#1778 endpoint bàn PHÁT prices_include_tax, cả hai giá trị', function (bool $flag) {
    // Màn dine-in GHI ĐÈ `currentBranch` bằng payload của endpoint này. Một cờ
    // vắng mặt vì thế không im lặng giữ giá trị cũ — nó thành `false`, và menu
    // dán nhãn "Chưa gồm thuế" lên đúng những giá ĐÃ gồm thuế. Sai theo hướng
    // đắt hơn: khách đọc ￥1,300 rồi tự cộng thêm 8–10%.
    ShopOrderSetting::query()->updateOrCreate(
        ['branch_id' => $this->branch->id],
        ['organization_id' => $this->branch->console_organization_id, 'prices_include_tax' => $flag],
    );

    $this->getJson('/api/v1/customer/tables/valid-qr-token-abc123')
        ->assertOk()
        ->assertJsonPath('data.branch.prices_include_tax', $flag);
})->with([true, false]);

it('#1778 endpoint bàn và endpoint /branches TRẢ LỜI GIỐNG NHAU', function () {
    // Đây mới là bất biến thật. Trước bản vá, cùng một phiên cho hai câu trái
    // ngược: menu nói "Chưa gồm thuế", màn tóm tắt nói "Đã gồm thuế" — cùng
    // món, cùng con số. Ghim sự ĐỒNG Ý giữa hai nguồn, không phải giá trị của
    // riêng một cái, vì lỗi nằm ở chỗ chúng lệch nhau.
    ShopOrderSetting::query()->updateOrCreate(
        ['branch_id' => $this->branch->id],
        ['organization_id' => $this->branch->console_organization_id, 'prices_include_tax' => true],
    );

    $fromTable = $this->getJson('/api/v1/customer/tables/valid-qr-token-abc123')
        ->assertOk()->json('data.branch.prices_include_tax');

    $branches = collect($this->getJson('/api/v1/customer/branches')->assertOk()->json('data'));
    $row = $branches->firstWhere('id', $this->branch->id);

    expect($row)->not->toBeNull('endpoint /branches không trả chi nhánh này — phép so dưới sẽ rỗng')
        ->and($fromTable)->toBe($row['prices_include_tax']);
});
