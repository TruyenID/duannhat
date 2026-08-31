import { useMutation, useQueryClient } from '@tanstack/react-query';

import { useDevice } from '@/lib/device-context';
import { orderService } from '@/services/order-service';
import type { CustomerOrder, OrderItemUpdateInput } from '@/types/pos';

interface UpdateItemVars {
  orderId: string;
  itemId: string;
  body: OrderItemUpdateInput;
}

export function useUpdateItem() {
  const { shopSlug } = useDevice();
  const queryClient = useQueryClient();

  return useMutation<{ data: CustomerOrder }, Error, UpdateItemVars>({
    mutationFn: ({ orderId, itemId, body }) =>
      orderService.updateItem(orderId, itemId, body),

    onSuccess: (res, { orderId }) => {
      queryClient.setQueryData<CustomerOrder>(['orders', shopSlug, 'detail', orderId], res.data);
      queryClient.invalidateQueries({ queryKey: ['orders', shopSlug, 'list'] });
    },
  });
}
