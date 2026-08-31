<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\TaxType;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Seeds realistic Customer & Order data for frontend testing.
 *
 * Run: php artisan db:seed --class=CustomerOrderSeeder
 *
 * Creates: 2 zones, 6 tables, 3 products with SKUs, 10 customers, 15 orders
 * with 2-4 items each across all statuses. Safe to run multiple times —
 * skips if customers already exist.
 */
class CustomerOrderSeeder extends Seeder
{
    use RefusesToRunInProduction;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $org = Organization::first();

        $branch = Branch::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->where('is_headquarters', false)
            ->orderBy('created_at')
            ->first();

        $brand = $branch
            ? $branch->brand
            : Brand::where('console_organization_id', $org->console_organization_id)
                ->where('is_active', true)
                ->orderBy('created_at')
                ->first();

        $user = User::first();

        if (! $org || ! $brand || ! $branch || ! $user) {
            $this->command->error('Missing org/brand/branch/user. Run the main seeder first.');

            return;
        }

        $orgId = $org->id;
        $brandId = $brand->id;
        $branchId = $branch->id;
        $userId = $user->id;

        if (Customer::where('organization_id', $org->id)->exists()) {
            $this->command->info('Customers already seeded — skipping to table assignment.');
            $this->assignOrphanedTables($orgId, $brandId, $branchId, $userId);

            return;
        }

        // =================================================================
        //  Zones + Tables
        // =================================================================

        $zoneA = Zone::firstOrCreate(
            ['code' => 'MAIN', 'branch_id' => $branchId],
            ['name' => 'Main Hall', 'organization_id' => $orgId, 'is_active' => true],
        );

        $zoneB = Zone::firstOrCreate(
            ['code' => 'TER', 'branch_id' => $branchId],
            ['name' => 'Terrace', 'organization_id' => $orgId, 'is_active' => true],
        );

        $tables = [];
        foreach ([
            ['code' => 'A1', 'name' => 'Table A1', 'seat_count' => 2, 'zone_id' => $zoneA->id],
            ['code' => 'A2', 'name' => 'Table A2', 'seat_count' => 4, 'zone_id' => $zoneA->id],
            ['code' => 'A3', 'name' => 'Table A3', 'seat_count' => 4, 'zone_id' => $zoneA->id],
            ['code' => 'B1', 'name' => 'Table B1', 'seat_count' => 6, 'zone_id' => $zoneA->id],
            ['code' => 'T1', 'name' => 'Terrace 1', 'seat_count' => 2, 'zone_id' => $zoneB->id],
            ['code' => 'T2', 'name' => 'Terrace 2', 'seat_count' => 4, 'zone_id' => $zoneB->id],
        ] as $t) {
            $tables[] = Table::firstOrCreate(
                ['code' => $t['code'], 'branch_id' => $branchId],
                array_merge($t, [
                    'branch_id' => $branchId,
                    'organization_id' => $orgId,
                    'status' => 'free',
                    'is_active' => true,
                    'qr_token' => bin2hex(random_bytes(16)),
                ]),
            );
        }

        // =================================================================
        //  Products + SKUs
        // =================================================================

        $pt = ProductType::where('brand_id', $brandId)->first()
            ?? ProductType::factory()->create([
                'name' => 'Beverage',
                'organization_id' => $orgId,
                'brand_id' => $brandId,
            ]);

        // A brand that already carries its real catalog (CatalogSnapshotSeeder)
        // gets demo orders drawn from that catalog. Inventing Matcha Latte /
        // Croissant rows next to it would show up in the shop menu as products
        // nobody sells.
        $catalogSkus = ProductSku::whereHas(
            'product',
            fn ($query) => $query->where('brand_id', $brandId)->whereNull('deleted_at'),
        )->where('is_active', true)->inRandomOrder()->limit(12)->get();

        $skuDefs = $catalogSkus->count() >= 6 ? [] : [
            ['product' => 'Matcha Latte (M)', 'slug' => 'matcha-latte-m', 'sku' => 'ML-M', 'price' => 580],
            ['product' => 'Matcha Latte (L)', 'slug' => 'matcha-latte-l', 'sku' => 'ML-L', 'price' => 720],
            ['product' => 'Iced Coffee (M)', 'slug' => 'iced-coffee-m', 'sku' => 'IC-M', 'price' => 480],
            ['product' => 'Iced Coffee (L)', 'slug' => 'iced-coffee-l', 'sku' => 'IC-L', 'price' => 620],
            ['product' => 'Plain Croissant', 'slug' => 'croissant-plain', 'sku' => 'CR-PL', 'price' => 350],
            ['product' => 'Chocolate Croissant', 'slug' => 'croissant-choco', 'sku' => 'CR-CH', 'price' => 420],
        ];

