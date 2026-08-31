<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TillTenderCategory;
use App\Models\TillTenderType;
use App\Models\User;
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
        'slug' => 'cat-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    // System rows (org-wide).
    foreach (
        [
            ['key' => 'card', 'name' => 'Thẻ', 'sort_order' => 10],
            ['key' => 'qr', 'name' => 'QR', 'sort_order' => 20],
            ['key' => 'emoney', 'name' => 'Tiền điện tử', 'sort_order' => 30],
        ] as $row
    ) {
        TillTenderCategory::create([
            'organization_id' => $this->orgId,
            'branch_id' => null,
            'key' => $row['key'],
            'name' => $row['name'],
            'sort_order' => $row['sort_order'],
            'is_active' => true,
            'is_system' => true,
        ]);
    }
});

it('lists system + shop-scoped categories ordered by sort_order then key', function () {
    TillTenderCategory::create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'key' => 'voucher',
        'name' => 'Voucher giấy',
        'sort_order' => 50,
        'is_active' => true,
        'is_system' => false,
    ]);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tender-categories")
        ->assertOk()
        ->assertJsonCount(4, 'data');

    $keys = array_column($response->json('data'), 'key');
    expect($keys)->toBe(['card', 'qr', 'emoney', 'voucher']);
});

it('creates a shop-scoped category', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tender-categories", [
            'key' => 'voucher',
            'name' => 'Voucher giấy',
            'sort_order' => 50,
        ])
        ->assertCreated()
        ->assertJsonPath('data.key', 'voucher')
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.branch_id', $this->shop->id);

    expect(TillTenderCategory::where('key', 'voucher')->where('branch_id', $this->shop->id)->exists())->toBeTrue();
});

it('rejects key clash against existing org-wide system row', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tender-categories", [
            'key' => 'card',
            'name' => 'Thẻ override',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TENDER_CATEGORY_KEY_TAKEN');
});

it('rejects edit of a system category', function () {
    $sys = TillTenderCategory::where('organization_id', $this->orgId)
        ->where('key', 'card')
        ->first();

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/tender-categories/{$sys->id}", [
            'name' => 'Thẻ tín dụng',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'TENDER_CATEGORY_SYSTEM_READONLY');
});

it('refuses delete when tender types still belong to category', function () {
    $cat = TillTenderCategory::create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'key' => 'voucher',
        'name' => 'Voucher giấy',
        'sort_order' => 50,
        'is_active' => true,
        'is_system' => false,
    ]);

    TillTenderType::create([
        'tender_key' => 'voucher_a',
        'name' => 'Voucher A',
        'category' => 'voucher',
        'currency_code' => 'JPY',
        'is_expected_anchor' => false,
        'requires_terminal_total' => false,
        'sort_order' => 100,
        'is_active' => true,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/tender-categories/{$cat->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'TENDER_CATEGORY_IN_USE');
});

it('blocks tender_type create when category key does not exist', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tender-types", [
            'tender_key' => 'mystery',
            'name' => 'Mystery',
            'category' => 'nonexistent',
            'sort_order' => 100,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TENDER_CATEGORY_UNKNOWN');
});

it('allows tender_type create when category was just defined by the shop', function () {
    TillTenderCategory::create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'key' => 'voucher',
        'name' => 'Voucher giấy',
        'sort_order' => 50,
        'is_active' => true,
        'is_system' => false,
    ]);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tender-types", [
            'tender_key' => 'voucher_50k',
            'name' => 'Voucher 50K',
            'category' => 'voucher',
            'sort_order' => 100,
        ])
        ->assertCreated()
        ->assertJsonPath('data.category', 'voucher');
});
