<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Services\Provisioning\BranchBaselineProvisioner;
use App\Services\Provisioning\BrandBaselineProvisioner;
use Illuminate\Database\Seeder;

/**
 * Dựng baseline cho MỌI brand và branch đang có trong DB (#2320).
 *
 * Thay cho ba seeder đã gỡ — `TaxTypeSeeder`, `JapaneseTaxSeeder` và
 * `EnsureBrandReverbAppsSeeder`. Cả ba ra đời cùng một lý do: `Brand::created`
 * không bắn khi seed (`DatabaseSeeder` dùng `WithoutModelEvents`), nên mỗi mục
 * baseline lại phải có một seeder quét bù riêng. Ba seeder ⇒ ba bản cài đặt ⇒
 * chúng bắt đầu mâu thuẫn nhau: `JapaneseTaxSeeder` XOÁ đúng những hàng
 * `TaxTypeService` vừa tạo để chiếm chỗ cho id tất định của nó, và vì ghi thẳng
 * bằng `DB::table()->upsert()` nên bỏ qua luôn `tax_type_rates` (#2318) — loại
 * thuế sinh ra không có kỳ hiệu lực nào.
 *
 * Ở đây chỉ còn MỘT đường: provisioner. Cùng đường mà `Brand::created`,
 * `BranchProvisioningService` và `php artisan provisioning:reconcile` đi.
 *
 * Idempotent: chạy lại không tạo thêm gì, và **không bao giờ ghi đè** lựa chọn
 * đã có của người vận hành.
 */
class BaselineProvisioningSeeder extends Seeder
{
    public function run(): void
    {
        $brands = app(BrandBaselineProvisioner::class);
        $branches = app(BranchBaselineProvisioner::class);

        $changed = 0;

        foreach (Brand::query()->get() as $brand) {
            $changed += $brands->ensure($brand)->changed() ? 1 : 0;
        }

        foreach (Branch::query()->get() as $branch) {
            $changed += $branches->ensure($branch)->changed() ? 1 : 0;
        }

        $this->command?->info("BaselineProvisioningSeeder: {$changed} chủ thể được bổ sung baseline.");
    }
}
