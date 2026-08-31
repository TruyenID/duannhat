import { apiFetch } from '@/lib/api';
import type { ShopDetail } from '@/types/pos';

export const shopService = {
  getBySlug(shopSlug: string): Promise<{ data: ShopDetail }> {
    return apiFetch(`/api/v1/shops/${shopSlug}`);
  },
};
