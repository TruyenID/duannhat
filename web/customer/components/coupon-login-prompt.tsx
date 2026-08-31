"use client";

/**
 * CouponLoginPrompt
 * -----------------
 * Hiển thị khi backend preview trả về `error_code === 'customer_required'`
 * (coupon yêu cầu đăng nhập) và user hiện đang là guest.
 *
 * Flow:
 *  1. User nhập coupon → click "Áp dụng" → preview API trả lỗi customer_required.
 *  2. Component này render dưới input coupon, có CTA "Đăng nhập".
 *  3. CTA click → persist coupon vào cart-context (sessionStorage) →
 *     router.push(`/login?redirect=<currentPath>`).
 *  4. Sau khi login thành công, login form đẩy user về `<currentPath>`.
 *  5. Checkout page mount lại → đọc `appliedCouponCode` từ cart-context →
 *     auto-fill couponCode + couponDebounced → preview chạy lại → lần này
 *     `is_valid: true` (đã có customer_id) → coupon được áp.
 */

import { useTranslations } from "next-intl";
import { usePathname, useRouter } from "@/i18n/routing";
import { Button } from "@/components/ui/button";
import { LogIn } from "lucide-react";
import { useCart } from "@/context/cart-context";

interface CouponLoginPromptProps {
  /** Coupon code đang preview — sẽ được persist để auto-apply sau login. */
  couponCode: string;
}

export function CouponLoginPrompt({ couponCode }: CouponLoginPromptProps) {
  const t = useTranslations("checkout");
  const router = useRouter();
  const pathname = usePathname();
  const { setAppliedCouponCode } = useCart();

  const handleQuickLogin = () => {
    const code = couponCode.trim();
    if (code) {
      // Persist vào sessionStorage qua cart-context — cart-context tự ghi
      // vào APPLIED_COUPON_KEY trong useEffect. Khi user quay lại checkout
      // sau login, useEffect mount sẽ đọc lại và auto-fill input coupon.
      setAppliedCouponCode(code.toUpperCase());
    }
    // pathname đã có locale prefix (next-intl). useRouter từ "@/i18n/routing"
    // sẽ thêm locale prefix lần nữa cho `/login` nên ta truyền raw pathname.
    const redirectTo = pathname || "/checkout";
    router.push(`/login?redirect=${encodeURIComponent(redirectTo)}`);
  };

  return (
    <div className="mt-2 rounded-md border border-amber-300 bg-amber-50 px-2.5 py-2 text-xs text-amber-900">
      <div className="mb-2">{t("couponError.customer_required")}</div>
      <Button
        type="button"
        size="sm"
        onClick={handleQuickLogin}
        className="h-8 gap-1.5 px-3 text-xs"
      >
        <LogIn className="h-3.5 w-3.5" />
        {t("couponLoginCta")}
      </Button>
    </div>
  );
}
