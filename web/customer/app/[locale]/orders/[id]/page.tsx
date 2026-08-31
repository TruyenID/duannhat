"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { useParams } from "next/navigation";
import { Link, usePathname, useRouter } from "@/i18n/routing";
import { useTranslations } from "next-intl";
import { useBrand } from "@/context/brand-context";
import { accountHref, authEntryPointsAllowed, loginHref, registerHref } from "@/lib/shop-routes";
import { AlertCircle, Info, Receipt } from "lucide-react";
import { apiFetch, ApiError } from "@/lib/api";
import { FEATURES } from "@/lib/feature-flags";
import { useAuth } from "@/context/auth-context";
import { loadGuestOrders } from "@/lib/guest-orders";
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
  | { kind: "forbidden" }
  | { kind: "error"; message: string };

// ─── Page ─────────────────────────────────────────────────────────────────

export default function OrderDetailPage() {
  const t = useTranslations("guestOrders");
  const { currentBranch } = useBrand();
  const tCommon = useTranslations("common");
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { isLoggedIn, isLoading: authLoading } = useAuth();

  const [state, setState] = useState<FetchState>({ kind: "loading" });

  /** Re-fetch helper — dùng cho realtime trigger (Reverb OrderPaid event)
   * và cho cancel/retry flows. Quietly update state, không bật loading
   * full-page (UX mượt hơn). */
  const refetchOrder = useCallback(() => {
    apiFetch<{ data: OrderDetailData }>(`/api/v1/customer/orders/${id}`, {
      silent401: true,
    })
      .then((res) => {
        setState({ kind: "ok", data: res.data });
      })
      .catch((err) => {
        console.warn("[orders/[id]] refetch failed", err);
      });
  }, [id]);

  // Countdown hết giờ → refetch 1 lần duy nhất. Ref guard vì badge gọi
  // onExpired lại mỗi khi effect của nó re-run ở secondsLeft === 0; không
  // chặn thì refetch → setState → re-render → refetch (vòng lặp).
  const expiredHandledRef = useRef(false);
  const handlePaymentExpired = useCallback(() => {
    if (expiredHandledRef.current) return;
    expiredHandledRef.current = true;
    refetchOrder();
  }, [refetchOrder]);

  // plan-050 — theo dõi đơn cho tới khi thu ngân/kiosk thu xong tiền, rồi
  // refetch để StatusBanner re-render màn "Thanh toán thành công". Đơn đã trả
  // đủ thì tắt hẳn. Nếu `refetchOrder` lỗi (wifi quán chập chờn) thì `enabled`
  // vẫn true → hook bắn lại sau 30s → tự gỡ, không cần khách reload.
  useOrderSettlement(id, {
    enabled: state.kind === "ok" && !state.data.is_fully_paid,
    onPaid: () => {
      refetchOrder();
    },
  });

  // Auth gate: guest chỉ xem được order trong localStorage (saveGuestOrder)
  // → tránh probe arbitrary ID. Logged-in user → /account/orders/{id}.
  useEffect(() => {
    if (authLoading) return;

    if (isLoggedIn) {
      router.replace(accountHref(currentBranch.slug, `orders/${id}`));
      return;
    }

    const guestOrders = loadGuestOrders();
    const hasAccess = guestOrders.some((o) => o.id === id);
    if (!hasAccess) {
      setState({ kind: "forbidden" });
      return;
    }

    let cancelled = false;
    apiFetch<{ data: OrderDetailData }>(`/api/v1/customer/orders/${id}`, {
      silent401: true,
    })
      .then((res) => {
        if (!cancelled) setState({ kind: "ok", data: res.data });
      })
      .catch((err) => {
        if (cancelled) return;
        if (err instanceof ApiError && err.status === 404) {
          setState({ kind: "not-found" });
        } else if (err instanceof ApiError) {
          setState({ kind: "error", message: `${err.status} ${err.message}` });
        } else {
          setState({ kind: "error", message: t("fetchError") });
        }
      });
    return () => {
      cancelled = true;
    };
  }, [authLoading, isLoggedIn, id, router, t]);

  if (authLoading || state.kind === "loading") {
    return <OrderDetailLoader />;
  }

  if (state.kind !== "ok") {
    const block =
      state.kind === "forbidden"
        ? {
            icon: <AlertCircle className="size-12 text-amber-500" />,
            title: t("forbiddenTitle"),
            message: t("forbiddenMessage"),
          }
        : state.kind === "not-found"
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
          ctaHref="/orders"
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
        /* Footer banner — guest only (logged-in đã redirect ở trên) */
        footer={<GuestStorageHint />}
      />
    </OrderDetailFrame>
  );
}

// ─── Guest footer banner ──────────────────────────────────────────────────

function GuestStorageHint() {
  const t = useTranslations("guestOrders");
  const { currentBranch } = useBrand();
  const pathname = usePathname();
  // Biến thể message không có CTA đăng nhập/đăng ký khi FEATURES.auth off
  // (#47), khi thôi mời đăng nhập (`authEntryPoints`), hoặc khi URL không xác
  // định được cửa hàng (#1717) — xem chú thích ở `app/[locale]/orders/page.tsx`.
  if (!FEATURES.auth || !FEATURES.authEntryPoints || !authEntryPointsAllowed(pathname)) {
    return (
      <div className="flex items-start gap-2 rounded-2xl border border-neutral-200 bg-neutral-100/60 px-4 py-3 text-xs leading-relaxed text-neutral-700 md:text-base">
        <Info className="mt-0.5 size-4 shrink-0 text-neutral-500 md:size-5" />
        <p>
          {t.rich("guestStorageHintDetailNoAuth", {
            b: (chunks) => (
              <span className="font-semibold text-neutral-900">{chunks}</span>
            ),
          })}
        </p>
      </div>
    );
  }
  return (
    <div className="flex items-start gap-2 rounded-2xl border border-neutral-200 bg-neutral-100/60 px-4 py-3 text-xs leading-relaxed text-neutral-700 md:text-base">
      <Info className="mt-0.5 size-4 shrink-0 text-neutral-500 md:size-5" />
      <p>
        {t.rich("guestStorageHintDetail", {
          b: (chunks) => (
            <span className="font-semibold text-neutral-900">{chunks}</span>
          ),
          login: (chunks) => (
            <Link
              href={loginHref(currentBranch.slug)}
              className="font-semibold text-emerald-700 hover:underline"
            >
              {chunks}
            </Link>
          ),
          register: (chunks) => (
            <Link
              href={registerHref(currentBranch.slug)}
              className="font-semibold text-emerald-700 hover:underline"
            >
              {chunks}
            </Link>
          ),
        })}
      </p>
    </div>
  );
}
