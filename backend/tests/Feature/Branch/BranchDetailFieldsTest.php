<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// =========================================================================
//  Schema — verify the 5 new columns are present on `branches`
// =========================================================================

describe('schema', function () {
    it('adds address, phone, seat_capacity, business_hours, weekly_hours columns to branches', function () {
        expect(Schema::hasColumns('branches', [
            'address',
            'phone',
            'seat_capacity',
            'business_hours',
            'weekly_hours',
        ]))->toBeTrue();
    });
});

// =========================================================================
//  Model — persistence + casts
// =========================================================================

describe('model', function () {
    it('persists the new detail fields through Branch::create', function () {
        $weekly = [
            'mon' => ['open' => '11:00', 'close' => '22:00'],
            'tue' => ['open' => '11:00', 'close' => '22:00'],
            'sun' => ['closed' => true],
        ];

        $branch = Branch::create([
            'console_branch_id' => (string) Str::uuid(),
            'console_organization_id' => (string) Str::uuid(),
            'slug' => 'detail-shop',
            'name' => 'Detail Shop',
            'is_active' => true,
            'address' => '東京都渋谷区道玄坂1-2-3',
            'phone' => '03-1234-5678',
            'seat_capacity' => 42,
            'business_hours' => '11:00 - 22:00',
            'weekly_hours' => $weekly,
        ]);

        $fresh = $branch->fresh();

        expect($fresh->address)->toBe('東京都渋谷区道玄坂1-2-3')
            ->and($fresh->phone)->toBe('03-1234-5678')
            ->and($fresh->seat_capacity)->toBe(42)
            ->and($fresh->business_hours)->toBe('11:00 - 22:00')
            ->and($fresh->weekly_hours)->toBe($weekly);
    });

    it('casts seat_capacity to integer', function () {
        $branch = Branch::factory()->create(['seat_capacity' => '30']);

        expect($branch->fresh()->seat_capacity)->toBeInt()->toBe(30);
    });

    it('casts weekly_hours to array', function () {
        $branch = Branch::factory()->create([
            'weekly_hours' => ['mon' => ['open' => '09:00', 'close' => '17:00']],
        ]);

        expect($branch->fresh()->weekly_hours)
            ->toBeArray()
            ->toHaveKey('mon');
    });
});

// =========================================================================
//  HQ update endpoint — PUT /api/v1/hq/{brand}/shops/{shop}
// =========================================================================

