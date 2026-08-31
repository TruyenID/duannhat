<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PlatformDirectorySeeder extends Seeder
{
    public function run(): void
    {
        $organizationSlug = (string) config('sso.directory_organization_slug');
        if ($organizationSlug === '') {
            throw new RuntimeException('SSO_DIRECTORY_ORGANIZATION_SLUG is required.');
        }

        $brandsPayload = $this->client()->get($this->endpoint('brands'), [
            'organization_slug' => $organizationSlug,
        ])->throw()->json();
        $branchesPayload = $this->client()->get($this->endpoint('branches'), [
            'organization_slug' => $organizationSlug,
        ])->throw()->json();

        $organizationData = $brandsPayload['organization'] ?? null;
        if (! is_array($organizationData) || ! filled($organizationData['id'] ?? null)) {
            throw new RuntimeException('Platform directory returned no organization identity.');
        }

        $organization = Organization::withTrashed()->updateOrCreate(
            ['console_organization_id' => (string) $organizationData['id']],
            [
                'name' => (string) ($organizationData['name'] ?? $organizationSlug),
                'slug' => (string) ($organizationData['slug'] ?? $organizationSlug),
                'is_active' => true,
            ],
        );
        $organization->restore();

        foreach ($brandsPayload['brands'] ?? [] as $brandData) {
            if (! is_array($brandData) || ! filled($brandData['brand_id'] ?? null)) {
                continue;
            }

            $brand = Brand::withTrashed()->updateOrCreate(
                ['console_brand_id' => (string) $brandData['brand_id']],
                [
                    'console_organization_id' => $organization->console_organization_id,
                    'slug' => (string) $brandData['brand_slug'],
                    'name' => (string) $brandData['brand_name'],
                    'description' => $brandData['description'] ?? null,
                    'logo_url' => $brandData['logo_url'] ?? null,
                    'is_active' => (bool) ($brandData['is_active'] ?? true),
                ],
            );
            $brand->restore();
        }

        foreach ($branchesPayload['branches'] ?? [] as $branchData) {
            if (! is_array($branchData) || ! filled($branchData['id'] ?? null)) {
                continue;
            }

            $branch = Branch::withTrashed()->updateOrCreate(
                ['console_branch_id' => (string) $branchData['id']],
                [
                    'console_organization_id' => $organization->console_organization_id,
                    'console_brand_id' => filled($branchData['brand_id'] ?? null)
                        ? (string) $branchData['brand_id']
                        : null,
                    'code' => $branchData['code'] ?? null,
                    'slug' => (string) $branchData['slug'],
                    'name' => (string) $branchData['name'],
                    'is_headquarters' => (bool) ($branchData['is_headquarters'] ?? false),
                    'is_active' => (bool) ($branchData['is_active'] ?? true),
                    'timezone' => $branchData['timezone'] ?? null,
                    'currency' => $branchData['currency'] ?? null,
                    'locale' => $branchData['locale'] ?? null,
                ],
            );
            $branch->restore();
        }

        $this->command?->info(sprintf(
            'PlatformDirectorySeeder: %d brand(s), %d branch(es) synced for %s.',
            count($brandsPayload['brands'] ?? []),
            count($branchesPayload['branches'] ?? []),
            $organizationSlug,
        ));
    }

    private function client(): PendingRequest
    {
        $serviceSlug = (string) config('sso.service_slug');
        $serviceSecret = (string) config('sso.client_secret');
        if ($serviceSlug === '' || $serviceSecret === '') {
            throw new RuntimeException('Platform service directory credentials are not configured.');
        }

        return Http::acceptJson()->withHeaders([
            'X-Service-Slug' => $serviceSlug,
            'X-Service-Secret' => $serviceSecret,
        ])->timeout((int) config('sso.http_timeout', 5));
    }

    private function endpoint(string $resource): string
    {
        return rtrim((string) config('sso.issuer'), '/').'/api/sso/service/'.$resource;
    }
}
