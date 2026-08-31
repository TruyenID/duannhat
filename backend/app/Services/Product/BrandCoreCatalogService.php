<?php

namespace App\Services\Product;

use App\Models\Brand;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * Provisions the canonical "Combo" ProductType for a brand.
 *
 * Idempotent — safe to call on a brand that already has the row.
 * Invoked from the `Brand::created` hook in AppServiceProvider (per-brand auto-provision)
 * and from BrandCoreCatalogSeeder (one-off backfill for existing brands).
 */
class BrandCoreCatalogService
{
    /** @var array<string, string> */
    private const COMBO_NAME = [
        'ja' => 'コンボ',
        'en' => 'Combo',
        'vi' => 'Combo',
    ];

    public function __construct(
        private readonly ProductTypeService $productTypes,
    ) {}

    public function ensureCombo(Brand $brand): void
    {
        $organization = Organization::where('console_organization_id', $brand->console_organization_id)->first();

        if (! $organization) {
            Log::warning('BrandCoreCatalogService: skipping combo provisioning, organization not synced yet', [
                'brand_id' => $brand->id,
                'console_organization_id' => $brand->console_organization_id,
            ]);

            return;
        }

        $this->productTypes->ensureComboProductType(
            $organization->id,
            $brand->id,
            self::COMBO_NAME,
        );
    }
}
