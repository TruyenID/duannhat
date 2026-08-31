<?php

/**
 * Plan-023 M7 T7.11 — SystemNotificationRuleSeeder coverage.
 */

use App\Models\Brand;
use App\Models\NotificationRule;
use App\Models\Organization;
use Database\Seeders\SystemNotificationRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'rule-seed-'.Str::random(4),
        'is_active' => true,
    ]);
});

it('M7-T7.11 seeds 4 inactive shadow rules per brand', function () {
    app(SystemNotificationRuleSeeder::class)->run();

    $rules = NotificationRule::query()
        ->where('brand_id', $this->brand->id)
        ->where('name', 'like', 'Shadow:%')
        ->get();

    expect($rules)->toHaveCount(4);
    expect($rules->every(fn (NotificationRule $r) => $r->is_active === false))->toBeTrue();

    $triggers = $rules->pluck('trigger_model_type')->sort()->values()->all();
    expect($triggers)->toBe(['CustomerOrder', 'Recipe', 'Recipe', 'StockAlert']);
});

it('M7-T7.11 idempotent — running seeder twice does not duplicate', function () {
    app(SystemNotificationRuleSeeder::class)->run();
    app(SystemNotificationRuleSeeder::class)->run();

    expect(NotificationRule::query()->where('brand_id', $this->brand->id)->count())->toBe(4);
});

it('M7-T7.11 each rule carries valid conditions + action shape', function () {
    app(SystemNotificationRuleSeeder::class)->run();

    NotificationRule::query()
        ->where('brand_id', $this->brand->id)
        ->each(function (NotificationRule $rule) {
            $action = (array) $rule->action;
            expect($action)->toHaveKeys(['template_key', 'priority', 'channels', 'audience_name']);
            expect($action['template_key'])->toBeString();
            expect($action['channels'])->toBeArray();
        });
});

it('M8-T8.9 StockTransfer rules scope by the warehouse branch, not the raw warehouse_id', function () {
    // StockTransfer has no branch_id column. The branch-scoped audience resolver
    // reads `branch_field` as a branch id; feeding it destination_warehouse_id
    // would query role_user_pivots.branch_id against a warehouse UUID and resolve
    // to zero recipients. The seed must traverse the warehouse relation.
    $rules = collect(SystemNotificationRuleSeeder::systemRules())
        ->filter(fn (array $r) => $r['trigger_model_type'] === 'StockTransfer')
        ->keyBy(fn (array $r) => $r['conditions']['value']);

    expect($rules->get('in_transit')['action']['audience_rule']['branch_field'])
        ->toBe('destinationWarehouse.branch_id');
    expect($rules->get('completed')['action']['audience_rule']['branch_field'])
        ->toBe('sourceWarehouse.branch_id');

    // Never the raw warehouse FK.
    $rules->each(function (array $r) {
        expect($r['action']['audience_rule']['branch_field'])
            ->not->toBeIn(['destination_warehouse_id', 'source_warehouse_id']);
    });
});
