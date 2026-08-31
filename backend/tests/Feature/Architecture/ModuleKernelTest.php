<?php

declare(strict_types=1);

/**
 * #962 Phase 1 (#1359) — exit criteria: "một module rỗng boot được trong Laravel
 * application", và "CI cưỡng chế được luật phụ thuộc".
 *
 * Vế thứ hai đã xong ở #1532: `deptrac analyse` chạy trong job `arch-gate`.
 * File này là vế thứ nhất.
 */

use App\Modules\Kernel\ModuleRegistry;
use App\Modules\Kernel\ModuleServiceProvider;
use Tests\Fixtures\Modules\Pilot\PilotModuleServiceProvider;

it('K1: một module rỗng boot được', function () {
    $provider = app()->register(PilotModuleServiceProvider::class);

    expect($provider)->toBeInstanceOf(ModuleServiceProvider::class)
        ->and(app('pilot.module.marker'))->toBe('booted');
});

it('K2: registry chỉ boot module CÓ MÃ, bỏ qua phần còn lại', function () {
    /*
     * Đây là tính chất khiến kernel này thêm được module mà không phải sửa
     * kernel. Bản đầu (#1359) khẳng định điều đó bằng cách chờ 0 module boot —
     * đúng lúc đó, nhưng nó ghim TÌNH TRẠNG ("chưa ai có provider") chứ không
     * ghim TÍNH CHẤT, nên #1360 vừa thêm module đầu tiên là nó đỏ.
     *
     * Bản này ghim đúng tính chất, và mạnh hơn: module NÀO có provider thì boot,
     * module nào chưa có thì lặng lẽ bỏ qua — không có danh sách nào phải sửa ở
     * kernel khi module thứ hai xuất hiện.
     */
    $manifest = (array) config('modules');
    $registry = new ModuleRegistry(app(), $manifest);
    $booted = $registry->boot();

    $declared = array_keys($manifest['modules'] ?? []);
    $withCode = array_values(array_filter(
        $declared,
        fn (string $m): bool => class_exists($registry->providerClass($m)),
    ));

    expect($booted)->toBe($withCode)
        ->and($booted)->toContain('Notifications')   // #1360 — module pilot
        ->and(count($booted))->toBeLessThan(count($declared));
});

it('K3: tên module PHẢI khớp config/modules.php', function () {
    /*
     * Hai sổ đăng ký gọi cùng một thứ bằng hai tên khác nhau chính là cách bản
     * đồ cũ trôi mất — nên runtime và bộ đo dùng CHUNG một chuỗi, và lệch thì
     * throw ngay lúc boot chứ không âm thầm.
     */
    $registry = new ModuleRegistry(app(), ['modules' => ['Ordering' => []]]);

    expect($registry->providerClass('Ordering'))
        ->toBe('App\\Modules\\Ordering\\OrderingModuleServiceProvider');
});

it('K4: mọi module trong manifest đều có tên provider suy ra được', function () {
    $registry = new ModuleRegistry(app(), (array) config('modules'));

    foreach (array_keys(config('modules.modules')) as $module) {
        expect($registry->providerClass($module))
            ->toStartWith('App\\Modules\\')
            ->toEndWith('ModuleServiceProvider');
    }
});
