<?php

/**
 * Plan-023 M8 — end-to-end rule-firing tests for the notification rule engine.
 *
 * These cover the critical path the plan-audit flagged as broken: a matched
 * NotificationRule must resolve its `action.audience_rule` into real recipients
 * and dispatch a Notification. Before the fix, EvaluateRuleJob passed
 * `recipients => []` unconditionally, so every active system rule threw
 * NotificationException::recipientsEmpty() and delivered zero notifications.
 *
 * They also lock in the NOTIFICATION_USE_RULES guard: flipping the flag must
 * never silently disable a hardcoded emitter that has no live replacement rule.
 */

use App\Jobs\Notification\EvaluateRuleJob;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockAlert;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMember;
use App\Services\Notification\AudienceRuleResolver;
use App\Services\Notification\NotificationService;
use App\Services\Notification\RuleEvaluatorService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give a user a role in an org (+ optional branch) via the role_user_pivots
 * table the AudienceRuleResolver reads.
 */
function ruleEngineRole(User $user, string $slug, string $consoleOrgId, string $orgId, ?string $branchId = null): void
{
    $role = Role::firstOrCreate(
        ['slug' => $slug],
        [
            'id' => (string) Str::uuid(),
            'console_organization_id' => $consoleOrgId,
            'name' => ucfirst(str_replace('_', ' ', $slug)),
            'level' => 100,
        ],
    );

    DB::table('role_user_pivots')->insert([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'organization_id' => $orgId,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function () {
    // Rule engine only dispatches when the flag is on. Default it on for the
    // dispatch scenarios; the emitter-guard block flips it explicitly.
    config()->set('notifications.use_rules', true);

    $this->consoleOrgId = (string) Str::uuid();
    $this->org = Organization::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);
});

/**
 * Build an active Product rule that fires on status changed_to $value and
 * routes to the given audience_rule.
 */
function productRule(string $orgId, string $value, array $audienceRule, array $paramTemplate = []): NotificationRule
{
    return NotificationRule::factory()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $orgId,
        'brand_id' => null,
        'branch_id' => null,
        'name' => 'test product '.$value.' '.Str::random(4),
        'trigger_event' => 'model.updated',
        'trigger_model_type' => 'Product',
        'conditions' => ['field' => 'status', 'op' => 'changed_to', 'value' => $value],
        'action' => [
            'template_key' => 'product.'.$value,
            'priority' => 'normal',
            'channels' => ['in_app'],
            'audience_rule' => $audienceRule,
            'param_template' => $paramTemplate,
        ],
        'cooldown_minutes' => 0,
        'is_active' => true,
        'last_fired_at' => null,
        'fire_count' => 0,
    ]);
}

function runRule(NotificationRule $rule, Product $product, array $changes, array $context = []): void
{
    (new EvaluateRuleJob($rule->id, Product::class, (string) $product->id, $changes, $context))
        ->handle(
            app(RuleEvaluatorService::class),
            app(NotificationService::class),
            app(AudienceRuleResolver::class),
        );
}

// =========================================================================
//  Audience resolution — the core bug fix
// =========================================================================

it('M8-e2e-1: role_scoped rule resolves brand admins and dispatches to them', function () {
    $admin = User::factory()->create();
    ruleEngineRole($admin, 'org-admin', $this->consoleOrgId, $this->org->id);

    $product = Product::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'status' => 'pending',
    ]);

    $rule = productRule($this->org->id, 'pending', [
        'type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'brand_id',
    ]);

    runRule($rule, $product, ['status' => ['draft', 'pending']]);

    $n = Notification::where('type', 'product.pending')->latest('id')->first();
    expect($n)->not->toBeNull()
        ->and($n->recipients()->count())->toBe(1)
        ->and($n->recipients()->first()->recipient_id)->toBe($admin->id);

    expect(NotificationRuleFiring::where('rule_id', $rule->id)->where('outcome', 'matched')->count())->toBe(1);
    expect($rule->fresh()->fire_count)->toBe(1);
});

