/**
 * Material Lot Reservation Service — pure TypeScript, no React dependency.
 *
 * SHOP surface only (`/api/v1/shops/{shopSlug}/material-lot-reservations`,
 * #3112). The HQ surface exists too but is org-wide and HQ-role only, so it is
 * deliberately absent here: the screens that hold lots are shop screens, and
 * pointing them at the HQ route is what turned #3077 into a 403 aimed at
 * exactly the warehouse staff who needed it.
 *
 * The React-Query layer lives in src/hooks/api/use-material-lot-reservations.ts.
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export type MaterialLotReservationStatus = "active" | "consumed" | "cancelled" | "expired";

export interface MaterialLotReservation {
  id: string;
  organization_id: string;
  material_lot_id: string;
  material_batch_id: string | null;
  qty_reserved: number | string;
  reserved_by_id: string;
  expected_consume_at: string | null;
  status: MaterialLotReservationStatus;
  reason: string | null;
  created_at: string;
  updated_at: string;
}

export interface CreateLotReservationInput {
  material_lot_id: string;
  qty_reserved: number;
  /** The production batch this hold serves. Must live in the same shop. */
  material_batch_id?: string | null;
  expected_consume_at?: string | null;
  reason?: string | null;
}

// =========================================================================
//  Endpoints
// =========================================================================

// `/api/v1` là BẮT BUỘC ở đây, không phải quy ước cho đẹp: `apiFetch` gọi
// `fetch(path)` THẲNG, không cộng base nào, và `next.config.ts` chỉ rewrite các
// route UI (`/hq/…`, `/shop/…`) chứ không có `/shops/*`. Thiếu tiền tố thì mọi
// lượt gọi rơi vào chính app Next.js và trả 404 — cả bốn thao tác giữ lô chết,
// trong khi màn hình vẫn báo "lưu lô thành công" rồi mới nói N hold thất bại.
function shopUrl(shopSlug: string, path = ""): string {
  return `/api/v1/shops/${shopSlug}/material-lot-reservations${path}`;
}

export const materialLotReservationShopService = {
  /** Holds attached to ONE batch. The batch id is required by the API — a shop
   *  route must not be able to page through the whole organization's holds. */
  listByBatch: (
    shopSlug: string,
    materialBatchId: string,
    status?: MaterialLotReservationStatus
  ) => {
    const params = new URLSearchParams({ material_batch_id: materialBatchId });
    if (status) params.set("status", status);
    return apiFetch<PaginatedResponse<MaterialLotReservation>>(
      `${shopUrl(shopSlug)}?${params.toString()}`
    );
  },

  create: (shopSlug: string, input: CreateLotReservationInput) =>
    apiFetch<{ data: MaterialLotReservation }>(shopUrl(shopSlug), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  release: (shopSlug: string, id: string) =>
    apiFetch<{ data: MaterialLotReservation }>(shopUrl(shopSlug, `/${id}/release`), {
      method: "POST",
    }),

  renew: (shopSlug: string, id: string, expectedConsumeAt?: string | null) =>
    apiFetch<{ data: MaterialLotReservation }>(shopUrl(shopSlug, `/${id}/renew`), {
      method: "POST",
      body: JSON.stringify({ expected_consume_at: expectedConsumeAt ?? null }),
    }),
};
