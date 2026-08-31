<?php

/**
 * #1822 — hợp đồng poll cho kiosk và workstation.
 *
 * Thay `PaymentStatusCompatibilityTest` khi class cũ bị xoá. Test cũ trộn hai
 * thứ: ánh xạ legacy `'confirmed' → succeeded` (đã chết cùng shim) và hai bảng
 * poll (vẫn sống). File này chỉ giữ phần sống.
 *
 * Ca quan trọng nhất là ca CUỐI: `'confirmed'` không còn được dịch. Nếu ai đó
 * thêm lại lớp tương thích thì ca đó đỏ.
 */

use App\Omnify\Enums\PaymentStatusEnum;
use App\Support\PaymentPollStatus;

it('kiosk poll trả đúng ba từ khách hiểu được', function (PaymentStatusEnum|string|null $in, string $out) {
    expect(PaymentPollStatus::forKioskPoll($in))->toBe($out);
})->with([
    'succeeded → paid' => [PaymentStatusEnum::Succeeded, 'paid'],
    'failed → failed' => [PaymentStatusEnum::Failed, 'failed'],
    'refunded → failed' => [PaymentStatusEnum::Refunded, 'failed'],
    'pending → pending' => [PaymentStatusEnum::Pending, 'pending'],
    'null → pending' => [null, 'pending'],
    'chuỗi lạ → pending' => ['gì đó không phải trạng thái', 'pending'],
]);

it('workstation poll trả đúng từ vựng enum', function (PaymentStatusEnum|string|null $in, string $out) {
    expect(PaymentPollStatus::forWorkstationPoll($in))->toBe($out);
})->with([
    'succeeded' => [PaymentStatusEnum::Succeeded, 'succeeded'],
    'failed' => [PaymentStatusEnum::Failed, 'failed'],
    'refunded' => [PaymentStatusEnum::Refunded, 'refunded'],
    'pending' => [PaymentStatusEnum::Pending, 'pending'],
    'null → pending' => [null, 'pending'],
    'chuỗi lạ → pending' => ['gì đó không phải trạng thái', 'pending'],
]);

it('#1822 KHÔNG còn dịch chuỗi legacy `confirmed`', function () {
    // Đây là ca ghim việc xoá. `PaymentStatusCompatibility` từng ánh xạ
    // `'confirmed' → Succeeded` để đọc được hàng do workstation tiền-cutover
    // ghi. Chủ repo xác nhận 2026-08-05 chưa có bản phát hành nào, nên không
    // tồn tại hàng nào như vậy.
    //
    // Fail-safe đúng hướng: một chuỗi không nhận ra rơi về `pending`, tức máy
    // hỏi lại — KHÔNG phải `paid`. Nếu ai thêm lại lớp dịch, ca này đỏ.
    expect(PaymentPollStatus::forKioskPoll('confirmed'))->toBe('pending')
        ->and(PaymentPollStatus::forWorkstationPoll('confirmed'))->toBe('pending');
});