it('M8-e2e-2: model_user rule dispatches to the model FK user (submitter)', function () {
    $submitter = User::factory()->create();

    $product = Product::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'status' => 'approved',
        'created_by_id' => $submitter->id,
    ]);

    $rule = productRule($this->org->id, 'approved', [
        'type' => 'model_user', 'field' => 'created_by_id',
    ]);

    runRule($rule, $product, ['status' => ['pending', 'approved']]);

    $n = Notification::where('type', 'product.approved')->latest('id')->first();
    expect($n)->not->toBeNull()
        ->and($n->recipients()->count())->toBe(1)
        ->and($n->recipients()->first()->recipient_id)->toBe($submitter->id);
});

it('M8-e2e-3: matched rule whose audience resolves to nobody logs skipped_no_recipients, no notification', function () {
    // No brand_admin user exists → audience resolves empty.
    $product = Product::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'status' => 'pending',
    ]);

    $rule = productRule($this->org->id, 'pending', [
        'type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'brand_id',
    ]);

    $before = Notification::count();
    runRule($rule, $product, ['status' => ['draft', 'pending']]);

    expect(Notification::count())->toBe($before);
    expect(NotificationRuleFiring::where('rule_id', $rule->id)->where('outcome', 'skipped_no_recipients')->count())->toBe(1);
    // Not an `error` outcome — recipientsEmpty() must not be thrown.
    expect(NotificationRuleFiring::where('rule_id', $rule->id)->where('outcome', 'error')->count())->toBe(0);
});

it('M8-e2e-4: param_template resolves $model and $context into notification params', function () {
    $admin = User::factory()->create();
    ruleEngineRole($admin, 'org-admin', $this->consoleOrgId, $this->org->id);

    $product = Product::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'status' => 'pending',
        'name' => 'Miso Ramen',
    ]);

    $rule = productRule($this->org->id, 'pending', [
        'type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'brand_id',
    ], ['product_name' => '$model.name', 'reason' => '$context.reason', 'static' => 'lit']);

    runRule($rule, $product, ['status' => ['draft', 'pending']], ['reason' => 'low margin']);

    $n = Notification::where('type', 'product.pending')->latest('id')->first();
    expect($n->params['product_name'])->toBe('Miso Ramen')
        ->and($n->params['reason'])->toBe('low margin')
        ->and($n->params['static'])->toBe('lit');
});

// =========================================================================
//  Gating outcomes
// =========================================================================

it('M8-e2e-5: an M8 domain rule dispatches even when use_rules=false (no hardcoded emitter to shadow)', function () {
    // Regression: the shadow gate used to suppress EVERY active rule, so the M8
    // system rules — the sole delivery path for their domains — never fired
    // under the shipped NOTIFICATION_USE_RULES=false default. Product has no
    // hardcoded emitter, so its rule must dispatch regardless of the flag.
    config()->set('notifications.use_rules', false);

    $admin = User::factory()->create();
    ruleEngineRole($admin, 'org-admin', $this->consoleOrgId, $this->org->id);

    $product = Product::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'status' => 'pending',
    ]);

    $rule = productRule($this->org->id, 'pending', [
        'type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'brand_id',
    ]);

    $before = Notification::count();
    runRule($rule, $product, ['status' => ['draft', 'pending']]);

    expect(Notification::count())->toBe($before + 1);
    expect(NotificationRuleFiring::where('rule_id', $rule->id)->where('outcome', 'matched')->count())->toBe(1);
    expect(NotificationRuleFiring::where('rule_id', $rule->id)->where('outcome', 'shadow')->count())->toBe(0);
});

