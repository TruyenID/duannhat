import { apiFetch } from "@/lib/api";
import type { KdsItem, KdsOrder } from "./orders";

export interface OperationOptions {
  idempotencyKey: string;
}

export interface ItemOperationResponse {
  data: KdsItem;
}

export interface OrderOperationResponse {
  data: KdsOrder;
}

type ItemAction = "mark-preparing" | "mark-ready" | "mark-served";

function itemOperation(
  orderId: string,
  itemId: string,
  action: ItemAction,
  opts: OperationOptions,
): Promise<ItemOperationResponse> {
  return apiFetch<ItemOperationResponse>(
    `/api/v1/kds/orders/${orderId}/items/${itemId}/${action}`,
    {
      method: "POST",
      headers: { "Idempotency-Key": opts.idempotencyKey },
    },
  );
}

export function markPreparing(
  orderId: string,
  itemId: string,
  opts: OperationOptions,
): Promise<ItemOperationResponse> {
  return itemOperation(orderId, itemId, "mark-preparing", opts);
}

export function markReady(
  orderId: string,
  itemId: string,
  opts: OperationOptions,
): Promise<ItemOperationResponse> {
  return itemOperation(orderId, itemId, "mark-ready", opts);
}

export function markServed(
  orderId: string,
  itemId: string,
  opts: OperationOptions,
): Promise<ItemOperationResponse> {
  return itemOperation(orderId, itemId, "mark-served", opts);
}

export async function revertItem(
  orderId: string,
  itemId: string,
  to: "preparing" | "pending",
  opts: OperationOptions,
): Promise<ItemOperationResponse> {
  return apiFetch<ItemOperationResponse>(
    `/api/v1/kds/orders/${orderId}/items/${itemId}/revert`,
    {
      method: "POST",
      headers: { "Idempotency-Key": opts.idempotencyKey },
      body: JSON.stringify({ to }),
    },
  );
}

export async function bumpAll(
  orderId: string,
  scope: "pending" | "preparing",
  opts: OperationOptions,
): Promise<OrderOperationResponse> {
  return apiFetch<OrderOperationResponse>(
    `/api/v1/kds/orders/${orderId}/bump-all`,
    {
      method: "POST",
      headers: { "Idempotency-Key": opts.idempotencyKey },
      body: JSON.stringify({ scope }),
    },
  );
}
