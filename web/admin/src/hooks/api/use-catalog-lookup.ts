/**
 * Catalog Lookup Hooks — long-cached shop-scoped SKU + material lists
 * used by every create form in the inventory / production flows.
 */

import { useQuery } from "@tanstack/react-query";
import { catalogLookupService } from "@/services/catalog-lookup-service";
import { useTranslation } from "@/providers/app-provider";

export function useProductSkuLookup(shopSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: ["catalog", shopSlug, "product-skus", locale] as const,
    queryFn: () => catalogLookupService.productSkus(shopSlug),
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
  });
}

export function useMaterialLookup(shopSlug: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: ["catalog", shopSlug, "materials", locale] as const,
    queryFn: () => catalogLookupService.materials(shopSlug),
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
  });
}

export function useRecipeLookup(shopSlug: string, materialId?: string) {
  const { locale } = useTranslation();
  return useQuery({
    queryKey: ["catalog", shopSlug, "recipes", materialId ?? null, locale] as const,
    queryFn: () => catalogLookupService.recipes(shopSlug, materialId),
    enabled: !!shopSlug && !!materialId,
    staleTime: 2 * 60 * 1000,
  });
}
