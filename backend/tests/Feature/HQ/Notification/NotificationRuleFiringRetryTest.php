<?php

/**
 * TC-NOTIF-FIR03 — retry a failed rule firing.
 *
 * POST /hq/{brandSlug}/notifications/rules/{id}/firings/{firing}/retry
 * re-runs the evaluation pipeline against the firing's model and records
 * a fresh firing row. Only `error` firings on an active rule are retryable.
 */

use App\Models\Brand;
use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
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
        'slug' => 'rule-retry-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
    $this->actingAs($this->user);

    // Active HQ rule (branch_id null) whose empty-AND conditions always match,
    // so a retry produces a non-error firing. cooldown 0 so retry is never
    // skipped. action has no template_key → dispatch is a no-op in any mode.
    $this->rule = NotificationRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'conditions' => ['combinator' => 'and', 'children' => []],
        'action' => ['template_key' => '', 'channels' => []],
        'cooldown_minutes' => 0,
        'is_active' => true,
    ]);

    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/rules/{$this->rule->id}/firings";
});

function makeErrorFiring($rule, $brand): NotificationRuleFiring
{
    // Retry re-loads the model by (model_type FQCN, model_id) — point it at the
    // brand row, which always exists, so loadModel succeeds.
    return NotificationRuleFiring::factory()->create([
        'rule_id' => $rule->id,
        'notification_id' => null,
        'model_type' => Brand::class,
        'model_id' => $brand->id,
        'outcome' => 'error',
        'error_message' => 'boom',
        'evaluation_trace' => [],
    ]);
}

it('TC-NOTIF-FIR03 retries a failed firing and records a new firing row', function () {
    $firing = makeErrorFiring($this->rule, $this->brand);

    $before = NotificationRuleFiring::where('rule_id', $this->rule->id)->count();

    $response = $this->postJson("{$this->base}/{$firing->id}/retry")->assertOk();

    // A brand-new firing row is returned, distinct from the failed one and not
    // itself an error.
    $newId = $response->json('data.id');
    expect($newId)->not->toBe($firing->id);
    expect($response->json('data.outcome'))->not->toBe('error');

    expect(NotificationRuleFiring::where('rule_id', $this->rule->id)->count())
        ->toBe($before + 1);
});

it('TC-NOTIF-FIR03 rejects retrying a non-error firing', function () {
    $firing = NotificationRuleFiring::factory()->create([
        'rule_id' => $this->rule->id,
        'notification_id' => null,
        'model_type' => Brand::class,
        'model_id' => $this->brand->id,
        'outcome' => 'matched',
        'error_message' => null,
        'evaluation_trace' => [],
    ]);

    $this->postJson("{$this->base}/{$firing->id}/retry")
        ->assertStatus(422)
        ->assertJsonPath('error', 'not_retryable');
});

it('TC-NOTIF-FIR03 rejects retrying when the rule is inactive', function () {
    $this->rule->update(['is_active' => false]);
    $firing = makeErrorFiring($this->rule, $this->brand);

    $this->postJson("{$this->base}/{$firing->id}/retry")
        ->assertStatus(422)
        ->assertJsonPath('error', 'rule_inactive');
});

it('TC-NOTIF-FIR03 rejects retrying a firing with no model', function () {
    $firing = NotificationRuleFiring::factory()->create([
        'rule_id' => $this->rule->id,
        'notification_id' => null,
        'model_type' => null,
        'model_id' => null,
        'outcome' => 'error',
        'error_message' => 'boom',
        'evaluation_trace' => [],
    ]);

    $this->postJson("{$this->base}/{$firing->id}/retry")
        ->assertStatus(422)
        ->assertJsonPath('error', 'model_missing');
});

it('TC-NOTIF-FIR03 404s for a firing that belongs to another rule', function () {
    $otherRule = NotificationRule::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_active' => true,
    ]);
    $firing = makeErrorFiring($otherRule, $this->brand);

    $this->postJson("{$this->base}/{$firing->id}/retry")->assertNotFound();
});
