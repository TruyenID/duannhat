<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Models\Product;
use App\Services\Promotion\MenuPromotionService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Plan-019 — seeds two demo promotions per demo branch:
 *
 *   1. "Late-night Happy Hour 20%" — applies_to=categories, picks the
 *      first 1–2 categories that look like drinks/dessert (matches by
 *      slug-ish name fragment), daily 21:00–23:00, every weekday,
 *      valid 30 days, stackable_with_coupons, active.
 *
 *   2. "Premium dish 50% exclusive" — applies_to=products, the
 *      branch's most expensive product, daily 14:00–17:00, weekdays
 *      Mon–Fri, valid 14 days, exclusive_with_coupons, active.
 *
 * Idempotent: matches on (branch_id, name.en). Re-runs sync rather
 * than duplicate.
 */
class MenuPromotionSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(MenuPromotionService $service): void
    {
        $branches = Branch::all();
        if ($branches->isEmpty()) {
            $this->command?->warn('MenuPromotionSeeder: no branches found, skipping.');

            return;
        }

        $count = 0;
        foreach ($branches as $branch) {
            $orgId = Organization::where('console_organization_id', $branch->console_organization_id ?? null)
                ->value('id')
                ?? Organization::first()?->id;

            // Two different Brand rows are in play and must NOT be conflated:
            //   • catalogBrandId — the brand the branch's products/categories
            //     actually belong to (derived from its menu). Needed to LOOK UP
            //     applicable categories/products.
            //   • adminBrandId — the brand resolved via console_brand_id, which
            //     is what the Shop admin filters promotions by. Must be stored
            //     as the promotion's brand_id or the admin list shows nothing.
            $catalogBrandId = $this->resolveCatalogBrandId($branch);
            $adminBrandId = Brand::where('console_brand_id', $branch->console_brand_id ?? null)->value('id');
            $lookupBrandId = $catalogBrandId ?? $adminBrandId;
            $storeBrandId = $adminBrandId ?? $catalogBrandId;

            if ($orgId === null || $lookupBrandId === null || $storeBrandId === null) {
                $this->command?->warn("MenuPromotionSeeder: branch {$branch->id} has no resolved org/brand, skipping.");

                continue;
            }

            foreach ($this->definitionsFor($branch, $lookupBrandId) as $row) {
                if ($row === null) {
                    continue;
                }

                $existing = MenuPromotion::query()
                    ->where('branch_id', $branch->id)
                    ->whereTranslation('name', $row['name:en'])
                    ->withTrashed()
                    ->first();

                $row['organization_id'] = $orgId;
                // Store the admin-facing brand so the Shop promotions list (which
                // filters by brand_id) can see it; categories were resolved with
                // the catalog brand above.
                $row['brand_id'] = $storeBrandId;
                $row['branch_id'] = $branch->id;

                try {
                    if ($existing) {
                        $service->update($existing, $row);
                    } else {
                        $service->create($row);
                    }
                    $count++;
                } catch (\Throwable $e) {
                    // BR-MP-LOCK: once a promotion has been applied to orders its
                    // contract fields are frozen, so re-seeding can't re-sync it.
                    // That's fine for a demo seeder — skip and keep going.
                    $this->command?->warn("MenuPromotionSeeder: skip '{$row['name:en']}' on branch {$branch->id} — {$e->getMessage()}");
                }
            }
        }

        $this->command?->info("MenuPromotionSeeder: {$count} promotions seeded across ".$branches->count().' branch(es).');
    }

    /**
     * Catalog brand the branch actually serves — taken from the brand_id of
     * the products in its active menu. Falls back to null when the branch has
     * no menu yet (caller then tries console_brand_id).
     */
    private function resolveCatalogBrandId(Branch $branch): ?string
    {
        $menuId = Menu::where('branch_id', $branch->id)->value('id');
        if ($menuId === null) {
            return null;
        }

        $productId = MenuProduct::where('menu_id', $menuId)
            ->where('is_active', true)
            ->value('product_id');

        return $productId === null
            ? null
            : Product::where('id', $productId)->value('brand_id');
    }

    /**
     * @return array<int, array<string, mixed>|null>
     */
    private function definitionsFor(Branch $branch, string $brandId): array
    {
        $now = now();

        // Pick drink + dessert categories for happy hour (best-effort).
        $drinkLikeCats = Category::query()
            ->where('brand_id', $brandId)
            ->where(function ($q) {
                $q->whereTranslationLike('name', '%drink%')
                    ->orWhereTranslationLike('name', '%beverage%')
                    ->orWhereTranslationLike('name', '%đồ uống%')
                    ->orWhereTranslationLike('name', '%ドリンク%')
                    ->orWhereTranslationLike('name', '%dessert%')
                    ->orWhereTranslationLike('name', '%tráng miệng%')
                    ->orWhereTranslationLike('name', '%デザート%');
            })
            ->limit(2)
            ->pluck('id')
            ->all();

        // Fallback: just take the first two categories so the seeder still
        // runs against a freshly-seeded brand without thematic categories.
        if ($drinkLikeCats === []) {
            $drinkLikeCats = Category::query()
                ->where('brand_id', $brandId)
                ->limit(2)
                ->pluck('id')
                ->all();
        }

        // Product is brand-scoped, not branch-scoped — Premium pick is
        // the most recently-added product in the brand. Good enough for
        // a demo seed; the real policy when a shop manager actually
        // creates this promotion is "shop manager picks the product".
        $premiumProduct = Product::query()
            ->where('brand_id', $brandId)
            ->orderByDesc('id')
            ->first();

        $defs = [];

        // Demo all-day Happy Hour — always inside its daily window (00:00–23:59,
        // every weekday) so the discount + strikethrough renders on the customer
        // menu regardless of the hour you open it. Applies to the first few
        // brand categories for broad visual coverage.
        $allDayCats = Category::query()
            ->where('brand_id', $brandId)
            ->orderBy('id')
            ->limit(3)
            ->pluck('id')
            ->all();

        if ($allDayCats !== []) {
            $defs[] = [
                'name:ja' => 'デモ・ハッピーアワー 15%（終日）',
                'name:en' => 'Demo Happy Hour 15% (all-day)',
                'name:vi' => 'Happy Hour demo 15% (cả ngày)',
                'description:vi' => 'Giảm 15% cả ngày để demo hiển thị giá khuyến mãi trên menu khách.',
                'discount_percent' => 15,
                'applies_to' => 'categories',
                'applicable_category_ids' => $allDayCats,
                'daily_time_from' => '00:00',
                'daily_time_to' => '23:59',
                'weekdays' => null, // every day
                'valid_from' => $now->copy()->subDay(),
                'valid_until' => $now->copy()->addDays(30),
                'stacking_mode' => 'stackable_with_coupons',
                'is_active' => true,
            ];
        }

        if ($drinkLikeCats !== []) {
            $defs[] = [
                'name:ja' => '深夜ハッピーアワー 20%',
                'name:en' => 'Late-night Happy Hour 20%',
                'name:vi' => 'Happy Hour cuối ngày 20%',
                'description:vi' => 'Giảm 20% đồ uống & tráng miệng từ 21:00 đến 23:00 mỗi ngày.',
                'discount_percent' => 20,
                'applies_to' => 'categories',
                'applicable_category_ids' => $drinkLikeCats,
                'daily_time_from' => '21:00',
                'daily_time_to' => '23:00',
                'weekdays' => null, // every day
                'valid_from' => $now->copy()->subDay(),
                'valid_until' => $now->copy()->addDays(30),
                'stacking_mode' => 'stackable_with_coupons',
                'is_active' => true,
            ];
        }

        if ($premiumProduct !== null) {
            $defs[] = [
                'name:ja' => 'プレミアム料理 50% (併用不可)',
                'name:en' => 'Premium dish 50% (exclusive)',
                'name:vi' => 'Món Premium giảm 50% (không cộng coupon)',
                'description:vi' => 'Giảm 50% món premium từ 14:00 đến 17:00 các ngày trong tuần.',
                'discount_percent' => 50,
                'applies_to' => 'products',
                'applicable_product_ids' => [$premiumProduct->id],
                'daily_time_from' => '14:00',
                'daily_time_to' => '17:00',
                'weekdays' => [1, 2, 3, 4, 5],
                'valid_from' => $now->copy()->subDay(),
                'valid_until' => $now->copy()->addDays(14),
                'stacking_mode' => 'exclusive_with_coupons',
                'is_active' => true,
            ];
        }

        return $defs;
    }
}
