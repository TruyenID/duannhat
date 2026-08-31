<?php

/**
 * HQ Audience admin API contract tests (plan-012 T1.4).
 *
 * Pins:
 *   - Index / store / show / update / destroy happy paths
 *   - is_system protection on PATCH + DELETE
 *   - Preview returns {count, sample} and is rate-limited to 10/min
 *   - Cross-brand access returns 404
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\NotificationAudience;
use App\Models\NotificationSchedule;
use App\Models\Organization;
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
        'slug' => 'aud-hq-'.Str::random(4),
        'is_active' => true,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/audiences";
});

describe('happy path', function () {
    it('lists audiences scoped to the brand', function () {
        NotificationAudience::factory()->count(2)->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $response = $this->actingAs($this->user)->getJson($this->base);

        $response->assertOk()->assertJsonCount(2, 'data');
    });

    it('creates an audience with the authenticated user as creator', function () {
        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'All warehouse managers',
            'description' => 'Every warehouse_manager across our warehouses',
            'rule' => [
                'version' => 1,
                'combinator' => 'or',
                'rules' => [['type' => 'role', 'role' => 'warehouse_manager']],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'All warehouse managers')
            ->assertJsonPath('data.created_by_id', $this->user->id);
    });

    it('updates a non-system audience', function () {
        $audience = NotificationAudience::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_system' => false,
            'name' => 'Old',
        ]);

        $response = $this->actingAs($this->user)
            ->patchJson("{$this->base}/{$audience->id}", ['name' => 'New']);

        $response->assertOk()->assertJsonPath('data.name', 'New');
    });

    it('deletes a non-system audience', function () {
        $audience = NotificationAudience::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_system' => false,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$audience->id}")
            ->assertNoContent();

        expect(NotificationAudience::find($audience->id))->toBeNull();
    });
});

describe('TC-NOTIF-AUD06 audience_in_use', function () {
    it('returns 422 audience_in_use when a schedule still references the audience', function () {
        $audience = NotificationAudience::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_system' => false,
        ]);

        NotificationSchedule::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'template_key' => 'stock.alert.low',
            'audience_id' => $audience->id,
            'channels' => ['in_app'],
            'priority' => 'normal',
            'rrule' => 'FREQ=DAILY;BYHOUR=9;BYMINUTE=0',
            'timezone' => 'Asia/Tokyo',
            'starts_at' => now()->subDay(),
            'next_occurrence_at' => now()->addHours(2),
            'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$audience->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'audience_in_use');

        // Audience must still exist — refusal, not a partial delete.
        expect(NotificationAudience::find($audience->id))->not->toBeNull();
    });

    it('also blocks the delete when only terminal schedules reference the audience', function () {
        $audience = NotificationAudience::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_system' => false,
        ]);

        NotificationSchedule::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'template_key' => 'stock.alert.low',
            'audience_id' => $audience->id,
            'channels' => ['in_app'],
            'priority' => 'normal',
            'rrule' => 'FREQ=DAILY;BYHOUR=9;BYMINUTE=0',
            'timezone' => 'Asia/Tokyo',
            'starts_at' => now()->subDay(),
            'next_occurrence_at' => null,
            'status' => 'cancelled',
        ]);

        // The FK is RESTRICT regardless of schedule status, so the check
        // must guard the friendly 422 against the SQL constraint that
        // would otherwise surface as a 500.
        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$audience->id}")
            ->assertStatus(422)
            ->assertJsonPath('error', 'audience_in_use');
    });
});

describe('is_system protection', function () {
    it('returns 403 on PATCH when is_system=true', function () {
        $audience = NotificationAudience::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_system' => true,
        ]);

        $this->actingAs($this->user)
            ->patchJson("{$this->base}/{$audience->id}", ['name' => 'X'])
            ->assertForbidden();
    });

    it('returns 403 on DELETE when is_system=true', function () {
        $audience = NotificationAudience::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_system' => true,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("{$this->base}/{$audience->id}")
            ->assertForbidden();
    });
});

describe('preview', function () {
    it('returns {count, sample} for a user rule', function () {
        $targets = User::factory()->count(3)->create(['console_organization_id' => $this->orgId]);

        $response = $this->actingAs($this->user)->postJson("{$this->base}/preview", [
            'rule' => [
                'rules' => [['type' => 'user', 'user_ids' => $targets->pluck('id')->all()]],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.count', 3)
            ->assertJsonCount(3, 'data.sample');
    });

    it('throttles to 10 requests/minute per user', function () {
        $payload = ['rule' => ['rules' => [['type' => 'user', 'user_ids' => []]]]];

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($this->user)->postJson("{$this->base}/preview", $payload)->assertOk();
        }

        $this->actingAs($this->user)
            ->postJson("{$this->base}/preview", $payload)
            ->assertStatus(429);
    });

    // =========================================================================
    // Cross-tenant INLINE-rule preview leak (BLOCKER — cross-tenant PII leak)
    // =========================================================================
    //
    // `preview` resolves fully caller-controlled `rule` JSON through the same
    // brand-unscoped resolvers as the broadcast `audience_inline` path. Before
    // the fix, a Brand-A admin could preview a rule naming Brand-B users/devices
    // and read back a recipient count + a 10-row sample of REAL foreign
    // recipient names. Post-fix, the same `assertInlineRecipientsOwned()` guard
    // that protects the delivery path aborts with 422 `audience_cross_tenant`
    // and leaks neither count nor names.
    // =========================================================================

    it('returns 422 and leaks nothing when previewing a foreign-org user rule', function () {
        $foreignOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $foreignOrgId,
            'console_organization_id' => $foreignOrgId,
        ]);
        $foreignUser = User::factory()->create([
            'console_organization_id' => $foreignOrgId,
            'name' => 'Foreign Victim',
        ]);

        $response = $this->actingAs($this->user)->postJson("{$this->base}/preview", [
            'rule' => [
                'rules' => [['type' => 'user', 'user_ids' => [$foreignUser->id]]],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('message', 'audience_cross_tenant');

        // No count and no name sample leaked.
        $body = $response->getContent();
        expect($body)->not->toContain('Foreign Victim')
            ->and($body)->not->toContain($foreignUser->id)
            ->and($response->json('data'))->toBeNull();
    });

    it('returns 422 when a preview rule mixes an own user with a foreign-org user', function () {
        $ownUser = User::factory()->create([
            'console_organization_id' => $this->orgId,
        ]);
        $foreignOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $foreignOrgId,
            'console_organization_id' => $foreignOrgId,
        ]);
        $foreignUser = User::factory()->create([
            'console_organization_id' => $foreignOrgId,
        ]);

        $this->actingAs($this->user)->postJson("{$this->base}/preview", [
            'rule' => [
                'rules' => [['type' => 'user', 'user_ids' => [$ownUser->id, $foreignUser->id]]],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'audience_cross_tenant');
    });

    it('returns 422 when previewing a foreign-org device rule', function () {
        $foreignOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $foreignOrgId,
            'console_organization_id' => $foreignOrgId,
        ]);
        $foreignBranch = Branch::factory()->create([
            'console_organization_id' => $foreignOrgId,
        ]);
        $foreignDevice = Device::factory()->create([
            'organization_id' => $foreignOrgId,
            'branch_id' => $foreignBranch->id,
            'name' => 'Foreign Terminal',
        ]);

        $response = $this->actingAs($this->user)->postJson("{$this->base}/preview", [
            'rule' => [
                'rules' => [['type' => 'device', 'device_ids' => [$foreignDevice->id]]],
            ],
        ]);

        $response->assertStatus(422)->assertJsonPath('message', 'audience_cross_tenant');
        expect($response->getContent())->not->toContain('Foreign Terminal');
    });

    it('still previews an own-org rule normally (guard does not over-reject)', function () {
        $targets = User::factory()->count(2)->create(['console_organization_id' => $this->orgId]);

        $this->actingAs($this->user)->postJson("{$this->base}/preview", [
            'rule' => [
                'rules' => [['type' => 'user', 'user_ids' => $targets->pluck('id')->all()]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonCount(2, 'data.sample');
    });
});

describe('authorization', function () {
    it('rejects a user without org binding', function () {
        $stranger = User::factory()->create(['console_organization_id' => (string) Str::uuid()]);

        $this->actingAs($stranger)->getJson($this->base)->assertForbidden();
    });
});

describe('cross-tenant authz pin (issue #173 part E)', function () {
    it('does not list audiences from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        NotificationAudience::factory()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
        ]);
        // Own audience
        NotificationAudience::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);

        $response = $this->actingAs($this->user)->getJson($this->base)->assertOk();
        expect($response->json('data'))->toHaveCount(1);
    });

    it('returns 404 when fetching an audience from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $foreign = NotificationAudience::factory()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/{$foreign->id}")
            ->assertNotFound();
    });

    it('returns 404 when updating an audience from another organization', function () {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'is_active' => true,
        ]);
        $foreign = NotificationAudience::factory()->create([
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
            'is_system' => false,
        ]);

        $this->actingAs($this->user)
            ->patchJson("{$this->base}/{$foreign->id}", ['name' => 'pwned'])
            ->assertNotFound();

        // Pin the row was NOT mutated.
        expect($foreign->fresh()->name)->not->toBe('pwned');
    });
});

describe('brand_id mass-assignment guard (issue #171)', function () {
    it('ignores caller-supplied brand_id and pins to the route brand', function () {
        // Create a SECOND brand the caller has SSO access to (same org), so
        // the caller is technically authorized for both — but the URL slug
        // names brand A, so the new audience must land on brand A regardless
        // of what the body says.
        $foreignBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user)->postJson($this->base, [
            'name' => 'aud-pin-test',
            'brand_id' => $foreignBrand->id, // attempt to pin elsewhere
            'rule' => [
                'version' => 1,
                'combinator' => 'or',
                'rules' => [['type' => 'role', 'role' => 'warehouse_manager']],
            ],
        ])->assertCreated();

        $createdId = $response->json('data.id');
        $persisted = NotificationAudience::find($createdId);

        expect($persisted->brand_id)->toBe($this->brand->id)
            ->and($persisted->brand_id)->not->toBe($foreignBrand->id);
    });

    it('ignores caller-supplied brand_id on update', function () {
        $foreignBrand = Brand::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);
        $audience = NotificationAudience::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'is_system' => false,
        ]);

        $this->actingAs($this->user)
            ->patchJson("{$this->base}/{$audience->id}", [
                'name' => 'renamed',
                'brand_id' => $foreignBrand->id,
            ])
            ->assertOk();

        expect($audience->fresh()->brand_id)->toBe($this->brand->id);
    });
});
