<?php

/**
 * #3084 — lượt dọn hàng đã mang UUID bịa.
 *
 * Bản vá bootstrap chỉ áp cho connection MỚI. Không có lượt dọn này thì câu truy
 * vấn duy nhất dùng được — `WHERE operator_org_unit_id = <sentinel>` — sót đúng
 * những hàng đang chạy thật, tức phần giá trị nhất của bản vá không với tới dữ
 * liệu đang có.
 */

use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Policy\UnresolvedOwnership;
use App\Services\Payment\Settlement\UnresolvedOwnershipBackfill;
use Illuminate\Support\Str;

uses()->group('payment');

function fabricatedConnection(array $overrides = []): PaymentGatewayConnection
{
    return PaymentGatewayConnection::factory()->create(array_merge([
        // Đúng hình dạng production trước bản vá: mỗi hàng một UUID mới.
        'brand_owner_org_unit_id' => (string) Str::uuid(),
        'operator_org_unit_id' => (string) Str::uuid(),
    ], $overrides));
}

it('dry-run đếm đúng mà không ghi gì', function () {
    $connection = fabricatedConnection();
    $before = (string) $connection->operator_org_unit_id;

    $result = app(UnresolvedOwnershipBackfill::class)->backfill(apply: false);

    expect($result['marked'])->toBeGreaterThanOrEqual(1)
        ->and($result['ids'])->toContain((string) $connection->id)
        ->and((string) $connection->fresh()->operator_org_unit_id)->toBe($before);
});

it('đóng dấu hàng mang UUID bịa', function () {
    $connection = fabricatedConnection();

    // `expected` là BẮT BUỘC khi ghi (#3084) — xem docblock service: không có
    // phép thử nào tách UUID bịa khỏi UUID Identity thật, nên số đếm là rào duy nhất.
    app(UnresolvedOwnershipBackfill::class)->backfill(apply: true, expected: 1);

    $fresh = $connection->fresh();

    expect((string) $fresh->brand_owner_org_unit_id)->toBe(UnresolvedOwnership::BRAND_OWNER_ORG_UNIT_ID)
        ->and((string) $fresh->operator_org_unit_id)->toBe(UnresolvedOwnership::OPERATOR_ORG_UNIT_ID);
});

it('KHÔNG chạm hàng connection tổng hợp của đường webhook', function () {
    // Hằng số của hàng đó là định danh CÓ CHỦ ĐÍCH cho một hàng tổng hợp đã ngưng
    // dùng, không phải giá trị bịa. Đè sentinel lên là xoá mất sự phân biệt giữa
    // hai khái niệm — và cả hai đều tra bằng cùng một kiểu câu SQL.
    $synthetic = fabricatedConnection([
        'id' => '00000000-0000-4000-8000-000000000001',
        'brand_owner_org_unit_id' => '00000000-0000-4000-8000-000000000004',
        'operator_org_unit_id' => '00000000-0000-4000-8000-000000000005',
    ]);

    // Phải có MỘT hàng khớp thật, và `expected: 1` phải khớp nó. Nếu để lượt ghi
    // bị rào đếm từ chối (0 hàng khớp mà đòi ghi), bài này vẫn XANH nhưng xanh
    // vì lý do KHÁC: hàng tổng hợp còn nguyên chỉ vì lệnh không chạy, chứ không
    // phải vì bộ lọc chừa nó ra. Rào đó không chứng minh gì.
    $victim = fabricatedConnection();

    app(UnresolvedOwnershipBackfill::class)->backfill(apply: true, expected: 1);

    expect((string) $synthetic->fresh()->operator_org_unit_id)
        ->toBe('00000000-0000-4000-8000-000000000005');

    // Chứng minh lượt ghi ĐÃ chạy — nếu không, khẳng định trên là rỗng.
    expect((string) $victim->fresh()->operator_org_unit_id)
        ->toBe(UnresolvedOwnership::OPERATOR_ORG_UNIT_ID);
});

it('chạy lần hai không còn gì để làm', function () {
    fabricatedConnection();

    $backfill = app(UnresolvedOwnershipBackfill::class);
    $backfill->backfill(apply: true, expected: 1);

    expect($backfill->backfill(apply: true, expected: 0)['marked'])->toBe(0,
        'Lệnh phải idempotent — lượt hai còn hàng nào là dấu hiệu điều kiện lọc đọc sai chính thứ nó vừa ghi.'
    );
});