        $allSkus = $skuDefs === [] ? $catalogSkus->all() : [];
        foreach ($skuDefs as $sd) {
            $product = Product::firstOrCreate(
                ['slug' => $sd['slug'], 'brand_id' => $brandId],
                [
                    'status' => 'active',
                    'product_type_id' => $pt->id,
                    'organization_id' => $orgId,
                    'brand_id' => $brandId,
                ],
            );
            if ($product->wasRecentlyCreated) {
                DB::table('products')->where('id', $product->id)->update(['name' => $sd['product']]);
                DB::table('product_translations')->insert([
                    ['product_id' => $product->id, 'locale' => 'ja', 'name' => $sd['product'], 'description' => null],
                    ['product_id' => $product->id, 'locale' => 'en', 'name' => $sd['product'], 'description' => null],
                    ['product_id' => $product->id, 'locale' => 'vi', 'name' => $sd['product'], 'description' => null],
                ]);
            }
            $allSkus[] = $product->skus()->firstOrCreate(
                [],
                [
                    'name' => $sd['product'],
                    'sku' => $sd['sku'],
                    // issue #875 — selling_price is the menu price; cost stays 0
                    // (auto-derived from recipe later).
                    'selling_price' => $sd['price'],
                    'cost_price' => 0,
                    'cost_price_auto' => 0,
                    'is_cost_override' => false,
                    'is_active' => true,
                ],
            );
        }

        // =================================================================
        //  Customers
        // =================================================================

        $customers = [];
        $customerData = [
            ['first_name' => 'Tanaka', 'last_name' => 'Yuki', 'phone' => '09012345678', 'email' => 'tanaka@example.com', 'address' => 'Tokyo, Shibuya-ku 1-2-3', 'note' => 'VIP — regular weekday lunch'],
            ['first_name' => 'Suzuki', 'last_name' => 'Hana', 'phone' => '08098765432', 'email' => 'suzuki.hana@example.com', 'address' => 'Osaka, Namba 4-5-6', 'tax_code' => 'T1234567890'],
            ['first_name' => 'Nguyễn', 'last_name' => 'Minh Tuấn', 'phone' => '09011112222', 'email' => null, 'note' => 'Prefers takeaway'],
            ['first_name' => 'Yamamoto', 'last_name' => 'Kenji', 'phone' => '07033334444', 'email' => 'yamamoto.k@example.com', 'address' => 'Kyoto, Gion 7-8'],
            ['first_name' => 'Lê', 'last_name' => 'Thị Mai', 'phone' => '09055556666', 'email' => 'le.mai@example.com', 'address' => 'Tokyo, Shinjuku 9-10', 'tax_code' => 'T9876543210', 'note' => 'Corporate — invoice monthly'],
            ['first_name' => 'Sato', 'last_name' => 'Rina', 'phone' => '09066667777', 'email' => 'sato.r@example.com'],
            ['first_name' => 'Watanabe', 'last_name' => 'Taro', 'phone' => '08077778888', 'email' => null, 'note' => 'Allergic to nuts'],
            ['first_name' => 'Trần', 'last_name' => 'Văn Hùng', 'phone' => '09088889999', 'email' => 'tran.hung@example.com', 'address' => 'Tokyo, Ikebukuro 11-12'],
            ['first_name' => 'Kobayashi', 'last_name' => 'Yui', 'phone' => '07099990000', 'email' => 'kobayashi@example.com'],
            ['first_name' => 'Phạm', 'last_name' => 'Thị Lan', 'phone' => '09000001111', 'email' => 'pham.lan@example.com', 'address' => 'Osaka, Umeda 13-14', 'note' => 'Prefers window seat'],
        ];

        foreach ($customerData as $cd) {
            $customers[] = Customer::create(array_merge([
                'organization_id' => $orgId,
                'brand_id' => $brandId,
                'branch_id' => $branchId,
                'phone' => null,
                'email' => null,
                'address' => null,
                'tax_code' => null,
                'note' => null,
            ], $cd));
        }

        // =================================================================
        //  Payment Methods (ensure seeded)
        // =================================================================

