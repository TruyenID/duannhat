"use client";

/**
 * Lịch sử đơn hàng của khách ĐÃ ĐĂNG NHẬP (`/account/orders`).
 *
 * Dùng đúng card + tab của `/orders` (guest) — xem
 * `components/order-history-card.tsx`. Khác biệt duy nhất còn lại giữa hai
 * màn hình là NGUỒN dữ liệu: guest đọc pointer localStorage rồi batch-fetch,
 * còn đây là `GET /customer/me/orders` có cursor pagination (nút "Xem thêm").
 *
 * Vỏ do LAYOUT của nhóm `(tabs)` cấp (#1938), không còn bọc ở đây — trang này
 * từng tự dựng header + sub-header riêng nên vào lịch sử đơn là mất sidebar
 * điều hướng, muốn sang mục khác phải bấm back.
 */

import { useState, useEffect, useCallback } from "react";
import { useRouter } from "@/i18n/routing";
import { useParams } from "next/navigation";
import { accountHref } from "@/lib/shop-routes";
import { useTranslations } from "next-intl";
import { useAuth } from "@/context/auth-context";
import { apiFetch } from "@/lib/api";
import { Button } from "@/components/ui/button";
import OrderHistoryCard, {
  OrderHistoryEmptyState,
  OrderHistoryTabs,
  matchesOrderTab,
  useNowMs,
  type OrderHistorySummary,
  type OrderHistoryTab,
} from "@/components/order-history-card";

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/**
 * `CustomerOrderSummaryResource` — cùng resource mà `/orders` nhận từ endpoint
 * batch, cộng vài trường chỉ màn hình này dùng.
 */
interface AccountOrder extends OrderHistorySummary {
  order_code: string;
  created_at: string;
}