it('để yên hàng đã mang dấu sẵn', function () {
    // Rào chiều ngược lại: một điều kiện lọc quá rộng sẽ ghi đè mọi hàng mỗi
    // lượt chạy, và `updated_at` của bảng cấu hình tiền sẽ nhiễu vĩnh viễn.
    $already = fabricatedConnection([
        'brand_owner_org_unit_id' => UnresolvedOwnership::BRAND_OWNER_ORG_UNIT_ID,
        'operator_org_unit_id' => UnresolvedOwnership::OPERATOR_ORG_UNIT_ID,
    ]);

    $result = app(UnresolvedOwnershipBackfill::class)->backfill(apply: false);

    expect($result['ids'])->not->toContain((string) $already->id);
});

/*
|--------------------------------------------------------------------------
| #3084 (bổ sung) — rào SỐ ĐẾM, vì không có rào HÌNH DẠNG nào tồn tại
|--------------------------------------------------------------------------
|
| Docblock của lệnh từng khai rằng hàng do admin/HQ nhập tay "không bao giờ
| khớp". Nó khớp: điều kiện là "không phải sentinel", mà một UUID Identity THẬT
| cũng không phải sentinel. Lời khai đó chưa từng có bài nào kiểm, nên nó sống
| sót — trên một lệnh GHI ĐÈ cột nói ai chịu trách nhiệm pháp lý cho tiền.
|
| Không sửa được bằng điều kiện chặt hơn: `Str::uuid()` bịa và UUID Identity
| thật đều là UUID v4 hợp lệ, không phép thử nào tách được. Đoán theo hình dạng
| ở đây là lặp lại đúng sai lầm đang đi dọn (#3084).
*/

it('#3084 hàng admin/HQ nhập tay VẪN nằm trong tầm — đây là lý do phải có rào đếm', function () {
    // Đo sự thật khó chịu, đừng làm mượt nó: giá trị Identity thật cũng bị đếm.
    $real = fabricatedConnection([
        'brand_owner_org_unit_id' => '11111111-1111-4111-8111-111111111111',
        'operator_org_unit_id' => '22222222-2222-4222-8222-222222222222',
    ]);

    $result = app(UnresolvedOwnershipBackfill::class)->backfill(apply: false);

    expect($result['ids'])->toContain((string) $real->id);
});

it('#3084 --apply KHÔNG có expected thì TỪ CHỐI, và không ghi một hàng nào', function () {
    $connection = fabricatedConnection();
    $before = (string) $connection->operator_org_unit_id;

    $result = app(UnresolvedOwnershipBackfill::class)->backfill(apply: true);

    expect($result['refused'])->toBe('expected_missing')
        ->and($result['applied'])->toBeFalse();

    expect((string) $connection->fresh()->operator_org_unit_id)->toBe($before);
});

it('#3084 số đếm lệch thì TỪ CHỐI — dữ liệu đã đổi kể từ lượt dry-run', function () {
    // Người vận hành đọc dry-run thấy 1 hàng, rồi giữa hai lượt có hàng thứ hai
    // ra đời. Ghi tiếp là đè lên một hàng chưa ai nhìn.
    fabricatedConnection();
    fabricatedConnection();

    $result = app(UnresolvedOwnershipBackfill::class)->backfill(apply: true, expected: 1);

    expect($result['refused'])->toBe('expected_mismatch')
        ->and($result['applied'])->toBeFalse()
        ->and($result['marked'])->toBe(2);
});

it('#3084 số đếm khớp thì ghi bình thường', function () {
    $connection = fabricatedConnection();

    $result = app(UnresolvedOwnershipBackfill::class)->backfill(apply: true, expected: 1);

    expect($result['refused'])->toBeNull()
        ->and($result['applied'])->toBeTrue();

    expect((string) $connection->fresh()->operator_org_unit_id)
        ->toBe(UnresolvedOwnership::OPERATOR_ORG_UNIT_ID);
});

it('#3084 lệnh CLI thoát khác 0 khi bị từ chối — ops thấy được, script dừng được', function () {
    fabricatedConnection();

    $this->artisan('payments:mark-unresolved-ownership --apply')
        ->assertExitCode(1);

    $this->artisan('payments:mark-unresolved-ownership --apply --expect=99')
        ->assertExitCode(1);

    $this->artisan('payments:mark-unresolved-ownership --apply --expect=1')
        ->assertExitCode(0);
});
