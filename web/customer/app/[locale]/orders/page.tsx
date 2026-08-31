"use client";

import { useCallback, useEffect, useState } from "react";
import { Link, usePathname, useRouter } from "@/i18n/routing";
import { useTranslations } from "next-intl";
import { useBrand } from "@/context/brand-context";
import { accountHref, authEntryPointsAllowed, loginHref, registerHref } from "@/lib/shop-routes";
import { toast } from "sonner";
import { ArrowLeft, Info, Loader2 } from "lucide-react";
import Header from "@/components/Header";
import { apiFetch, ApiError } from "@/lib/api";
import { FEATURES } from "@/lib/feature-flags";
import { useAuth } from "@/context/auth-context";
import {
  loadGuestOrders,
  removeGuestOrder,
  type GuestOrder,
} from "@/lib/guest-orders";
import OrderHistoryCard, {
  OrderHistoryEmptyState,
  OrderHistoryTabs,
  matchesOrderTab,
  useNowMs,
  type OrderHistorySummary,
  type OrderHistoryTab,
} from "@/components/order-history-card";

// ─── Types ────────────────────────────────────────────────────────────────

type FetchedState =
  | { kind: "loading" }
  | { kind: "ok"; data: OrderHistorySummary }
  | { kind: "not-found" }
  | { kind: "error" };

// ─── Page ─────────────────────────────────────────────────────────────────

