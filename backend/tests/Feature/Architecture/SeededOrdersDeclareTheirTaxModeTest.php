<?php

declare(strict_types=1);

/**
 * Một đơn phải KHAI ĐÚNG chế độ thuế của chính số tiền nó mang.
 *
 * `customer_orders.is_tax_included` không phải nhãn trang trí — nó chọn một
 * trong hai bất biến của `OrderPricingCalculator::priceGroups`:
 *
 *   true  (総額表示) total = subtotal − discount + service_charge     ← thuế NẰM TRONG subtotal
 *   false (税抜)     total = subtotal − discount + service_charge + tax
 *
 * Đọc sai cờ là đọc sai cả câu chuyện tiền. Người tiêu thụ nó có mặt ở khắp nơi:
 * dòng 端数調整 + `showGrossSummary` của pos-web, khối thuế trên phiếu in của máy
 * trạm (`print_tax_blocks.go`), `KioskOrderResource`, `OrderPaidInvoiceMail`.
 *
 * ## Thiệt hại đã ĐO được
 *
 * `DashboardSeeder` định giá 税込 (`$lineTax` là thuế RÚT RA từ giá, và
 * `$total = $sub − $discount` cố ý không cộng thuế lên) nhưng KHÔNG ghi cờ. Cột
 * mặc định `0`, nên trên một DB `migrate:fresh --seed` có **2.702 / 3.240** đơn
 * khai "giá chưa gồm thuế" trong khi tiền của chính chúng nói ngược lại. Đo bằng
 * cách đối chiếu `total` với cả hai bất biến trên MySQL của docker.
 *
 * ## Vì sao phép kiểm này đọc MÃ NGUỒN chứ không chạy seeder
 *
 * Và đây cũng là lý do nó sống được lâu đến thế: `DashboardSeeder` **không chạy
 * được trong suite**. Nó dựng `order_code` bằng `SUBSTRING_INDEX(...)`, một hàm
 * MySQL mà sqlite `:memory:` của `phpunit.xml` không có — nên không một bài test
 * hành vi nào chạm tới nổi seeder này, hôm nay hay ngày mai. Neo mã nguồn là thứ
 * DUY NHẤT với tới được, nên nó neo đúng một điều: hai seeder sinh đơn phải khai
 * cờ, và khai đúng CHIỀU của số học chính chúng viết ra.
 *
 * Đổi số học thì đổi luôn hằng số ở đây — đó là điểm của bài test, không phải
 * phiền toái của nó.
 */
$seeder = static fn (string $file): string => (string) file_get_contents(
    database_path("seeders/{$file}.php"),
);

it('DashboardSeeder khai 総額表示 — đúng với thuế nó RÚT RA từ giá', function () use ($seeder) {
    $source = $seeder('DashboardSeeder');

    // Tiền đề: seeder này vẫn đang định giá 税込. Nếu ai đó đổi sang cộng thuế
    // lên trên thì kỳ vọng bên dưới phải đảo theo, và bài test phải đỏ để bắt
    // người đó nhìn lại — chứ không im lặng đúng vì lý do khác.
    expect($source)->toContain('$total = max(0, $sub - $discount);');
    expect($source)->toContain('$lineTax = (int) ($subtotal - round($subtotal / (1 + $taxRate / 100)));');

    expect($source)->toContain("'is_tax_included' => true,");
    expect($source)->not->toContain("'is_tax_included' => false,");
});

it('CustomerOrderSeeder khai 税抜 — đúng với thuế nó CỘNG THÊM', function () use ($seeder) {
    $source = $seeder('CustomerOrderSeeder');

    // Chiều ngược lại, ghim vì cùng một lý do: seeder này cộng thuế lên tổng.
    expect($source)->toContain('$total = $sub - $discount + $tax;');

    expect($source)->toContain("'is_tax_included' => false,");
    expect($source)->not->toContain("'is_tax_included' => true,");
});

it('mọi seeder ghi thẳng customer_orders đều phải khai cờ', function (string $file) use ($seeder) {
    // Danh sách này là kết quả của `grep -rl "customer_orders')->insert\|
    // CustomerOrder::create\|CustomerOrder::factory" database/seeders/`.
    // `OrderWithStripePaymentSeeder` và `ProductRatingSeeder` cố ý VẮNG MẶT: cả
    // hai ghi `tax_amount => 0`, và khi thuế bằng 0 hai bất biến trùng nhau nên
    // cờ không quyết định gì. Thêm chúng vào đây là đòi một lời khai không mang
    // thông tin.
    expect($seeder($file))->toMatch("/'is_tax_included' => (true|false),/");
})->with(['DashboardSeeder', 'CustomerOrderSeeder']);
