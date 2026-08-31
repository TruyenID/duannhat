"use client";

import { cva, type VariantProps } from "class-variance-authority";
import { useTranslation } from "@/providers/app-provider";
import type { CustomerOrderStatus } from "@/services/order-service";

const badgeVariants = cva(
  "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium",
  {
    variants: {
      status: {
        pending: "bg-yellow-100 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-300",
        awaiting_confirmation: "bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300",
        confirmed: "bg-teal-100 text-teal-800 dark:bg-teal-950 dark:text-teal-300",
        open: "bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300",
        dining: "bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300",
        checkout: "bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300",
        paying: "bg-orange-100 text-orange-800 dark:bg-orange-950 dark:text-orange-300",
        closed: "bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300",
        voided: "bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300",
        // #512 — auto-expired takeaway (counter-pay deadline lapsed). Muted so it
        // reads as "no longer live", distinct from a manual red `voided`.
        expired: "bg-slate-200 text-slate-600 dark:bg-slate-800 dark:text-slate-400",
      },
    },
    defaultVariants: {
      status: "pending",
    },
  }
);

export interface OrderStatusBadgeProps extends VariantProps<typeof badgeVariants> {
  status: CustomerOrderStatus;
}

export function OrderStatusBadge({ status }: OrderStatusBadgeProps) {
  const { t } = useTranslation();
  return (
    <span data-slot="order-status-badge" className={badgeVariants({ status })}>
      {t(`shop.orders.status.${status}`)}
    </span>
  );
}
