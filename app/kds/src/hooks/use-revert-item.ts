import { revertItem } from "@/services/kds/operations";
import { useItemStatusMutation } from "./use-item-status-mutation";

interface RevertItemArgs {
  orderId: string;
  itemId: string;
  to: "pending" | "preparing";
}

export function useRevertItem() {
  return useItemStatusMutation<RevertItemArgs>(
    ({ orderId, itemId, to }, opts) => revertItem(orderId, itemId, to, opts),
    ({ to }) => to,
  );
}
