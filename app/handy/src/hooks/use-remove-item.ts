import { useMutation, useQueryClient } from '@tanstack/react-query';

import { useDevice } from '@/lib/device-context';
import { orderService } from '@/services/order-service';
import type { CustomerOrder } from '@/types/pos';

interface RemoveItemVars {
  orderId: string;
  itemId: string;
}

export function useRemoveItem() {
  const { shopSlug } = useDevice();
  const queryClient = useQueryClient();

  return useMutation<{ data: CustomerOrder }, Error, RemoveItemVars>({
    mutationFn: ({ orderId, itemId }) =>
      orderService.removeItem(orderId, itemId),

    onSuccess: (res, { orderId }) => {
      queryClient.setQueryData<CustomerOrder>(['orders', shopSlug, 'detail', orderId], res.data);
      queryClient.invalidateQueries({ queryKey: ['orders', shopSlug, 'list'] });
    },
  });
}
