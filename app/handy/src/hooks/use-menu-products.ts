import { useInfiniteQuery } from '@tanstack/react-query';

import { normalizeMenuProducts } from '@/lib/normalize-menu';
import { shopMenuService } from '@/services/shop-menu-service';
import type { ShopMenuProduct } from '@/types/pos';

export interface MenuProductsFilters {
  search?: string;
  per_page?: number;
}

export function useMenuProducts(shopSlug: string, menuId: string, filters?: MenuProductsFilters) {
  return useInfiniteQuery<ShopMenuProduct[]>({
    queryKey: ['shop-menus', shopSlug, 'products', menuId, filters],
    queryFn: async ({ pageParam = 1 }) => {
      const res = await shopMenuService.listProducts(shopSlug, menuId, {
        ...filters,
        page: pageParam as number,
        per_page: filters?.per_page ?? 100,
      });
      return normalizeMenuProducts(res.data);
    },
    initialPageParam: 1,
    getNextPageParam: (_lastPage, allPages) => {
      // stop if last page returned fewer than per_page items
      const perPage = filters?.per_page ?? 100;
      const last = allPages[allPages.length - 1];
      return last.length < perPage ? undefined : allPages.length + 1;
    },
    staleTime: 5 * 60_000,
    enabled: !!menuId,
  });
}