it('M8-e2e-5b: a rule shadowing a hardcoded emitter records shadow and dispatches nothing when use_rules=false', function () {
    // StockAlert:model.created still has StockAlertNotificationObserver as its
    // live emitter, so its shadow rule must NOT dispatch while the flag is off.
    config()->set('notifications.use_rules', false);

    $manager = User::factory()->create();
    ruleEngineRole($manager, 'warehouse_manager', $this->consoleOrgId, $this->org->id);

    $warehouse = Warehouse::factory()->create(['organization_id' => $this->org->id]);
    $alert = StockAlert::factory()->create([
        'organization_id' => $this->org->id,
        'warehouse_id' => $warehouse->id,
    ]);

    $rule = NotificationRule::factory()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->org->id,
        'brand_id' => null,
        'branch_id' => null,
        'name' => 'shadow stock alert '.Str::random(4),
        'trigger_event' => 'model.created',
        'trigger_model_type' => 'StockAlert',
        'conditions' => ['combinator' => 'and', 'children' => []],
        'action' => [
            'template_key' => 'stock.alert.low',
            'priority' => 'high',
            'channels' => ['in_app'],
            'audience_rule' => ['type' => 'role_scoped', 'role' => 'warehouse_manager', 'scope' => 'organization', 'org_field' => 'organization_id'],
            'param_template' => [],
        ],
        'cooldown_minutes' => 0,
        'is_active' => true,
        'fire_count' => 0,
    ]);

    $before = Notification::count();
    (new EvaluateRuleJob($rule->id, StockAlert::class, (string) $alert->id, []))
        ->handle(
            app(RuleEvaluatorService::class),
            app(NotificationService::class),
            app(AudienceRuleResolver::class),
        );

    expect(Notification::count())->toBe($before);
    expect(NotificationRuleFiring::where('rule_id', $rule->id)->where('outcome', 'shadow')->count())->toBe(1);
});

it('M8-e2e-6: condition mismatch records skipped_condition and dispatches nothing', function () {
    $admin = User::factory()->create();
    ruleEngineRole($admin, 'org-admin', $this->consoleOrgId, $this->org->id);

    $product = Product::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'status' => 'draft',
    ]);

    // Rule wants status changed_to pending, but the change is to draft.
    $rule = productRule($this->org->id, 'pending', [
        'type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'brand_id',
    ]);

    $before = Notification::count();
    runRule($rule, $product, ['status' => ['approved', 'draft']]);

    expect(Notification::count())->toBe($before);
    expect(NotificationRuleFiring::where('rule_id', $rule->id)->where('outcome', 'skipped_condition')->count())->toBe(1);
});

// =========================================================================
//  Full bridge path (Eloquent event → bridge → job → dispatch, sync queue)
// =========================================================================

it('M8-e2e-7: updating a model fires the rule through the bridge end to end', function () {
    $admin = User::factory()->create();
    ruleEngineRole($admin, 'org-admin', $this->consoleOrgId, $this->org->id);

    $rule = productRule($this->org->id, 'pending', [
        'type' => 'role_scoped', 'role' => 'org-admin', 'scope' => 'brand', 'brand_field' => 'brand_id',
    ], ['product_name' => '$model.name']);

    $product = Product::factory()->create([
        'organization_id' => $this->org->id,
        'brand_id' => $this->brand->id,
        'status' => 'draft',
    ]);

    $before = Notification::where('type', 'product.pending')->count();

    // Real update → eloquent.updated event → ModelEventToRuleBridge → sync job.
    $product->update(['status' => 'pending']);

    expect(Notification::where('type', 'product.pending')->count())->toBe($before + 1);
    $n = Notification::where('type', 'product.pending')->latest('id')->first();
    expect($n->recipients()->first()->recipient_id)->toBe($admin->id);
});

// =========================================================================
//  NOTIFICATION_USE_RULES emitter guard — no silent coverage gaps
// =========================================================================

it('M8-e2e-8: flag on but no covering rule → hardcoded emitter still fires (no coverage gap)', function () {
    config()->set('notifications.use_rules', true);

    $branch = Branch::factory()->create(['console_organization_id' => $this->consoleOrgId]);
    $warehouse = Warehouse::factory()->create([
        'organization_id' => $this->org->id,
        'branch_id' => $branch->id,
    ]);
    $manager = User::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);
    grantOrgAccess($manager, $this->org->id);
    WarehouseMember::factory()->create([
        'warehouse_id' => $warehouse->id,
        'user_id' => $manager->id,
        'role' => 'manager',
    ]);

    // No NotificationRule covers StockAlert → hasActiveCoverage() is false →
    // the observer must NOT short-circuit even with the flag on.
    expect(NotificationRule::hasActiveCoverage('StockAlert', 'model.created', $this->org->id))->toBeFalse();

    $before = Notification::where('type', 'stock.alert.low')->count();

    StockAlert::create([
        'organization_id' => $this->org->id,
        'warehouse_id' => $warehouse->id,
        'alert_type' => 'low_stock',
        'current_quantity' => 2,
        'min_stock' => 10,
        'unit' => 'kg',
        'status' => 'active',
    ]);

    // The hardcoded observer stayed active and delivered — flipping the flag on
    // did not silently drop the notification.
    expect(Notification::where('type', 'stock.alert.low')->count())->toBe($before + 1);
});

