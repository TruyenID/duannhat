/**
 * TakeawayOrdersView — full-page list of the shop's active takeaway orders,
 * shown when the pinned "Mang về" tab is active (mirrors TablesOverview's
 * layout). Takeaway orders carry no table, so the table-centric overview can't
 * surface them — this tab is their home. Tapping a card opens (or switches to)
 * that order's tab, showing its full detail (items / cart / payment) just like
 * tapping a serving table on the overview grid.
 *
 * Data source: the dedicated `useTakeawayOrders` feed (order_type=takeaway),
 * branch-scoped server-side and kept live by the workstation socket. The parent
 * passes the already-fetched list.
 */

import { useMemo, useState } from "react";
import { ChevronRightIcon, SearchIcon, ShoppingBagIcon } from "lucide-react";
import { Input } from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { HelpButton } from "@/help/help-button";
import { useLocale, useTranslation } from "@/providers/app-provider";
import { formatTime } from "@/lib/format-date";
import { formatCurrency } from "../lib/totals";
import { isProvisionalCode } from "../lib/order-code";
import type { CustomerOrder, CustomerOrderStatus } from "../types";

// Status → badge tint. Same restrained -50 palette the table-status chips use
// (src/app/pos/lib/table-status.ts) so this surface reads as part of the same
// product, not a separate design.
const STATUS_BADGE: Record<CustomerOrderStatus, string> = {
  pending: "bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300",
  // Counter-pay customer-web takeaway lands here (`confirmed`) — same
  // "incoming, not yet worked" meaning as `pending`, so same tint.
  awaiting_confirmation: "bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300",
  confirmed: "bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300",
  expired: "bg-muted text-muted-foreground",
  open: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300",
  dining: "bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300",
  checkout: "bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300",
  paying: "bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300",
  closed: "bg-muted text-muted-foreground",
  voided: "bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300",
};

// A takeaway order's identity is usually the walk-in contact captured on the
// order (customer_takeaway_name), not a saved customer record — prefer that,
// then fall back to the linked customer's name.
function customerName(order: CustomerOrder): string | null {
  const takeaway = order.customer_takeaway_name?.trim();
  if (takeaway) return takeaway;
  const c = order.customer;
  if (!c) return null;
  const name = [c.first_name, c.last_name].filter(Boolean).join(" ").trim();
  return name || null;
}

function customerPhone(order: CustomerOrder): string | null {
  return (
    order.customer_takeaway_phone?.trim() || order.customer?.phone?.trim() || null
  );
}

export interface TakeawayOrdersViewProps {
  /** Takeaway orders only (parent filters order_type === "takeaway"). */
  orders: CustomerOrder[];
  /** Open or switch to the tab for this order (shows its full detail). */
  onOpenOrder: (orderId: string) => void;
  /** Shop display currency — forwarded to formatCurrency for each amount. */
  currencyCode?: string;
}