        $cashMethod = PaymentMethod::where('code', 'cash')->first();
        if (! $cashMethod) {
            $this->call(PaymentMethodSeeder::class);
            $cashMethod = PaymentMethod::where('code', 'cash')->first();
        }
        $cardMethod = PaymentMethod::where('code', 'card')->first();

        // =================================================================
        //  Orders (15 total — mix of all statuses and types)
        //
        //  New statuses: open, dining, checkout, paying, closed, voided
        //  Table link: Table.current_order_id (not CustomerOrder.table_id)
        //  Payment: via order_payments table (not inline)
        // =================================================================

        $orderDefs = [
            // Open orders (active — show on POS screen)
            ['status' => 'open', 'type' => 'dine_in', 'customer' => 0, 'table' => 0, 'minutes_ago' => 5, 'item_status' => 'pending'],
            ['status' => 'open', 'type' => 'takeaway', 'customer' => 2, 'table' => null, 'minutes_ago' => 12, 'item_status' => 'pending'],
            ['status' => 'open', 'type' => 'dine_in', 'customer' => null, 'table' => 1, 'minutes_ago' => 2, 'note' => 'Walk-in group of 3', 'item_status' => 'preparing'],

            // Dining orders (items served, customer eating)
            ['status' => 'open', 'type' => 'dine_in', 'customer' => 1, 'table' => 2, 'minutes_ago' => 25, 'item_status' => 'served'],
            ['status' => 'open', 'type' => 'takeaway', 'customer' => 5, 'table' => null, 'minutes_ago' => 18, 'item_status' => 'ready'],

            // Closed orders (today — paid and done)
            ['status' => 'closed', 'type' => 'dine_in', 'customer' => 3, 'table' => null, 'minutes_ago' => 90],
            ['status' => 'closed', 'type' => 'takeaway', 'customer' => 4, 'table' => null, 'minutes_ago' => 120, 'discount' => 200],
            ['status' => 'closed', 'type' => 'dine_in', 'customer' => 6, 'table' => null, 'minutes_ago' => 180],
            ['status' => 'closed', 'type' => 'dine_in', 'customer' => 7, 'table' => null, 'minutes_ago' => 240, 'discount' => 100],
            ['status' => 'closed', 'type' => 'takeaway', 'customer' => 8, 'table' => null, 'minutes_ago' => 300],
            ['status' => 'closed', 'type' => 'dine_in', 'customer' => 9, 'table' => null, 'minutes_ago' => 360, 'note' => 'Birthday celebration'],

            // Voided orders
            ['status' => 'voided', 'type' => 'takeaway', 'customer' => null, 'table' => null, 'minutes_ago' => 45, 'note' => 'Customer changed mind'],
            ['status' => 'voided', 'type' => 'dine_in', 'customer' => 0, 'table' => null, 'minutes_ago' => 200, 'note' => 'Duplicate order'],

            // More open (for polling demo)
            ['status' => 'open', 'type' => 'dine_in', 'customer' => 4, 'table' => 3, 'minutes_ago' => 1, 'item_status' => 'pending'],
            ['status' => 'open', 'type' => 'takeaway', 'customer' => null, 'table' => null, 'minutes_ago' => 0, 'note' => 'Just placed — walk-in', 'item_status' => 'pending'],
        ];