it('M8-e2e-9: active covering rule with audience_rule → emitter short-circuits', function () {
    config()->set('notifications.use_rules', true);

    // A live replacement rule for StockAlert with a resolvable audience.
    NotificationRule::factory()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->org->id,
        'brand_id' => null,
        'branch_id' => null,
        'name' => 'cover stock '.Str::random(4),
        'trigger_event' => 'model.created',
        'trigger_model_type' => 'StockAlert',
        'conditions' => ['combinator' => 'and', 'children' => []],
        'action' => [
            'template_key' => 'stock.alert.low',
            'audience_rule' => ['type' => 'model_user', 'field' => 'created_by_id'],
            'param_template' => [],
        ],
        'cooldown_minutes' => 0,
        'is_active' => true,
        'fire_count' => 0,
    ]);

    expect(NotificationRule::hasActiveCoverage('StockAlert', 'model.created', $this->org->id))->toBeTrue();
});

it('M8-e2e-10: a shadow rule (audience_name only, no audience_rule) is not coverage', function () {
    NotificationRule::factory()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->org->id,
        'brand_id' => null,
        'branch_id' => null,
        'name' => 'shadow stock '.Str::random(4),
        'trigger_event' => 'model.created',
        'trigger_model_type' => 'StockAlert',
        'conditions' => ['combinator' => 'and', 'children' => []],
        'action' => [
            'template_key' => 'stock.alert.low',
            'audience_name' => 'All warehouse managers', // placeholder, NOT audience_rule
            'param_template' => [],
        ],
        'cooldown_minutes' => 0,
        'is_active' => true,
        'fire_count' => 0,
    ]);

    expect(NotificationRule::hasActiveCoverage('StockAlert', 'model.created', $this->org->id))->toBeFalse();
});

// =========================================================================
//  StockTransfer branch-scoped audience — warehouse→branch translation
// =========================================================================

it('M8-transfer-audience: in_transit rule resolves the destination warehouse branch managers', function () {
    // StockTransfer has no branch_id column; the branch-scoped audience must
    // traverse the warehouse relation to reach branch_id. Feeding the raw
    // warehouse_id would query role_user_pivots.branch_id against a warehouse
    // UUID and resolve to nobody.
    $branchId = (string) Str::uuid();

    $manager = User::factory()->create();
    ruleEngineRole($manager, 'warehouse_manager', $this->consoleOrgId, $this->org->id, $branchId);

    $destWarehouse = Warehouse::factory()->create([
        'organization_id' => $this->org->id,
        'branch_id' => $branchId,
    ]);
    $srcWarehouse = Warehouse::factory()->create([
        'organization_id' => $this->org->id,
        'branch_id' => (string) Str::uuid(),
    ]);

    $transfer = StockTransfer::factory()->create([
        'organization_id' => $this->org->id,
        'source_warehouse_id' => $srcWarehouse->id,
        'destination_warehouse_id' => $destWarehouse->id,
        'status' => 'in_transit',
    ]);

    $resolver = app(AudienceRuleResolver::class);

    // Fixed rule: traverse the relation to the branch.
    $resolved = $resolver->resolve(
        ['type' => 'role_scoped', 'role' => 'warehouse_manager', 'scope' => 'branch', 'branch_field' => 'destinationWarehouse.branch_id'],
        $transfer->fresh(),
    );
    expect($resolved->pluck('id')->all())->toContain($manager->id);

    // Buggy rule (raw warehouse_id) resolves to nobody — documents the defect.
    $broken = $resolver->resolve(
        ['type' => 'role_scoped', 'role' => 'warehouse_manager', 'scope' => 'branch', 'branch_field' => 'destination_warehouse_id'],
        $transfer->fresh(),
    );
    expect($broken->pluck('id')->all())->not->toContain($manager->id);
});
