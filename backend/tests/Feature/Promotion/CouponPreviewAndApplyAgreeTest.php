<?php

declare(strict_types=1);

use App\Exceptions\CouponException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Services\Order\Coupon\OrderCouponService;
use App\Services\Promotion\CouponService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * #2080 §2 — điều kiện coupon được kiểm ở HAI nơi độc lập; bài này buộc chúng
 * trả lời giống nhau.
 *
 * | Đường | Hàm | Ai gọi |
 * |---|---|---|
 * | xem trước | `CouponService::validatePreview` | nút "kiểm mã" ở POS/khách |
 * | áp dụng thật | `OrderCouponService::validateForApply` | lúc gắn coupon vào đơn |
 *
 * Đọc cạnh nhau thì thấy chúng là **cùng năm phép kiểm, cùng thứ tự** — hai bản
 * cài đặt của một luật. Trôi lệch thì khách thấy "áp được" rồi bị từ chối lúc
 * thanh toán, hoặc ngược lại: xem trước từ chối một mã vẫn dùng được.
 *
 * Cùng họ với #2074 (`tax_breakdown` tính hai nơi).
 *
 * ## Vì sao đo ở CỬA CÔNG KHAI, không gọi thẳng validator
 *
 * `validatePreview` / `validateForApply` đều `protected`. Chọc vào chúng bằng
 * reflection sẽ ghim CÁCH CÀI ĐẶT — bài test đỏ khi ai đó gộp hai hàm lại làm
 * một, tức đỏ đúng lúc lỗi được sửa. Ở đây gọi `preview()` và `apply()`, nên
 * điều được ghim là **hành vi**: cùng một coupon hỏng phải cho cùng một mã lỗi
 * ở cả hai đường, bất kể bên trong tổ chức thế nào.
 *
 * ## Bốn ô trong bảng này trước đây TRỐNG (#2080 §3)
 *
 * - `coupon_expired` ở đường APPLY — chỉ có test ở preview;
 * - `coupon_not_started` ở **cả hai** đường — `grep "not_started" tests/` rỗng;
 * - `coupon_branch_not_eligible` — zero hit trong toàn bộ `tests/`;
 * - `coupon_paused` ở đường apply.
 */
function couponParityWorld(): array
{
    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);

    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);

    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    $other = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    return [$brand, $branch, $other];
}

function couponParityOrder(Brand $brand, Branch $branch, float $subtotal): CustomerOrder
{
    return CustomerOrder::factory()->create([
        // `customer_orders` mang `organization_id`, KHÔNG phải
        // `console_organization_id` — hai tên gần giống nhau ở hai bảng khác nhau.
        'organization_id' => $branch->console_organization_id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'status' => 'open',
        'subtotal' => $subtotal,
        'discount_amount' => 0,
        'coupon_id' => null,
    ]);
}

/**
 * Chạy CẢ HAI đường trên cùng một trạng thái và trả về mã lỗi mỗi bên.
 *
 * @return array{preview: ?string, apply: ?string}
 */
function couponParityErrorCodes(Coupon $coupon, Brand $brand, Branch $branch, float $subtotal): array
{
    $preview = app(CouponService::class)->preview(
        $coupon->code,
        $brand->id,
        $branch->id,
        null,
        $subtotal,
    );

    $previewCode = ($preview['is_valid'] ?? false) ? null : ($preview['error_code'] ?? 'unknown');

    $applyCode = null;
    try {
        app(OrderCouponService::class)->apply(
            couponParityOrder($brand, $branch, $subtotal),
            $coupon->code,
        );
    } catch (CouponException $e) {
        $applyCode = $e->errorCode;
    }

    return ['preview' => $previewCode, 'apply' => $applyCode];
}

function expectBothPathsSay(Coupon $coupon, Brand $brand, Branch $branch, float $subtotal, ?string $want): void
{
    $got = couponParityErrorCodes($coupon, $brand, $branch, $subtotal);

    $msg = sprintf(
        "Hai đường coupon KHÔNG đồng ý.\n\n  xem trước : %s\n  áp dụng   : %s\n  chờ đợi   : %s\n\n".
        'Khách sẽ thấy một câu trả lời ở nút kiểm mã và một câu khác lúc thanh toán.',
        $got['preview'] ?? '(hợp lệ)',
        $got['apply'] ?? '(hợp lệ)',
        $want ?? '(hợp lệ)',
    );

    expect($got['preview'])->toBe($want, $msg);
    expect($got['apply'])->toBe($want, $msg);
}

