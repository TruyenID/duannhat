import { ApiError } from "@/lib/api";
import type { CustomerOrder } from "../types";

/**
 * Trạng thái đơn mà CẢ HAI đầu còn cho phép áp / gỡ mã giảm giá.
 *
 * Không phải phỏng đoán — đây là bản sao có chủ đích của hai allowlist đang
 * chạy, và chúng khớp nhau:
 *
 *   Cloud       `OrderCouponService::assertOrderModifiable`
 *   Workstation `OrderCouponMutable` (`order_mutation_gate.go`)
 *
 * `paying` VẮNG MẶT ở cả hai, và đó là điều đúng: đơn đã nhận một phần tiền thì
 * đổi tổng là làm sai lệch khoản đã thu. Không rào ở đây thì thu ngân bấm và ăn
 * 422 giữa lúc khách đứng chờ — nên rào là để KHÔNG MỜI người ta bấm.
 *
 * Nới thêm một trạng thái ở đây mà backend không nới lại chính là cái bẫy đó.
 */
const COUPON_MUTABLE_STATUSES = new Set([
  "open",
  "dining",
  "pending",
  "confirmed",
  "checkout",
]);

export function couponMayBeChanged(order: CustomerOrder | null): boolean {
  return !!order && COUPON_MUTABLE_STATUSES.has(String(order.status));
}

/**
 * Rút `error_code` có cấu trúc ra khỏi một 422 của CouponException.
 *
 * Giao diện tra khoá `coupon.error.<code>` để hiện thông điệp bản địa hoá, và
 * `meta` mang danh sách món xung đột cho CTA "dùng mã thay khuyến mãi". Không
 * nhận dạng được thì lùi về `generic` — vẫn nói được điều gì đó, thay vì im.
 */
export function parseCouponError(
  err: unknown,
): { code: string; meta?: Record<string, unknown> } {
  if (err instanceof ApiError) {
    const body = err.body as {
      error_code?: string;
      meta?: Record<string, unknown>;
    };
    if (body?.error_code) return { code: body.error_code, meta: body.meta };
  }

  return { code: "generic" };
}
