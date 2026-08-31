import { markServed } from "@/services/kds/operations";
import { useItemStatusMutation } from "./use-item-status-mutation";

export function useMarkServed() {
  return useItemStatusMutation(
    ({ orderId, itemId }, opts) => markServed(orderId, itemId, opts),
    "served",
  );
}
