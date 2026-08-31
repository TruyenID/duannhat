import { useTranslation } from "@/i18n";
import { TicketCard } from "./ticket-card";
import type { KdsOrder } from "@/services/kds/orders";

export function TicketGrid({ orders }: { orders: KdsOrder[] }) {
  const { t } = useTranslation();

  if (orders.length === 0) {
    return (
      <div className="grid place-items-center min-h-[60vh] text-muted-foreground">
        {t("dashboard.no_orders")}
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 p-4">
      {orders.map((order) => (
        <TicketCard key={order.id} order={order} />
      ))}
    </div>
  );
}
