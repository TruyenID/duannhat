/**
 * TableHistoryView — full-page master–detail history for ONE table.
 *
 * Opened from a table card's `…` menu on the overview. Left column groups the
 * table's orders by day (all statuses, newest first); the right column tells the
 * selected order's story — when it opened, when each item was added, which items
 * were voided (when + why), and how it was paid (method, and for cash the amount
 * tendered + change). Served LAN-first by the workstation (persistent table_id +
 * order_tables pivot → full local history); a Cloud fallback returns only the
 * table's live order.
 *
 * The row + order-detail rendering lives in order-history-shared.tsx so the
 * all-tables OrderHistoryPage shows the identical "full listing".
 */

import { useMemo, useState } from "react";
import { ArrowLeftIcon } from "lucide-react";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import { HelpButton } from "@/help/help-button";
import { useLocale, useTranslation } from "@/providers/app-provider";
import { formatDate, formatYmd } from "@/lib/format-date";
import { useTableOrders } from "@/hooks/api/use-orders";
import { formatCurrency } from "../lib/totals";
import { OrderDetail, OrderRow, num } from "./order-history-shared";
import type { CustomerOrder } from "../types";

type DateWindow = "today" | "d7" | "d30" | "all";

function windowToDateFrom(w: DateWindow): string | undefined {
  if (w === "all") return undefined;
  const days = w === "today" ? 0 : w === "d7" ? 6 : 29;
  const d = new Date();
  d.setDate(d.getDate() - days);
  return formatYmd(d);
}

export interface TableHistoryViewProps {
  shopSlug: string;
  table: { id: string; name: string };
  onClose: () => void;
}

