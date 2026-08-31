/**
 * useTables — list tables for the active shop (used by TablePicker,
 * BindTableDialog, ChangeTableDialog).
 *
 * 1-minute staleTime so the table grid stays responsive when table status
 * changes elsewhere (order create/close, manual status toggle).
 *
 * `refetchInterval` lets the caller opt into active polling. When the
 * workstation is unreachable the realtime WS channel is off, so a voided
 * order's table release (done server-side immediately) has no push channel
 * to reach *other* devices — they'd otherwise stay "occupied" until the next
 * incidental refetch. Polling on cloud-fallback bounds that staleness (#541).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { ApiError, isNetworkError, type PaginatedResponse } from "@/lib/api";
import { enqueueLightAction } from "@/lib/offline-action-queue";
import { getT } from "@/providers/app-provider";
import { tableService, type TableFilters } from "@/services/table-service";
import type { TableResource, TableStatusValue } from "@/app/pos/types";
import { tableKeys } from "./query-keys";

export interface UseTablesOptions {
  /** Poll interval in ms; omit/undefined to disable active polling. */
  refetchInterval?: number;
}

export function useTables(
  shopSlug: string,
  filters: TableFilters = {},
  { refetchInterval }: UseTablesOptions = {},
) {
  return useQuery({
    queryKey: tableKeys.list(shopSlug, filters),
    queryFn: () => tableService.list(shopSlug, filters),
    enabled: !!shopSlug,
    staleTime: 60 * 1000,
    refetchInterval,
  });
}

export interface UpdateTableStatusVars {
  tableId: string;
  status: TableStatusValue;
}

/**
 * Change a table's status from the overview. Optimistically patches every
 * cached table list (so the grid reflects the new status instantly), rolls back
 * on error, and refetches on settle to adopt the server's authoritative row.
 * apiFetch handles LAN (workstation → immediate sync UP) vs cloud routing.
 */
export function useUpdateTableStatus(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ tableId, status }: UpdateTableStatusVars) =>
      tableService.changeStatus(shopSlug, tableId, status),
    onMutate: async ({ tableId, status }) => {
      await qc.cancelQueries({ queryKey: tableKeys.all(shopSlug) });
      const previous = qc.getQueriesData<PaginatedResponse<TableResource>>({
        queryKey: tableKeys.all(shopSlug),
      });
      qc.setQueriesData<PaginatedResponse<TableResource>>(
        { queryKey: tableKeys.all(shopSlug) },
        (old) =>
          old
            ? {
                ...old,
                data: old.data.map((t) =>
                  t.id === tableId ? { ...t, status } : t,
                ),
              }
            : old,
      );
      return { previous };
    },
    onError: (error, vars, ctx) => {
      // #1501 — CHƯA GỬI ĐƯỢC khác với BỊ TỪ CHỐI.
      //
      // Đổi trạng thái bàn là hành động nhẹ, không dính tiền, và ghi sau đè
      // ghi trước nên phát lại là idempotent tự nhiên. Mất mạng thì GIỮ
      // nguyên trạng thái lạc quan và xếp hàng: bắt nhân viên bấm lại "đã
      // dọn xong" sau mỗi lần Wi-Fi chớp là cách nhanh nhất để sơ đồ bàn trở
      // thành thứ không ai tin.
      //
      // Chỉ áp dụng cho lỗi MẠNG. Một câu trả lời 4xx/5xx là dứt khoát —
      // hoàn nguyên và báo lỗi như cũ.
      if (isNetworkError(error)) {
        void enqueueLightAction({
          type: "table.status",
          payload: { shopSlug, tableId: vars.tableId, status: vars.status },
        });
        toast.info(getT()("offline.queue.enqueued"));
        return;
      }

      // Roll every patched query back to its pre-mutation snapshot.
      ctx?.previous?.forEach(([key, data]) => qc.setQueryData(key, data));
      if (error instanceof ApiError) {
        toast.error(
          (error.body?.message as string | undefined) ?? error.message,
        );
      } else if (error instanceof Error) {
        toast.error(error.message);
      } else {
        toast.error("Unknown error");
      }
    },
    onSettled: () => {
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
    },
  });
}
