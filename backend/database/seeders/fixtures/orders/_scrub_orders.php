<?php

declare(strict_types=1);

use Database\Seeders\OrderFixtureScrubber;

/**
 * Ẩn danh fixture đơn hàng tại chỗ — chạy sau MỖI lần chụp lại (#2220):
 *
 *     php database/seeders/fixtures/orders/_scrub_orders.php
 *
 * Toàn bộ luật nằm ở `Database\Seeders\OrderFixtureScrubber`; file này chỉ là
 * cửa gọi, cố ý không cần Laravel để chạy được ngay trên cây làm việc trước
 * khi `git add`. Quên chạy thì
 * `tests/Feature/Seeders/SeederFixturesCarryNoProductionSecretsTest.php` đỏ.
 */

require __DIR__.'/../../OrderFixtureScrubber.php';

OrderFixtureScrubber::scrubAll();

echo "Đã ẩn danh. Kiểm lại trước khi commit:\n";
echo "  grep -rc 'console_access_token' database/seeders/fixtures/orders\n";
