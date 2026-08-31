<?php

declare(strict_types=1);

/**
 * #2716 — template chuông hệ thống phải lên production mỗi lần deploy.
 *
 * `SystemNotificationTemplateSeeder` chỉ nằm trong `DatabaseSeeder` (local /
 * fresh install). Đường `deploy-xserver.yml` trước đây chỉ `db:seed BetoyaSeeder`,
 * nên key mới (`till.unresolved_orders`, …) không bao giờ xuất hiện trên
 * production và chuông HQ in ra đúng cái key thô.
 *
 * Seeder là danh mục hệ thống, `firstOrCreate` theo `key` — thiếu thì thêm,
 * bản quán đã sửa thì không đè. `updateOrCreate` ở đây sẽ cướp copy.
 *
 * Rào này đọc FILE, không boot app: nằm ở Architecture vì arch-gate chạy trên
 * mọi PR vào `dev`.
 */
it('seeds system notification templates on every production deploy', function () {
    $root = dirname(__DIR__, 4);
    $yaml = (string) file_get_contents($root.'/.github/workflows/deploy-xserver.yml');

    expect(str_contains($yaml, 'db:seed --class=SystemNotificationTemplateSeeder'))->toBeTrue(
        'Deploy phải seed template chuông hệ thống. firstOrCreate theo key, không đè bản quán đã sửa.',
    );

    $seeder = (string) file_get_contents($root.'/backend/database/seeders/SystemNotificationTemplateSeeder.php');
    expect(str_contains($seeder, 'firstOrCreate'))->toBeTrue();
    expect(str_contains($seeder, 'updateOrCreate'))->toBeFalse();
});
