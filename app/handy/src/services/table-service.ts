import { apiFetch } from '@/lib/api';
import type { PaginatedResponse, TableResource, TableStatusValue } from '@/types/pos';

export interface TableListFilters {
  status?: TableStatusValue;
  zone_id?: string;
  search?: string;
  per_page?: number;
  page?: number;
}

export const tableService = {
  list(filters?: TableListFilters): Promise<PaginatedResponse<TableResource>> {
    const params = new URLSearchParams();
    if (filters?.status) params.set('status', filters.status);
    if (filters?.zone_id) params.set('zone_id', filters.zone_id);
    if (filters?.search) params.set('search', filters.search);
    params.set('per_page', String(filters?.per_page ?? 100));
    if (filters?.page) params.set('page', String(filters.page));
    return apiFetch(`/api/v1/handy/tables?${params}`);
  },
};
