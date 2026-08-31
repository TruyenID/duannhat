import { apiFetch } from '@/lib/api';
import type {
  PaginatedResponse,
  ShopMenuByDayResource,
  ShopMenuProduct,
} from '@/types/pos';

export interface MenuProductFilters {
  search?: string;
  page?: number;
  per_page?: number;
}

export const shopMenuService = {
  listByDay(_shopSlug: string, dayOfWeek: number): Promise<PaginatedResponse<ShopMenuByDayResource>> {
    return apiFetch(`/api/v1/handy/menus/by-day/${dayOfWeek}`);
  },

  listProducts(
    _shopSlug: string,
    menuId: string,
    filters?: MenuProductFilters,
  ): Promise<PaginatedResponse<ShopMenuProduct>> {
    const params = new URLSearchParams();
    if (filters?.search) params.set('search', filters.search);
    if (filters?.page) params.set('page', String(filters.page));
    params.set('per_page', String(filters?.per_page ?? 100));
    const qs = params.toString();
    return apiFetch(`/api/v1/handy/menus/${menuId}/products${qs ? `?${qs}` : ''}`);
  },
};