export default function GuestOrdersPage() {
  const t = useTranslations("guestOrders");
  const { currentBranch } = useBrand();
  const tCommon = useTranslations("common");
  const router = useRouter();
  const { isLoggedIn, isLoading: authLoading } = useAuth();

	  const [pointers, setPointers] = useState<GuestOrder[]>([]);
	  const [fetched, setFetched] = useState<Record<string, FetchedState>>({});
	  const [filter, setFilter] = useState<OrderHistoryTab>("all");
	  const nowMs = useNowMs();

  // Đọc tab từ query parameter để set default filter
	  useEffect(() => {
	    if (typeof window === "undefined") return;
	    const params = new URLSearchParams(window.location.search);
	    const tabParam = params.get("tab");
	    if (tabParam === "pending" || tabParam === "paid" || tabParam === "all") {
	      setFilter(tabParam);
	    }
	  }, []);

  // Logged-in customers → /account/orders (BE-backed list).
  // IMPORTANT: Only redirect if we have BOTH token AND user data.
  // This prevents redirect loop when token exists but is invalid.
  const { user } = useAuth();
  useEffect(() => {
    if (authLoading) return;
    // Only redirect if fully authenticated (not just token exists)
    if (isLoggedIn && user) {
      router.replace(accountHref(currentBranch.slug, "orders"));
    }
    // Guest → fall through and render the localStorage-backed list below.
  }, [authLoading, isLoggedIn, user, router]);

  // Hydrate pointers từ localStorage MỘT LẦN khi page mount.
  // Không poll nữa để tránh lặp lại batch fetch + log liên tục.
  useEffect(() => {
    const latest = loadGuestOrders();
    setPointers(latest);
  }, []);

	  // plan-031: Batch fetch orders from DB using localStorage IDs.
	  // Guest users track order IDs in localStorage, then fetch fresh data
	  // from database (including payment_due_at for countdown).
	  // Auto-refresh every 30s để đồng bộ với job auto-cancel trên BE.
	  useEffect(() => {
	    if (pointers.length === 0) return;

	    let cancelled = false;

	    // Đảm bảo mọi pointer đều có state ban đầu là `loading` ít nhất một lần.
	    pointers.forEach((p) => {
	      setFetched((prev) =>
	        prev[p.id] ? prev : { ...prev, [p.id]: { kind: "loading" } },
	      );
	    });

	    const fetchBatch = () => {
	      if (cancelled || pointers.length === 0) return;

	      apiFetch<{ data: OrderHistorySummary[] }>("/api/v1/customer/orders/batch", {
	        method: "POST",
	        body: JSON.stringify({ ids: pointers.map((p) => p.id) }),
	        headers: { "Content-Type": "application/json" },
	        silent401: true,
	      })
	        .then((res) => {
	          if (cancelled) return;

	          const orderMap = new Map(res.data.map((o) => [o.id, o]));
	          setFetched((prev) => {
	            const next = { ...prev };
	            pointers.forEach((p) => {
	              const order = orderMap.get(p.id);
	              if (order) {
	                // Detect transition -> cancelled để show toast một lần
	                const prevState = prev[p.id];
	                const wasCancelled =
	                  prevState &&
	                  prevState.kind === "ok" &&
	                  prevState.data.status === "cancelled";
	                const isNowCancelled = order.status === "cancelled";

	                if (!wasCancelled && isNowCancelled) {
	                  // Thông báo đơn đã bị huỷ do quá thời gian thanh toán
	                  toast.error(t("cancelledMessage"));
	                }

	                // Giữ pointer cho TẤT CẢ orders (kể cả cancelled) để filter
	                // tab xử lý hiển thị (pending/paid/cancelled/all).
	                next[p.id] = { kind: "ok", data: order };
	              } else {
	                // Không tìm thấy order tương ứng trong batch response.
	                // Xem như pointer stale → xoá để tránh badge hiển thị sai.
	                console.warn("[/orders] Order NOT FOUND in response:", p.id);
	                console.warn(
	                  "[/orders] Available IDs in batch:",
	                  Array.from(orderMap.keys()),
	                );
	                removeGuestOrder(p.id);
	                next[p.id] = { kind: "not-found" };
	              }
	            });
	            return next;
	          });
	        })
	        .catch((err) => {
	          if (cancelled) return;
	          console.error("[/orders] Batch fetch failed:", err);
	          // Fallback to individual fetches on batch error
	          pointers.forEach((p) => {
	            apiFetch<{ data: OrderHistorySummary }>(
	              `/api/v1/customer/orders/${p.id}`,
	              { silent401: true },
	            )
	              .then((res) => {
	                if (cancelled) return;
	                setFetched((prev) => ({
	                  ...prev,
	                  [p.id]: { kind: "ok", data: res.data },
	                }));
	              })
	              .catch((err) => {
	                if (cancelled) return;
	                const status = err instanceof ApiError ? err.status : null;
	                setFetched((prev) => ({
	                  ...prev,
	                  [p.id]: { kind: status === 404 ? "not-found" : "error" },
	                }));
	              });
	          });
	        });
	    };

	    // Fetch ngay lần đầu khi mount / thay đổi pointers
	    fetchBatch();

	    // Auto-refresh mỗi 30s theo plan-031
	    const intervalId = window.setInterval(fetchBatch, 30_000);

	    return () => {
	      cancelled = true;
	      clearInterval(intervalId);
	    };
	  }, [pointers, t]);

  // plan-031 — When a card countdown reaches 0 on the CLIENT clock we must NOT
  // remove the guest pointer or hide the order outright: a skewed-fast clock
  // would strand a still-payable order (localStorage is the guest's only handle
  // to it). Instead reconcile with the server — re-fetch the order and let its
  // authoritative `status` decide. Cancelled orders keep their pointer (shown in
  // the "Đã huỷ" tab); only a 404 (server confirms the order is truly gone)
  // drops the pointer. A still-open order is left untouched and stays payable.
  //
  // Dùng ĐÚNG endpoint batch của list (không phải `/orders/{id}`): detail
  // resource có shape khác (thiếu `branch`, items được lọc khác) nên nếu
  // reconcile bằng nó thì card đang hiển thị tên món + ảnh sẽ bị ghi đè bằng
  // dữ liệu nghèo hơn — đúng triệu chứng "cứ bị mất hình" sau khi countdown
  // về 0.
  const reconcileOnExpiry = useCallback((id: string) => {
    apiFetch<{ data: OrderHistorySummary[] }>("/api/v1/customer/orders/batch", {
      method: "POST",
      body: JSON.stringify({ ids: [id] }),
      headers: { "Content-Type": "application/json" },
      silent401: true,
    })
      .then((res) => {
        const order = res.data?.find((o) => o.id === id);
        if (!order) return; // không có trong batch → giữ nguyên state cũ
        setFetched((prev) => ({ ...prev, [id]: { kind: "ok", data: order } }));
      })
      .catch((err) => {
        const status = err instanceof ApiError ? err.status : null;
        if (status === 404) {
          removeGuestOrder(id);
          setPointers((prev) => prev.filter((p) => p.id !== id));
          setFetched((prev) => {
            const next = { ...prev };
            delete next[id];
            return next;
          });
        }
        // Network / 5xx: keep the pointer — never strand on a failed reconcile.
      });
  }, []);

  // Show loading while checking auth OR while logged-in redirect is happening
  // Show loading spinner khi đang check auth HOẶC đang redirect logged-in user
  if (authLoading || (isLoggedIn && user)) {
    return (
      <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
        <Header showLogo hideSwitcher />
        <div className="flex flex-1 items-center justify-center">
          <Loader2 className="size-8 animate-spin text-primary" />
        </div>
      </div>
    );
  }

  return (
    <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
      {/* `hideShadow` để mobile không có gạch ngang giữa global header và
          sub-header sticky bên dưới — match pattern checkout-page-mobile. */}
      <Header showLogo hideSwitcher hideOrderCta hideShadow hideRegister />

      {/* Sub-header: back + tiêu đề.
          - Mobile: sticky `top-12 z-30 bg-white` + border-b để tách lớp
            khi scroll qua (pattern checkout mobile).
          - Desktop: non-sticky, bg-#FAFAFA cùng page, `md:border-b-0` để
            tránh duplicate với border/shadow của global Header phía
            trên. Container `md:max-w-7xl` để "Lịch sử đặt hàng" align
            cùng cột dọc với VIET ORIGIN logo trong Header. */}
      <div className="sticky top-12 z-30 border-b border-neutral-200 bg-white md:static md:top-auto md:z-auto md:border-b-0 md:bg-[#FAFAFA]">
        <div className="mx-auto flex max-w-3xl items-center gap-2 px-4 py-3 md:max-w-7xl md:px-6 md:py-4">
          <button
            onClick={() => router.back()}
            aria-label={tCommon("back")}
            className="-ml-1 flex size-7 items-center justify-center rounded-lg text-neutral-700 transition-colors hover:bg-muted"
          >
            <ArrowLeft className="size-5" />
          </button>
          <h1 className="truncate text-base font-bold text-neutral-900">
            {t("title")}
          </h1>
        </div>
      </div>

	      <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-4 md:px-6 md:py-6">
	        {pointers.length > 0 && (
	          <OrderHistoryTabs value={filter} onChange={setFilter} />
	        )}

        {pointers.length === 0 ? (
          <OrderHistoryEmptyState />
        ) : (
          <div className="space-y-3">
            {pointers
              .map((p) => ({ pointer: p, state: fetched[p.id] }))
              // Card chỉ render được khi đã có dữ liệu server; loading/error/
              // not-found trước đây cũng lọt qua filter rồi bị card trả null.
              .filter(
                (
                  entry,
                ): entry is {
                  pointer: GuestOrder;
                  state: { kind: "ok"; data: OrderHistorySummary };
                } => entry.state?.kind === "ok",
              )
              .filter((entry) => matchesOrderTab(entry.state.data, filter, nowMs))
              .map(({ pointer, state }) => (
                <OrderHistoryCard
                  key={pointer.id}
                  order={state.data}
                  detailHref={`/orders/${pointer.id}`}
                  code={pointer.code}
                  dateIso={pointer.createdAt}
                  shopSlug={pointer.shop}
                  onReconcileExpiry={reconcileOnExpiry}
                />
              ))}
          </div>
        )}

        {/* Footer banner — chỉ render khi guest (page đã guard
            logged-in redirect ở trên). Khuyến khích login để lưu vĩnh viễn
            + tích điểm. Click vào "Đăng nhập" / "Đăng ký" navigate sang
            login/register flow. */}
        {pointers.length > 0 && <GuestStorageHint />}
      </main>
    </div>
  );
}

