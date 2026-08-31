/**
 * Order payment hooks — list + create.
 *
 * Cash happy path: POST /payments with amount + tendered_amount → backend
 * auto-confirms (is_auto_confirm) and auto-closes the order if paid_amount
 * covers total_amount. POS then invalidates the order detail + tables to
 * reflect the closed state.
 */

import {
  useMutation,
  useQuery,
  useQueryClient,
  type UseMutationOptions,
} from "@tanstack/react-query";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { getT } from "@/providers/app-provider";
import {
  orderPaymentService,
  type OrderPaymentCreateInput,
} from "@/services/order-payment-service";
import type { OrderPayment } from "@/app/pos/types";
import { orderKeys, orderPaymentKeys, tableKeys } from "./query-keys";

type MutationOpts<TData, TVariables> = Omit<
  UseMutationOptions<TData, unknown, TVariables>,
  "mutationFn"
>;

export function useOrderPayments(shopSlug: string, orderId: string | null) {
  return useQuery({
    queryKey: orderPaymentKeys.list(shopSlug, orderId ?? ""),
    queryFn: () => orderPaymentService.list(shopSlug, orderId as string),
    enabled: !!shopSlug && !!orderId,
  });
}

export function useCreatePayment(
  shopSlug: string,
  orderId: string,
  opts: MutationOpts<{ data: OrderPayment }, OrderPaymentCreateInput> = {},
) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (body: OrderPaymentCreateInput) =>
      orderPaymentService.create(shopSlug, orderId, body),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: orderKeys.detail(shopSlug, orderId) });
      qc.invalidateQueries({ queryKey: orderKeys.all(shopSlug) });
      qc.invalidateQueries({ queryKey: tableKeys.all(shopSlug) });
      qc.invalidateQueries({
        queryKey: orderPaymentKeys.list(shopSlug, orderId),
      });
      toast.success(getT()("pos.toast.payment_success"));
    },
    onError: (error) => {
      if (error instanceof ApiError) {
        toast.error(
          (error.body?.message as string | undefined) ?? error.message,
        );
      } else if (error instanceof Error) {
        toast.error(error.message);
      } else {
        toast.error("Unknown error");
      }
    },
    ...opts,
  });
}