it('coupon TẠM DỪNG bị từ chối giống nhau ở cả hai đường', function () {
    [$brand, $branch] = couponParityWorld();

    $coupon = Coupon::factory()->create([
        'brand_id' => $brand->id,
        'code' => 'PAUSED1',
        'status' => 'paused',
        'valid_from' => CarbonImmutable::now()->subDay(),
        'valid_until' => CarbonImmutable::now()->addDay(),
        'min_order_subtotal' => 0,
    ]);

    expectBothPathsSay($coupon, $brand, $branch, 5000.0, 'coupon_paused');
});

it('coupon CHƯA TỚI HẠN bị từ chối giống nhau — ô trống ở CẢ HAI đường', function () {
    // `grep "not_started" tests/` trả về RỖNG trước bài này: nhánh `valid_from`
    // chưa từng được test ở đâu, dù nó là một trong năm phép kiểm.
    [$brand, $branch] = couponParityWorld();

    $coupon = Coupon::factory()->create([
        'brand_id' => $brand->id,
        'code' => 'FUTURE1',
        'status' => 'draft', // enum chỉ có draft|paused; 'draft' là trạng thái KHÔNG bị từ chối
        'valid_from' => CarbonImmutable::now()->addWeek(),
        'valid_until' => CarbonImmutable::now()->addMonth(),
        'min_order_subtotal' => 0,
    ]);

    expectBothPathsSay($coupon, $brand, $branch, 5000.0, 'coupon_not_started');
});

it('coupon HẾT HẠN bị từ chối giống nhau — nhánh apply trước đây không test nào chạm', function () {
    [$brand, $branch] = couponParityWorld();

    $coupon = Coupon::factory()->create([
        'brand_id' => $brand->id,
        'code' => 'EXPIRED1',
        'status' => 'draft', // enum chỉ có draft|paused; 'draft' là trạng thái KHÔNG bị từ chối
        'valid_from' => CarbonImmutable::now()->subMonth(),
        'valid_until' => CarbonImmutable::now()->subDay(),
        'min_order_subtotal' => 0,
    ]);

    expectBothPathsSay($coupon, $brand, $branch, 5000.0, 'coupon_expired');
});

it('NGƯỠNG giỏ hàng chưa đạt bị từ chối giống nhau', function () {
    // Ngưỡng là chỗ hai đường dễ lệch nhất về NGUỒN SỐ: preview nhận `subtotal`
    // do người gọi truyền, apply đọc `order->subtotal`. Cùng luật, hai nguồn.
    [$brand, $branch] = couponParityWorld();

    $coupon = Coupon::factory()->create([
        'brand_id' => $brand->id,
        'code' => 'MIN5000',
        'status' => 'draft', // enum chỉ có draft|paused; 'draft' là trạng thái KHÔNG bị từ chối
        'valid_from' => CarbonImmutable::now()->subDay(),
        'valid_until' => CarbonImmutable::now()->addDay(),
        'min_order_subtotal' => 5000,
    ]);

    expectBothPathsSay($coupon, $brand, $branch, 4999.0, 'coupon_min_subtotal_not_met');

    // Và mặt kia: ĐÚNG ngưỡng thì cả hai phải cho qua. Không có vế này thì bài
    // test xanh cả khi hai đường cùng từ chối MỌI thứ.
    expectBothPathsSay($coupon, $brand, $branch, 5000.0, null);
});

it('coupon HẾT LƯỢT bị từ chối giống nhau', function () {
    [$brand, $branch] = couponParityWorld();

    $coupon = Coupon::factory()->create([
        'brand_id' => $brand->id,
        'code' => 'USEDUP1',
        'status' => 'draft', // enum chỉ có draft|paused; 'draft' là trạng thái KHÔNG bị từ chối
        'valid_from' => CarbonImmutable::now()->subDay(),
        'valid_until' => CarbonImmutable::now()->addDay(),
        'min_order_subtotal' => 0,
        'usage_limit_total' => 3,
        'times_used' => 3,
    ]);

    expectBothPathsSay($coupon, $brand, $branch, 5000.0, 'coupon_exhausted');
});

it('coupon SAI CHI NHÁNH bị từ chối giống nhau — mã lỗi này zero hit trong tests/ trước đây', function () {
    [$brand, $branch, $other] = couponParityWorld();

    $coupon = Coupon::factory()->create([
        'brand_id' => $brand->id,
        'code' => 'BRANCHY1',
        'status' => 'draft', // enum chỉ có draft|paused; 'draft' là trạng thái KHÔNG bị từ chối
        'valid_from' => CarbonImmutable::now()->subDay(),
        'valid_until' => CarbonImmutable::now()->addDay(),
        'min_order_subtotal' => 0,
    ]);

    // Danh sách trắng CHỈ có chi nhánh kia ⇒ chi nhánh đang bán không đủ điều kiện.
    $coupon->branches()->attach($other->id);

    expectBothPathsSay($coupon, $brand, $branch, 5000.0, 'coupon_branch_not_eligible');
});
