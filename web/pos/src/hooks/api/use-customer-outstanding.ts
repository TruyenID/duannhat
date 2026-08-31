/**
 * useCustomerOutstanding — list a customer's unpaid orders (status=paying,
 * paid < total) in the current shop. PaymentDialog consumes this so staff
 * can settle old debt as part of the current payment flow.
 */

import { useQuery } from "@tanstack/react-query";
import { customerService } from "@/services/customer-service";
import { customerKeys } from "./query-keys";

export function useCustomerOutstanding(
  shopSlug: string,
  customerId: string | null | undefined,
) {
  return useQuery({
    queryKey: customerKeys.outstanding(shopSlug, customerId ?? ""),
    queryFn: () => customerService.getOutstanding(shopSlug, customerId as string),
    enabled: !!shopSlug && !!customerId,
    staleTime: 30 * 1000,
  });
}
