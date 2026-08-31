<?php

/**
 * Plan-023 M7 T7.5 (TESTS.md M7-13 / M7-14) — HQ NotificationRule CRUD.
 *
 * Covers the admin CRUD surface for `/api/v1/hq/{brandSlug}/notifications/rules*`
 * that no existing test exercised: index (pagination + filters + HQ scope),
 * store (is_active pinned false, brand pinned, validation + DSL rejection),
 * show (recent firings preview), update (toggle + field edit), destroy
 * (204 + firings cascade), cross-brand isolation, and dry-run.
 */

use App\Models\Brand;
use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
use App\Models\Organization;
use App\Models\Product;
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
        'slug' => 'rule-crud-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/rules";
});

function validRulePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Notify on approval',
        'description' => 'Fires when a product is approved.',
        'trigger_event' => 'model.updated',
        'trigger_model_type' => 'Product',
        'conditions' => [
            'combinator' => 'and',
            'children' => [
                ['field' => 'status', 'op' => 'changed_to', 'value' => 'approved'],
            ],
        ],
        'action' => [
            'template_key' => 'product.approved',
            'channels' => ['in_app'],
            'priority' => 'normal',
            'audience_rule' => ['type' => 'model_user', 'field' => 'created_by_id'],
        ],
        'cooldown_minutes' => 0,
    ], $overrides);
}

// ---------------------------------------------------------------- store

it('M7-13 store creates a rule that ships INACTIVE regardless of body is_active', function () {
    $response = $this->postJson($this->base, validRulePayload(['is_active' => true]))
        ->assertCreated();

    $id = $response->json('data.id');
    $row = NotificationRule::findOrFail($id);

    expect($row->is_active)->toBeFalse()
        ->and($row->brand_id)->toBe($this->brand->id)
        ->and($row->branch_id)->toBeNull()
        ->and($row->created_by_id)->toBe($this->user->id);
});

it('M7-13 store pins brand_id from the URL and ignores a spoofed body brand_id', function () {
    $otherBrand = Brand::factory()->create(['console_organization_id' => (string) Str::uuid()]);

    $response = $this->postJson($this->base, validRulePayload([
        'brand_id' => $otherBrand->id,
        'branch_id' => (string) Str::uuid(),
    ]))->assertCreated();

    $row = NotificationRule::findOrFail($response->json('data.id'));
    expect($row->brand_id)->toBe($this->brand->id)
        ->and($row->branch_id)->toBeNull();
});

it('M7-13 store rejects a missing name', function () {
    $this->postJson($this->base, validRulePayload(['name' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('M7-13 store rejects a malformed trigger_event', function () {
    $this->postJson($this->base, validRulePayload(['trigger_event' => 'model.exploded']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('trigger_event');
});

it('M7-13 store rejects invalid conditions DSL (unknown op)', function () {
    $payload = validRulePayload([
        'conditions' => [
            'combinator' => 'and',
            'children' => [
                ['field' => 'status', 'op' => 'wibble', 'value' => 'approved'],
            ],
        ],
    ]);

    $this->postJson($this->base, $payload)
        ->assertStatus(422)
        ->assertJsonPath('message', 'Invalid rule conditions DSL.');
});

// ---------------------------------------------------------------- index

it('M7-13 index paginates and excludes shop-scoped (branch_id) rows', function () {
    NotificationRule::factory()->count(3)->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
    ]);
    // A shop-scoped rule must never surface on the HQ list.
    NotificationRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => (string) Str::uuid(),
    ]);
    // A different brand's rule must never surface either.
    NotificationRule::factory()->create([
        'organization_id' => (string) Str::uuid(),
        'brand_id' => Brand::factory()->create()->id,
        'branch_id' => null,
    ]);

    $response = $this->getJson($this->base)->assertOk();

    expect($response->json('meta.total'))->toBe(3)
        ->and($response->json('data'))->toHaveCount(3);
});

it('M7-13 index filters by is_active and trigger_event', function () {
    NotificationRule::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => null,
        'is_active' => true, 'trigger_event' => 'model.created',
    ]);
    NotificationRule::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => null,
        'is_active' => false, 'trigger_event' => 'model.updated',
    ]);

    expect($this->getJson("{$this->base}?is_active=1")->assertOk()->json('meta.total'))->toBe(1);
    expect($this->getJson("{$this->base}?trigger_event=model.updated")->assertOk()->json('meta.total'))->toBe(1);
});

// ---------------------------------------------------------------- show

it('M7-13 show returns the rule with a recent_firings preview', function () {
    $rule = NotificationRule::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => null,
    ]);
    NotificationRuleFiring::factory()->count(2)->create([
        'rule_id' => $rule->id, 'outcome' => 'matched', 'model_type' => null, 'model_id' => null,
    ]);

    $this->getJson("{$this->base}/{$rule->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $rule->id)
        ->assertJsonCount(2, 'data.recent_firings');
});

// ---------------------------------------------------------------- update

it('M7-13 update toggles is_active and edits fields', function () {
    $rule = NotificationRule::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => null,
        'is_active' => false, 'name' => 'Old name',
    ]);

    $this->patchJson("{$this->base}/{$rule->id}", [
        'is_active' => true,
        'name' => 'New name',
    ])->assertOk();

    $fresh = $rule->fresh();
    expect($fresh->is_active)->toBeTrue()
        ->and($fresh->name)->toBe('New name');
});

// ---------------------------------------------------------------- destroy

it('M7-13 destroy returns 204 and removes the rule', function () {
    $rule = NotificationRule::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => null,
    ]);

    $this->deleteJson("{$this->base}/{$rule->id}")->assertNoContent();

    expect(NotificationRule::find($rule->id))->toBeNull();
    // Firing cascade is a DB-level FK (onDelete cascade) exercised in
    // RuleSchemaIntegrityTest; SQLite :memory: does not enforce it at runtime.
});

// ---------------------------------------------------------------- isolation

it('M7-13 cannot show/update/delete a rule that belongs to another brand', function () {
    $otherBrand = Brand::factory()->create(['console_organization_id' => (string) Str::uuid()]);
    $foreign = NotificationRule::factory()->create([
        'organization_id' => (string) Str::uuid(),
        'brand_id' => $otherBrand->id,
        'branch_id' => null,
    ]);

    $this->getJson("{$this->base}/{$foreign->id}")->assertNotFound();
    $this->patchJson("{$this->base}/{$foreign->id}", ['name' => 'hijack'])->assertNotFound();
    $this->deleteJson("{$this->base}/{$foreign->id}")->assertNotFound();
});

// ---------------------------------------------------------------- dry-run

it('M7-14 dry-run replays the rule against recent rows and returns matches', function () {
    // Match on `name` (a plain string column) — `status` is cast to an enum, so
    // a `=` string compare on it never matches through the evaluator.
    $rule = NotificationRule::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => null,
        'trigger_event' => 'model.updated',
        'trigger_model_type' => 'Product',
        'conditions' => ['field' => 'name', 'op' => '=', 'value' => 'DRYRUN_MATCH'],
        'is_active' => true,
    ]);

    Product::factory()->count(2)->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'name' => 'DRYRUN_MATCH',
    ]);
    Product::factory()->count(3)->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'name' => 'DRYRUN_OTHER',
    ]);

    $response = $this->postJson("{$this->base}/{$rule->id}/dry-run")->assertOk();

    expect($response->json('data.matched_count'))->toBe(2)
        ->and($response->json('data.sample'))->toHaveCount(2);
});
