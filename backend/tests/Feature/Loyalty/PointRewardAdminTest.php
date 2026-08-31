<?php

/**
 * #1514 — catalog đổi điểm: CRUD ở HQ, công tắc theo chi nhánh ở Shop, và
 * tồn kho ở đường đổi điểm của khách.
 *
 *   GET|POST         /api/v1/hq/{brand}/point-rewards
 *   GET|PATCH|DELETE /api/v1/hq/{brand}/point-rewards/{id}
 *   GET              /api/v1/shops/{shop}/point-rewards
 *   PATCH            /api/v1/shops/{shop}/point-rewards/{id}/availability
 *
 * Ba bất biến đắt nhất, mỗi cái đã suýt viết ngược:
 *   1. Không có dòng pivot ⇒ CÒN BẬT (BR-PRB01). Viết bằng inner join thì
 *      mọi phần thưởng biến mất ở mọi chi nhánh chưa từng bấm công tắc.
 *   2. Hết hàng ⇒ VẪN nằm trong catalog, chỉ khoá nút đổi (BR-PR05).
 *   3. Hết hàng KHÔNG được trừ điểm của khách.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerPointEntry;
use App\Models\Organization;
use App\Models\PointReward;
use App\Models\PointRewardBranch;
use App\Models\User;
use App\Omnify\Enums\PointEntryKindEnum;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'pr-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'shop-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'shop-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    $this->hq = "/api/v1/hq/{$this->brand->slug}/point-rewards";
    $this->shop = "/api/v1/shops/{$this->branch->slug}/point-rewards";
});

function makeReward(array $attrs = []): PointReward
{
    return PointReward::factory()->create([
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'is_active' => true,
        'cost_points' => 200,
        'discount_type' => 'fixed',
        'discount_value' => 2500,
        'max_discount_cap' => null,
        'stock_quantity' => null,
        'service_condition' => 'both',
        ...$attrs,
    ]);
}

function pointCustomer(): array
{
    $customer = Customer::factory()->selfRegistered()->create();

    return [$customer, $customer->createToken('test')->plainTextToken];
}

function giveCustomerPoints(Customer $customer, int $points): void
{
    CustomerPointEntry::create([
        'customer_id' => $customer->id,
        'organization_id' => test()->orgId,
        'kind' => PointEntryKindEnum::Earn->value,
        'points' => $points,
    ]);
}

// ─── HQ CRUD ────────────────────────────────────────────────────────────

it('creates a reward with translations, image-less, and defaults service_condition to both', function () {
    $response = $this->actingAs($this->user)
        ->postJson($this->hq, [
            'vi' => ['name' => 'Bia', 'description' => 'Chỉ phục vụ ăn tại chỗ.'],
            'ja' => ['name' => 'ビール'],
            'cost_points' => 200,
            'discount_type' => 'fixed',
            'discount_value' => 2500,
            'stock_quantity' => 10,
            'service_condition' => 'dine_in',
        ])
        ->assertCreated();

    $response->assertJsonPath('data.cost_points', 200)
        ->assertJsonPath('data.stock_quantity', 10)
        ->assertJsonPath('data.remaining_stock', 10)
        ->assertJsonPath('data.is_out_of_stock', false)
        ->assertJsonPath('data.service_condition', 'dine_in')
        ->assertJsonPath('data.translations.vi.name', 'Bia')
        ->assertJsonPath('data.translations.ja.name', 'ビール');
});

it('rejects a reward with no name in any language', function () {
    $this->actingAs($this->user)
        ->postJson($this->hq, [
            'cost_points' => 200,
            'discount_type' => 'fixed',
            'discount_value' => 2500,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ja.name');
});

it('rejects a discount cap on a fixed-amount reward (BR-PR04)', function () {
    $this->actingAs($this->user)
        ->postJson($this->hq, [
            'vi' => ['name' => 'Bia'],
            'cost_points' => 200,
            'discount_type' => 'fixed',
            'discount_value' => 2500,
            'max_discount_cap' => 5000,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('max_discount_cap');
});

it('clears a stale cap when the type flips percent to fixed', function () {
    $reward = makeReward(['discount_type' => 'percent', 'discount_value' => 10, 'max_discount_cap' => 5000]);

    $this->actingAs($this->user)
        ->patchJson("{$this->hq}/{$reward->id}", [
            'discount_type' => 'fixed',
            'discount_value' => 2500,
        ])
        ->assertOk()
        ->assertJsonPath('data.max_discount_cap', null);
});

it('lists inactive rewards too — admin must see what it just switched off', function () {
    makeReward(['is_active' => false]);

    $this->actingAs($this->user)
        ->getJson($this->hq)
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('soft-deletes a reward and keeps the customer point history readable', function () {
    [$customer] = pointCustomer();
    $reward = makeReward();

    CustomerPointEntry::create([
        'customer_id' => $customer->id,
        'organization_id' => $this->orgId,
        'point_reward_id' => $reward->id,
        'kind' => PointEntryKindEnum::Redeem->value,
        'points' => -200,
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->hq}/{$reward->id}")
        ->assertNoContent();

    expect(PointReward::withTrashed()->find($reward->id))->not->toBeNull();

    // Dòng lịch sử vẫn trỏ được sang phần thưởng — không thủng.
    $entry = CustomerPointEntry::where('point_reward_id', $reward->id)->first();
    expect($entry)->not->toBeNull()
        ->and($entry->point_reward_id)->toBe($reward->id);
});

it('404s on a reward belonging to another brand', function () {
    $otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'other-'.Str::random(4),
    ]);
    $foreign = makeReward(['brand_id' => $otherBrand->id]);

    $this->actingAs($this->user)
        ->patchJson("{$this->hq}/{$foreign->id}", ['cost_points' => 1])
        ->assertNotFound();
});

// ─── Công tắc theo chi nhánh ────────────────────────────────────────────

it('reports a reward as available at a branch that never touched the switch (BR-PRB01)', function () {
    makeReward();

    $this->actingAs($this->user)
        ->getJson($this->shop)
        ->assertOk()
        ->assertJsonPath('data.0.is_available_at_branch', true);
});

it('turns a reward off for one branch without affecting the others', function () {
    $reward = makeReward();

    $this->actingAs($this->user)
        ->patchJson("{$this->shop}/{$reward->id}/availability", ['is_available' => false])
        ->assertOk()
        ->assertJsonPath('data.is_available_at_branch', false);

    $this->actingAs($this->user)
        ->getJson($this->shop)
        ->assertJsonPath('data.0.is_available_at_branch', false);

    // Chi nhánh khác không đổi.
    $this->actingAs($this->user)
        ->getJson("/api/v1/shops/{$this->otherBranch->slug}/point-rewards")
        ->assertJsonPath('data.0.is_available_at_branch', true);

    // Và cấp brand cũng không đổi — cửa hàng không ghi được vào `point_rewards`.
    expect($reward->fresh()->is_active)->toBeTrue();
});

it('keeps the pivot row when a branch switches a reward back on', function () {
    $reward = makeReward();

    $this->actingAs($this->user)
        ->patchJson("{$this->shop}/{$reward->id}/availability", ['is_available' => false])
        ->assertOk();

    $this->actingAs($this->user)
        ->patchJson("{$this->shop}/{$reward->id}/availability", ['is_available' => true])
        ->assertOk();

    // Dòng còn đó với cờ true — dấu vết ai bấm lúc nào là chủ đích, không
    // phải rác cần dọn.
    $pivot = PointRewardBranch::where('point_reward_id', $reward->id)
        ->where('branch_id', $this->branch->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and((bool) $pivot->is_available)->toBeTrue();
});

it('records who pressed the switch (#1723)', function () {
    $reward = makeReward();

    $this->actingAs($this->user)
        ->patchJson("{$this->shop}/{$reward->id}/availability", ['is_available' => false])
        ->assertOk();

    $pivot = PointRewardBranch::where('point_reward_id', $reward->id)
        ->where('branch_id', $this->branch->id)
        ->first();

    // `toggled_by_id` là Association thật, KHÔNG phải `options.audit` — hook
    // auto-fill của Omnify không còn, controller phải tự truyền người bấm vào.
    // Quên truyền, hoặc quên khai cột trong `$fillable` của model editable
    // (`$fillable` ghi đè chứ không cộng dồn), thì cột này lặng lẽ lưu null và
    // dấu vết "ai tắt phần thưởng này" — lý do duy nhất dòng pivot được giữ
    // lại khi bật lại — mất sạch mà không có gì kêu.
    expect($pivot->toggled_by_id)->toBe($this->user->id);

    // Và bấm lại thì dấu vết cập nhật theo người bấm sau, không đóng băng ở
    // người tạo dòng: dòng này chỉ có một hành động, nên "ai tạo" và "ai sửa
    // lần cuối" là cùng một câu hỏi (xem header `PointRewardBranch.yaml`).
    $other = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($other, $this->orgId);

    $this->actingAs($other)
        ->patchJson("{$this->shop}/{$reward->id}/availability", ['is_available' => true])
        ->assertOk();

    expect($pivot->fresh()->toggled_by_id)->toBe($other->id);
});

it('hides a branch-disabled reward from the customer catalog for that branch only', function () {
    [, $token] = pointCustomer();
    $reward = makeReward();

    $this->actingAs($this->user)
        ->patchJson("{$this->shop}/{$reward->id}/availability", ['is_available' => false])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/customer/me/points/rewards?branch_id={$this->branch->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/customer/me/points/rewards?branch_id={$this->otherBranch->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('still shows every reward when the customer passes no branch at all', function () {
    [, $token] = pointCustomer();
    $reward = makeReward();

    $this->actingAs($this->user)
        ->patchJson("{$this->shop}/{$reward->id}/availability", ['is_available' => false])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/me/points/rewards')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ─── Tồn kho ────────────────────────────────────────────────────────────

it('keeps an out-of-stock reward in the catalog but flags it (BR-PR05)', function () {
    [, $token] = pointCustomer();
    makeReward(['stock_quantity' => 1, 'redeemed_count' => 1]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/me/points/rewards')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.is_out_of_stock', true)
        ->assertJsonPath('data.0.remaining_stock', 0);
});

it('reports remaining_stock null for an unlimited reward', function () {
    [, $token] = pointCustomer();
    makeReward(['stock_quantity' => null]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/customer/me/points/rewards')
        ->assertOk()
        ->assertJsonPath('data.0.remaining_stock', null)
        ->assertJsonPath('data.0.is_out_of_stock', false);
});

it('bumps redeemed_count on a successful redeem', function () {
    [$customer, $token] = pointCustomer();
    giveCustomerPoints($customer, 500);
    $reward = makeReward(['stock_quantity' => 2]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/me/points/redeem', ['reward_id' => $reward->id])
        ->assertCreated();

    expect((int) $reward->fresh()->redeemed_count)->toBe(1)
        ->and($reward->fresh()->remainingStock())->toBe(1);
});

it('refuses a redeem when the reward is out of stock and does NOT spend the points', function () {
    [$customer, $token] = pointCustomer();
    giveCustomerPoints($customer, 500);
    $reward = makeReward(['stock_quantity' => 1, 'redeemed_count' => 1]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/me/points/redeem', ['reward_id' => $reward->id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'REWARD_OUT_OF_STOCK');

    // Cái đắt nhất: số dư không được suy suyển.
    expect((int) CustomerPointEntry::where('customer_id', $customer->id)->sum('points'))->toBe(500);
});

it('lets the last unit be redeemed exactly once', function () {
    [$customer, $token] = pointCustomer();
    giveCustomerPoints($customer, 1000);
    $reward = makeReward(['stock_quantity' => 1]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/me/points/redeem', ['reward_id' => $reward->id])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/me/points/redeem', ['reward_id' => $reward->id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'REWARD_OUT_OF_STOCK');

    expect((int) CustomerPointEntry::where('customer_id', $customer->id)->sum('points'))->toBe(800);
});

it('separates out-of-stock from discontinued so the customer gets the right message', function () {
    [$customer, $token] = pointCustomer();
    giveCustomerPoints($customer, 500);
    $reward = makeReward(['is_active' => false]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/customer/me/points/redeem', ['reward_id' => $reward->id])
        ->assertStatus(422)
        ->assertJsonPath('error', 'REWARD_UNAVAILABLE');
});
