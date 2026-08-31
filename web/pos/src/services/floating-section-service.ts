/**
 * Spotlight ("Khung giờ ưu đãi") — #1320, the client half of #1180.
 *
 * Reads `GET /api/v1/pos/floating-sections`, a **workstation-only** endpoint
 * (#1380). The workstation decides what comes back: it lists only the sections
 * whose schedule window is open right now, on the shop's own clock, and returns
 * each member with its PROMO price. Nothing here re-derives any of that — the
 * device is the authority, and a second copy of the window rule in the browser
 * would be a second thing that can drift.
 *
 * Cloud does not serve this path. A POS running against Cloud (no workstation
 * paired, or the LAN is down) therefore gets a 404 or a network error, and the
 * correct behaviour is **no spotlight, no noise**: the cashier still has the
 * ordinary menu, and an error toast for "this shop has no promotions" would be
 * a lie. So every failure resolves to an empty list.
 */

import { apiFetch } from "@/lib/api";

/** One promo-priced SKU of a spotlight member. */
export interface FloatingSectionSku {
  id: string;
  name: string;
  sku: string | null;
  /**
   * The PROMO price — `pos_floating_section_product_skus.selling_price`, not
   * the menu price for the same SKU. Same field name as the menu shape on
   * purpose: what decides which price this is, is where it came from.
   */
  selling_price: number;
  image_url: string | null;
}

/** One product inside a spotlight. */
export interface FloatingSectionProduct {
  /** `pos_floating_section_products.id` — "this product, from THIS spotlight". */
  floating_section_product_id: string;
  product_id: string;
  name: string;
  image_url: string | null;
  /** Collapsed by Cloud; null = inherit. Display only — the device re-reads it. */
  tax_type_id: string | null;
  display_order: number;
  skus: FloatingSectionSku[];
}

export interface FloatingSection {
  id: string;
  name: string;
  priority: number;
  products: FloatingSectionProduct[];
}

export const floatingSectionService = {
  /**
   * Open spotlights right now, or `[]` when there is no workstation to ask.
   *
   * Never throws and never toasts: absence of a spotlight is an ordinary state,
   * not a failure the cashier can act on.
   */
  async listOpen(): Promise<FloatingSection[]> {
    try {
      const res = await apiFetch<{ data: FloatingSection[] }>(
        "/api/v1/pos/floating-sections",
      );

      return res.data ?? [];
    } catch {
      return [];
    }
  },
};
