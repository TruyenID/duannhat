import { cn } from "@/lib/utils";
import { useTranslation } from "@/i18n";
import type { ItemStatus } from "@/services/kds/orders";

const STYLES: Record<ItemStatus, string> = {
  pending: "bg-slate-200 text-slate-700",
  preparing: "bg-amber-200 text-amber-900",
  ready: "bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300",
  served: "bg-blue-200 text-blue-900",
  voided: "bg-red-200 text-red-900 line-through",
};

const I18N_KEYS: Record<ItemStatus, string> = {
  pending: "status.pending",
  preparing: "status.preparing",
  ready: "status.ready",
  served: "status.served",
  voided: "status.voided",
};

export function StatusBadge({
  status,
  className,
}: {
  status: ItemStatus;
  className?: string;
}) {
  const { t } = useTranslation();
  return (
    <span
      data-status={status}
      className={cn(
        "inline-flex items-center px-2 py-1 rounded text-xs font-medium",
        STYLES[status],
        className,
      )}
    >
      {t(I18N_KEYS[status])}
    </span>
  );
}