export function TableHistoryView({ shopSlug, table, onClose }: TableHistoryViewProps) {
  const { t } = useTranslation();
  const { locale } = useLocale();
  const [dateWindow, setDateWindow] = useState<DateWindow>("d30");
  const [selectedId, setSelectedId] = useState<string | null>(null);

  const dateFrom = windowToDateFrom(dateWindow);
  const ordersQuery = useTableOrders(shopSlug, table.id, { dateFrom });

  // Safety net: the workstation filters server-side, but keep only orders truly
  // bound to this table when the server echoes tables[] (an old build might not
  // filter). Orders whose tables[] is empty are kept (server already scoped).
  const orders = useMemo(() => {
    const rows = ordersQuery.data?.data ?? [];
    return rows.filter(
      (o) => !o.tables?.length || o.tables.some((tb) => tb.id === table.id),
    );
  }, [ordersQuery.data, table.id]);

  // Group by calendar day for scannable multi-day history. `orders` is already
  // newest-first from the query, so Map insertion order preserves that.
  const groups = useMemo(() => {
    const byDay = new Map<string, CustomerOrder[]>();
    for (const o of orders) {
      const when = o.created_at ?? o.opened_at;
      const key = when ? formatYmd(new Date(when)) : "—";
      (byDay.get(key) ?? byDay.set(key, []).get(key)!).push(o);
    }
    return [...byDay.entries()].map(([key, list]) => {
      const when = list[0]?.created_at ?? list[0]?.opened_at;
      return { key, label: when ? formatDate(when, locale) : "—", orders: list };
    });
  }, [orders, locale]);

  // On lg the detail panel defaults to the newest order (no explicit tap yet);
  // on mobile `selectedId === null` keeps the list visible until a row is tapped.
  const detailId = selectedId ?? orders[0]?.id ?? null;

  const revenue = useMemo(
    () => orders.reduce((sum, o) => sum + num(o.paid_amount), 0),
    [orders],
  );

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden bg-background">
      {/* Header */}
      <div className="shrink-0 border-b bg-card px-3 py-3 sm:px-4">
        <div className="flex flex-wrap items-center gap-3">
          <button
            type="button"
            onClick={onClose}
            aria-label={t("common.back")}
            className="text-muted-foreground hover:bg-muted hover:text-foreground flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-full transition-colors"
          >
            <ArrowLeftIcon className="size-5" />
          </button>
          <div className="min-w-0">
            <h2 className="truncate text-base font-semibold tracking-tight">
              {t("pos.table_history.title", { table: table.name })}
            </h2>
            <p className="text-xs text-muted-foreground">
              {t("pos.table_history.summary", {
                count: orders.length,
                revenue: formatCurrency(revenue),
              })}
            </p>
          </div>

          <HelpButton topic="table-history" />

          <div className="ml-auto flex items-center gap-1 rounded-lg border bg-muted/40 p-0.5">
            {(["today", "d7", "d30", "all"] as DateWindow[]).map((w) => (
              <button
                key={w}
                type="button"
                onClick={() => setDateWindow(w)}
                className={cn(
                  "cursor-pointer rounded-md px-2.5 py-1 text-xs font-medium transition-colors",
                  dateWindow === w
                    ? "bg-card text-foreground shadow-sm"
                    : "text-muted-foreground hover:text-foreground",
                )}
              >
                {t(`pos.table_history.date.${w}`)}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Master–detail */}
      <div className="flex min-h-0 flex-1">
        {/* Order list, grouped by day */}
        <div
          className={cn(
            "w-full shrink-0 overflow-y-auto border-r lg:w-[340px]",
            selectedId && "hidden lg:block",
          )}
        >
          {ordersQuery.isLoading ? (
            <div className="flex items-center justify-center py-16">
              <Spinner />
            </div>
          ) : orders.length === 0 ? (
            <div className="px-6 py-16 text-center text-sm text-muted-foreground">
              {t("pos.table_history.no_orders")}
            </div>
          ) : (
            groups.map((group) => (
              <div key={group.key}>
                <div className="sticky top-0 z-[1] border-b border-border/60 bg-background/95 px-4 py-2 text-xs font-medium text-muted-foreground backdrop-blur">
                  {group.label}
                </div>
                <ul className="divide-y divide-border/50">
                  {group.orders.map((order) => (
                    <li key={order.id}>
                      <OrderRow
                        order={order}
                        active={order.id === detailId}
                        onSelect={() => setSelectedId(order.id)}
                        t={t}
                        locale={locale}
                      />
                    </li>
                  ))}
                </ul>
              </div>
            ))
          )}
        </div>

        {/* Detail */}
        <div
          className={cn(
            "min-w-0 flex-1 overflow-y-auto",
            !selectedId && "hidden lg:block",
          )}
        >
          {detailId ? (
            <OrderDetail
              shopSlug={shopSlug}
              orderId={detailId}
              onBack={() => setSelectedId(null)}
              showPlace={false}
              // #3040 — bật in lại NGAY TRÊN màn bán hàng, đảo ruling cũ.
              //
              // Bản trước cố ý KHÔNG truyền cờ này: màn theo bàn mở giữa giờ
              // phục vụ cạnh một đơn đang sống, nên một cú chạm nhầm là giấy
              // khách không hề xin. Lý lẽ đó vẫn đúng — nó chỉ thua một chi phí
              // lớn hơn, và là chi phí ĐANG XẢY RA.
              //
              // Đơn trả online không có màn biên lai nào bật ra: khách trả bằng
              // QR/thẻ ở Cloud, đơn sync-down về `closed`, và tờ giấy duy nhất
              // tự in là phiếu 「ĐÃ THANH TOÁN BÀN X」 cho nhân viên dọn bàn —
              // không phải biên lai của khách. Muốn in, thu ngân phải rời màn
              // bán hàng: Báo cáo → Lịch sử → tìm đơn → in. Khách đứng chờ hết
              // quãng đó.
              //
              // Chủ dự án chốt 2026-08-16: giấy in nhầm là chi phí CÓ THỂ xảy
              // ra và rẻ; khách chờ là chi phí CHẮC CHẮN và đang diễn ra.
              //
              // Rào tự nhiên vẫn còn nguyên và không nới: nút chỉ hiện khi
              // `status === "closed"` VÀ có thanh toán đã vào tiền. Đơn đang
              // sống — thứ mà ruling cũ lo — không bao giờ hiện nút.
              allowReprint
              t={t}
              locale={locale}
            />
          ) : (
            <div className="flex h-full items-center justify-center px-6 text-center text-sm text-muted-foreground">
              {t("pos.table_history.select_order")}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
