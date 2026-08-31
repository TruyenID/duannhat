"use client";

/**
 * Chi tiết đơn hàng của khách ĐÃ ĐĂNG NHẬP (`/account/orders/{id}`).
 *
 * Dùng đúng bộ dựng của `/orders/{id}` (guest) — xem
 * `components/order-detail-view.tsx`. Khác biệt còn lại giữa hai màn hình là
 * NGUỒN dữ liệu (`/customer/me/orders/{id}` có xác thực, thay vì endpoint
 * public-by-uuid của guest) và những field chỉ đơn của khách đăng nhập mới có:
 * danh sách payment, chi nhánh, bàn, số khách, ghi chú đơn.
 */

import { useState, useEffect, useCallback, useRef } from "react";
import { useRouter } from "@/i18n/routing";
import { useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { AlertCircle, Receipt } from "lucide-react";
import { useAuth } from "@/context/auth-context";
import { apiFetch, ApiError } from "@/lib/api";
import { useOrderSettlement } from "@/hooks/use-order-settlement";
import {
  OrderDetailBody,
  OrderDetailErrorBlock,
  OrderDetailFrame,
  OrderDetailLoader,
  type OrderDetailData,
} from "@/components/order-detail-view";

type FetchState =
  | { kind: "loading" }
  | { kind: "ok"; data: OrderDetailData }
  | { kind: "not-found" }
  | { kind: "error"; message: string };

export default function AccountOrderDetailView() {
  const { isLoggedIn, isLoading } = useAuth();
  const router = useRouter();
  const params = useParams();
  const id = params.id as string;
  const t = useTranslations("guestOrders");
  const tCommon = useTranslations("common");

  const [state, setState] = useState<FetchState>({ kind: "loading" });

  const refetchOrder = useCallback(() => {
    apiFetch<{ data: OrderDetailData }>(`/api/v1/customer/me/orders/${id}`, {
      silent401: true,
    })
      .then((res) => setState({ kind: "ok", data: res.data }))
      .catch((err) => {
        console.warn("[account/orders/[id]] refetch failed", err);
      });
  }, [id]);

  // Countdown hết giờ → refetch ĐÚNG MỘT LẦN. Badge gọi onExpired lại mỗi khi
  // effect của nó chạy lại ở secondsLeft === 0; không chặn thì refetch →
  // setState → re-render → refetch (vòng lặp).
  const expiredHandledRef = useRef(false);
  const handlePaymentExpired = useCallback(() => {
    if (expiredHandledRef.current) return;
    expiredHandledRef.current = true;
    refetchOrder();
  }, [refetchOrder]);

  // plan-050 — theo đơn cho tới khi thu ngân/kiosk thu xong tiền rồi refetch,
  // để banner tự chuyển sang "Đã thanh toán" mà khách không phải reload.
  useOrderSettlement(id, {
    enabled: state.kind === "ok" && !state.data.is_fully_paid,
    onPaid: () => refetchOrder(),
  });

  // Auth guard
  useEffect(() => {
    if (!isLoading && !isLoggedIn) {
      router.replace("/login?redirect=/account/orders");
    }
  }, [isLoading, isLoggedIn, router]);

  // Fetch order detail
  useEffect(() => {
    if (!isLoggedIn || !id) return;
    const ac = new AbortController();
    apiFetch<{ data: OrderDetailData }>(`/api/v1/customer/me/orders/${id}`, {
      silent401: true,
      signal: ac.signal,
    })
      .then(({ data }) => {
        if (!ac.signal.aborted) setState({ kind: "ok", data });
      })
      .catch((err) => {
        if (ac.signal.aborted) return;
        if (err instanceof ApiError && err.status === 404) {
          setState({ kind: "not-found" });
        } else if (err instanceof ApiError) {
          setState({ kind: "error", message: `${err.status} ${err.message}` });
        } else {
          setState({ kind: "error", message: t("fetchError") });
        }
      });
    return () => ac.abort();
  }, [isLoggedIn, id, t]);

  if (isLoading || state.kind === "loading") {
    return <OrderDetailLoader />;
  }

  if (!isLoggedIn) return null;

  if (state.kind !== "ok") {
    const block =
      state.kind === "not-found"
        ? {
            icon: <Receipt className="size-12 text-neutral-300" />,
            title: t("notFoundTitle"),
            message: t("notFoundMessage"),
          }
        : {
            icon: <AlertCircle className="size-12 text-destructive" />,
            title: t("fetchError"),
            message: state.message,
          };

    return (
      <OrderDetailFrame
        backLabel={tCommon("back")}
        onBack={() => router.back()}
        title={t("detailTitle")}
      >
        <OrderDetailErrorBlock
          icon={block.icon}
          title={block.title}
          message={block.message}
          ctaHref="/account/orders"
          ctaLabel={t("backToList")}
        />
      </OrderDetailFrame>
    );
  }

  const order = state.data;

  return (
    <OrderDetailFrame
      backLabel={tCommon("back")}
      onBack={() => router.back()}
      title={t("detailTitle")}
    >
      <OrderDetailBody
        order={order}
        payHref={`/orders/${order.id}/pay`}
        onPaymentExpired={handlePaymentExpired}
      />
    </OrderDetailFrame>
  );
}
