import { useIsMutating } from "@tanstack/react-query";
import { useMarkPreparing } from "@/hooks/use-mark-preparing";
import { useMarkReady } from "@/hooks/use-mark-ready";
import { useMarkServed } from "@/hooks/use-mark-served";
import { useRevertItem } from "@/hooks/use-revert-item";
import { BUMP_MUTATION_KEY } from "@/hooks/bump-mutation-key";
import { StatusBadge } from "./status-badge";
import { Undo2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { useTranslation } from "@/i18n";
import type { ItemStatus, ItemTransition, KdsItem } from "@/services/kds/orders";

// Kitchen only bumps pending→preparing and preparing→ready.
// mark-served is waiter's action — not shown on KDS.
const KITCHEN_FORWARD_TRANSITIONS = ["mark-preparing", "mark-ready"] as const;

type KitchenForwardTransition = (typeof KITCHEN_FORWARD_TRANSITIONS)[number];

function isKitchenForwardTransition(t: ItemTransition): t is KitchenForwardTransition {
  return (KITCHEN_FORWARD_TRANSITIONS as readonly string[]).includes(t);
}

const REVERT_TARGET: Record<ItemStatus, "pending" | "preparing"> = {
  pending: "pending",
  preparing: "pending",
  ready: "preparing",
  served: "preparing",
  voided: "pending",
};

export function ItemRow({ orderId, item }: { orderId: string; item: KdsItem }) {
  const { t } = useTranslation();
  const markPreparing = useMarkPreparing();
  const markReady = useMarkReady();
  const markServed = useMarkServed();
  const revertItem = useRevertItem();
  // Disable every action on this row while ANY bump (this item, another item,
  // or a bump-all on any ticket) is in flight — shared anti-misclick guard.
  // A per-hook `isPending` only sees this row's own mutations, which lets a
  // cook double-tap across rows or race a bump-all. `useIsMutating` reads the
  // shared BUMP_MUTATION_KEY so all bump controls gate together. Must be called
  // before the early returns to satisfy rules-of-hooks.
  const anyPending = useIsMutating({ mutationKey: BUMP_MUTATION_KEY }) > 0;

  // Served items are waiter's domain — hide from kitchen entirely.
  if (item.status === "served") return null;

  if (item.status === "voided") {
    return (
      <div
        data-testid={`item-row-${item.id}`}
        data-status={item.status}
        className="flex items-center gap-2 py-2 border-b last:border-b-0 opacity-50"
      >
        <div className="flex-1 p-2 flex items-center justify-between gap-2">
          <span className="font-medium line-through">
            ×{item.quantity} {item.menu_item_name}
          </span>
          <StatusBadge status={item.status} />
        </div>
      </div>
    );
  }

  const forward = item.allowed_transitions.find(isKitchenForwardTransition);
  const canRevert = item.allowed_transitions.includes("revert");

  function handleForward() {
    if (forward === "mark-preparing") markPreparing.mutate({ orderId, itemId: item.id });
    else if (forward === "mark-ready") markReady.mutate({ orderId, itemId: item.id });
    else if (forward === "mark-served") markServed.mutate({ orderId, itemId: item.id });
  }

  function handleRevert() {
    revertItem.mutate({
      orderId,
      itemId: item.id,
      to: REVERT_TARGET[item.status],
    });
  }

  return (
    <div
      data-testid={`item-row-${item.id}`}
      data-status={item.status}
      className="flex items-stretch gap-2 py-2 border-b last:border-b-0"
    >
      <button
        type="button"
        data-testid={`item-${item.id}-forward`}
        onClick={handleForward}
        disabled={!forward || anyPending}
        className={cn(
          "flex-1 text-left p-2 rounded min-h-11 transition-colors",
          forward && !anyPending && "hover:bg-muted active:bg-muted/80",
          (!forward || anyPending) && "opacity-60 cursor-not-allowed",
        )}
      >
        <div className="flex items-center justify-between gap-2">
          <span className="font-medium">
            ×{item.quantity} {item.menu_item_name}
          </span>
          <div className="flex items-center gap-1.5">
            {item.print_status === "failed" && (
              <span
                className="rounded bg-red-600 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white"
                aria-label="máy in lỗi"
              >
                Máy in lỗi
              </span>
            )}
            <StatusBadge status={item.status} />
          </div>
        </div>
        {item.note && (
          <div className="text-xs text-muted-foreground mt-1">{item.note}</div>
        )}
        {item.toppings.length > 0 && (
          <ul className="mt-1.5 space-y-0.5">
            {item.toppings.map((topping, idx) => (
              <li key={`${topping.name ?? ""}:${idx}`} className="text-xs text-muted-foreground">
                + {topping.quantity > 1 ? `×${topping.quantity} ` : ""}{topping.name ?? t("item.topping_unnamed")}
              </li>
            ))}
          </ul>
        )}
      </button>

      {canRevert && (
        <button
          type="button"
          data-testid={`item-${item.id}-revert`}
          onClick={handleRevert}
          disabled={anyPending}
          aria-label={t("dashboard.revert")}
          title={t("dashboard.revert")}
          className={cn(
            "shrink-0 min-h-11 min-w-11 px-3 rounded border",
            "border-amber-400 text-amber-700 dark:text-amber-300 dark:border-amber-600",
            !anyPending && "hover:bg-amber-50 dark:hover:bg-amber-950 active:bg-amber-100",
            anyPending && "opacity-60 cursor-not-allowed",
          )}
        >
          <Undo2 className="size-5" />
        </button>
      )}
    </div>
  );
}
