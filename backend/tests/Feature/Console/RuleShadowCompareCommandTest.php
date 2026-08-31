<?php

/**
 * Plan-023 M7 T7.12 — `notifications:rule-shadow-compare` smoke.
 */

use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationRule;
use App\Models\NotificationRuleFiring;
use App\Models\Organization;
use Database\Seeders\SystemNotificationRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
        'slug' => 'shadow-cmp-'.Str::random(4),
        'is_active' => true,
    ]);

    // Seed the 4 shadow rules so the command has rule rows to walk.
    app(SystemNotificationRuleSeeder::class)->run();
});

it('M7-T7.12 reports parity when hardcoded + shadow counts match', function () {
    // No notifications, no firings → every counter is 0 → trivially matches.
    $exit = Artisan::call('notifications:rule-shadow-compare', ['--since' => '1d']);
    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('Parity confirmed');
});

it('M7-T7.12 reports drift when shadow under-fires', function () {
    $rule = NotificationRule::query()->where('brand_id', $this->brand->id)->first();
    $action = (array) $rule->action;

    // One hardcoded notification, zero shadow firings — drift.
    Notification::factory()->create([
        'organization_id' => $this->orgId,
        'type' => $action['template_key'],
        'subject_id' => 'trigger-1',
    ]);

    $exit = Artisan::call('notifications:rule-shadow-compare', ['--since' => '1d']);
    expect($exit)->toBe(1);
    expect(Artisan::output())->toContain('Drift detected');
});

it('M7-T7.12 writes CSV to --output', function () {
    $path = tempnam(sys_get_temp_dir(), 'shadow-cmp-').'.csv';
    try {
        Artisan::call('notifications:rule-shadow-compare', ['--since' => '1d', '--output' => $path]);
        expect(file_exists($path))->toBeTrue();
        expect(file_get_contents($path))->toContain('emitter,rule_id,trigger_id');
    } finally {
        @unlink($path);
    }
});

it('M7-T7.12 includes a matched shadow firing without flagging drift', function () {
    $rule = NotificationRule::query()->where('brand_id', $this->brand->id)->first();
    $action = (array) $rule->action;

    Notification::factory()->create([
        'organization_id' => $this->orgId,
        'type' => $action['template_key'],
        'subject_id' => 'trigger-match-1',
    ]);
    NotificationRuleFiring::query()->create([
        'id' => (string) Str::uuid(),
        'rule_id' => $rule->id,
        'model_type' => $rule->trigger_model_type,
        'model_id' => 'trigger-match-1',
        'outcome' => 'shadow',
        'fired_at' => now(),
    ]);

    $exit = Artisan::call('notifications:rule-shadow-compare', ['--since' => '1d']);
    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('Parity confirmed');
});
