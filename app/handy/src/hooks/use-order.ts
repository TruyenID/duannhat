import { useQuery } from '@tanstack/react-query';

import { useDevice } from '@/lib/device-context';
import { orderService } from '@/services/order-service';
import type { CustomerOrder } from '@/types/pos';

export function useOrder(orderId: string) {
  const { shopSlug } = useDevice();

  return useQuery<CustomerOrder>({
    queryKey: ['orders', shopSlug, 'detail', orderId],
    queryFn: async () => {
      const res = await orderService.get(orderId);
      return res.data;
    },
    // staleTime: 30_000 — socket patch cache trực tiếp qua setQueryData nên
    // không cần staleTime: 0. invalidateQueries không được dùng nữa để tránh
    // network request không cần thiết khi component active.
    staleTime: 30_000,
    enabled: !!shopSlug && !!orderId,
  });
}
