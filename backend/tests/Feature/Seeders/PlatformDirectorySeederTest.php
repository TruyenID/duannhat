<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use Database\Seeders\PlatformDirectorySeeder;
use Illuminate\Support\Facades\Http;

it('seeds organization brands and branches exclusively from the Platform service directory', function () {
    config([
        'sso.issuer' => 'https://platform.test',
        'sso.service_slug' => 'tempo-test',
        'sso.client_secret' => 'service-secret',
        'sso.directory_organization_slug' => 'betoya',
    ]);

    Http::fake([
        'https://platform.test/api/sso/service/brands*' => Http::response([
            'organization' => ['id' => 'org-console-1', 'slug' => 'betoya', 'name' => 'Betoya Foods'],
            'brands' => [[
                'brand_id' => 'brand-console-1',
                'brand_slug' => 'betoya',
                'brand_name' => 'Betoya',
                'description' => 'Vietnamese food',
                'logo_url' => null,
                'is_active' => true,
            ]],
        ]),
        'https://platform.test/api/sso/service/branches*' => Http::response([
            'organization' => ['id' => 'org-console-1', 'slug' => 'betoya', 'name' => 'Betoya Foods'],
            'branches' => [[
                'id' => 'branch-console-1',
                'slug' => 'head-office',
                'code' => 'BETOYA-001',
                'name' => 'Head Office',
                'brand_id' => 'brand-console-1',
                'is_headquarters' => true,
                'is_active' => true,
                'timezone' => 'Asia/Tokyo',
                'currency' => 'JPY',
                'locale' => 'ja',
            ]],
        ]),
    ]);

    $this->seed(PlatformDirectorySeeder::class);

    expect(Organization::query()->where('console_organization_id', 'org-console-1')->exists())->toBeTrue()
        ->and(Brand::query()->where('console_brand_id', 'brand-console-1')->where('slug', 'betoya')->exists())->toBeTrue()
        ->and(Branch::query()->where('console_branch_id', 'branch-console-1')->where('slug', 'head-office')->exists())->toBeTrue();

    Http::assertSentCount(2);
});
