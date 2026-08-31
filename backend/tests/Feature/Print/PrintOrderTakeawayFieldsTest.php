<?php

declare(strict_types=1);

use App\Services\Print\Renderer\PrintRenderOrder;

/*
 * #1925 bổ sung — khối khách MANG ĐI.
 *
 * VO ban đầu dừng ở 7 trường mà issue liệt kê. `printCustomerHeader`
 * (`internal/service/print_service.go:257-267`) — hàm mà họ bill gọi hai lần —
 * đọc thêm ba trường nữa.
 *
 * Test này ghim SỰ CÓ MẶT của chúng, vì đó là thứ hỏng im lặng: thiếu một
 * trường không làm gì đỏ, chỉ làm tờ giấy thiếu chữ, và người phát hiện là nhân
 * viên đang tìm xem đơn mang đi này của ai.
 */

it('#1925 order mang đủ ba trường của khối khách mang đi', function () {
    $o = new PrintRenderOrder(
        orderCode: 'A-001',
        orderType: 'takeaway',
        customerTakeawayName: 'Tanaka',
        customerTakeawayPhone: '090-0000-0000',
        scheduledPickupTime: '2026-08-06T18:30:00+09:00',
    );

    expect($o->customerTakeawayName)->toBe('Tanaka')
        ->and($o->customerTakeawayPhone)->toBe('090-0000-0000')
        ->and($o->scheduledPickupTime)->toBe('2026-08-06T18:30:00+09:00');
});

it('#1925 ba trường đó có mặt trên VO, không chỉ trong hàm dựng', function () {
    // Ghim bằng reflection chứ không chỉ bằng một lần khởi tạo: một trường bị
    // gỡ khỏi VO nhưng còn trong docblock vẫn làm test trên xanh nếu test chỉ
    // đọc giá trị vừa truyền vào.
    $props = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(PrintRenderOrder::class))->getProperties(),
    );

    expect($props)->toContain('customerTakeawayName')
        ->toContain('customerTakeawayPhone')
        ->toContain('scheduledPickupTime');
});
