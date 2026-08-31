import { useMutation, useQueryClient } from '@tanstack/react-query';

import { useDevice } from '@/lib/device-context';
import { orderService } from '@/services/order-service';
import { useCartStore } from '@/store/cart-store';
import type { CustomerOrder, OrderItemInput } from '@/types/pos';

interface AddItemsVars {
  orderId: string;
  items: OrderItemInput[];
}

export function useAddItems() {
  const { shopSlug } = useDevice();
  const queryClient = useQueryClient();
  const clear = useCartStore((s) => s.clear);

  return useMutation<{ data: CustomerOrder }, Error, AddItemsVars>({
    mutationFn: ({ orderId, items }) =>
      orderService.addItems(orderId, items),

    onSuccess: (response, { orderId }) => {
      queryClient.setQueryData<CustomerOrder>(['orders', shopSlug, 'detail', orderId], response.data);
      queryClient.invalidateQueries({ queryKey: ['orders', shopSlug, 'list'] });
      clear();
    },
  });
}
