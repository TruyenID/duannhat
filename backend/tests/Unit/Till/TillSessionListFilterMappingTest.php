<?php

declare(strict_types=1);

/**
 * #1562 — `ListTillSessionsRequest::toFilter()` là biên giới delivery → Payments.
 *
 * Trước đây `ShopTillTrackingService` nhận thẳng FormRequest, nên không có chỗ
 * nào để ánh xạ sai. Giờ có: 9 tham số đi qua một constructor có tên, và một cú
 * hoán chỗ (`variance` ↔ `opener_id`, cả hai đều `?string`) là lọc sai âm thầm —
 * PHP không kêu, và bộ test HTTP hiện có KHÔNG chạm bốn tham số này
 * (`force_abandoned`, `opener_id`, `variance`, `sort` chỉ được kiểm ở tầng 422).
 *
 * Nên bài test này ghim đúng phần mà refactor vừa tạo ra rủi ro: bảng ánh xạ.
 */

use App\Http\Requests\Shop\ListTillSessionsRequest;
use Illuminate\Support\Carbon;

function makeFilterFrom(array $query): \App\Services\Till\TillSessionListFilter
{
    return ListTillSessionsRequest::create('/api/v1/shops/x/till/sessions', 'GET', $query)
        ->toFilter();
}

it('ánh xạ từng tham số vào ĐÚNG ô của nó', function () {
    $filter = makeFilterFrom([
        'from' => '2026-03-01',
        'to' => '2026-03-31',
        'per_page' => '40',
        'sort' => 'variance_abs_desc',
        'till_id' => ['t-1', 't-2'],
        'status' => ['settled'],
        'opener_id' => 'u-9',
        'variance' => 'out_of_tolerance',
        'force_abandoned' => '1',
    ]);

    expect($filter->from->toDateString())->toBe('2026-03-01')
        ->and($filter->to->toDateString())->toBe('2026-03-31')
        ->and($filter->perPage)->toBe(40)
        ->and($filter->sort)->toBe('variance_abs_desc')
        ->and($filter->tillIds)->toBe(['t-1', 't-2'])
        ->and($filter->statuses)->toBe(['settled'])
        // Hai ô này cùng kiểu `?string` — hoán chỗ là lọc sai mà không ai kêu.
        ->and($filter->openerId)->toBe('u-9')
        ->and($filter->variance)->toBe('out_of_tolerance')
        ->and($filter->forceAbandoned)->toBeTrue();
});

it('phân biệt force_abandoned=false với force_abandoned KHÔNG gửi', function () {
    // Đây là chỗ dễ hỏng nhất: query string luôn là chuỗi, nên `(bool) "0"`
    // ra false, còn `is_null` mới tách được "không lọc" khỏi "lọc = false".
    // Nhầm hai cái này thì danh sách ca lặng lẽ bỏ mất mọi ca bị force-abandon.
    expect(makeFilterFrom([])->forceAbandoned)->toBeNull()
        ->and(makeFilterFrom(['force_abandoned' => '0'])->forceAbandoned)->toBeFalse()
        ->and(makeFilterFrom(['force_abandoned' => '1'])->forceAbandoned)->toBeTrue();
});

it('mặc định được giải nghĩa Ở TẦNG DELIVERY, không để service tự đoán', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-20 15:00:00'));

    $filter = makeFilterFrom([]);

    // Khoảng mặc định today−7d → today, và service nhận giá trị đã chốt chứ
    // không gọi `now()` — đồng hồ ứng dụng thô chỉ hợp lệ ở tầng trình bày (#1091).
    expect($filter->from->toDateString())->toBe('2026-03-13')
        ->and($filter->to->toDateString())->toBe('2026-03-20')
        ->and($filter->perPage)->toBe(25)
        ->and($filter->sort)->toBe('opened_at_desc')
        ->and($filter->tillIds)->toBe([])
        ->and($filter->statuses)->toBe([]);

    Carbon::setTestNow();
});
