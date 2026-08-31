<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductSku;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds ProductReview rows with realistic comments + updates aggregates.
 *
 * Creates fake closed orders as scaffolding for reviews, then populates
 * product_reviews with a mix of thumbs up/down and optional comments.
 * Idempotent — clears previous review data before re-seeding.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=ProductRatingSeeder
 */
class ProductRatingSeeder extends Seeder
{
    /** Sample positive comments (ja/en/vi mix — realistic for the project) */
    private const POSITIVE_COMMENTS = [
        'とても美味しかったです！また食べたい。',
        '味付けが絶妙でした。',
        'ボリュームたっぷりで大満足です。',
        '友達にもおすすめしたい！',
        'スタッフの対応も良くて、料理も最高。',
        'Really delicious, will order again!',
        'Great portion size and fresh ingredients.',
        'Best pho I have had in a while.',
        'Perfectly seasoned, loved every bite.',
        'Rất ngon, sẽ quay lại lần sau!',
        'Món này đúng vị quê nhà.',
        'Phần ăn nhiều, giá cả hợp lý.',
    ];

    /** Sample negative comments */
    private const NEGATIVE_COMMENTS = [
        'ちょっと味が薄かったです。',
        '期待ほどではなかった。',
        'A bit too salty for my taste.',
        'Portion was smaller than expected.',
        'Hơi mặn so với khẩu vị của mình.',
        'Đợi hơi lâu nhưng chất lượng bình thường.',
    ];

    public function run(): void
    {
        $org = Organization::first();
        $brand = Brand::where('is_active', true)->first();
        $branch = Branch::where('is_active', true)
            ->where('is_headquarters', false)
            ->first();

        if (! $org || ! $brand || ! $branch) {
            $this->command->error('Missing org/brand/branch. Run the main seeder first.');

            return;
        }

        $products = Product::whereNull('deleted_at')
            ->where('brand_id', $brand->id)
            ->get();

        if ($products->isEmpty()) {
            $this->command->warn('No products found for this brand.');

            return;
        }

        // Clean previous review data
        ProductReview::where('organization_id', $org->id)->delete();
        Product::where('brand_id', $brand->id)->update([
            'review_up_count' => 0,
            'review_total_count' => 0,
        ]);

        $reviewCount = 0;
        $commentCount = 0;

        DB::transaction(function () use ($org, $brand, $branch, $products, &$reviewCount, &$commentCount) {
            foreach ($products as $product) {
                // ~30% of products have no reviews
                if (fake()->boolean(30)) {
                    continue;
                }

                // Each product gets 5-30 reviews
                $numReviews = fake()->numberBetween(5, 30);
                $ups = 0;

                // Get a SKU for the order items
                $sku = ProductSku::where('product_id', $product->id)->first();
                if (! $sku) {
                    continue;
                }

                for ($i = 0; $i < $numReviews; $i++) {
                    // Create a minimal closed order as scaffold
                    $order = CustomerOrder::create([
                        'order_code' => 'REV-'.Str::upper(Str::random(8)),
                        'order_type' => fake()->randomElement(['dine_in', 'takeaway']),
                        'status' => 'closed',
                        'subtotal' => 1000,
                        'discount_amount' => 0,
                        'service_charge' => 0,
                        'tax_amount' => 0,
                        'total_amount' => 1000,
                        'paid_amount' => 1000,
                        'total_tip' => 0,
                        'opened_at' => now()->subDays(fake()->numberBetween(1, 60)),
                        'closed_at' => now()->subDays(fake()->numberBetween(0, 59)),
                        'branch_id' => $branch->id,
                        'brand_id' => $brand->id,
                        'organization_id' => $org->id,
                    ]);

                    $item = CustomerOrderItem::create([
                        'customer_order_id' => $order->id,
                        'product_sku_id' => $sku->id,
                        'quantity' => 1,
                        'unit_price' => 1000,
                        'original_unit_price' => 1000,
                        'subtotal' => 1000,
                        // #2411 — 0% là giá trị TÁC GIẢ chọn, không phải "chưa
                        // biết": đơn demo này khai `tax_amount => 0` và
                        // `total_amount == subtotal`, nên bất kỳ tỉ lệ nào khác
                        // sẽ mâu thuẫn với chính số tiền nó vừa ghi.
                        'tax_rate' => 0,
                        'status' => 'served',
                    ]);

                    // 80% thumbs up
                    $sentiment = fake()->boolean(80) ? 'up' : 'down';
                    if ($sentiment === 'up') {
                        $ups++;
                    }

                    // 40% leave a comment
                    $comment = null;
                    if (fake()->boolean(40)) {
                        $comment = $sentiment === 'up'
                            ? fake()->randomElement(self::POSITIVE_COMMENTS)
                            : fake()->randomElement(self::NEGATIVE_COMMENTS);
                        $commentCount++;
                    }

                    ProductReview::create([
                        'product_id' => $product->id,
                        'customer_order_id' => $order->id,
                        'customer_order_item_id' => $item->id,
                        'customer_id' => null,
                        'sentiment' => $sentiment,
                        'comment' => $comment,
                        'organization_id' => $org->id,
                        'brand_id' => $brand->id,
                        'branch_id' => $branch->id,
                    ]);

                    $reviewCount++;
                }

                // Update aggregates
                $product->update([
                    'review_up_count' => $ups,
                    'review_total_count' => $numReviews,
                ]);
            }
        });

        $productCount = Product::where('brand_id', $brand->id)
            ->where('review_total_count', '>', 0)
            ->count();

        $this->command->info("Seeded {$reviewCount} reviews ({$commentCount} with comments) across {$productCount} products.");
    }
}