describe('HQ update endpoint', function () {
    beforeEach(function () {
        $this->orgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $this->orgId,
            'console_organization_id' => $this->orgId,
        ]);

        $this->brand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'slug' => 'test-brand',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        grantOrgAccess($this->user, $this->orgId);

        $this->shop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'slug' => 'detail-target',
            'name' => 'Detail Target',
        ]);

        $this->endpoint = "/api/v1/hq/{$this->brand->slug}/shops/{$this->shop->id}";
    });

    it('updates the 5 new fields and returns them in the resource', function () {
        $weekly = [
            'mon' => ['open' => '10:00', 'close' => '21:00'],
            'sun' => ['closed' => true],
        ];

        $response = $this->actingAs($this->user)->putJson($this->endpoint, [
            'address' => '大阪府大阪市中央区1-1',
            'phone' => '06-0000-0000',
            'seat_capacity' => 60,
            'business_hours' => '10:00 - 21:00',
            'weekly_hours' => $weekly,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.address', '大阪府大阪市中央区1-1')
            ->assertJsonPath('data.phone', '06-0000-0000')
            ->assertJsonPath('data.seat_capacity', 60)
            ->assertJsonPath('data.business_hours', '10:00 - 21:00')
            ->assertJsonPath('data.weekly_hours.mon.open', '10:00')
            ->assertJsonPath('data.weekly_hours.sun.closed', true);

        $fresh = $this->shop->fresh();
        expect($fresh->seat_capacity)->toBe(60)
            ->and($fresh->weekly_hours)->toBe($weekly);
    });

    it('accepts a partial update without clobbering untouched fields', function () {
        $this->shop->update([
            'address' => 'old-address',
            'phone' => 'old-phone',
        ]);

        $this->actingAs($this->user)
            ->putJson($this->endpoint, ['phone' => '080-1111-2222'])
            ->assertOk()
            ->assertJsonPath('data.phone', '080-1111-2222')
            ->assertJsonPath('data.address', 'old-address');
    });

    it('returns 422 when seat_capacity is negative', function () {
        $this->actingAs($this->user)
            ->putJson($this->endpoint, ['seat_capacity' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seat_capacity']);
    });

    it('returns 422 when weekly_hours is not an array', function () {
        $this->actingAs($this->user)
            ->putJson($this->endpoint, ['weekly_hours' => 'not-an-array'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weekly_hours']);
    });

    it('returns 422 when weekly_hours.*.open has an invalid time format', function () {
        $this->actingAs($this->user)
            ->putJson($this->endpoint, [
                'weekly_hours' => ['mon' => ['open' => '25:99', 'close' => '22:00']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weekly_hours.mon.open']);
    });

    it('returns 403 when the user belongs to a different organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherUser = User::factory()->create([
            'console_organization_id' => $otherOrgId,
        ]);

        $this->actingAs($otherUser)
            ->putJson($this->endpoint, ['phone' => '03-9999-9999'])
            ->assertForbidden();
    });

    it('returns 401 when unauthenticated', function () {
        $this->putJson($this->endpoint, ['phone' => '03-0000-0000'])
            ->assertUnauthorized();
    });
});

// =========================================================================
//  HQ create endpoint — POST /api/v1/hq/{brand}/shops
// =========================================================================

describe('HQ create endpoint', function () {
    beforeEach(function () {
        $this->orgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $this->orgId,
            'console_organization_id' => $this->orgId,
        ]);

        $this->brand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'slug' => 'create-brand',
            'is_active' => true,
        ]);

        $this->user = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        grantOrgAccess($this->user, $this->orgId);

        $this->endpoint = "/api/v1/hq/{$this->brand->slug}/shops";
    });

    it('creates a shop with the 5 new fields and returns them in the resource', function () {
        $weekly = [
            'mon' => ['open' => '11:00', 'close' => '22:00'],
            'sun' => ['closed' => true],
        ];

        $response = $this->actingAs($this->user)->postJson($this->endpoint, [
            'name' => '新宿店',
            'slug' => 'shinjuku',
            'address' => '東京都新宿区1-1',
            'phone' => '03-1111-2222',
            'seat_capacity' => 48,
            'business_hours' => '11:00 - 22:00',
            'weekly_hours' => $weekly,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'shinjuku')
            ->assertJsonPath('data.address', '東京都新宿区1-1')
            ->assertJsonPath('data.phone', '03-1111-2222')
            ->assertJsonPath('data.seat_capacity', 48)
            ->assertJsonPath('data.business_hours', '11:00 - 22:00')
            ->assertJsonPath('data.weekly_hours.mon.open', '11:00')
            ->assertJsonPath('data.weekly_hours.sun.closed', true);

        $branch = Branch::where('slug', 'shinjuku')->first();
        expect($branch->seat_capacity)->toBe(48)
            ->and($branch->weekly_hours)->toBe($weekly);
    });

    it('returns 422 when seat_capacity is negative on create', function () {
        $this->actingAs($this->user)
            ->postJson($this->endpoint, [
                'name' => 'Bad Shop',
                'slug' => 'bad-shop',
                'seat_capacity' => -5,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['seat_capacity']);
    });

    it('returns 422 when weekly_hours.*.open has an invalid time format on create', function () {
        $this->actingAs($this->user)
            ->postJson($this->endpoint, [
                'name' => 'Bad Time Shop',
                'slug' => 'bad-time',
                'weekly_hours' => ['mon' => ['open' => '99:99', 'close' => '22:00']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['weekly_hours.mon.open']);
    });
});

// =========================================================================
//  Customer branches endpoint — GET /api/v1/customer/branches
// =========================================================================

describe('customer branches endpoint', function () {
    it('exposes the 5 new detail fields in the index response', function () {
        Branch::factory()->create([
            'slug' => 'exposed-shop',
            'name' => 'Exposed Shop',
            'is_active' => true,
            'address' => '神奈川県横浜市1-1',
            'phone' => '045-111-2222',
            'seat_capacity' => 24,
            'business_hours' => '12:00 - 23:00',
            'weekly_hours' => ['fri' => ['open' => '12:00', 'close' => '23:00']],
        ]);

        $response = $this->getJson('/api/v1/customer/branches');

        $response->assertOk();

        $branch = collect($response->json('data'))
            ->firstWhere('slug', 'exposed-shop');

        expect($branch)->not->toBeNull()
            ->and($branch['address'])->toBe('神奈川県横浜市1-1')
            ->and($branch['phone'])->toBe('045-111-2222')
            ->and($branch['seat_capacity'])->toBe(24)
            ->and($branch['business_hours'])->toBe('12:00 - 23:00')
            ->and($branch['weekly_hours'])->toBe(['fri' => ['open' => '12:00', 'close' => '23:00']]);
    });
});
