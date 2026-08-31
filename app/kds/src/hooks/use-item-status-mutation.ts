import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useRealtime } from "@/providers/use-realtime";
import { useErrorToast } from "@/lib/error-toast";
import type { ItemStatus, KdsItem, KdsOrdersQueryData } from "@/services/kds/orders";
import type { OperationOptions } from "@/services/kds/operations";
import { BUMP_MUTATION_KEY } from "./bump-mutation-key";

interface ItemMutationArgs {
  orderId: string;
  itemId: string;
}

interface MutationContext {
  prev: KdsOrdersQueryData | undefined;
}

/**
 * Apply an optimistic status change to an item, keeping the lifecycle
 * timestamps consistent with the new status.
 *
 * A revert (ready → preparing, preparing → pending) must clear the timestamps
 * that no longer apply — otherwise the optimistic snapshot keeps `ready_at`
 * populated on an item that is no longer ready. That stale `ready_at` lets the
 * item "skip the ready gate": a downstream reader (offline IDB snapshot, or any
 * consumer that treats `ready_at != null` as "is ready") would still see it as
 * ready even though the kitchen just reverted it. Clearing here mirrors what
 * the server returns on the next refetch, so the optimistic and authoritative
 * states agree.
 */
export function applyItemStatus(item: KdsItem, status: ItemStatus): KdsItem {
  const isReadyOrBeyond = status === "ready" || status === "served";
  return {
    ...item,
    status,
    // Left ready/served → drop ready_at (no longer ready).
    ready_at: isReadyOrBeyond ? item.ready_at : null,
    // Left served → drop served_at.
    served_at: status === "served" ? item.served_at : null,
    // Back to pending → drop started_preparing_at (prep never happened).
    started_preparing_at: status === "pending" ? null : item.started_preparing_at,
  };
}

/**
 * Factory for single-item KDS mutations (mark-preparing, mark-ready,
 * mark-served, revert). All four share the same optimistic-update / rollback /
 * invalidate pattern — only the service call and the target status differ.
 *
 * @param serviceFn  Function that calls the API (receives args + idempotencyKey)
 * @param nextStatus Status to write optimistically; pass null to derive from args
 *                   (used by revert where target status comes from the args)
 */
export function useItemStatusMutation<TArgs extends ItemMutationArgs>(
  serviceFn: (args: TArgs, opts: OperationOptions) => Promise<unknown>,
  nextStatus: ItemStatus | ((args: TArgs) => ItemStatus),
) {
  const qc = useQueryClient();
  const realtime = useRealtime();
  const notifyError = useErrorToast();

  return useMutation<unknown, Error, TArgs, MutationContext>({
    // Shared across all bump mutations (single-item + bump-all) so the UI can
    // gate every bump control while any bump is in flight — see item-row.tsx /
    // ticket-card.tsx `useIsMutating(BUMP_MUTATION_KEY)`. This is the
    // anti-misclick guard: a per-hook `isPending` only knows about its own
    // instance, so without a shared key a cook could tap a second item (or
    // bump-all) while the first request is still open.
    mutationKey: BUMP_MUTATION_KEY,
    mutationFn: async (args) => {
      const idempotencyKey = crypto.randomUUID();
      realtime.recordBumpKey(idempotencyKey);
      return serviceFn(args, { idempotencyKey });
    },
    onMutate: async (args) => {
      await qc.cancelQueries({ queryKey: ["kds", "orders"] });
      const prev = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);
      const status =
        typeof nextStatus === "function" ? nextStatus(args) : nextStatus;
      qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], (old) =>
        old
          ? {
              ...old,
              orders: old.orders.map((o) =>
                o.id !== args.orderId
                  ? o
                  : {
                      ...o,
                      items: o.items.map((i) =>
                        i.id === args.itemId ? applyItemStatus(i, status) : i,
                      ),
                    },
              ),
            }
          : old,
      );
      return { prev };
    },
    onError: (err, _args, ctx) => {
      if (ctx?.prev) {
        qc.setQueryData(["kds", "orders"], ctx.prev);
      }
      notifyError(err);
    },
    onSettled: () => {
      void qc.invalidateQueries({ queryKey: ["kds", "orders"] });
    },
  });
}
