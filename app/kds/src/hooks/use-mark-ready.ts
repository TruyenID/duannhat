import { markReady } from "@/services/kds/operations";
import { useItemStatusMutation } from "./use-item-status-mutation";

export function useMarkReady() {
  return useItemStatusMutation(
    ({ orderId, itemId }, opts) => markReady(orderId, itemId, opts),
    "ready",
  );
}
