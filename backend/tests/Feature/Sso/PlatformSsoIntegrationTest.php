<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\User;
use App\Sso\UserProvisioner;
use Dxs\Auth\Contracts\ProvisionsUsers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

it('registers the server-side Platform SSO routes', function () {
    expect(Route::has('sso.redirect'))->toBeTrue()
        ->and(Route::has('sso.callback'))->toBeTrue()
        ->and(Route::has('sso.logout'))->toBeTrue();
});

it('keeps BFF redirects on the configured browser-facing origin', function () {
    config(['sso.public_url' => 'https://tempo.godx.jp']);
    URL::forceRootUrl(rtrim((string) config('sso.public_url'), '/'));
    URL::forceScheme('https');

    expect(url('/select-context'))->toBe('https://tempo.godx.jp/select-context');

    URL::forceRootUrl(null);
    URL::forceScheme(null);
});

it('binds the app provisioner and stores verified Platform identity tokens', function () {
    Http::fake([
        'https://platform.test/api/sso/organizations' => Http::response([[
            'organization_id' => 'cb77c7a3-62b0-54c2-b6dd-091429113b31',
            'organization_slug' => 'betoya',
            'organization_name' => 'Betoya',
            'service_role' => 'admin',
            'service_role_level' => 100,
        ]]),
        'https://platform.test/api/sso/branches*' => Http::response([
            'all_branches_access' => true,
            'branches' => [[
                'id' => '75b90978-303e-57f0-8bc6-e146ad493051',
                'slug' => 'head-office',
                'code' => 'BETOYA-001',
                'name' => 'Head Office',
                'is_headquarters' => true,
                'timezone' => 'Asia/Tokyo',
                'currency' => 'JPY',
                'locale' => 'ja',
                'brand_id' => '00000001-bbbb-4bbb-bbbb-000000000001',
            ]],
        ]),
        'https://platform.test/api/sso/brands*' => Http::response([
            'all_brands_access' => true,
            'brands' => [[
                'brand_id' => '00000001-bbbb-4bbb-bbbb-000000000001',
                'brand_slug' => 'betoya',
                'brand_name' => 'Betoya',
                'is_active' => true,
            ]],
        ]),
    ]);

    $provisioner = app(ProvisionsUsers::class);

    expect($provisioner)->toBeInstanceOf(UserProvisioner::class);

    $user = $provisioner->provision([
        'sub' => 'platform-subject-1',
        'name' => 'Betoya Admin',
        'email' => 'admin@betoya.jp',
        'organization_context_id' => 'cb77c7a3-62b0-54c2-b6dd-091429113b31',
    ], [
        'access_token' => 'platform-access-token',
        'refresh_token' => 'platform-refresh-token',
        'expires_in' => 900,
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->console_user_id)->toBe('platform-subject-1')
        ->and($user->console_organization_id)->toBe('cb77c7a3-62b0-54c2-b6dd-091429113b31')
        ->and($user->console_access_token)->toBe('platform-access-token')
        ->and($user->console_refresh_token)->toBe('platform-refresh-token')
        ->and($user->roles()->where('slug', 'tempo-admin')->exists())->toBeTrue()
        ->and($user->roles()->wherePivotNull('branch_id')->where('slug', 'tempo-admin')->exists())->toBeTrue()
        ->and(Brand::query()->where('slug', 'betoya')->where('is_active', true)->exists())->toBeTrue()
        ->and(Branch::query()->where('slug', 'head-office')->where('is_active', true)->exists())->toBeTrue();
});

it('replaces organization-wide access with the exact Platform branch scope for a member', function () {
    Http::fake([
        'https://platform.test/api/sso/organizations' => Http::response([[
            'organization_id' => 'cb77c7a3-62b0-54c2-b6dd-091429113b31',
            'organization_slug' => 'betoya',
            'organization_name' => 'Betoya',
            'service_role' => 'member',
            'service_role_level' => 10,
        ]]),
        'https://platform.test/api/sso/brands*' => Http::response([
            'all_brands_access' => false,
            'brands' => [[
                'brand_id' => '00000001-bbbb-4bbb-bbbb-000000000001',
                'brand_slug' => 'betoya',
                'brand_name' => 'Betoya',
                'is_active' => true,
            ]],
        ]),
        'https://platform.test/api/sso/branches*' => Http::response([
            'all_branches_access' => false,
            'branches' => [[
                'id' => '75b90978-303e-57f0-8bc6-e146ad493051',
                'slug' => 'head-office',
                'code' => 'BETOYA-001',
                'name' => 'Head Office',
                'is_headquarters' => true,
                'timezone' => 'Asia/Tokyo',
                'currency' => 'JPY',
                'locale' => 'ja',
                'brand_id' => '00000001-bbbb-4bbb-bbbb-000000000001',
            ]],
        ]),
    ]);

    $provisioner = app(ProvisionsUsers::class);
    $user = $provisioner->provision([
        'sub' => 'platform-member-1',
        'name' => 'Betoya Member',
        'email' => 'member@betoya.jp',
        'organization_context_id' => 'cb77c7a3-62b0-54c2-b6dd-091429113b31',
    ], [
        'access_token' => 'platform-member-token',
        'expires_in' => 900,
    ]);

    $branch = Branch::query()->where('slug', 'head-office')->firstOrFail();

    expect($user->roles()->where('slug', 'tempo-member')->wherePivot('branch_id', $branch->id)->exists())->toBeTrue()
        ->and($user->roles()->wherePivotNull('branch_id')->exists())->toBeFalse();
});

it('declares every Tempo tenant permission for the Platform admin role', function () {
    $permissions = collect(config('authz.permissions'))->pluck('slug');
    $admin = collect(config('authz.roles'))->firstWhere('role', 'admin');

    expect($permissions)->toHaveCount(33)
        ->and($permissions)->not->toContain('system.cross_tenant.access')
        ->and($admin['permissions'])->toHaveCount(33)
        ->and($admin['permissions'])->toEqualCanonicalizing($permissions->all());
});
