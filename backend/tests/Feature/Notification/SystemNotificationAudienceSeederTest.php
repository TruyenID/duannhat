<?php

/**
 * Idempotency + coverage test for SystemNotificationAudienceSeeder
 * (plan-012 T1.6).
 */

use App\Models\Brand;
use App\Models\NotificationAudience;
use App\Models\Organization;
use Database\Seeders\SystemNotificationAudienceSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $orgId,
        'slug' => 'seeder-'.Str::random(4),
    ]);
});

it('creates the baseline is_system audiences for every brand', function () {
    app(SystemNotificationAudienceSeeder::class)->run();

    $audiences = NotificationAudience::query()->where('brand_id', $this->brand->id)->get();
    expect($audiences)->toHaveCount(3);
    expect($audiences->every(fn ($a) => $a->is_system === true))->toBeTrue();
    expect($audiences->pluck('name')->all())->toContain('All warehouse managers', 'All shop managers', 'All brand admins');
});

it('is idempotent — running the seeder twice does not duplicate rows', function () {
    app(SystemNotificationAudienceSeeder::class)->run();
    app(SystemNotificationAudienceSeeder::class)->run();

    expect(NotificationAudience::query()->where('brand_id', $this->brand->id)->count())->toBe(3);
});
