import { apiFetch } from '@/lib/api';
import type { ShopOrderSettings } from '@/types/pos';

export const shopOrderSettingsService = {
  get(_shopSlug: string): Promise<{ data: ShopOrderSettings }> {
    return apiFetch(`/api/v1/handy/settings/order`);
  },
};