interface OrdersResponse {
  data: AccountOrder[];
  meta: { has_more: boolean; next_cursor: string | null };
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------
export default function AccountOrdersView() {
  // Cửa hàng đang xem, từ segment `[shop]` của URL (#1505).
  const { shop } = useParams<{ shop?: string }>();
  const { isLoggedIn, isLoading } = useAuth();
  const router = useRouter();
  const t = useTranslations("account");

  const [orders, setOrders] = useState<AccountOrder[] | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [cursor, setCursor] = useState<string | null>(null);
  const [hasMore, setHasMore] = useState(false);
  const [filter, setFilter] = useState<OrderHistoryTab>("all");

  // Auth guard
  useEffect(() => {
    if (!isLoading && !isLoggedIn) {
      router.replace("/login?redirect=/account/orders");
    }
  }, [isLoading, isLoggedIn, router]);

  // Fetch on mount. Lọc theo tab làm ở client (cùng predicate với `/orders`)
  // nên không refetch khi đổi tab — BE chỉ biết `status` thô, không biết khái
  // niệm "chưa thanh toán" mà card đang hiển thị.
  useEffect(() => {
    if (!isLoggedIn) return;
    const ac = new AbortController();

    apiFetch<OrdersResponse>("/api/v1/customer/me/orders", {
      silent401: true,
      signal: ac.signal,
    })
      .then(({ data, meta }) => {
        if (ac.signal.aborted) return;
        setOrders(data);
        setCursor(meta.next_cursor);
        setHasMore(meta.has_more);
      })
      .catch(() => {
        if (!ac.signal.aborted) setOrders([]);
      });

    return () => ac.abort();
  }, [isLoggedIn]);

  const loadMore = useCallback(async () => {
    if (!cursor) return;
    setLoadingMore(true);
    try {
      const { data, meta } = await apiFetch<OrdersResponse>(
        `/api/v1/customer/me/orders?cursor=${encodeURIComponent(cursor)}`,
        { silent401: true },
      );
      setOrders((prev) => [...(prev ?? []), ...data]);
      setCursor(meta.next_cursor);
      setHasMore(meta.has_more);
    } catch {
      // error state — keep existing data
    } finally {
      setLoadingMore(false);
    }
  }, [cursor]);

  // plan-031 — countdown chạm 0 KHÔNG được tự quyết định đơn đã hết hạn (đồng
  // hồ máy khách có thể chạy nhanh). Hỏi lại server đúng một đơn qua endpoint
  // batch — cùng resource với list nên card không bị ghi đè bằng shape nghèo
  // hơn — rồi để `is_payment_overdue` của server quyết định.
  const reconcileOnExpiry = useCallback((id: string) => {
    apiFetch<{ data: AccountOrder[] }>("/api/v1/customer/orders/batch", {
      method: "POST",
      body: JSON.stringify({ ids: [id] }),
      headers: { "Content-Type": "application/json" },
      silent401: true,
    })
      .then((res) => {
        const fresh = res.data?.find((o) => o.id === id);
        if (!fresh) return;
        setOrders((prev) =>
          prev
            ? prev.map((o) => (o.id === id ? { ...o, ...fresh } : o))
            : prev,
        );
      })
      .catch(() => {
        // Network / 5xx: giữ nguyên đơn — không bao giờ ẩn đơn vì lỗi mạng.
      });
  }, []);

  const nowMs = useNowMs();
  const loading = orders === null;
  const visibleOrders = (orders ?? []).filter((order) =>
    matchesOrderTab(order, filter, nowMs),
  );

  // Vỏ được giữ nguyên kể cả lúc chưa biết phiên đăng nhập: sidebar không phụ
  // thuộc dữ liệu đơn hàng nên hiện ngay, chỉ panel mới quay spinner.
  if (isLoading) {
    return (
      <>
        <div className="flex items-center justify-center py-20">
          <span className="h-5 w-5 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>
      </>
    );
  }

  if (!isLoggedIn) return null;

  return (
    <>
      <h2 className="text-xl font-bold text-primary">{t("navOrders")}</h2>

      <div className="mt-6">
        {!loading && orders && orders.length > 0 && (
          <OrderHistoryTabs value={filter} onChange={setFilter} />
        )}

        {/* Loading skeleton — cùng hình dáng với card thật để không nhảy layout */}
        {loading && (
          <div className="space-y-3">
            {[1, 2, 3].map((i) => (
              <div
                key={i}
                className="animate-pulse rounded-2xl border border-neutral-200 bg-white p-3 shadow-sm"
              >
                <div className="flex gap-3">
                  <div className="size-16 shrink-0 rounded-xl bg-neutral-100" />
                  <div className="flex-1 space-y-2 py-1">
                    <div className="flex items-center justify-between">
                      <div className="h-4 w-16 rounded bg-neutral-100" />
                      <div className="h-3 w-20 rounded bg-neutral-100" />
                    </div>
                    <div className="h-3 w-40 rounded bg-neutral-100" />
                    <div className="flex items-center justify-between">
                      <div className="h-3 w-16 rounded bg-neutral-100" />
                      <div className="h-3 w-14 rounded bg-neutral-100" />
                    </div>
                  </div>
                </div>
              </div>
            ))}
          </div>
        )}

        {/* Empty — chưa có đơn nào, hoặc tab đang chọn không có đơn nào */}
        {!loading && visibleOrders.length === 0 && <OrderHistoryEmptyState />}

        {/* Order list */}
        {!loading && visibleOrders.length > 0 && (
          <div className="space-y-3">
            {visibleOrders.map((order) => (
              <OrderHistoryCard
                key={order.id}
                order={order}
                detailHref={accountHref(shop, `orders/${order.id}`)}
                code={order.order_code ?? order.code}
                dateIso={order.created_at}
                shopSlug={order.branch?.slug ?? ""}
                onReconcileExpiry={reconcileOnExpiry}
              />
            ))}
          </div>
        )}

        {/* Load more — danh sách phân trang theo cursor; lọc tab chạy ở client
            nên một tab hẹp có thể rỗng dù server còn dữ liệu, vẫn phải cho tải
            tiếp. */}
        {!loading && hasMore && (
          <div className="flex justify-center pt-4">
            <Button
              variant="outline"
              size="sm"
              className="rounded-xl"
              disabled={loadingMore}
              onClick={() => loadMore()}
            >
              {loadingMore ? (
                <span className="h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
              ) : (
                t("loadMore")
              )}
            </Button>
          </div>
        )}
      </div>
    </>
  );
}
