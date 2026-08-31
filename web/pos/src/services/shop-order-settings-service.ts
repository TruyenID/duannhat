/**
 * Shop Order Settings Service.
 *
 * pos-web reads the full settings (quick-order toggle, currency, service
 * charge…) and may
 * edit ONLY the 精算 close-report section toggles via a scoped PATCH (backend
 * updateCloseReportToggles — never currency/tax/policy). admin-web owns the
 * full PATCH side.
 *
 *   GET   /api/v1/pos/settings/order
 *   PATCH /api/v1/pos/settings/order   (close_report_* toggles only)
 */

import { apiFetch } from "@/lib/api";

export interface ShopOrderSettingsStatus {
  value: string;
  label: string;
  description: string;
}

export interface ShopOrderSettings {
  default_order_item_status: string | null;
  enable_quick_order: boolean;
  /**
   * BR-SOS05 — branch service charge rate (%). Backend computes the actual
   * service charge at checkout from this rate against the discounted subtotal.
   * Decimal serialised as string by Laravel (precision 5, scale 2) — coerce
   * with `Number(...)` at call sites.
   *
   * NOTE: the former settings-level `tax_rate` was dropped in plan-043 — a
   * branch's consumption tax is now driven by tax_types + per-line snapshots
   * (`customer_order_items.tax_rate`), so the settings payload no longer
   * carries a flat branch tax rate.
   */
  service_charge_rate: string;
  /**
   * BR-SOS06 — ISO 4217 currency code that pos-web uses to format every
   * money amount. Default "VND" preserves legacy hard-coded behaviour;
   * admin-web exposes a dropdown to flip this per shop. Pure display
   * preference — backend never converts amounts.
   */
  currency_code: string;
  // 精算 close-report optional-section toggles (default true). Editable here.
  close_report_payment_methods: boolean;
  close_report_service_charge: boolean;
  close_report_denominations: boolean;
  close_report_drawer_check: boolean;
  /**
   * plan-043 — gate the per-rate 課税売上/消費税 block on the thermal close
   * report (8%対象 / 10%対象 with インボイス). Thermal print only; the Z-report
   * PDF always includes the breakdown (DESIGN Decision 8).
   */
  close_report_tax_breakdown: boolean;
  /**
   * plan-043 — 総額表示 mode. When true the branch's menu prices already include
   * tax; the pos-web menu card shows the 税込 price + a reference 税抜, and the
   * cart extracts the inner tax rather than adding it. Optional so a stale
   * workstation build that predates the field degrades to excluded (税抜).
   */
  prices_include_tax?: boolean;
  /**
   * When true, order items can be edited / removed / voided regardless of
   * status; false (default) keeps the pending-only rule. Read by the cart to
   * relax the per-item edit gate — the backend + workstation enforce the same
   * setting. Optional so a stale build degrades to pending-only.
   */
  allow_item_edit_any_status?: boolean;
  /**
   * plan-051 (#1149) — per-status void matrix. Raw column value (null =
   * legacy-flag fallback) + the server-RESOLVED effective list. Both optional:
   * at P4 time the settings serializer does not yet emit them (backend gap) —
   * resolveVoidableStatuses() falls back to allow_item_edit_any_status.
   */
  item_voidable_statuses?: string[] | null;
  effective_item_voidable_statuses?: string[] | null;
  /**
   * plan-051 (#1150) — when the branch deducts stock per line
   * (on_close default | on_preparing | on_add). Display-only for pos-web.
   */
  stock_deduction_timing?: "on_close" | "on_preparing" | "on_add";
  /** plan-043 — tax rate (%) applied to the service charge. */
  service_charge_tax_rate?: string;
  /** plan-043 — branch default TaxType id (null = brand default). */
  default_tax_type_id?: string | null;
  available_statuses: ShopOrderSettingsStatus[];
  available_currencies?: Array<{ code: string; label: string; symbol: string }>;
}

/** The five close-report toggle keys — the only fields pos-web may write. */
export const CLOSE_REPORT_TOGGLE_KEYS = [
  "close_report_payment_methods",
  "close_report_service_charge",
  "close_report_denominations",
  "close_report_drawer_check",
  "close_report_tax_breakdown",
] as const;

export type CloseReportToggleKey = (typeof CLOSE_REPORT_TOGGLE_KEYS)[number];
export type CloseReportToggles = Record<CloseReportToggleKey, boolean>;

export const shopOrderSettingsService = {
  // shopSlug param retained for caller compatibility + TanStack Query cache
  // keys; request resolves the shop server-side via X-Shop-Slug header.
  get: (_shopSlug: string) =>
    apiFetch<{ data: ShopOrderSettings }>(`/api/v1/pos/settings/order`),

  /** Scoped PATCH — close-report section toggles only. */
  updateCloseReportToggles: (_shopSlug: string, body: Partial<CloseReportToggles>) =>
    apiFetch<{ data: CloseReportToggles }>(`/api/v1/pos/settings/order`, {
      method: "PATCH",
      body: JSON.stringify(body),
      headers: { "Content-Type": "application/json" },
    }),
};
