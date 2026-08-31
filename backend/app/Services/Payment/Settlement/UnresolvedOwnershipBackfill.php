<?php

namespace App\Services\Payment\Settlement;

use App\Models\PaymentGatewayConnection;
use App\Services\Payment\Policy\UnresolvedOwnership;

/**
 * #3084 — đóng dấu "chưa phân giải" lên các connection đã mang UUID BỊA.
 *
 * Nằm ở `Settlement\` chứ không cạnh {@see UnresolvedOwnership} trong `Policy\`
 * vì namespace đó phải THUẦN: `PaymentPolicyResolverTest` cấm `App\Models\`,
 * `DB::` và `Stripe\` ở đó, và lệnh này đọc/ghi model. Cùng chỗ với
 * {@see DuplicateConnectionConsolidator} — hai lượt dọn dữ liệu một lần, cùng
 * aggregate, cùng khuôn lệnh.
 *
 * Bản vá ở {@see UnresolvedOwnership} chỉ áp cho connection MỚI. Hàng đã sinh
 * vẫn giữ giá trị `Str::uuid()` của lượt tạo ra chúng, nên câu truy vấn duy nhất
 * dùng được — `WHERE operator_org_unit_id = <sentinel>` — sẽ sót đúng những hàng
 * đang chạy thật. Tức phần giá trị nhất của bản vá không với tới dữ liệu đang có.
 *
 * ## Nó KHÔNG phân giải quyền sở hữu
 *
 * Nó thay một câu trả lời sai bằng "chưa biết". Câu trả lời đúng phải đến từ
 * Identity, và nguồn đọc đó chưa tồn tại — xem
 * {@see UnavailableBranchManagementProjectionSource}. Lệnh này chỉ làm cho việc
 * chưa biết trở nên TRA RA ĐƯỢC.
 *
 * ## Hàng KHÔNG được chạm, và vì sao
 *
 * Hàng connection tổng hợp của đường webhook Stripe mang bộ hằng số riêng của
 * nó (`…0004` / `…0005`). Đó không phải giá trị bịa — nó là định danh có chủ
 * đích của một hàng tổng hợp đã ngưng dùng, và đè sentinel lên là xoá mất sự
 * phân biệt giữa hai khái niệm.
 *
 * ## Hàng do admin/HQ nhập tay thì SAO — và vì sao phải có `$expected`
 *
 * Bản trước của docblock này khai rằng hàng admin/HQ tạo qua API "không bao giờ
 * khớp điều kiện dưới đây". **Sai, và sai theo chiều phá huỷ.** Điều kiện là
 * "không phải sentinel", mà một UUID Identity THẬT do người nhập cũng không
 * phải sentinel — nên nó khớp, và bị đè.
 *
 * Không sửa được bằng một điều kiện chặt hơn, vì **không tồn tại** phép thử
 * phân biệt `Str::uuid()` bịa với UUID Identity thật: cả hai là UUID v4 hợp lệ.
 * Đó chính là toàn bộ nội dung của #3084 — một UUID bịa trông y hệt dữ liệu
 * thật. Đoán theo hình dạng ở đây là lặp lại đúng sai lầm đang đi dọn.
 *
 * Nên gác bằng SỐ ĐẾM, fail-closed: `--apply` phải kèm `$expected` là số hàng
 * người vận hành ĐÃ nhìn thấy ở lượt dry-run. Lệch một hàng ⇒ từ chối ghi.
 * Con số đó biến "lệnh âm thầm đè bao nhiêu hàng cũng được" thành "lệnh dừng
 * lại khi thực tế khác với thứ người ta vừa đọc". Trên production hôm nay số
 * đó là 2; lớn hơn 2 nghĩa là nó sắp xoá quyền sở hữu THẬT của ai đó.
 *
 * Chạy lại được: lượt hai không còn hàng nào khớp.
 */
final class UnresolvedOwnershipBackfill
{
    /**
     * @param  int|null  $expected  Số hàng người vận hành đã thấy ở lượt dry-run.
     *                              BẮT BUỘC khi `$apply` — xem docblock lớp.
     * @return array{marked: int, ids: list<string>, applied: bool, refused: string|null}
     */
    public function backfill(bool $apply, ?int $expected = null): array
    {
        $rows = PaymentGatewayConnection::query()
            // Hàng connection tổng hợp của đường webhook Stripe
            // (`ProviderEvent\LegacyGlobalStripeConnection::CONNECTION_ID`).
            // Viết thẳng UUID chứ KHÔNG `use` class đó: rào /legacy/i của #2188
            // quét CODE sau khi bóc comment, nên nhắc tên ở đây thì được, còn
            // một dòng import là thêm một định danh mới vào `backend/app/`.
            ->whereKeyNot('00000000-0000-4000-8000-000000000001')
            ->get()
            ->filter(fn (PaymentGatewayConnection $c): bool => ! UnresolvedOwnership::marks(
                $c->brand_owner_org_unit_id === null ? null : (string) $c->brand_owner_org_unit_id,
                $c->operator_org_unit_id === null ? null : (string) $c->operator_org_unit_id,
            ))
            ->values();

        $ids = $rows->map(static fn (PaymentGatewayConnection $c): string => (string) $c->id)->all();
        $marked = count($ids);

        // Rào fail-closed. Nó KHÔNG bảo vệ được bằng cách đoán hàng nào là bịa —
        // phép thử đó không tồn tại (xem docblock lớp). Nó bảo vệ bằng cách bắt
        // người vận hành nói ra con số họ vừa ĐỌC, rồi từ chối khi thực tế khác.
        if ($apply) {
            if ($expected === null) {
                return ['marked' => $marked, 'ids' => $ids, 'applied' => false, 'refused' => 'expected_missing'];
            }

            if ($expected !== $marked) {
                return ['marked' => $marked, 'ids' => $ids, 'applied' => false, 'refused' => 'expected_mismatch'];
            }
        }

        if ($apply && $ids !== []) {
            PaymentGatewayConnection::query()->whereIn('id', $ids)->update([
                'brand_owner_org_unit_id' => UnresolvedOwnership::BRAND_OWNER_ORG_UNIT_ID,
                'operator_org_unit_id' => UnresolvedOwnership::OPERATOR_ORG_UNIT_ID,
            ]);
        }

        return ['marked' => $marked, 'ids' => $ids, 'applied' => $apply, 'refused' => null];
    }
}
