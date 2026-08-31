/**
 * useShopPaymentMethods — the shop's raw `payment_methods` rows.
 *
 * Separate from `useEffectivePaymentOptions` on purpose: that one answers "what
 * may this terminal charge with", this one lists the rows
 * `order_payments.payment_method_id` points at. `on_account` lives only here —
 * it has no gateway catalog option at all — so the debt CTA has to read it.
 *
 * The `payment-methods` root is already on the never-cache money list.
 */

import { useQuery } from "@tanstack/react-query";
import { posPaymentMethodService } from "@/services/payment-method-service";
import { paymentMethodKeys } from "./query-keys";

export function useShopPaymentMethods(shopSlug: string) {
  return useQuery({
    queryKey: paymentMethodKeys.list(shopSlug),
    queryFn: () => posPaymentMethodService.list(),
    select: (r) => r.data,
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
  });
}
