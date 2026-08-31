<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Services\Product\BrandCoreCatalogService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Backfills the canonical "Combo" ProductType + "Combos" Category for every
 * existing brand. Idempotent — safe to re-run after each `db:seed`.
 *
 * New brands receive the same provisioning automatically via `Brand::created` hook;
 * this seeder exists for brands that pre-date that hook (or whose Organization
 * arrived later via SSO sync after the observer skipped).
 */
class BrandCoreCatalogSeeder extends Seeder
{
    use WithoutModelEvents;

    public function __construct(private readonly BrandCoreCatalogService $catalog) {}

    public function run(): void
    {
        Brand::query()->each(function (Brand $brand): void {
            $this->catalog->ensureCombo($brand);
        });
    }
}