// ─── Subcomponents ────────────────────────────────────────────────────────

function GuestStorageHint() {
  const t = useTranslations("guestOrders");
  const { currentBranch } = useBrand();
  const pathname = usePathname();
  // Dùng biến thể message không có CTA đăng nhập/đăng ký khi FEATURES.auth off
  // (#47 — tránh link mồ côi tới route đã bị chặn) hoặc khi URL không xác định
  // được cửa hàng (#1717). `/orders` là lịch sử guest gộp nhiều cửa hàng, nên
  // CTA ở đây từng dựng href từ localStorage và quy khách về một chi nhánh
  // tuỳ tiện.
  // `authEntryPoints` off → cùng biến thể không-CTA đó: chuỗi
  // `guestStorageHintNoAuth` đã nói đủ ý (đơn lưu trên máy này), chỉ thiếu lời
  // mời đăng nhập — đúng thứ đang phải tắt.
  if (!FEATURES.auth || !FEATURES.authEntryPoints || !authEntryPointsAllowed(pathname)) {
    return (
      <div className="mt-4 flex items-start gap-2 rounded-2xl border border-neutral-200 bg-neutral-100/60 px-4 py-3 text-xs leading-relaxed text-neutral-700 md:text-base">
        <Info className="mt-0.5 size-4 shrink-0 text-neutral-500 md:size-5" />
        <p>
          {t.rich("guestStorageHintNoAuth", {
            b: (chunks) => <span className="font-semibold text-neutral-900">{chunks}</span>,
          })}
        </p>
      </div>
    );
  }
  return (
    <div className="mt-4 flex items-start gap-2 rounded-2xl border border-neutral-200 bg-neutral-100/60 px-4 py-3 text-xs leading-relaxed text-neutral-700 md:text-base">
      <Info className="mt-0.5 size-4 shrink-0 text-neutral-500 md:size-5" />
      <p>
        {t.rich("guestStorageHint", {
          b: (chunks) => <span className="font-semibold text-neutral-900">{chunks}</span>,
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
