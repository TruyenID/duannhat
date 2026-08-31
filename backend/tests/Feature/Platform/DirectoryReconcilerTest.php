<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Services\Platform\DirectoryReconciler;
use Illuminate\Console\Scheduling\Schedule;

/**
 * #3143 (ADR 0002, layer 3) — every drift shape the sweep must catch, and every
 * shape it must NOT report.
 *
 * The false-positive half matters as much as the other: a report that cries on
 * correct behaviour is a report that stops being read, and then the real drift
 * scrolls past with it.
 */
beforeEach(function (): void {
    $this->organization = Organization::factory()->create([
        'console_organization_id' => 'org-remote-1',
        'name' => 'Betoya',
        'slug' => 'betoya',
        'operating_country' => 'JP',
    ]);

    $this->remoteOrganization = [
        'organization_id' => 'org-remote-1',
        'organization_name' => 'Betoya',
        'organization_slug' => 'betoya',
        'country' => 'JP',
    ];
});

it('reports nothing when the mirror matches', function (): void {
    $drift = app(DirectoryReconciler::class)
        ->compare($this->organization, $this->remoteOrganization, [], []);

    expect($drift)->toBe([]);
});

it('reports a renamed organization', function (): void {
    $drift = app(DirectoryReconciler::class)->compare(
        $this->organization,
        [...$this->remoteOrganization, 'organization_name' => 'Betoya Holdings'],
        [],
        [],
    );

    expect($drift)->toHaveCount(1)
        ->and($drift[0]['field'])->toBe('name')
        ->and($drift[0]['local'])->toBe('Betoya')
        ->and($drift[0]['remote'])->toBe('Betoya Holdings');
});

it('reports a branch Platform knows about that the mirror never received', function (): void {
    // THE shape a missed write path produces, and the reason this sweep exists.
    $drift = app(DirectoryReconciler::class)->compare(
        $this->organization,
        $this->remoteOrganization,
        [],
        [['id' => 'branch-remote-9', 'name' => 'Hongo', 'slug' => 'hongo']],
    );

    expect($drift)->toHaveCount(1)
        ->and($drift[0])->toMatchArray([
            'entity' => 'branch',
            'id' => 'branch-remote-9',
            'field' => '*',
            'local' => null,
            'remote' => 'present',
        ]);
});

it('reports a mirrored branch Platform no longer returns, as its own shape', function (): void {
    Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_branch_id' => 'branch-local-only',
    ]);

    $drift = app(DirectoryReconciler::class)
        ->compare($this->organization, $this->remoteOrganization, [], []);

    // Not folded in with "missing locally": Platform may legitimately scope what
    // it returns, so the two directions need different verdicts from a human.
    expect($drift)->toHaveCount(1)
        ->and($drift[0]['local'])->toBe('present')
        ->and($drift[0]['remote'])->toBeNull();
});

it('reports a field difference on a mirrored brand', function (): void {
    Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => 'brand-remote-1',
        'name' => 'Old name',
        'slug' => 'betoya-cafe',
    ]);

    $drift = app(DirectoryReconciler::class)->compare(
        $this->organization,
        $this->remoteOrganization,
        [['brand_id' => 'brand-remote-1', 'brand_name' => 'New name', 'brand_slug' => 'betoya-cafe']],
        [],
    );

    expect($drift)->toHaveCount(1)
        ->and($drift[0]['field'])->toBe('name');
});

it('does NOT report a field Platform did not send', function (): void {
    // `UserProvisioner` adopts several fields only when present — an older
    // Platform without `country` must not reset an already-mirrored value. So a
    // missing key is the mirror behaving as designed, not drift.
    $remote = $this->remoteOrganization;
    unset($remote['country']);

    expect(app(DirectoryReconciler::class)->compare($this->organization, $remote, [], []))->toBe([]);
});

it('does NOT report boolean values that merely differ in representation', function (): void {
    Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => 'brand-bool',
        'is_active' => true,
        'slug' => 'b',
        'name' => 'B',
    ]);

    // `1` over JSON versus `true` in the column is the same fact. Reporting it
    // would fill the output with noise on every single row.
    $drift = app(DirectoryReconciler::class)->compare(
        $this->organization,
        $this->remoteOrganization,
        [['brand_id' => 'brand-bool', 'is_active' => 1, 'brand_slug' => 'b', 'brand_name' => 'B']],
        [],
    );

    expect($drift)->toBe([]);
});

it('does NOT report a branch is_active difference, because that column is not a mirror', function (): void {
    // `UserProvisioner::syncBranches()` hardcodes `is_active => true`. Comparing
    // it would report drift on every inactive upstream branch forever — a guard
    // crying on correct behaviour is a guard someone silences.
    Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_branch_id' => 'branch-inactive',
        'is_active' => true,
        'slug' => 'x',
        'name' => 'X',
    ]);

    $drift = app(DirectoryReconciler::class)->compare(
        $this->organization,
        $this->remoteOrganization,
        [],
        [['id' => 'branch-inactive', 'is_active' => false, 'slug' => 'x', 'name' => 'X']],
    );

    expect($drift)->toBe([]);
});

it('treats an empty string upstream as the NULL it is locally', function (): void {
    Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => 'brand-empty',
        'description' => null,
        'slug' => 'e',
        'name' => 'E',
    ]);

    $drift = app(DirectoryReconciler::class)->compare(
        $this->organization,
        $this->remoteOrganization,
        [['brand_id' => 'brand-empty', 'description' => '', 'brand_slug' => 'e', 'brand_name' => 'E']],
        [],
    );

    expect($drift)->toBe([]);
});

it('#3143 — is on the scheduler, because a sweep nobody runs proves nothing', function (): void {
    // The whole value of layer 3 is that it runs unattended: a drift detector
    // that only fires when someone remembers is a drift detector that reports on
    // the day you already suspected something. Registering it is therefore part
    // of the feature, not an ops afterthought — the sibling command
    // `payments:legacy-removal-readiness` needed its own fix (#2840) for exactly
    // this omission.
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'platform:reconcile-directory'));

    expect($event)->not->toBeNull('platform:reconcile-directory is not scheduled — register it in routes/console.php.')
        // `--strict` is what turns drift into a FAILED run, which is the only
        // part cron can notice.
        ->and($event->command)->toContain('--strict')
        ->and($event->expression)->toBe('40 4 * * *');
});
