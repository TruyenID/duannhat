import { useMutation, useQuery } from "@tanstack/react-query";
import { toast } from "sonner";
import { productionCalculatorService } from "@/services/production-calculator-service";

export function useVariantsWithRecipe(shopSlug: string) {
  return useQuery({
    queryKey: ["production-calculator", shopSlug, "variants"] as const,
    queryFn: () => productionCalculatorService.variants(shopSlug),
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
  });
}

// Calculator endpoints are POST-shaped queries (input too large for URL).
// Skip success toast — the computed result IS the visible feedback rendered
// in the calculator panel. Error toast is mandatory so a silent compute
// failure doesn't leave the user staring at a stale / empty result.
export function useCalculateProduction(shopSlug: string) {
  return useMutation({
    mutationFn: (payload: { warehouse_id: string; variant_ids: string[] }) =>
      productionCalculatorService.calculate(shopSlug, payload),
    onError: (e: Error) => toast.error(e.message || "Failed to calculate production."),
  });
}

export function useCalculateShortage(shopSlug: string) {
  return useMutation({
    mutationFn: (payload: { warehouse_id: string; variant_id: string; desired_quantity: number }) =>
      productionCalculatorService.shortage(shopSlug, payload),
    onError: (e: Error) => toast.error(e.message || "Failed to calculate shortage."),
  });
}
