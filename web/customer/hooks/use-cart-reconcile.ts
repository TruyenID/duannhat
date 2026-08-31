"use client";

import { useEffect } from "react";

import { useBrand } from "@/context/brand-context";
import { useCart } from "@/context/cart-context";
import { apiFetch } from "@/lib/api";
import type { MenuCategory } from "@/data/menu";
import type { MergedMenuContext } from "@/lib/menu-item-match";

interface MenuPayload {
  menu_id: string;
  menu_name: string;
  schedule_end_time: string | null;
  cart_timeout_minutes: number;
  categories: MenuCategory[];
  menus?: MergedMenuContext[];
}

/**
 * #1715 — đối chiếu giỏ với menu đang phát, cho những màn KHÔNG tự làm việc đó.
 *
 * Các màn đọc giá trong giỏ mà chưa từng soát lại lần nào: trang đặt bàn và
 * (trước đây) menu bàn dine-in. Khách ngồi ở đó cả bữa trong khi khung giờ ưu
 * đãi đóng, giá trong giỏ đứng yên ở con số cũ.
 *
 * #2675 đã xoá màn thứ ba (`/checkout-review`) — route chết, không đường vào.
 *
 * Hook này cố ý KHÔNG được nhét vào 5 chỗ gọi cũ trong cùng lần sửa: chúng đang
 * chạy đúng và mỗi chỗ còn làm thêm việc riêng (đặt menu metadata, dựng section
 * đã enrich, bắt lỗi ngoài giờ mở cửa). Gom lại là một bản refactor riêng.
 *
 * **Chọn endpoint theo kiểu đơn, không theo trang** (#478): backend tách menu
 * theo service type, nên soát một giỏ dine-in bằng menu Takeaway sẽ đóng dấu sai
 * menu và sai luôn hạn giỏ — đúng lỗi mà #478 đã phải sửa một lần.
 */
export function useCartReconcile(pollMs?: number) {
  const { currentBranch } = useBrand();
  const { items, orderType, dineInTable, reconcileCrossTimeItems } = useCart();
  const itemCount = items.length;
  const qrToken = dineInTable?.qr_token;
  const branchSlug = currentBranch?.slug;

  useEffect(() => {
    if (itemCount === 0) return;

    const endpoint =
      orderType === "dine_in" && qrToken
        ? `/api/v1/customer/tables/${qrToken}/menu`
        : branchSlug
          ? `/api/v1/customer/branches/${branchSlug}/menu`
          : null;
    if (!endpoint) return;

    const ac = new AbortController();

    const run = () => {
      apiFetch<{ data: MenuPayload }>(endpoint, { signal: ac.signal, silent401: true })
        .then(({ data }) => {
          if (ac.signal.aborted) return;
          reconcileCrossTimeItems({
            menuId: data.menu_id,
            menuName: data.menu_name,
            scheduleEndTime: data.schedule_end_time,
            cartTimeoutMinutes: data.cart_timeout_minutes,
            categories: data.categories,
            menus: data.menus,
          });
        })
        .catch(() => {
          // Best-effort: lỗi mạng thì giữ nguyên giỏ, không chặn khách.
        });
    };

    run();
    if (!pollMs) return () => ac.abort();

    const timer = setInterval(run, pollMs);
    return () => {
      ac.abort();
      clearInterval(timer);
    };
  }, [itemCount, orderType, qrToken, branchSlug, pollMs, reconcileCrossTimeItems]);
}
