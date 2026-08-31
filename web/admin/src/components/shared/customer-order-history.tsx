"use client";
import Link from "next/link";
import type { CustomerOrder } from "@/services/order-service";
import { Card, Spinner } from "@godxjp/ui";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
export interface CustomerOrderHistoryProps {
  orders: CustomerOrder[];
  isLoading?: boolean;
  basePath: string;
  emptyMessage?: string;
  showBranch?: boolean;
}
export function CustomerOrderHistory({
  orders,
  isLoading,
  basePath,
  emptyMessage = "No orders yet.",
  showBranch,
}: CustomerOrderHistoryProps) {
  const { locale } = useTranslation();
  const { timezone } = useTimezone();
  return (
    <Card data-slot="customer-order-history" className="p-4">
      <h2 className="mb-3 text-sm font-semibold">Order history</h2>
      {isLoading && (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Spinner className="size-3.5" /> Loading…
        </div>
      )}
      {!isLoading && orders.length === 0 && (
        <p className="text-sm text-muted-foreground">{emptyMessage}</p>
      )}
      {!isLoading && orders.length > 0 && (
        <div className="space-y-1">
          {orders.map((o) => (
            <Link
              key={o.id}
              href={`${basePath}/${o.id}`}
              className="flex items-center justify-between rounded px-2 py-1.5 text-sm hover:bg-muted"
            >
              <span className="font-mono text-xs">{o.order_code}</span>
              {showBranch && (
                <span className="max-w-[100px] truncate text-xs text-muted-foreground">
                  {o.branch_id}
                </span>
              )}
              <span className="text-xs text-muted-foreground capitalize">{o.status}</span>
              <span className="tabular-nums">¥{o.total_amount.toLocaleString()}</span>
              <span className="text-xs text-muted-foreground">
                {formatDate(o.created_at, locale, timezone)}
              </span>
            </Link>
          ))}
        </div>
      )}
    </Card>
  );
}
