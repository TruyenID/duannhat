import { useMutation, useQueryClient } from '@tanstack/react-query';

import { useDevice } from '@/lib/device-context';
import { orderService } from '@/services/order-service';
import type { CustomerOrder } from '@/types/pos';

interface VoidOrderVars {
  orderId: string;
  voidReason: string;
}

export function useVoidOrder() {
  const { shopSlug } = useDevice();
  const queryClient = useQueryClient();

  return useMutation<{ data: CustomerOrder }, Error, VoidOrderVars>({
    mutationFn: ({ orderId, voidReason }) =>
      orderService.voidOrder(orderId, voidReason),

    onSuccess: (_, { orderId }) => {
      queryClient.removeQueries({ queryKey: ['orders', shopSlug, 'detail', orderId] });
      queryClient.invalidateQueries({ queryKey: ['orders', shopSlug, 'list'] });
      queryClient.invalidateQueries({ queryKey: ['tables', shopSlug] });
    },
  });
}
