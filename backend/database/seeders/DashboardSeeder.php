<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds historical order data for the HQ dashboard.
 *
 * Fixes:
 *  1. Creates categories (メイン / サイド / ドリンク) per brand if missing.
 *  2. Assigns active products to categories via product_category pivot.
 *  3. Ensures products are in active status.
 *  4. Creates 7 months of closed orders across all non-HQ branches for EACH brand.
 *
 * Safe to re-run — skips brands that already have historical orders.
 */
class DashboardSeeder extends Seeder
{
    use RefusesToRunInProduction;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $org = Organization::first();
        if (! $org) {
            $this->command->error('No organization found.');

            return;
        }

        $user = User::first();
        if (! $user) {
            $this->command->error('No user found.');

            return;
        }

        $cashMethod = PaymentMethod::where('code', 'cash')->first();

        $brands = Brand::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->get();

        if ($brands->isEmpty()) {
            $this->command->error('No active brands found.');

            return;
        }

        foreach ($brands as $brand) {
            $this->command->info("Processing brand: {$brand->name} (slug: {$brand->slug})");
            $this->seedBrand($org, $brand, $user, $cashMethod);
        }
    }

    private function seedBrand(
        Organization $org,
        Brand $brand,
        User $user,
        ?PaymentMethod $cashMethod,
    ): void {
        $orgId = $org->id;
        $brandId = $brand->id;
        $userId = $user->id;

        $shopBranches = Branch::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->where('is_headquarters', false)
            ->get();

        if ($shopBranches->isEmpty()) {
            $this->command->warn('  No shop branches — skipping.');

            return;
        }

        // Skip if historical data already exists (more than 31 days back).
        $historyExists = CustomerOrder::where('brand_id', $brandId)
            ->where('status', 'closed')
            ->where('created_at', '<', now()->subDays(31))
            ->exists();

        if ($historyExists) {
            $this->command->info('  Historical orders already exist — skipping.');

            return;
        }

        // ── 1. Categories ────────────────────────────────────────────────────
        //
        // Only a brand without a catalog needs the main/side/drink bootstrap.
        // One that already carries its own categories (CatalogSnapshotSeeder)
        // would otherwise show three extra top-level rows in the admin tree,
        // holding a re-classification of products it already files elsewhere.
        // The historical orders below are what this seeder is actually for, and
        // they read the existing catalog either way.
        $bootstrapCategories = ! Category::where('brand_id', $brandId)->exists();

        $catDefs = [
            'CAT-MAIN' => [
                'slug' => 'main',
                'ja' => 'メイン',
                'en' => 'Main',
                'vi' => 'Món chính',
            ],
            'CAT-SIDE' => [
                'slug' => 'side',
                'ja' => 'サイド',
                'en' => 'Side',
                'vi' => 'Món phụ',
            ],
            'CAT-DRINK' => [
                'slug' => 'drink',
                'ja' => 'ドリンク',
                'en' => 'Drink',
                'vi' => 'Đồ uống',
            ],
        ];

        // Categories are org-scoped (unique on organization_id + sku), shared across brands.
        $categories = [];
        foreach ($bootstrapCategories ? $catDefs : [] as $sku => $def) {
            $category = Category::firstOrCreate(
                ['organization_id' => $orgId, 'sku' => $sku],
                [
                    'brand_id' => $brandId,
                    'name' => $def['ja'],
                    'slug' => $def['slug'],
                    'is_active' => true,
                ]
            );

            foreach (['ja', 'en', 'vi'] as $locale) {
                $trans = $category->translateOrNew($locale);
                if (empty($trans->name)) {
                    $trans->name = $def[$locale];
                    $trans->save();
                }
            }

            $categories[$sku] = $category;
        }

        // ── 2. Assign products to categories + activate ──────────────────────

        $products = Product::where('brand_id', $brandId)
            ->whereIn('status', ['active', 'approved'])
            ->get();

        $activatedCount = 0;
        $pivotCount = 0;

        foreach ($products as $product) {
            if ($product->status !== 'active') {
                $product->status = 'active';
                $product->save();
                $activatedCount++;
            }

            if (! $bootstrapCategories) {
                continue;
            }

            $typeCode = optional($product->productType)->code;
            $catSku = match ($typeCode) {
                'DRINK' => 'CAT-DRINK',
                'FOOD' => $this->guessFoodCategory($product->slug),
                default => 'CAT-MAIN',
            };

            $category = $categories[$catSku] ?? $categories['CAT-MAIN'];

            $alreadyLinked = DB::table('product_category')
                ->where('product_id', $product->id)
                ->where('category_id', $category->id)
                ->exists();

            if (! $alreadyLinked) {
                DB::table('product_category')->insert([
                    'product_id' => $product->id,
                    'category_id' => $category->id,
                ]);
                $pivotCount++;
            }
        }

        $this->command->info("  Activated {$activatedCount} products, linked {$pivotCount} category pairs.");

        // ── 3. SKU pool ──────────────────────────────────────────────────────

        $skuPool = ProductSku::whereHas('product', fn ($q) => $q->where('brand_id', $brandId)->where('status', 'active'))
            ->where('is_active', true)
            ->with('product')
            ->get();

        if ($skuPool->isEmpty()) {
            $this->command->warn('  No active SKUs — skipping order generation.');

            return;
        }

        // ── 4. Historical orders (7 months × branches) ──────────────────────
        //
        // Bulk-insert path: build rows in memory, then send four chunked
        // INSERTs per brand (orders, items, conditions, payments) inside one
        // transaction. Skips Eloquent events/observers — fine here, the
        // seed data is internal and totals are calculated up-front.

        $orderNum = (int) (DB::table('customer_orders')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(order_code, '-', -1) AS UNSIGNED)) as max_num")
            ->value('max_num') ?? 0);

        $year = now()->year;

        $orderRows = [];
        $itemRows = [];
        $conditionRows = [];
        $paymentRows = [];

        $branchTableIdsCache = [];
        foreach ($shopBranches as $branch) {
            $branchTableIdsCache[$branch->id] = Table::where('branch_id', $branch->id)
                ->where('is_active', true)
                ->pluck('id')
                ->all();
        }

        $skuArray = $skuPool->all();
        $skuCount = count($skuArray);

        // #2221 / #2188 — dòng đơn demo cũng phải stamp thuế đủ ngay từ đầu:
        // seeder này viết trước plan-043 và là nguồn của 11.308 dòng NULL
        // tax_rate trên mọi DB fresh. Default tax theo branch (chuỗi resolution
        // thật: ShopOrderSetting.default_tax_type_id → tax_types.rate).
        $branchTaxCache = [];
        foreach ($shopBranches as $branch) {
            $tax = DB::table('shop_order_settings')
                ->join('tax_types', 'tax_types.id', '=', 'shop_order_settings.default_tax_type_id')
                ->where('shop_order_settings.branch_id', $branch->id)
                ->first(['tax_types.id as id', 'tax_types.rate as rate', 'shop_order_settings.currency_code as currency']);
            $branchTaxCache[$branch->id] = $tax
                ? [(string) $tax->id, (float) $tax->rate, (string) ($tax->currency ?? 'JPY')]
                : [null, 0.0, 'JPY'];
        }

        for ($monthOffset = 7; $monthOffset >= 0; $monthOffset--) {
            $monthStart = now()->subMonths($monthOffset)->startOfMonth();
            $monthEnd = $monthOffset === 0
                ? now()
                : now()->subMonths($monthOffset)->endOfMonth();
            $monthSpanSeconds = (int) $monthStart->diffInSeconds($monthEnd);

            $baseOrders = (int) round(15 + (7 - $monthOffset) * 2.5 + rand(-3, 3));

            foreach ($shopBranches as $branch) {
                $branchOrders = $baseOrders + rand(-2, 4);
                $branchTableIds = $branchTableIdsCache[$branch->id];

                for ($i = 0; $i < $branchOrders; $i++) {
                    $orderNum++;

                    $createdAt = Carbon::instance($monthStart)->addSeconds(rand(0, $monthSpanSeconds));
                    $checkoutAt = $createdAt->copy()->addMinutes(rand(15, 45));
                    $closedAt = $createdAt->copy()->addMinutes(rand(46, 60));

                    $discount = rand(0, 10) > 8 ? rand(100, 500) : 0;
                    $orderType = rand(0, 3) > 0 ? 'dine_in' : 'takeaway';
                    $tableId = ($orderType === 'dine_in' && $branchTableIds !== [])
                        ? $branchTableIds[array_rand($branchTableIds)]
                        : null;

                    $orderId = (string) Str::orderedUuid();

                    // Items (build before knowing total).
                    $itemCount = rand(2, 5);
                    $sub = 0;
                    $taxSum = 0;
                    [$taxTypeId, $taxRate, $currency] = $branchTaxCache[$branch->id];
                    $servedBase = $createdAt->copy();
                    for ($k = 0; $k < $itemCount; $k++) {
                        $sku = $skuArray[rand(0, $skuCount - 1)];
                        $qty = rand(1, 3);
                        // issue #875 — order line price comes from the menu price
                        // (selling_price); cost_price is now 0 for seeded SKUs.
                        $price = (int) $sku->selling_price;
                        $subtotal = $qty * $price;
                        $sub += $subtotal;

                        // Giá seed là 税込 (JP default #2108) → thuế nội hàm.
                        $lineTax = (int) ($subtotal - round($subtotal / (1 + $taxRate / 100)));
                        $taxSum += $lineTax;

                        $itemRows[] = [
                            'id' => (string) Str::orderedUuid(),
                            'customer_order_id' => $orderId,
                            'product_sku_id' => $sku->id,
                            'quantity' => $qty,
                            'unit_price' => $price,
                            'original_unit_price' => $price,
                            'subtotal' => $subtotal,
                            'tax_type_id' => $taxTypeId,
                            'tax_rate' => $taxRate,
                            'tax_amount' => $lineTax,
                            'status' => 'served',
                            'served_at' => $servedBase->copy()->addMinutes(rand(10, 25)),
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ];
                    }

                    // Kẹp giống `OrderPricingCalculator` (`min($discount, $subtotal)`)
                    // TRƯỚC khi dùng, chứ không chỉ kẹp tổng bằng `max(0, …)`.
                    //
                    // Kẹp một đầu thôi thì dòng `discount` dưới kia ghi con số CHƯA
                    // kẹp: đo được 9 đơn có `dis > sub` (410 − 499), sổ khai giảm
                    // ¥499 trong khi thực tế chỉ giảm ¥410. Đó đúng là điều
                    // `WritesCustomerOrders::writeConditions` cấm — *"Ghi con số
                    // chưa kẹp vào sổ là nói dối về tiền"* — và nó làm mọi phép
                    // đối chiếu `subtotal − discount ≡ total` vỡ, kể cả dòng
                    // 端数調整 của pos-web (hiện +¥89 trên một đơn không có gì để
                    // làm tròn).
                    $discount = min($discount, $sub);
                    $total = max(0, $sub - $discount);

                    $orderRows[] = [
                        'id' => $orderId,
                        'order_code' => sprintf('ORD-%d-%05d', $year, $orderNum),
                        'order_type' => $orderType,
                        'table_id' => $tableId,
                        'status' => 'closed',
                        'subtotal' => $sub,
                        'total_amount' => $total,
                        'paid_amount' => $total,
                        // Đơn này ĐÃ định giá 税込 ở trên — `$lineTax` là thuế RÚT
                        // RA từ giá, và `$total = $sub − $discount` cố ý KHÔNG
                        // cộng thuế lên. Cờ phải nói đúng điều đó.
                        //
                        // Bỏ trống thì cột mặc định `0`, và 2.702 đơn demo mỗi
                        // lần seed đi khai "giá chưa gồm thuế" trong khi tiền của
                        // chính chúng nói ngược lại. Mọi consumer rẽ nhánh theo cờ
                        // đều đọc sai chế độ: dòng 端数調整 của pos-web,
                        // `showGrossSummary` của giỏ hàng, khối thuế trên phiếu in
                        // của máy trạm (`print_tax_blocks.go`), `KioskOrderResource`,
                        // `OrderPaidInvoiceMail`. Không có gì kêu — chỉ là các con
                        // số sai trên màn hình người ta dùng để soát tiền.
                        'is_tax_included' => true,
                        'total_tip' => 0,
                        'guest_count' => rand(1, 6),
                        'opened_at' => $createdAt,
                        'checkout_at' => $checkoutAt,
                        'closed_at' => $closedAt,
                        'created_by_id' => $userId,
                        'branch_id' => $branch->id,
                        'brand_id' => $brandId,
                        'organization_id' => $orgId,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];

                    if ($taxSum > 0) {
                        $conditionRows[] = [
                            'id' => (string) Str::orderedUuid(),
                            'conditionable_type' => (new CustomerOrder)->getMorphClass(),
                            'conditionable_id' => $orderId,
                            'type' => 'tax',
                            'source' => 'tax_type',
                            'label' => rtrim(rtrim(number_format($taxRate, 2), '0'), '.').'%',
                            'rate' => $taxRate,
                            'amount' => $taxSum,
                            'taxable_base' => max(0, $total - $taxSum),
                            'currency_code' => $currency,
                            'meta' => json_encode(['rate_group' => (string) $taxRate]),
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ];
                    }
                    if ($discount > 0) {
                        $conditionRows[] = [
                            'id' => (string) Str::orderedUuid(),
                            'conditionable_type' => (new CustomerOrder)->getMorphClass(),
                            'conditionable_id' => $orderId,
                            'type' => 'discount',
                            'source' => 'manual',
                            'label' => 'Discount',
                            'rate' => null,
                            'amount' => -$discount,
                            'taxable_base' => null,
                            'currency_code' => $currency,
                            'meta' => null,
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ];
                    }

                    if ($cashMethod) {
                        $paymentRows[] = [
                            'id' => (string) Str::orderedUuid(),
                            'payment_code' => sprintf('PAY-%d-%05d', $year, $orderNum),
                            'payment_method_id' => $cashMethod->id,
                            'customer_order_id' => $orderId,
                            'amount' => $total,
                            'tip_amount' => 0,
                            'status' => 'succeeded',
                            'paid_at' => $closedAt,
                            'received_by_id' => $userId,
                            'branch_id' => $branch->id,
                            'brand_id' => $brandId,
                            'organization_id' => $orgId,
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ];
                    }
                }
            }
        }

        DB::transaction(function () use ($orderRows, $itemRows, $conditionRows, $paymentRows): void {
            foreach (array_chunk($orderRows, 500) as $chunk) {
                DB::table('customer_orders')->insert($chunk);
            }
            foreach (array_chunk($itemRows, 500) as $chunk) {
                DB::table('customer_order_items')->insert($chunk);
            }
            foreach (array_chunk($conditionRows, 500) as $chunk) {
                DB::table('order_conditions')->insert($chunk);
            }
            foreach (array_chunk($paymentRows, 500) as $chunk) {
                DB::table('order_payments')->insert($chunk);
            }
        });

        $this->command->info('  Seeded '.count($orderRows)." historical orders across {$shopBranches->count()} branches.");
    }

    private function guessFoodCategory(string $slug): string
    {
        $sideItems = ['goi-cuon', 'cha-gio', 'goi-du-du', 'banh-flan', 'che', 'xoi-hat-sen'];

        return in_array($slug, $sideItems, true) ? 'CAT-SIDE' : 'CAT-MAIN';
    }
}
