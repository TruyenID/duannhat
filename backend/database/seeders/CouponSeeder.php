<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Organization;
use App\Services\Promotion\CouponService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Plan-019 — seeds three demo coupons per demo brand:
 *   WELCOME10     — 10% off (cap 50k), 100 uses, 1/customer, valid 7 days.
 *   SAVE50K       — fixed 50.000 ₫ off, unlimited, walk-in OK, valid 30 days.
 *   EXPIRED_TEST  — past valid_until (smoke test for derived `expired` status).
 *
 * Idempotent: matches on (brand_id, code). Re-runs the seeder syncs
 * the existing row instead of duplicating.
 *
 * Goes through CouponService so Astrotomic translations + branch pivots
 * persist via the proper code path (convention #3 — raw Coupon::create
 * would silently drop locale keys).
 */
class CouponSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(CouponService $service): void
    {
        $brands = Brand::all();
        if ($brands->isEmpty()) {
            $this->command?->warn('CouponSeeder: no brands found, skipping.');

            return;
        }

        $count = 0;
        foreach ($brands as $brand) {
            $orgId = Organization::where('console_organization_id', $brand->console_organization_id)
                ->value('id');
            if ($orgId === null) {
                $this->command?->warn("CouponSeeder: brand {$brand->id} has no matching local organization, skipping.");

                continue;
            }

            foreach ($this->definitionsFor($brand) as $row) {
                $existing = Coupon::query()
                    ->where('brand_id', $brand->id)
                    ->where('code', $row['code'])
                    ->withTrashed()
                    ->first();

                $row['organization_id'] = $orgId;
                $row['brand_id'] = $brand->id;

                if ($existing) {
                    $service->update($existing, $row);
                } else {
                    $service->create($row);
                }
                $count++;
            }
        }

        $this->command?->info("CouponSeeder: {$count} coupons seeded across ".$brands->count().' brand(s).');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitionsFor(Brand $brand): array
    {
        $now = now();

        return [
            [
                'code' => 'WELCOME10',
                'name:ja' => 'ようこそ10%オフ',
                'name:en' => 'Welcome 10% off',
                'name:vi' => 'Chào mừng giảm 10%',
                'description:ja' => '初回注文限定。最大50,000VND割引。',
                'description:en' => 'First-time order discount, capped at 50,000 VND.',
                'description:vi' => 'Áp dụng cho đơn đầu tiên, tối đa 50.000₫.',
                'discount_type' => 'percent',
                'discount_value' => 10,
                'max_discount_cap' => 50000,
                'min_order_subtotal' => 100000,
                'usage_limit_total' => 100,
                'usage_limit_per_customer' => 1,
                'valid_from' => $now->copy()->subDay(),
                'valid_until' => $now->copy()->addDays(7),
                'status' => 'draft',
                'applicable_branch_ids' => [],
            ],
            [
                'code' => 'SAVE50K',
                'name:ja' => '50.000VND割引',
                'name:en' => 'Save 50,000 VND',
                'name:vi' => 'Giảm 50.000₫',
                'discount_type' => 'fixed',
                'discount_value' => 50000,
                'max_discount_cap' => null,
                'min_order_subtotal' => 200000,
                'usage_limit_total' => null,
                'usage_limit_per_customer' => 0,
                'valid_from' => $now->copy()->subDays(2),
                'valid_until' => $now->copy()->addDays(30),
                'status' => 'draft',
                'applicable_branch_ids' => [],
            ],
            [
                'code' => 'EXPIRED_TEST',
                'name:ja' => '期限切れテスト',
                'name:en' => 'Expired test coupon',
                'name:vi' => 'Coupon đã hết hạn',
                'discount_type' => 'percent',
                'discount_value' => 5,
                'max_discount_cap' => 20000,
                'min_order_subtotal' => 0,
                'usage_limit_total' => 50,
                'usage_limit_per_customer' => 1,
                'valid_from' => $now->copy()->subDays(30),
                'valid_until' => $now->copy()->subDays(2),
                'status' => 'draft',
                'applicable_branch_ids' => [],
            ],
        ];
    }
}
