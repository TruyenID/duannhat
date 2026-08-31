import { markPreparing } from "@/services/kds/operations";
import { useItemStatusMutation } from "./use-item-status-mutation";

export function useMarkPreparing() {
  return useItemStatusMutation(
    ({ orderId, itemId }, opts) => markPreparing(orderId, itemId, opts),
    "preparing",
  );
}
