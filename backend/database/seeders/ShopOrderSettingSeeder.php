<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ShopOrderSetting;
use Illuminate\Database\Seeder;

/**
 * Phí phục vụ demo cho môi trường dev — **chỉ dev**.
 *
 * Không có phí phục vụ thì màn thanh toán của khách hiển thị đúng: không có
 * dòng nào. Nhìn như tính năng hỏng, nên dev cần một con số khác 0.
 *
 * #2320 — seeder này TỪNG tạo hàng `shop_order_settings`. Việc đó đã chuyển hẳn
 * sang `BranchBaselineProvisioner`: hàng cài đặt chở currency, chế độ giá gồm thuế và loại thuế mặc định, tức là baseline chứ
 * không phải dữ liệu demo — và hai chỗ cùng tạo một hàng là đúng cái #2320 dọn.
 * Ở đây chỉ còn phần trang trí, và nó **không được** tạo hàng nào.
 *
 * Idempotent, và không bao giờ ghi đè con số người vận hành đã đặt: chỉ chạm
 * những hàng còn để phí phục vụ ở 0/null.
 */
class ShopOrderSettingSeeder extends Seeder
{
    private const DEMO_SERVICE_CHARGE_RATE = 5.0;

    private const DEMO_SERVICE_CHARGE_TAX_RATE = 10.0;

    public function run(): void
    {
        // Rào cứng, không chỉ dựa vào việc `DatabaseSeeder` không gọi nó ở
        // nhánh production: đây là seeder duy nhất còn GHI ĐÈ một con số tiền
        // mà người vận hành có thể đã cố ý đặt về 0. Ở dev đó là trang trí; ở
        // production đó là sửa hoá đơn của người khác.
        if (app()->isProduction()) {
            $this->command?->warn('ShopOrderSettingSeeder: bỏ qua trên production (chỉ dữ liệu demo).');

            return;
        }

        // Hongo is pinned by HongoShopConfigSeeder (0% service charge, tax floor).
        // Do not paint demo 5% over that shop — fresh local seed must match prod.
        $hongoIds = Branch::query()->where('slug', 'hongo')->pluck('id');

        $touched = ShopOrderSetting::query()
            ->where(fn ($q) => $q->whereNull('service_charge_rate')->orWhere('service_charge_rate', 0))
            ->when($hongoIds->isNotEmpty(), fn ($q) => $q->whereNotIn('branch_id', $hongoIds))
            ->update([
                'service_charge_rate' => self::DEMO_SERVICE_CHARGE_RATE,
                'service_charge_tax_rate' => self::DEMO_SERVICE_CHARGE_TAX_RATE,
                'close_report_tax_breakdown' => true,
            ]);

        $this->command?->info(
            "ShopOrderSettingSeeder: {$touched} chi nhánh nhận phí phục vụ demo "
            .'('.self::DEMO_SERVICE_CHARGE_RATE.'%).'
        );
    }
}
