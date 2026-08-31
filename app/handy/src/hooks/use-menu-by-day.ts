import { useQuery } from '@tanstack/react-query';

import { normalizeMenuByDay } from '@/lib/normalize-menu';
import { shopMenuService } from '@/services/shop-menu-service';
import type { ShopMenuByDayResource } from '@/types/pos';

export function useMenuByDay(shopSlug: string) {
  const dayOfWeek = new Date().getDay();
  return useQuery<ShopMenuByDayResource[]>({
    queryKey: ['shop-menus', shopSlug, 'by-day', dayOfWeek],
    queryFn: async () => {
      const res = await shopMenuService.listByDay(shopSlug, dayOfWeek);
      return normalizeMenuByDay(res.data);
    },
    staleTime: 5 * 60_000,
  });
}
