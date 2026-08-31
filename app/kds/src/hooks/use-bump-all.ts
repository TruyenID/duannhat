import { useMutation, useQueryClient } from "@tanstack/react-query";
import { bumpAll } from "@/services/kds/operations";
import { useRealtime } from "@/providers/use-realtime";
import { useErrorToast } from "@/lib/error-toast";
import type {
  KdsItem,
  KdsOrdersQueryData,
  ItemStatus,
  ItemTransition,
} from "@/services/kds/orders";
import { applyItemStatus } from "./use-item-status-mutation";
import { BUMP_MUTATION_KEY } from "./bump-mutation-key";

/**
 * The single-item forward transition that must be allowed for an item in a
 * given scope to be advanced. Bump-all is a batch of these per-item forwards,
 * so it MUST honour the same `allowed_transitions` gate that ItemRow honours
 * — most importantly the toppings-parent-ready guard, which withholds
 * `mark-ready` from an item whose toppings aren't ready yet. Advancing such an
 * item in bulk would bypass that guard.
 */
const REQUIRED_TRANSITION: Record<"pending" | "preparing", ItemTransition> = {
  pending: "mark-preparing",
  preparing: "mark-ready",
};

function isEligible(item: KdsItem, scope: "pending" | "preparing"): boolean {
  return (
    item.status === scope &&
    item.allowed_transitions.includes(REQUIRED_TRANSITION[scope])
  );
}

export interface BumpAllArgs {
  orderId: string;
  scope: "pending" | "preparing";
  /**
   * Items the caller expects cloud to bump (typically `order.items` filtered
   * by `scope`). Used to pre-record N self-echo dedup keys — cloud derives
   * `idempotency_key = ${batchKey}:${itemId}` per OrderItemStatusChanged event.
   */
  targetItems: KdsItem[];
}

interface BumpAllArgsWithBatchKey extends BumpAllArgs {
  batchKey: string;
}

interface MutationContext {
  prev: KdsOrdersQueryData | undefined;
}

/**
 * Bulk-advance all items in scope. Caller pre-filters items (avoids cache
 * re-reads inside the hook) and the hook injects a freshly generated batchKey
 * before invoking the underlying mutation, so onMutate (which records dedup
 * keys) and mutationFn (which sends Idempotency-Key) share it deterministically
 * via mutation variables — no useRef, no concurrency race.
 *
 * FE↔BE CONTRACT: cloud derives per-item idempotency_key as
 * `${batchKey}:${itemId}` (backend/app/Http/Controllers/Api/V1/Kds/KdsController.php
 * around `bumpAll`, and the OrderItemStatusChanged event payload). If that
 * format changes server-side, FE silently misses self-echo dedup. Backend
 * Pest test `BumpAllTest::it broadcasts ... idempotency_key ...` locks the
 * format. Touch BOTH sides if you change it.
 */
export function useBumpAll() {
  const qc = useQueryClient();
  const realtime = useRealtime();
  const notifyError = useErrorToast();

  const mutation = useMutation<unknown, Error, BumpAllArgsWithBatchKey, MutationContext>({
    mutationKey: BUMP_MUTATION_KEY,
    onMutate: async ({ orderId, scope, batchKey, targetItems }) => {
      await qc.cancelQueries({ queryKey: ["kds", "orders"] });
      const prev = qc.getQueryData<KdsOrdersQueryData>(["kds", "orders"]);

      // Only pre-record dedup keys for items the server will actually bump —
      // i.e. those whose `allowed_transitions` permit the forward move. The
      // backend bump-all honours the same guard, so recording a key for a
      // guard-blocked item would leave an orphan key; skipping it keeps FE and
      // BE dedup sets aligned.
      for (const item of targetItems) {
        if (isEligible(item, scope)) {
          realtime.recordBumpKey(`${batchKey}:${item.id}`);
        }
      }

      const nextStatus: ItemStatus = scope === "pending" ? "preparing" : "ready";
      qc.setQueryData<KdsOrdersQueryData>(["kds", "orders"], (old) =>
        old
          ? {
              ...old,
              orders: old.orders.map((o) =>
                o.id !== orderId
                  ? o
                  : {
                      ...o,
                      items: o.items.map((i) =>
                        isEligible(i, scope) ? applyItemStatus(i, nextStatus) : i,
                      ),
                    },
              ),
            }
          : old,
      );

      return { prev };
    },
    mutationFn: async ({ orderId, scope, batchKey }) => {
      return bumpAll(orderId, scope, { idempotencyKey: batchKey });
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

  return {
    ...mutation,
    /**
     * Returns `void` (or rejects, for `mutateAsync`) — matches TanStack v5
     * `useMutation` semantics. Re-entrant calls while a previous bump-all is
     * still in-flight are dropped (no-op) to defend against double-tap on
     * tablets where the React render that flips `isPending` hasn't flushed
     * between two synthetic click events.
     *
     * UX contract: callers MUST visually disable the trigger while
     * `isPending` is true (ticket-card.tsx already does `disabled={bumpAll.isPending}`).
     * The guard here is a belt-and-suspenders for the sub-frame race, not the
     * primary defense — without the button-disable, a user could tap the
     * second time before the first call resolves and would get no feedback.
     */
    mutate: (args: BumpAllArgs) => {
      if (mutation.isPending) return;
      mutation.mutate({ ...args, batchKey: crypto.randomUUID() });
    },
    mutateAsync: (args: BumpAllArgs) => {
      if (mutation.isPending) return Promise.resolve(undefined as unknown);
      return mutation.mutateAsync({ ...args, batchKey: crypto.randomUUID() });
    },
  };
}
