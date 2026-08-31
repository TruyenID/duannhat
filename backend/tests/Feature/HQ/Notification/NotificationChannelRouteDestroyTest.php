<?php

/**
 * DELETE /hq/{brand}/notifications/channel-routes/{type} tests (plan-012 polish).
 *
 * Frontend routing-matrix delete button hits this endpoint. Behaviour:
 * - Deletes the route row scoped to the brand's orgs if present.
 * - Returns 204 even when the row was already absent (idempotent).
 * - Rejects outsiders with 403.
 */

use App\Models\Brand;
use App\Models\NotificationChannelRoute;
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
        'slug' => 'del-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);
    $this->base = "/api/v1/hq/{$this->brand->slug}/notifications/channel-routes";
});

it('removes an existing route', function () {
    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'type' => 'stock.alert.low',
        'channels' => ['in_app', 'email'],
    ]);

    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/stock.alert.low")
        ->assertNoContent();

    expect(NotificationChannelRoute::query()->where('type', 'stock.alert.low')->count())->toBe(0);
});

it('returns 204 even when the route was already absent', function () {
    $this->actingAs($this->user)
        ->deleteJson("{$this->base}/no.such.type")
        ->assertNoContent();
});

it('forbids outsiders', function () {
    NotificationChannelRoute::query()->create([
        'organization_id' => $this->orgId,
        'type' => 'stock.alert.low',
        'channels' => ['in_app'],
    ]);

    $outsider = User::factory()->create([]);
    $this->actingAs($outsider)
        ->deleteJson("{$this->base}/stock.alert.low")
        ->assertForbidden();
});
