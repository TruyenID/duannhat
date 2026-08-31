/** Hai nguồn nợ của chi nhánh, tách bạch theo luật #1990 (#1998). */

import { useQuery } from "@tanstack/react-query";
import { shopDebtService } from "@/services/shop-debt-service";

export const shopDebtKeys = {
  openAccount: (shopSlug: string) => ["shop", shopSlug, "debts", "on-account"] as const,
  partPaid: (shopSlug: string) => ["shop", shopSlug, "debts", "part-paid"] as const,
};

export function useShopOpenAccountDebts(shopSlug: string) {
  return useQuery({
    queryKey: shopDebtKeys.openAccount(shopSlug),
    queryFn: () => shopDebtService.openAccount(shopSlug),
    enabled: !!shopSlug,
    staleTime: 60 * 1000,
  });
}

export function useShopPartPaidDebts(shopSlug: string) {
  return useQuery({
    queryKey: shopDebtKeys.partPaid(shopSlug),
    queryFn: () => shopDebtService.partPaid(shopSlug),
    enabled: !!shopSlug,
    staleTime: 60 * 1000,
  });
}
