import { useMutation, useQueryClient } from '@tanstack/react-query';

import { useDevice } from '@/lib/device-context';
import { orderService } from '@/services/order-service';
import type { CustomerOrder, OrderInitInput } from '@/types/pos';

interface InitOrderVars {
  orderId: string;
  body: OrderInitInput;
}

export function useInitOrder() {
  const { shopSlug } = useDevice();
  const queryClient = useQueryClient();

  return useMutation<{ data: CustomerOrder }, Error, InitOrderVars>({
    mutationFn: ({ orderId, body }) =>
      orderService.init(orderId, body),

    onSuccess: (res, { orderId }) => {
      queryClient.setQueryData<CustomerOrder>(['orders', shopSlug, 'detail', orderId], res.data);
      queryClient.invalidateQueries({ queryKey: ['orders', shopSlug, 'list'] });
    },
  });
}
