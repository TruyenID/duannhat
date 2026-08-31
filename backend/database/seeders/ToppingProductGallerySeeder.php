<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ToppingProductGallerySeeder — backfill gallery photos for products that
 * exist only AFTER the main gallery pass.
 *
 * `ProductGallerySeeder` runs early (right after ProductSeeder) so the menu
 * cards have photos. But the products that back topping-group items — the
 * "Sauce" / "Remove ingredient" rows the customer sees in the product modal —
 * are created LATER (menu-clone + topping seeders), so the first pass never
 * saw them and their thumbnails render as an empty grey placeholder.
 *
 * This re-runs the exact same, idempotent top-up now that every product
 * (toppings included) exists. `ProductGallerySeeder::topUpGallery()` skips any
 * product that already has enough photos, so re-running only fills the gaps —
 * safe on every `migrate:fresh --seed` and safe to run standalone via
 * `db:seed --class=ToppingProductGallerySeeder`.
 *
 * Registered LAST in DatabaseSeeder so it runs after all product-creating
 * seeders. Reuses ProductGallerySeeder rather than duplicating the on-disk
 * image-pool machinery.
 */
class ToppingProductGallerySeeder extends Seeder
{
    public function run(): void
    {
        $missing = Product::query()
            ->whereIn('id', DB::table('topping_group_items')->distinct()->pluck('product_id'))
            ->whereDoesntHave('files', fn ($q) => $q->where('collection', 'gallery'))
            ->count();

        $this->command?->info(
            "ToppingProductGallerySeeder: {$missing} topping product(s) missing a gallery photo — backfilling…"
        );

        // Idempotent top-up across every product; only the ones lacking photos
        // (the late-created topping products) actually get new files.
        $this->call(ProductGallerySeeder::class);
    }
}
