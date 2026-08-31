<?php

declare(strict_types=1);

/**
 * #1594 — `isPaidInFull()` là luật của ORDERING, đặt sau một cổng.
 *
 * Trước PR này, Payments tự trả lời bằng `OrderClosingService::isPaidEnough($order)`
 * — tức phải cầm `App\Models\CustomerOrder`. Câu trả lời KHÔNG phải phép trừ
 * đơn giản: dung sai làm tròn lấy từ `shop_order_settings.currency_code`, và
 * đọc sai nguồn tiền tệ từng ghi nhận 1.99 USD doanh thu ma (#821 E3).
 *
 * Bài test ghim hai thứ mà việc dời chỗ có thể lặng lẽ đổi:
 * đơn KHÔNG TỒN TẠI phải là `false` (không phải "đã trả đủ"), và đơn của
 * ORGANIZATION KHÁC cũng phải là `false` — cổng lọc theo tenant, bỏ mất bộ lọc
 * đó là một lỗ rò tenant chứ không phải một tối ưu.
 */

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Services\Order\Contracts\OrderQueryPort;
use Illuminate\Support\Str;

it('đơn không tồn tại KHÔNG phải "đã trả đủ"', function () {
    expect(app(OrderQueryPort::class)->isPaidInFull(
        '00000000-0000-4000-8000-000000000001',
        '00000000-0000-4000-8000-000000000002',
    ))->toBeFalse();
});

it('lọc theo tenant: đơn của org khác trả về false', function () {
    $orgId = (string) Str::uuid();
    $branch = Branch::factory()->create(['console_organization_id' => $orgId]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'total_amount' => 1000,
        'paid_amount' => 1000,
    ]);

    $port = app(OrderQueryPort::class);

    expect($port->isPaidInFull($orgId, (string) $order->id))->toBeTrue()
        ->and($port->isPaidInFull((string) Str::uuid(), (string) $order->id))->toBeFalse();
});

it('trả thiếu thì KHÔNG đủ — dung sai không nuốt một khoản thật', function () {
    $orgId = (string) Str::uuid();
    $branch = Branch::factory()->create(['console_organization_id' => $orgId]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'total_amount' => 1000,
        'paid_amount' => 900,
    ]);

    expect(app(OrderQueryPort::class)->isPaidInFull($orgId, (string) $order->id))->toBeFalse();
});
