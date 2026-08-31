"use client";

import { cva, type VariantProps } from "class-variance-authority";
import { useTranslation } from "@/providers/app-provider";
import type { CustomerOrderItemStatus } from "@/services/order-service";

const badgeVariants = cva(
  "inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium",
  {
    variants: {
      status: {
        pending: "bg-yellow-100 text-yellow-800",
        preparing: "bg-blue-100 text-blue-800",
        ready: "bg-indigo-100 text-indigo-800",
        served: "bg-green-100 text-green-800",
        voided: "bg-red-100 text-red-800",
      },
    },
    defaultVariants: {
      status: "pending",
    },
  }
);

export interface OrderItemStatusBadgeProps extends VariantProps<typeof badgeVariants> {
  status: CustomerOrderItemStatus;
}

export function OrderItemStatusBadge({ status }: OrderItemStatusBadgeProps) {
  const { t } = useTranslation();
  return (
    <span data-slot="order-item-status-badge" className={badgeVariants({ status })}>
      {t(`shop.orders.item_status.${status}`)}
    </span>
  );
}
