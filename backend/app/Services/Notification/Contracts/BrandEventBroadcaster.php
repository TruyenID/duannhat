<?php

declare(strict_types=1);

namespace App\Services\Notification\Contracts;

use App\Models\Brand;

/**
 * #962 — "phát sự kiện này trên app Reverb của brand".
 *
 * `RealtimeChannel` trước đây constructor-inject thẳng
 * `App\Broadcasting\BrandAwareBroadcastManager`. Class đó thuộc tầng
 * **Composition** — tầng được phép biết MỌI module, và vì thế không module nào
 * được phép biết nó. Một cạnh đi lên Composition là chỗ duy nhất trong bản đồ
 * layer có thể tạo ra vòng qua toàn hệ thống.
 *
 * Cổng khai ở đây (Notifications, bên TIÊU THỤ) và Composition hiện thực:
 * chiều phụ thuộc đảo lại đúng như ruleset đã cho phép. Không cần publish class
 * này trong `config/modules.php` vì bên hiện thực nằm ở Composition, vốn đã
 * được phép nhìn thấy Notifications.
 *
 * `Brand` là TenancyKernel — mọi module đều được biết — nên cổng cầm model này
 * không tạo cạnh nào.
 */
interface BrandEventBroadcaster
{
    /**
     * Phát `$event` bằng credential Reverb của `$brand`.
     *
     * Ném ra ngoài nếu driver hỏng; người gọi tự quyết định coi đó là
     * `failed` hay bỏ qua.
     */
    public function broadcastForBrand(Brand $brand, object $event): void;
}
