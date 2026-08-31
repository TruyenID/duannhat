"use client";

import type { LucideIcon } from "lucide-react";
import { BadgeCheck, CheckCircle2, Clock, FileEdit, PauseCircle, XCircle } from "lucide-react";

import { Badge } from "@godxjp/ui";
import type { ProductStatus } from "@/services/product-service";
import { useTranslation } from "@/providers/app-provider";

export interface ProductStatusBadgeProps {
  status: ProductStatus;
  /**
   * "badge" (default) — small inline pill for lists / headers.
   * "panel"           — full-width tinted block with icon; used on the
   *                      product detail sidebar so the state reads at a
   *                      glance without crowding the card.
   */
  variant?: "badge" | "panel";
  className?: string;
}

const STATUS_LABEL_KEY: Record<ProductStatus, string> = {
  draft: "status.draft",
  pending: "status.pending",
  approved: "status.approved",
  active: "product.status_label.active",
  inactive: "product.status_label.inactive",
  rejected: "status.rejected",
};

const STATUS_HINT_KEY: Record<ProductStatus, string> = {
  draft: "product.status_hint.draft",
  pending: "product.status_hint.pending",
  approved: "product.status_hint.approved",
  active: "product.status_hint.active",
  inactive: "product.status_hint.inactive",
  rejected: "product.status_hint.rejected",
};

const STATUS_ICON: Record<ProductStatus, LucideIcon> = {
  draft: FileEdit,
  pending: Clock,
  approved: BadgeCheck,
  active: CheckCircle2,
  inactive: PauseCircle,
  rejected: XCircle,
};

// Badge (outline pill) classes — used by lists, table cells, header chips.
const BADGE_CLASS: Record<ProductStatus, string> = {
  draft: "border-amber-300 bg-amber-50 text-amber-700",
  pending: "border-blue-300 bg-blue-50 text-blue-700",
  approved: "border-emerald-300 bg-emerald-50 text-emerald-700",
  active: "border-green-400 bg-green-50 text-green-700",
  inactive: "border-gray-300 bg-gray-100 text-gray-600",
  rejected: "border-red-300 bg-red-50 text-red-700",
};

// Panel (tinted block) classes — split so the icon halo, border, and text
// sit on coordinated swatches per state.
const PANEL_CLASS: Record<
  ProductStatus,
  { wrap: string; icon: string; title: string; hint: string }
> = {
  draft: {
    wrap: "border-amber-200 bg-amber-50/60",
    icon: "bg-amber-100 text-amber-700",
    title: "text-amber-800",
    hint: "text-amber-700/80",
  },
  pending: {
    wrap: "border-blue-200 bg-blue-50/60",
    icon: "bg-blue-100 text-blue-700",
    title: "text-blue-800",
    hint: "text-blue-700/80",
  },
  approved: {
    wrap: "border-emerald-200 bg-emerald-50/60",
    icon: "bg-emerald-100 text-emerald-700",
    title: "text-emerald-800",
    hint: "text-emerald-700/80",
  },
  active: {
    wrap: "border-green-300 bg-green-50",
    icon: "bg-green-100 text-green-700",
    title: "text-green-800",
    hint: "text-green-700/80",
  },
  inactive: {
    wrap: "border-gray-200 bg-gray-50",
    icon: "bg-gray-200 text-gray-700",
    title: "text-gray-800",
    hint: "text-gray-600",
  },
  rejected: {
    wrap: "border-red-200 bg-red-50/60",
    icon: "bg-red-100 text-red-700",
    title: "text-red-800",
    hint: "text-red-700/80",
  },
};

export function ProductStatusBadge({
  status,
  variant = "badge",
  className,
}: ProductStatusBadgeProps) {
  const Icon = STATUS_ICON[status];
  const { t } = useTranslation();
  const label = t(STATUS_LABEL_KEY[status]);
  const hint = t(STATUS_HINT_KEY[status]);

  if (variant === "panel") {
    const c = PANEL_CLASS[status];
    return (
      <div
        data-slot="product-status-panel"
        className={`flex items-center gap-3 rounded-md border px-3 py-2.5 ${c.wrap} ${className ?? ""}`}
      >
        <div className={`flex size-8 shrink-0 items-center justify-center rounded-full ${c.icon}`}>
          <Icon className="size-4" />
        </div>
        <div className="flex min-w-0 flex-col">
          <span className={`text-sm leading-tight font-semibold ${c.title}`}>{label}</span>
          <span className={`text-[11px] leading-tight ${c.hint}`}>{hint}</span>
        </div>
      </div>
    );
  }

  return (
    <Badge
      variant="outline"
      data-slot="product-status-badge"
      className={`gap-1 ${BADGE_CLASS[status]} ${className ?? ""}`}
    >
      <Icon className="size-3" />
      {label}
    </Badge>
  );
}
