import { useState } from "react";
import { useApplyCoupon, useReleaseCoupon } from "@/hooks/api/use-orders";
import { useTranslation } from "@/providers/app-provider";
import { formatCurrency } from "../lib/totals";
import { CouponRow } from "./order-cart";
import { parseCouponError } from "../lib/coupon";
import type { CustomerOrder } from "../types";

/**
 * Ô mã giảm giá TỰ CHỦ — dùng được ở bất kỳ màn nào có `shopSlug` + đơn.
 *
 * ## Vì sao nó ra đời
 *
 * Luồng một-chạm đưa thu ngân thẳng vào màn thu tiền, nên form mã giảm giá —
 * vốn nằm trên đường bắt buộc — thành đường tuỳ chọn. Kịch bản kẹt: chạm "Tính
 * tiền" xong khách mới đưa mã. Đơn đã sang `checkout`, ô nhập mã của giỏ chỉ
 * render khi `draft && !isCheckingOut`, và **không có route nào đưa đơn về
 * `open`** — cách duy nhất là huỷ cả đơn rồi nhập lại.
 *
 * Đáng nói là luồng CŨ cũng có đúng lỗ này, chỉ muộn hơn một bước (sau "Chốt
 * đơn" ô nhập mã cũng biến mất y hệt). Gắn ô này vào màn thu tiền đóng lỗ cho
 * cả hai, và làm được điều hôm nay không làm được: áp mã SAU khi đã chốt.
 *
 * ## Vì sao nó tự giữ mutation thay vì nhận qua prop
 *
 * `page.tsx` đang NẰM ĐÚNG TRÊN trần 926 dòng (`page-size-budget.arch.test.ts`),
 * nên thêm bốn prop ở đó là đỏ. `useApplyCoupon`/`useReleaseCoupon` khoá theo
 * `(shopSlug, orderId)` và tự `setQueryData` lên đúng cache key mà giỏ hàng
 * đọc — nên gọi ở đây cho ra CÙNG kết quả, không phải đường thứ hai.
 *
 * Giao diện và phép phân giải lỗi thì dùng lại nguyên `CouponRow` +
 * `parseCouponError` của giỏ: một bộ UI, một luật đọc lỗi.
 */
export function CouponField({
  shopSlug,
  order,
}: {
  shopSlug: string;
  order: CustomerOrder;
}) {
  const { t } = useTranslation();
  const [input, setInput] = useState("");
  const [error, setError] = useState<{
    code: string;
    meta?: Record<string, unknown>;
  } | null>(null);

  const applyMutation = useApplyCoupon(shopSlug, order.id);
  const releaseMutation = useReleaseCoupon(shopSlug, order.id);
  const pending = applyMutation.isPending || releaseMutation.isPending;

  async function apply(opts?: { downgradeExclusivePromotions?: boolean }) {
    const code = input.trim();
    if (!code) return;
    setError(null);
    try {
      await applyMutation.mutateAsync({
        code,
        customer_id: order.customer_id ?? null,
        downgrade_exclusive_promotions: opts?.downgradeExclusivePromotions,
      });
      setInput("");
    } catch (err) {
      // 422 có cấu trúc → hiện thông điệp bản địa hoá + CTA "dùng mã thay
      // khuyến mãi" của ca xung đột promotion, y như trong giỏ.
      setError(parseCouponError(err));
    }
  }

  return (
    <CouponRow
      label={t("pos.cart.discount")}
      order={order}
      input={input}
      onChangeInput={(v) => {
        setInput(v);
        setError(null);
      }}
      onApply={(opts) => void apply(opts)}
      onRelease={() => void releaseMutation.mutateAsync()}
      pending={pending}
      error={error}
      amount={`−${formatCurrency(order.discount_amount ?? 0)}`}
    />
  );
}
