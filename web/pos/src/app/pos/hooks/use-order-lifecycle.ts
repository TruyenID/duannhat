import { toast } from "sonner";
import {
  useApplyCoupon,
  useCheckoutOrder,
  useConfirmOrder,
  useReopenOrder,
  useVoidOrder,
} from "@/hooks/api/use-orders";
import type { useReleaseCoupon } from "@/hooks/api/use-orders";
import { useTranslation } from "@/providers/app-provider";
import { orderItemDisplayName } from "../lib/order-item-name";
import type { PosTab } from "./use-pos-tabs";
import type { CustomerOrder, CustomerOrderItem } from "../types";

export interface UseOrderLifecycleArgs {
  shopSlug: string;
  activeOrder: CustomerOrder | undefined;
  activeTab: PosTab | null;
  closeTab: (tabId: string) => void;
  /** Shared with the cart's stacking-conflict flow, so it is passed in. */
  releaseCoupon: ReturnType<typeof useReleaseCoupon>;
  setErrorMessage: (message: string | null) => void;
  surfaceError: (e: unknown) => void;
}

export interface UseOrderLifecycleResult {
  canCheckout: () => boolean;
  /**
   * Chuyển đơn sang `checkout`. Trả về **true chỉ khi đơn THẬT SỰ đã chuyển** —
   * người gọi cần biết để quyết định có mở màn thu tiền hay không.
   *
   * Trước đây hàm này trả `Promise<void>` và nuốt lỗi qua `surfaceError`, nên
   * không ai phân biệt được thành công với thất bại. Với luồng một-chạm thì đó
   * là khác biệt giữa "mở màn thu tiền" và "mở màn thu tiền cho một đơn còn
   * đang mở" — cửa sau sẽ 409 ngay khi bấm Thu.
   */
  checkout: (draft: { discount_amount: number }) => Promise<boolean>;
  applyCoupon: (
    code: string,
    opts?: { downgradeExclusivePromotions?: boolean },
  ) => Promise<void>;
  releaseCoupon: () => Promise<void>;
  acceptOrder: () => Promise<void>;
  acceptPending: boolean;
  voidOrder: (reason: string) => Promise<void>;
  reopenOrder: (reason: string) => Promise<void>;
}

/**
 * Moving the ORDER (as opposed to its lines) through its lifecycle: into
 * checkout, accepted, voided — plus the coupon attached to it, which is an
 * order-level attribute rather than a per-line one.
 *
 * Owns the mutations behind those because nothing else on the page uses
 * them; `releaseCoupon` is the exception, shared with the cart's
 * stacking-conflict retry, so it is passed in rather than instantiated twice.
 */
export function useOrderLifecycle({
  shopSlug,
  activeOrder,
  activeTab,
  closeTab,
  releaseCoupon: releaseCouponMutation,
  setErrorMessage,
  surfaceError,
}: UseOrderLifecycleArgs): UseOrderLifecycleResult {
  const { t } = useTranslation();
  const activeOrderId = activeOrder?.id ?? "";

  const checkoutMutation = useCheckoutOrder(shopSlug, activeOrderId);
  const applyCouponMutation = useApplyCoupon(shopSlug, activeOrderId);
  const voidOrderMutation = useVoidOrder(shopSlug, activeOrderId);
  const reopenOrderMutation = useReopenOrder(shopSlug, activeOrderId);
  const confirmOrderMutation = useConfirmOrder(shopSlug, activeOrderId);

  /**
   * Business-rule guard for the "Tính tiền" / checkout button.
   *
   * Every non-voided item on the active order must reach `served` before
   * staff can move the order into the checkout (cash-collection) step.
   * Returns true when checkout may proceed; returns false AND surfaces a
   * toast naming up to three unserved items when the rule blocks. Wired
   * into OrderCart's `onAttemptCheckout`, which gates the headline button
   * before the discount-draft form opens — staff sees the blocker on the
   * very first click, not after filling out discount.
   *
   * Belt-and-suspenders: also re-checked inside `checkout` because the draft
   * form can sit open while another terminal bumps an item back out of
   * `served` (KDS revert, WS broadcast). Cheap; running it twice is fine.
   */
  function canCheckout(): boolean {
    if (!activeOrder?.items) return true;
    const unserved = activeOrder.items.filter(
      (it: CustomerOrderItem) => it.status !== "voided" && it.status !== "served",
    );
    if (unserved.length === 0) return true;
    const names = unserved
      .slice(0, 3)
      .map((it) => orderItemDisplayName(it, "—"))
      .join(", ");
    const suffix = unserved.length > 3 ? ` +${unserved.length - 3}` : "";
    toast.error(
      t("pos.toast.checkout_blocked_unserved", {
        count: unserved.length,
        items: names + suffix,
      }),
    );
    return false;
  }

  async function checkout(draft: { discount_amount: number }): Promise<boolean> {
    if (!activeOrder) return false;
    if (!canCheckout()) return false;
    setErrorMessage(null);
    try {
      await checkoutMutation.mutateAsync(draft);

      return true;
    } catch (e) {
      surfaceError(e);

      return false;
    }
  }

  // plan-019 — coupon apply / release. Apply intentionally throws so the
  // OrderCart's coupon row can read the structured 422 error_code and
  // render a localized message; release goes through the standard
  // surfaceError path because the only realistic failure is a network
  // glitch (the server side accepts any released_at IS NULL row).
  async function applyCoupon(
    code: string,
    opts?: { downgradeExclusivePromotions?: boolean },
  ) {
    if (!activeOrder) return;
    setErrorMessage(null);
    await applyCouponMutation.mutateAsync({
      code,
      customer_id: activeOrder.customer_id ?? null,
      downgrade_exclusive_promotions: opts?.downgradeExclusivePromotions,
    });
  }

  async function releaseCoupon() {
    if (!activeOrder) return;
    setErrorMessage(null);
    try {
      await releaseCouponMutation.mutateAsync();
    } catch (e) {
      surfaceError(e);
    }
  }

  /**
   * Tiếp nhận đơn — accept a customer-submitted takeaway (pending|confirmed
   * → open) so the regular checkout CTA appears. The hook cache-sets the
   * returned open order and re-syncs on error (another terminal may have
   * accepted first), so no extra state juggling here.
   */
  async function acceptOrder() {
    if (!activeOrder) return;
    setErrorMessage(null);
    try {
      await confirmOrderMutation.mutateAsync();
    } catch (e) {
      surfaceError(e);
    }
  }

  async function voidOrder(reason: string) {
    if (!activeOrder || !activeTab) return;
    setErrorMessage(null);
    try {
      await voidOrderMutation.mutateAsync(reason);
      closeTab(activeTab.tabId);
    } catch (e) {
      surfaceError(e);
    }
  }

  /**
   * #2479 — KHÔNG đóng tab, khác hẳn `voidOrder`. Cả điểm của thao tác này là
   * thu ngân sửa tiếp trên chính đơn đó; đóng tab là bắt họ đi tìm lại nó.
   */
  async function reopenOrder(reason: string) {
    if (!activeOrder) return;
    setErrorMessage(null);
    try {
      await reopenOrderMutation.mutateAsync(reason);
    } catch (e) {
      surfaceError(e);
    }
  }

  return {
    canCheckout,
    checkout,
    applyCoupon,
    releaseCoupon,
    acceptOrder,
    acceptPending: confirmOrderMutation.isPending,
    voidOrder,
    reopenOrder,
  };
}
