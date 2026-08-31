import { apiFetch } from '@/lib/api';
import type {
  CustomerOrder,
  OrderCreateInput,
  OrderInitInput,
  OrderItemInput,
  OrderItemUpdateInput,
  PaginatedResponse,
} from '@/types/pos';

export interface OrderListFilters {
  status?: string;
  per_page?: number;
  sort?: string;
}

export const orderService = {
  list(filters?: OrderListFilters): Promise<PaginatedResponse<CustomerOrder>> {
    const params = new URLSearchParams();
    if (filters?.status) params.set('status', filters.status);
    params.set('per_page', String(filters?.per_page ?? 100));
    if (filters?.sort) params.set('sort', filters.sort);
    return apiFetch(`/api/v1/handy/orders?${params}`);
  },

  get(orderId: string): Promise<{ data: CustomerOrder }> {
    return apiFetch(`/api/v1/handy/orders/${orderId}`);
  },

  create(input: OrderCreateInput): Promise<{ data: CustomerOrder }> {
    return apiFetch(`/api/v1/handy/orders`, {
      method: 'POST',
      body: JSON.stringify(input),
    });
  },

  init(orderId: string, body: OrderInitInput): Promise<{ data: CustomerOrder }> {
    return apiFetch(`/api/v1/handy/orders/${orderId}/init`, {
      method: 'PUT',
      body: JSON.stringify(body),
    });
  },

  updateItem(
    orderId: string,
    itemId: string,
    body: OrderItemUpdateInput,
  ): Promise<{ data: CustomerOrder }> {
    return apiFetch(`/api/v1/handy/orders/${orderId}/items/${itemId}`, {
      method: 'PATCH',
      body: JSON.stringify(body),
    });
  },

  removeItem(
    orderId: string,
    itemId: string,
  ): Promise<{ data: CustomerOrder }> {
    return apiFetch(`/api/v1/handy/orders/${orderId}/items/${itemId}`, {
      method: 'DELETE',
    });
  },

  addItems(
    orderId: string,
    items: OrderItemInput[],
  ): Promise<{ data: CustomerOrder }> {
    return apiFetch(`/api/v1/handy/orders/${orderId}/items`, {
      method: 'POST',
      body: JSON.stringify({ items }),
    });
  },

  voidOrder(
    orderId: string,
    voidReason: string,
  ): Promise<{ data: CustomerOrder }> {
    return apiFetch(`/api/v1/handy/orders/${orderId}`, {
      method: 'DELETE',
      body: JSON.stringify({ void_reason: voidReason }),
    });
  },

  fireOrder(orderId: string): Promise<{ status: string; printed: number; errors?: string[] }> {
    return apiFetch(`/api/v1/handy/orders/${orderId}/fire`, { method: 'POST' });
  },
};
