import { useQuery } from "@tanstack/react-query";
import { shopService } from "@/services/shop-service";
import { shopKeys } from "./query-keys";

/** GET /api/v1/pos/shop */
export function useShop(shopSlug: string) {
  return useQuery({
    queryKey: shopKeys.detail(shopSlug),
    queryFn: () => shopService.getBySlug(shopSlug),
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000, // shop detail doesn't change often
  });
}
