import { useQuery } from '@tanstack/react-query';

import { useAppState } from '@/hooks/use-app-state';
import { useDevice } from '@/lib/device-context';
import { orderService } from '@/services/order-service';
import type { CustomerOrder } from '@/types/pos';
import { useSocketConnected } from '@/app/_layout';

export function useOpenOrders() {
  const { shopSlug } = useDevice();
  const appState = useAppState();
  const isForeground = appState === 'active';
  const wsConnected = useSocketConnected();

  return useQuery<CustomerOrder[]>({
    queryKey: ['orders', shopSlug, 'list', { status: 'open,dining' }],
    queryFn: async () => {
      const res = await orderService.list({ status: 'open,dining', per_page: 100 });
      return res.data;
    },
    enabled: !!shopSlug,
    staleTime: 30_000,
    // Socket patch cache trực tiếp khi WS connected → polling thừa.
    // Fallback về 30s poll khi WS mất kết nối hoặc app ở background.
    refetchInterval: wsConnected || !isForeground ? false : 30_000,
  });
}
