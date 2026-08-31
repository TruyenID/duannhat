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
        'slug' => 'tender-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    // Tender-type create/update now validates `category` against
    // till_tender_categories. Seed the 3 system rows the production
    // seeder would have planted, plus the legacy `cash` key still used
    // by anchor tenders so `seedOrgWideTender(... 'cash')` doesn't 422.
    foreach (
        [
            ['key' => 'cash', 'name' => 'Tiền mặt', 'sort_order' => 0],
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

function seedOrgWideTender(string $orgId, string $key = 'cash', string $category = 'cash'): TillTenderType
{
    return TillTenderType::create([
        'tender_key' => $key,
        'name' => ucfirst($key),
        'category' => $category,
        'currency_code' => 'JPY',
        'is_expected_anchor' => $category === 'cash' || $category === 'card',
        'requires_terminal_total' => false,
        'sort_order' => 0,
        'is_active' => true,
        'organization_id' => $orgId,
        'branch_id' => null,
    ]);
}

// =========================================================================
//  GET /shops/{slug}/tender-types
// =========================================================================

it('lists org-wide + shop-scoped tenders together', function () {
    seedOrgWideTender($this->orgId, 'cash', 'cash');
    seedOrgWideTender($this->orgId, 'credit', 'card');

    TillTenderType::create([
        'tender_key' => 'shop_voucher',
        'name' => 'Shop Voucher',
        'category' => 'qr',
        'currency_code' => 'JPY',
        'is_expected_anchor' => false,
        'requires_terminal_total' => false,
        'sort_order' => 100,
        'is_active' => true,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tender-types")
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('excludes other branches scoped tenders', function () {
    seedOrgWideTender($this->orgId);

    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'other-branch',
    ]);
    TillTenderType::create([
        'tender_key' => 'other_only',
        'name' => 'Other only',
        'category' => 'qr',
        'currency_code' => 'JPY',
        'is_expected_anchor' => false,
        'requires_terminal_total' => false,
        'sort_order' => 100,
        'is_active' => true,
        'organization_id' => $this->orgId,
        'branch_id' => $otherBranch->id,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tender-types")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('excludes inactive rows', function () {
    seedOrgWideTender($this->orgId, 'cash');
    TillTenderType::create([
        'tender_key' => 'disabled',
        'name' => 'Disabled',
        'category' => 'qr',
        'currency_code' => 'JPY',
        'is_expected_anchor' => false,
        'requires_terminal_total' => false,
        'sort_order' => 100,
        'is_active' => false,
        'organization_id' => $this->orgId,
        'branch_id' => null,
    ]);

    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tender-types")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// =========================================================================
//  POST /shops/{slug}/tender-types
// =========================================================================

it('creates a shop-scoped tender', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tender-types", [
            'tender_key' => 'shop_gift',
            'name' => 'Shop Gift Card',
            'category' => 'qr',
            'sort_order' => 50,
        ])
        ->assertCreated()
        ->assertJsonPath('data.tender_key', 'shop_gift')
        ->assertJsonPath('data.category', 'qr');

    expect(TillTenderType::where('tender_key', 'shop_gift')->first())
        ->branch_id->toBe($this->shop->id)
        ->organization_id->toBe($this->orgId);
});

it('lowercases tender_key on create', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tender-types", [
            'tender_key' => 'SHOP_VOUCHER',
            'name' => 'Voucher',
            'category' => 'qr',
        ])
        ->assertCreated()
        ->assertJsonPath('data.tender_key', 'shop_voucher');
});

it('rejects tender_key with disallowed chars', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tender-types", [
            'tender_key' => 'with-dashes',
            'name' => 'X',
            'category' => 'qr',
        ])
        ->assertUnprocessable();
});

it('rejects invalid category', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tender-types", [
            'tender_key' => 'foo',
            'name' => 'Foo',
            'category' => 'crypto',
        ])
        ->assertUnprocessable();
});

it('rejects duplicate tender_key within org', function () {
    seedOrgWideTender($this->orgId, 'cash');

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tender-types", [
            'tender_key' => 'cash',
            'name' => 'Shop cash override',
            'category' => 'cash',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'TENDER_KEY_TAKEN');
});

// =========================================================================
//  PATCH /shops/{slug}/tender-types/{id}
// =========================================================================

it('updates a shop-scoped tender', function () {
    $row = TillTenderType::create([
        'tender_key' => 'shop_voucher',
        'name' => 'Old name',
        'category' => 'qr',
        'currency_code' => 'JPY',
        'is_expected_anchor' => false,
        'requires_terminal_total' => false,
        'sort_order' => 100,
        'is_active' => true,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/tender-types/{$row->id}", [
            'name' => 'New name',
            'sort_order' => 999,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'New name')
        ->assertJsonPath('data.sort_order', 999);
});

it('rejects updating an org-wide tender', function () {
    $row = seedOrgWideTender($this->orgId, 'cash');

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/tender-types/{$row->id}", [
            'name' => 'Hacked',
        ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'TENDER_GLOBAL_READONLY');
});

it('returns 404 for tenders in another org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $row = seedOrgWideTender($otherOrgId, 'cash');

    $this->actingAs($this->manager)
        ->patchJson("/api/v1/shops/{$this->shop->slug}/tender-types/{$row->id}", [
            'name' => 'X',
        ])
        ->assertNotFound();
});

// =========================================================================
//  DELETE
// =========================================================================

it('deletes a shop-scoped tender', function () {
    $row = TillTenderType::create([
        'tender_key' => 'shop_voucher',
        'name' => 'Voucher',
        'category' => 'qr',
        'currency_code' => 'JPY',
        'is_expected_anchor' => false,
        'requires_terminal_total' => false,
        'sort_order' => 100,
        'is_active' => true,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/tender-types/{$row->id}")
        ->assertNoContent();

    expect(TillTenderType::find($row->id))->toBeNull();
});

it('rejects deleting an org-wide tender', function () {
    $row = seedOrgWideTender($this->orgId, 'cash');

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/tender-types/{$row->id}")
        ->assertStatus(403);

    expect(TillTenderType::find($row->id))->not->toBeNull();
});

// =========================================================================
//  Auth
// =========================================================================

it('rejects unauthenticated requests', function () {
    $this->getJson("/api/v1/shops/{$this->shop->slug}/tender-types")
        ->assertUnauthorized();
});

it('rejects cross-org user', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $outsider = User::factory()->create(['console_organization_id' => $otherOrgId]);

    $this->actingAs($outsider)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tender-types")
        ->assertForbidden();
});
