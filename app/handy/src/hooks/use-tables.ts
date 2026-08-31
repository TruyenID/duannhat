import { useQuery } from '@tanstack/react-query';

import { useAppState } from '@/hooks/use-app-state';
import { useDevice } from '@/lib/device-context';
import { tableService, type TableListFilters } from '@/services/table-service';
import type { TableResource } from '@/types/pos';
import { useSocketConnected } from '@/app/_layout';

export function useTables(filters?: TableListFilters) {
  const { shopSlug } = useDevice();
  const appState = useAppState();
  const isForeground = appState === 'active';
  const wsConnected = useSocketConnected();

  return useQuery<TableResource[]>({
    queryKey: ['tables', shopSlug, 'list', filters ?? {}],
    queryFn: async () => {
      const res = await tableService.list(filters);
      return res.data;
    },
    enabled: !!shopSlug,
    // Tables thay đổi chậm — staleTime cao để giảm refetch thừa.
    // Socket invalidate cache khi order tạo/đóng → refetch triggered tự động.
    staleTime: 60_000,
    // Tắt polling khi socket connected, fallback 30s khi mất WS hoặc background.
    refetchInterval: wsConnected || !isForeground ? false : 30_000,
  });
}
