/**
 * useEffectivePaymentOptions — resolver-backed checkout options for POS.
 */

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { effectivePaymentOptionsService } from "@/services/payment-method-service";
import { useLocale } from "@/providers/app-provider";
import { effectivePaymentOptionKeys } from "./query-keys";

interface UseEffectivePaymentOptionsOptions {
  enabled?: boolean;
}

export function useEffectivePaymentOptions(
  shopSlug: string,
  options: UseEffectivePaymentOptionsOptions = {},
) {
  const { enabled = true } = options;
  const { locale } = useLocale();

  return useQuery({
    queryKey: effectivePaymentOptionKeys.list(shopSlug, locale),
    queryFn: () => effectivePaymentOptionsService.list(shopSlug),
    enabled: enabled && !!shopSlug,
    staleTime: 5 * 60 * 1000,
    // The method buttons drive checkout — they must never blink out to empty
    // while the locale-triggered refetch runs, so keep the previous
    // language's list rendered until the new one lands.
    placeholderData: keepPreviousData,
  });
}

/** @deprecated Use useEffectivePaymentOptions */
export function usePaymentMethods(
  shopSlug: string,
  options: UseEffectivePaymentOptionsOptions = {},
) {
  return useEffectivePaymentOptions(shopSlug, options);
}
