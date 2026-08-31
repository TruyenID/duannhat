import { useQuery } from '@tanstack/react-query';

import { shopOrderSettingsService } from '@/services/shop-order-settings-service';
import type { ShopOrderSettings } from '@/types/pos';

export function useShopOrderSettings(shopSlug: string) {
  return useQuery<ShopOrderSettings>({
    queryKey: ['shop', shopSlug, 'settings', 'order'],
    queryFn: async () => {
      const res = await shopOrderSettingsService.get(shopSlug);
      return res.data;
    },
    staleTime: 300_000,
    enabled: !!shopSlug,
  });
}
