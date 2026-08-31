/**
 * #1501 — chạy hàng đợi hành động nhẹ khi kết nối trở lại.
 *
 * Không render gì. Chạy một lần lúc mount (dọn hàng đợi còn sót từ phiên
 * trước) và mỗi lần trạng thái mạng chuyển từ MẤT sang CÓ.
 *
 * Chỉ có hành động KHÔNG dính tiền đi qua đây — union kiểu nằm ở
 * `lib/offline-action-queue.ts` và có test ghim. Thanh toán / mở ca / đóng ca
 * KHÔNG xếp hàng: chúng bị khoá hẳn khi offline (`hooks/use-network-required`).
 */
import { useEffect, useRef } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { isNetworkError } from "@/lib/api";
import type { LightAction } from "@/lib/idb";
import { isOffline, useNetworkStatus } from "@/lib/network-status";
import { replayLightActions } from "@/lib/offline-action-queue";
import { tableService } from "@/services/table-service";
import type { TableStatusValue } from "@/app/pos/types";
import { useTranslation } from "@/providers/app-provider";

async function runLightAction(action: LightAction): Promise<void> {
  switch (action.type) {
    case "table.status": {
      const { shopSlug, tableId, status } = action.payload as {
        shopSlug: string;
        tableId: string;
        status: TableStatusValue;
      };
      await tableService.changeStatus(shopSlug, tableId, status);
      return;
    }
    default:
      // Bản ghi của một phiên bản khác. Ném lỗi KHÔNG-phải-mạng để nó bị coi
      // là bị từ chối và rời hàng đợi, thay vì kẹt lại mãi mãi.
      throw new Error(`unknown light action type: ${action.type}`);
  }
}

export function LightActionReplayer() {
  const queryClient = useQueryClient();
  const { t } = useTranslation();
  const offline = isOffline(useNetworkStatus());
  // Một lượt chạy đang dở không được chạy chồng: hai lượt song song sẽ gửi
  // cùng một hành động hai lần trước khi lượt đầu kịp xoá nó.
  const draining = useRef(false);

  useEffect(() => {
    if (offline || draining.current) return;
    draining.current = true;

    void replayLightActions(runLightAction, isNetworkError)
      .then((outcome) => {
        if (outcome.replayed > 0) {
          toast.success(
            t("offline.queue.replayed", { n: String(outcome.replayed) }),
          );
          void queryClient.invalidateQueries({ queryKey: ["tables"] });
        }
        if (outcome.expired > 0) {
          // Im lặng bỏ đi cũng tệ ngang im lặng ghi đè.
          toast.warning(
            t("offline.queue.expired", { n: String(outcome.expired) }),
          );
        }
      })
      .finally(() => {
        draining.current = false;
      });
  }, [offline, queryClient, t]);

  return null;
}
