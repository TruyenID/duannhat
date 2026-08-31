/**
 * Debt hooks — "Tra cứu nợ".
 *
 * `staleTime: 0` on both: a debt list is money owed, and the cashier is looking
 * at it precisely because they are about to collect. A cached figure that is
 * even a minute old can be one another terminal already settled.
 */

import { useQuery } from "@tanstack/react-query";
import { debtService } from "@/services/debt-service";
import { debtKeys } from "./query-keys";

export function useDebtCustomers(shopSlug: string, enabled = true) {
  return useQuery({
    queryKey: debtKeys.list(shopSlug),
    queryFn: () => debtService.list(),
    select: (r) => r.data,
    enabled: enabled && !!shopSlug,
    staleTime: 0,
  });
}

/**
 * Orders left part-paid. Same `staleTime: 0` and same never-cache root as the
 * on-account list — it is the same question ("what are we owed") asked of a
 * different table, and just as wrong to answer from a stale copy.
 */
export function usePartPaidOrders(shopSlug: string, enabled = true) {
  return useQuery({
    queryKey: debtKeys.partPaid(shopSlug),
    queryFn: () => debtService.listPartPaid(),
    select: (r) => r.data,
    enabled: enabled && !!shopSlug,
    staleTime: 0,
  });
}

export function useCustomerDebts(
  shopSlug: string,
  customerId: string | null,
  enabled = true,
) {
  return useQuery({
    queryKey: debtKeys.forCustomer(shopSlug, customerId ?? ""),
    queryFn: () => debtService.listForCustomer(customerId as string),
    select: (r) => r.data,
    enabled: enabled && !!shopSlug && !!customerId,
    staleTime: 0,
  });
}
