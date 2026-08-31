import { useMutation } from '@tanstack/react-query';

import { orderService } from '@/services/order-service';

export function useFireOrder() {
  return useMutation({
    mutationFn: (orderId: string) => orderService.fireOrder(orderId),
  });
}
