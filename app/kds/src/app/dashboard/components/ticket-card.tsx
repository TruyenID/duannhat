import { useEffect, useState } from "react";
import { useIsMutating } from "@tanstack/react-query";
import { useBumpAll } from "@/hooks/use-bump-all";
import { BUMP_MUTATION_KEY } from "@/hooks/bump-mutation-key";
import { cn, priorityColorClass } from "@/lib/utils";
import { useTranslation } from "@/i18n";
import { ItemRow } from "./item-row";
import type { KdsOrder } from "@/services/kds/orders";

export function TicketCard({ order }: { order: KdsOrder }) {
  const { t } = useTranslation();
  const bumpAll = useBumpAll();
  // Any bump in flight (this ticket's bump-all, another ticket's, or any
  // single-item bump) disables this button too — shared anti-misclick guard.
  const bumpsInFlight = useIsMutating({ mutationKey: BUMP_MUTATION_KEY }) > 0;

  // Server returns aging_minutes + priority, so the row only needs to re-render
  // when the server values change — but a 30s tick keeps the displayed minutes
  // approximately fresh between refetches (useOrders staleTime=5s, refetch=15s)
  // so the cook sees aging count up smoothly under load even if the network
  // hiccups for one poll cycle.
  const [, force] = useState(0);
  useEffect(() => {
    const interval = setInterval(() => force((n) => n + 1), 30_000);
    return () => clearInterval(interval);
  }, []);

  const pendingItems = order.items.filter((i) => i.status === "pending");
  const tableLabel = order.table
    ? `${t("dashboard.table")} ${order.table.code}`
    : t("dashboard.takeaway");

  return (
    <div
      data-testid={`ticket-${order.id}`}
      data-priority={order.priority}
      className={cn(
        "rounded-lg border-2 bg-card overflow-hidden",
        priorityColorClass(order.priority),
      )}
    >
      <header className="p-3 border-b bg-muted/30">
        <div className="flex justify-between items-baseline gap-2">
          <h3 className="font-semibold">
            {tableLabel} · #{order.order_code}
          </h3>
          <span className="text-sm text-muted-foreground tabular-nums">
            {order.aging_minutes} {t("dashboard.minutes")}
          </span>
        </div>
        {order.guest_count != null && order.guest_count > 0 && (
          <div className="text-xs text-muted-foreground mt-1">
            {order.guest_count} {t("dashboard.guests")}
          </div>
        )}
        {order.note && (
          <div className="text-xs text-foreground/80 mt-1 italic">
            {t("dashboard.order_note")}: {order.note}
          </div>
        )}
      </header>

      <div className="p-2">
        {order.items.map((item) => (
          <ItemRow key={item.id} orderId={order.id} item={item} />
        ))}
      </div>

      {pendingItems.length > 0 && order.can_bump_all && (
        <footer className="p-2 border-t bg-muted/20">
          <button
            type="button"
            onClick={() => {
              bumpAll.mutate({
                orderId: order.id,
                scope: "pending",
                targetItems: pendingItems,
              });
            }}
            disabled={bumpAll.isPending || bumpsInFlight}
            className="w-full bg-primary text-primary-foreground p-3 rounded-lg min-h-14 font-medium disabled:opacity-50"
          >
            {t("dashboard.bump_all_pending")}
          </button>
        </footer>
      )}
    </div>
  );
}
