import { useMutation, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import { zoneKeys } from "./query-keys";
import type { Table } from "@/types/tms";

const DUMMY_SLUG = "_";

interface TableResponse {
  data: Table;
}

export function useClearCall() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (tableId: string) =>
      apiFetch<TableResponse>(`/api/v1/tms/tables/${tableId}/call`, {
        method: "DELETE",
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: zoneKeys.list(DUMMY_SLUG) });
    },
  });
}