        $orderNum = 0;
        foreach ($orderDefs as $od) {
            $orderNum++;
            $createdAt = now()->subMinutes($od['minutes_ago'] ?? 0);
            $isClosed = $od['status'] === 'closed';
            $isVoided = $od['status'] === 'voided';
            $discount = $od['discount'] ?? 0;

            $order = CustomerOrder::create([
                'order_code' => $this->generateOrderCode(),
                'order_type' => $od['type'],
                'status' => $od['status'],
                'subtotal' => 0,
                'discount_amount' => $discount,
                'service_charge' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'paid_amount' => 0,
                'total_tip' => 0,
                'opened_at' => $createdAt,
                'checkout_at' => $isClosed ? $createdAt->copy()->addMinutes(rand(15, 30)) : null,
                'closed_at' => $isClosed ? $createdAt->copy()->addMinutes(rand(30, 45)) : null,
                'voided_at' => $isVoided ? $createdAt->copy()->addMinutes(rand(5, 15)) : null,
                'void_reason' => $isVoided ? ($od['note'] ?? 'Voided by staff') : null,
                'guest_count' => $od['type'] === 'dine_in' ? rand(1, 6) : null,
                'note' => $od['note'] ?? null,
                'created_by_id' => $userId,
                'customer_id' => isset($od['customer']) ? $customers[$od['customer']]->id : null,
                'branch_id' => $branchId,
                'brand_id' => $brandId,
                'organization_id' => $orgId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            // Set table.current_order_id for open dine_in orders
            if ($od['status'] === 'open' && $od['type'] === 'dine_in' && isset($od['table'])) {
                Table::where('id', $tables[$od['table']]->id)->update([
                    'current_order_id' => $order->id,
                    'status' => 'occupied',
                ]);
            }

            // plan-043 T1.17 — resolve the branch's default tax type once so
            // demo lines carry per-line snapshots consistent with totals. The
            // seeded default is the brand STANDARD (10/10), so the rate is 10%
            // for either order_type; picked generically here.
            //
            // #2188 — a seeder is a creation path, and creation paths stamp
            // FULLY or fail loudly: seeding NULL-rate lines is how the dev DB
            // ended up with 11.5k unstamped lines nobody noticed.
            // BaselineProvisioningSeeder runs before this one, so a missing type is a broken seed order.
            $taxType = $this->resolveBranchTaxType($branchId)
                ?? throw new RuntimeException(
                    "CustomerOrderSeeder: no tax type resolvable for branch {$branchId} — ".
                    'run BaselineProvisioningSeeder first; lines must never be created unstamped (#2188).'
                );
            $taxRate = (float) $taxType->rate;

            // Add 2-4 random items per order
            $itemCount = rand(2, 4);
            $itemStatus = $od['item_status'] ?? ($isClosed ? 'served' : ($isVoided ? 'voided' : 'pending'));
            $usedSkus = collect($allSkus)->shuffle()->take($itemCount);
            foreach ($usedSkus as $sku) {
                $qty = rand(1, 3);
                $price = match ($sku->sku) {
                    'ML-M' => 580, 'ML-L' => 720,
                    'IC-M' => 480, 'IC-L' => 620,
                    'CR-PL' => 350, 'CR-CH' => 420,
                    default => 500,
                };
                $lineSubtotal = $qty * $price;
                $order->items()->create([
                    'product_sku_id' => $sku->id,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'original_unit_price' => $price,
                    'subtotal' => $lineSubtotal,
                    'status' => $itemStatus,
                    'served_at' => $itemStatus === 'served' ? $createdAt->copy()->addMinutes(rand(10, 20)) : null,
                    'voided_at' => $itemStatus === 'voided' ? $createdAt->copy()->addMinutes(rand(1, 5)) : null,
                    'void_reason' => $itemStatus === 'voided' ? 'Order voided' : null,
                    // plan-043 per-line tax snapshot (immutable at add time).
                    'tax_type_id' => $taxType->id,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $itemStatus === 'voided' ? 0 : (int) round($lineSubtotal * $taxRate / 100),
                ]);
            }

            // Recalculate totals (exclude voided items). plan-043 — tax is a
            // single 10% group here (brand STANDARD), rounded once on the
            // discounted base; total is tax-excluded (additive).
            $sub = (int) $order->items()->where('status', '!=', 'voided')->sum(DB::raw('quantity * unit_price'));
            $tax = (int) round(max(0, $sub - $discount) * $taxRate / 100);
            $total = $sub - $discount + $tax;
            $order->update([
                'subtotal' => $sub,
                'tax_amount' => $tax,
                'total_amount' => $total,
                'is_tax_included' => false,
                'paid_amount' => $isClosed ? $total : 0,
            ]);
            $this->writeConditionLedger($order, $taxRate, $tax, min($discount, $sub));

            // Create payment records for closed orders
            if ($isClosed && $cashMethod) {
                $order->payments()->create([
                    'payment_code' => sprintf('PAY-%d-%04d', now()->year, $orderNum),
                    'payment_method_id' => $cashMethod->id,
                    'amount' => $total,
                    'tip_amount' => 0,
                    'status' => 'succeeded',
                    'paid_at' => $order->closed_at,
                    'received_by_id' => $userId,
                    'branch_id' => $branchId,
                    'brand_id' => $brandId,
                    'organization_id' => $orgId,
                ]);
            }
        }

        $this->assignOrphanedTables($orgId, $brandId, $branchId, $userId);

        $this->command->info("Seeded: 10 customers, {$orderNum} orders with items, 6 tables, 6 SKUs.");
    }

    /**
     * Ghi SỔ `order_conditions` bằng tay — `->update(['tax_amount' => …])` KHÔNG
     * làm việc đó ở đây, và đó là cái bẫy.
     *
     * #2041 gỡ ba cột tiền khỏi `customer_orders`; đường ghi mới là một
     * `Attribute` setter nhét số vào `pendingConditionAmounts`, rồi
     * `CustomerOrder::booted()` xả nó vào `order_conditions` ở sự kiện `saved`.
     * Nhưng `DatabaseSeeder` `use WithoutModelEvents` — cả lượt
     * `migrate:fresh --seed` chạy trong `Model::withoutEvents()`, nên `saved`
     * KHÔNG BAO GIỜ bắn và con số bị vứt lặng lẽ.
     *
     * Đo được: 32 đơn mỗi lượt seed mang `total = subtotal + thuế` trong khi
     * `tax_amount` đọc ra 0. Chạy `db:seed --class=CustomerOrderSeeder` RIÊNG thì
     * lại đúng — sự kiện bắn bình thường — nên bẫy này ẩn ngay trước mắt người
     * đi thử lại bằng tay.
     *
     * Hệ quả nhìn thấy được: dòng 端数調整 của pos-web hiện `+¥250` trên một đơn
     * không có gì để làm tròn, vì `subtotal − discount + thuế` không còn bằng
     * `total`. Sổ mới là nguồn chân lý, nên phải ghi thẳng vào sổ.
     *
     * Ghi TRỰC TIẾP, không qua model: mọi thứ ở đây phải đúng dù sự kiện có bị
     * tắt hay không.
     */
    private function writeConditionLedger(
        CustomerOrder $order,
        float $taxRate,
        float $tax,
        float $discount,
    ): void {
        $morph = $order->getMorphClass();
        DB::table('order_conditions')
            ->where('conditionable_type', $morph)
            ->where('conditionable_id', $order->id)
            ->whereIn('type', ['tax', 'discount'])
            ->delete();

        $currency = (string) (DB::table('shop_order_settings')
            ->where('branch_id', $order->branch_id)
            ->value('currency_code') ?? 'JPY');
        $now = now();
        $rows = [];

        if ($discount > 0) {
            $rows[] = [
                'id' => (string) Str::orderedUuid(),
                'conditionable_type' => $morph,
                'conditionable_id' => $order->id,
                'type' => 'discount',
                'source' => 'manual',
                'label' => 'Discount',
                'rate' => null,
                'amount' => -$discount,
                'taxable_base' => null,
                'currency_code' => $currency,
                'meta' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($tax != 0.0) {
            $rows[] = [
                'id' => (string) Str::orderedUuid(),
                'conditionable_type' => $morph,
                'conditionable_id' => $order->id,
                'type' => 'tax',
                'source' => 'tax_type',
                'label' => rtrim(rtrim(number_format($taxRate, 2), '0'), '.').'%',
                'rate' => $taxRate,
                // 税抜: nền chịu thuế là chính phần đã trừ giảm giá.
                'taxable_base' => max(0.0, (float) $order->subtotal - $discount),
                'amount' => $tax,
                'currency_code' => $currency,
                'meta' => json_encode(['rate_group' => (string) $taxRate]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('order_conditions')->insert($rows);
        }
    }

    private function generateOrderCode(): string
    {
        $year = now()->year;
        $prefix = "ORD-{$year}-";

        $lastNumber = CustomerOrder::withTrashed()
            ->where('order_code', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(order_code, ?) AS UNSIGNED)) as last_num', [strlen($prefix) + 1])
            ->value('last_num') ?? 0;

        return $prefix.sprintf('%04d', (int) $lastNumber + 1);
    }

    /**
     * Assign orders to occupied tables that don't have one yet.
     * (tables created by LocalDevSeeder with status=occupied)
     */
    private function assignOrphanedTables(string $orgId, string $brandId, string $branchId, string $userId): void
    {
        // Query ALL branches in this org, not just the seeder's target branch
        $orphanedTables = Table::where('organization_id', $orgId)
            ->where('status', 'occupied')
            ->whereNull('current_order_id')
            ->get();

        if ($orphanedTables->isEmpty()) {
            return;
        }

        $skus = ProductSku::whereHas('product', fn ($q) => $q->where('brand_id', $brandId))->get();

        foreach ($orphanedTables as $orphanedTable) {
            $createdAt = now()->subMinutes(rand(10, 60));

            // Use the table's own branch, resolve its brand
            $tableBranch = Branch::find($orphanedTable->branch_id);
            $tableBrandId = $tableBranch?->brand?->id ?? $brandId;

            $order = CustomerOrder::create([
                'order_code' => $this->generateOrderCode(),
                'order_type' => 'dine_in',
                'status' => 'open',
                'subtotal' => 0,
                'discount_amount' => 0,
                'service_charge' => 0,
                'tax_amount' => 0,
                'total_amount' => 0,
                'paid_amount' => 0,
                'total_tip' => 0,
                'opened_at' => $createdAt,
                'created_by_id' => $userId,
                'branch_id' => $orphanedTable->branch_id,
                'brand_id' => $tableBrandId,
                'organization_id' => $orgId,
            ]);

            // #2221 — nhánh này từng là chỗ DUY NHẤT còn seed dòng NULL tax_rate
            // sau khi nhánh chính đã stamp (#2188): mirror đúng nhánh chính.
            $tableTaxType = $this->resolveBranchTaxType((string) $orphanedTable->branch_id);
            if ($tableTaxType === null) {
                throw new RuntimeException(
                    "CustomerOrderSeeder: không resolve được tax type cho branch {$orphanedTable->branch_id} (bàn mồ côi)."
                );
            }
            $tableTaxRate = (float) $tableTaxType->rate;

            $usedSkus = $skus->shuffle()->take(rand(2, 4));
            foreach ($usedSkus as $sku) {
                $qty = rand(1, 3);
                // issue #875 — order line price comes from the menu price
                // (selling_price); cost_price is now 0 for seeded SKUs.
                $price = (int) $sku->selling_price ?: 500;
                $lineSubtotal = $qty * $price;
                $order->items()->create([
                    'product_sku_id' => $sku->id,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'original_unit_price' => $price,
                    'subtotal' => $lineSubtotal,
                    'status' => 'served',
                    'served_at' => $createdAt->copy()->addMinutes(rand(5, 10)),
                    'tax_type_id' => $tableTaxType->id,
                    'tax_rate' => $tableTaxRate,
                    'tax_amount' => (int) round($lineSubtotal * $tableTaxRate / 100),
                ]);
            }

            // #2229 — mirror đúng nhánh chính (:341-347): #2228 stamp thuế lên
            // DÒNG nhưng bỏ quên ĐẦU ĐƠN, sinh 15 đơn tự mâu thuẫn mỗi lần seed
            // (Σ dòng.tax_amount > 0 mà đơn.tax_amount = 0, total thiếu đúng
            // phần thuế). Nhánh mồ côi không có discount nên nền = $sub.
            $sub = (int) $order->items()->where('status', '!=', 'voided')->sum(DB::raw('quantity * unit_price'));
            $tax = (int) round($sub * $tableTaxRate / 100);
            $order->update([
                'subtotal' => $sub,
                'tax_amount' => $tax,
                'total_amount' => $sub + $tax,
                'is_tax_included' => false,
            ]);
            $this->writeConditionLedger($order, $tableTaxRate, $tax, 0);

            $orphanedTable->update(['current_order_id' => $order->id]);
        }

        $this->command->info("  → {$orphanedTables->count()} occupied table(s) assigned orders");
    }

    /**
     * plan-043 — the branch's default tax type (ShopOrderSetting.default_tax_
     * type_id), falling back to the brand STANDARD type. Memoised per branch.
     *
     * @var array<string, ?TaxType>
     */
    private array $branchTaxTypeCache = [];

    private function resolveBranchTaxType(string $branchId): ?TaxType
    {
        if (array_key_exists($branchId, $this->branchTaxTypeCache)) {
            return $this->branchTaxTypeCache[$branchId];
        }

        $defaultId = ShopOrderSetting::query()->where('branch_id', $branchId)->value('default_tax_type_id');
        $type = $defaultId ? TaxType::find($defaultId) : null;

        if ($type === null) {
            $brandId = Brand::query()
                ->where('console_brand_id', Branch::query()->where('id', $branchId)->value('console_brand_id'))
                ->value('id');
            $type = $brandId
                ? TaxType::query()->where('brand_id', $brandId)->where('code', 'STANDARD')->first()
                : null;
        }

        return $this->branchTaxTypeCache[$branchId] = $type;
    }
}