export function TakeawayOrdersView({
  orders,
  onOpenOrder,
  currencyCode,
}: TakeawayOrdersViewProps) {
  const { t } = useTranslation();
  const { locale } = useLocale();
  const [query, setQuery] = useState("");

  // Newest first — staff work the most recent takeaway orders. `created_at`
  // may be null on a bare create; those sort last (treated as epoch 0).
  const sorted = useMemo(() => {
    const ts = (o: CustomerOrder) =>
      o.created_at ? new Date(o.created_at).getTime() : 0;
    return [...orders].sort((a, b) => ts(b) - ts(a));
  }, [orders]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return sorted;
    return sorted.filter((o) => {
      const name = customerName(o)?.toLowerCase() ?? "";
      const phone = customerPhone(o) ?? "";
      return (
        o.order_code.toLowerCase().includes(q) ||
        name.includes(q) ||
        phone.includes(q)
      );
    });
  }, [sorted, query]);

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden bg-background">
      {/* Sticky header — title + count + search */}
      <div className="shrink-0 border-b bg-card px-3 py-3 sm:px-6 sm:py-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-2.5">
            <ShoppingBagIcon className="size-5 shrink-0 text-muted-foreground" />
            <h2 className="truncate text-base font-semibold tracking-tight">
              {t("pos.takeaway.title")}
            </h2>
            <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs font-medium tabular-nums text-muted-foreground">
              {orders.length}
            </span>
            <HelpButton topic="takeaway" />
          </div>

          {orders.length > 0 && (
            <div className="relative w-full sm:w-72">
              <SearchIcon className="text-muted-foreground pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2" />
              <Input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={t("pos.takeaway.search_placeholder")}
                className="h-10 pl-9"
                inputMode="search"
              />
            </div>
          )}
        </div>
      </div>

      {/* Scrollable card grid */}
      <div className="flex-1 overflow-y-auto px-3 py-3 pb-24 sm:px-6 sm:py-5 sm:pb-5">
        {filtered.length === 0 ? (
          <div className="flex flex-col items-center justify-center gap-2 py-20 text-center">
            <ShoppingBagIcon className="size-8 text-muted-foreground/40" />
            <div className="text-sm text-muted-foreground">
              {orders.length === 0
                ? t("pos.takeaway.empty")
                : t("pos.takeaway.empty_search")}
            </div>
          </div>
        ) : (
          <ul className="grid grid-cols-[repeat(auto-fill,minmax(256px,1fr))] gap-3">
            {filtered.map((order) => {
              const name = customerName(order);
              const phone = customerPhone(order);
              const remaining = Number(order.remaining_amount ?? 0);
              const created = order.created_at ?? order.opened_at;
              const badge = STATUS_BADGE[order.status] ?? STATUS_BADGE.open;
              const note = order.note?.trim();
              const provisional = isProvisionalCode(order.order_code);

              return (
                <li key={order.id}>
                  <button
                    type="button"
                    onClick={() => onOpenOrder(order.id)}
                    className={cn(
                      "group flex h-full min-h-[140px] w-full flex-col rounded-lg border bg-card p-4 text-left transition-colors",
                      "hover:border-primary/40 hover:bg-muted/30",
                      "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1",
                    )}
                  >
                    {/* Header: order code (full, the identifier) + status + a
                        chevron that fades in on hover — the app's list-card
                        affordance (see TablesOverview). A not-yet-synced LAN
                        order shows "đang cấp mã" instead of its internal WS-
                        code, matching the cart header. */}
                    <div className="flex items-center gap-2">
                      {provisional ? (
                        <span className="grow text-sm font-medium text-amber-600 dark:text-amber-400">
                          {t("pos.order.code_pending")}
                        </span>
                      ) : (
                        <span className="grow font-semibold tabular-nums tracking-tight text-foreground">
                          {order.order_code}
                        </span>
                      )}
                      <span
                        className={cn(
                          "shrink-0 rounded px-1.5 py-0.5 text-xs font-medium",
                          badge,
                        )}
                      >
                        {t(`pos.order_status.${order.status}`)}
                      </span>
                      <ChevronRightIcon className="size-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
                    </div>

                    {/* Meta — customer · phone · time, plain text (no icon
                        clutter), the way the rest of the app renders secondary
                        detail. */}
                    <div className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                      <span className="min-w-0 truncate">
                        {name ?? t("pos.takeaway.no_customer")}
                      </span>
                      {phone && (
                        <>
                          <span aria-hidden>·</span>
                          <span className="shrink-0 tabular-nums">{phone}</span>
                        </>
                      )}
                      {created && (
                        <span className="ml-auto shrink-0 tabular-nums">
                          {formatTime(created, locale, { withSeconds: false })}
                        </span>
                      )}
                    </div>

                    {/* Optional note — one muted line. */}
                    {note && (
                      <div className="mt-2 truncate text-xs text-muted-foreground/80">
                        {note}
                      </div>
                    )}

                    {/* Footer pinned to the bottom so every card's total lines
                        up across the grid. Total is the one figure that carries
                        weight; the unpaid balance sits quietly beside the label. */}
                    <div className="mt-auto flex items-baseline justify-between gap-2 border-t pt-3">
                      <span className="min-w-0 truncate text-xs text-muted-foreground">
                        {t("pos.takeaway.total")}
                        {remaining > 0 && (
                          <span className="text-amber-600 dark:text-amber-400">
                            {" · "}
                            {t("pos.takeaway.remaining")}{" "}
                            {formatCurrency(remaining, currencyCode)}
                          </span>
                        )}
                      </span>
                      <span className="shrink-0 text-base font-semibold tabular-nums text-foreground">
                        {formatCurrency(order.total_amount, currencyCode)}
                      </span>
                    </div>
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </div>
  );
}
