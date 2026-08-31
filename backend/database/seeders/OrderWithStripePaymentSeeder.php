<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Models\Table;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OrderWithStripePaymentSeeder extends Seeder
{
    /**
     * Seed 1 dine-in order với Stripe payment succeeded.
     */
    public function run(): void
    {
        // 1. Lấy branch đầu tiên
        $branch = Branch::with('brand')->first();
        if (! $branch) {
            $this->command->error('No branch found. Run DemoDataSeeder first.');

            return;
        }

        $brand = $branch->brand;
        $organizationId = $brand->organization_id;

        // 2. Lấy bàn đầu tiên
        $table = Table::where('branch_id', $branch->id)->first();
        if (! $table) {
            $this->command->warn('No table found, creating dummy table C-99');
            $table = Table::create([
                'id' => Str::ulid(),
                'branch_id' => $branch->id,
                'code' => 'C-99',
                'name' => 'Test Table',
                'status' => 'available',
                'is_active' => true,
                'qr_token' => Str::random(32),
            ]);
        }

        // 3. Lấy SKU từ order item đã tồn tại
        $existingItem = CustomerOrderItem::whereHas('customerOrder', fn ($q) => $q->where('branch_id', $branch->id))
            ->with('productSku')
            ->first();

        if (! $existingItem || ! $existingItem->productSku) {
            $this->command->error('No existing order item with SKU found. Cannot create test order.');

            return;
        }

        $sku = $existingItem->productSku;

        // 4. Tạo order
        $order = CustomerOrder::create([
            'id' => Str::ulid(),
            'order_code' => 'ORD-STRIPE-TEST',
            'order_type' => CustomerOrderTypeEnum::DineIn,
            'status' => CustomerOrderStatusEnum::Closed,
            'table_id' => $table->id,
            'branch_id' => $branch->id,
            'brand_id' => $brand->id,
            'organization_id' => $organizationId,
            'subtotal' => 1200.00,
            'discount_amount' => 0,
            'service_charge' => 0,
            'tax_amount' => 0,
            'total_amount' => 1200.00,
            'paid_amount' => 1200.00,
            'guest_count' => 2,
            'opened_at' => now()->subHour(),
            'checkout_at' => now()->subMinutes(10),
            'closed_at' => now()->subMinutes(5),
        ]);

        // 5. Tạo 1 item
        CustomerOrderItem::create([
            'id' => Str::ulid(),
            'customer_order_id' => $order->id,
            'product_sku_id' => $sku->id,
            'quantity' => 2,
            'unit_price' => 600.00,
            'original_unit_price' => 600.00,
            'subtotal' => 1200.00,
            // #2411 — 0% do tác giả chọn: đơn demo khai `tax_amount => 0` và
            // `total_amount == subtotal`.
            'tax_rate' => 0,
            'status' => OrderItemStatusEnum::Served,
        ]);

        // 6. Tạo payment method Stripe nếu chưa có
        $stripeMethod = PaymentMethod::firstOrCreate(
            ['code' => 'stripe'],
            [
                'id' => Str::ulid(),
                'name' => 'Stripe',
                'is_active' => true,
            ]
        );

        // 7. Tạo OrderPayment với Stripe
        OrderPayment::create([
            'id' => Str::ulid(),
            'customer_order_id' => $order->id,
            'payment_method_id' => $stripeMethod->id,
            'amount' => 1200.00,
            'status' => PaymentStatusEnum::Succeeded,
            'stripe_payment_intent_id' => 'pi_test_'.now()->timestamp,
            'paid_at' => now()->subMinutes(5),
            'organization_id' => $organizationId,
            'branch_id' => $branch->id,
            'brand_id' => $brand->id,
        ]);

        $this->command->info("✅ Created order {$order->order_code} (ID: {$order->id}) with Stripe payment ¥1200");
        $this->command->info("   View at: http://localhost:5430/shop/{$branch->slug}/orders/{$order->id}");
    }
}
