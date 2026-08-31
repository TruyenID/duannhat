import { apiFetch } from "@/lib/api";

export type ItemStatus = "pending" | "preparing" | "ready" | "served" | "voided";

export type OrderPriority = "normal" | "warning" | "critical";

export type ItemTransition =
  | "mark-preparing"
  | "mark-ready"
  | "mark-served"
  | "revert";

export interface KdsItemTopping {
  name: string | null;
  quantity: number;
  status: ItemStatus;
}

export interface KdsItem {
  id: string;
  menu_item_name: string;
  quantity: number;
  status: ItemStatus;
  note: string | null;

  aging_minutes: number;
  time_in_current_status_seconds: number;
  is_blocked_by_toppings: boolean;
  allowed_transitions: ItemTransition[];

  started_preparing_at: string | null;
  ready_at: string | null;
  served_at: string | null;

  /**
   * Plan-038 T9.8 — workstation print_status surfaces here so the KDS
   * card can render a "MÁY IN LỖI" badge when a fire failed.
   * Values: 'pending' | 'sent_to_kitchen' | 'failed'. May be omitted on
   * older cloud-fallback responses.
   */
  print_status?: "pending" | "sent_to_kitchen" | "failed" | string;

  toppings: KdsItemTopping[];
}

export interface KdsOrderTable {
  id: string;
  code: string;
  zone: string | null;
}

export interface KdsOrder {
  id: string;
  order_code: string;
  order_type: string;
  guest_count: number | null;
  note: string | null;
  opened_at: string | null;

  aging_minutes: number;
  is_late: boolean;
  priority: OrderPriority;

  pending_items_count: number;
  preparing_items_count: number;
  ready_items_count: number;
  oldest_pending_age_minutes: number;
  can_bump_all: boolean;

  table: KdsOrderTable | null;
  items: KdsItem[];
}

export interface KdsOrdersMeta {
  active_count: number;
  late_count: number;
  critical_count: number;
  avg_age_minutes: number;
  oldest_pending_item_age_minutes: number;
  items_by_status: {
    pending: number;
    preparing: number;
    ready: number;
    served: number;
  };
  fetched_at: string;
}

interface OrdersResponse {
  data: KdsOrder[];
  meta?: KdsOrdersMeta;
}

/**
 * Cache shape stored under `["kds", "orders"]`. Optimistic mutations operate
 * on `orders`; meta is read-only from the server (lossy on optimistic updates
 * — it'll resync at the next refetch).
 */
export interface KdsOrdersQueryData {
  orders: KdsOrder[];
  meta?: KdsOrdersMeta;
}

export async function getOrdersWithMeta(opts?: {
  limit?: number;
}): Promise<KdsOrdersQueryData> {
  const limit = opts?.limit ?? 200;
  const res = await apiFetch<OrdersResponse>(`/api/v1/kds/orders?limit=${limit}`, {
    method: "GET",
  });
  return { orders: res.data, meta: res.meta };
}
