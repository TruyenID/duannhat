import type { Branch, Brand, WeeklyHoursMap } from "@/data/brands";

/**
 * #1778 — folding `GET /api/v1/customer/tables/{qrToken}`'s `data.branch` into
 * the branch already held by `brand-context`.
 *
 * TWO sources write `currentBranch` and they race:
 *
 *   1. `brand-context` fetches `/api/v1/customer/branches` and maps the FULL
 *      branch (tax mode, tax type, rounding mode, order policy, locale…).
 *   2. the dine-in pages fetch `/api/v1/customer/tables/{qrToken}` and used to
 *      REPLACE `currentBranch` with a hand-built object carrying only the
 *      subset of fields that payload happens to expose.
 *
 * Whichever landed last won. Replacing meant every field the table payload does
 * not carry silently became its *default* — and a default is not "unknown", it
 * is a confident wrong answer. `prices_include_tax` fell to `false`, so the
 * dine-in menu printed "Chưa gồm thuế / 税抜" over prices that already include
 * tax (#1042 / #1072: the toggle drives the LABEL only, never the number), i.e.
 * the customer read ￥1,300 and mentally added another 8–10%. On a normal
 * connection the wrong writer won almost every time; throttling to Slow 4G
 * flipped the label back — the signature of a race, which is why it read as a
 * random display glitch rather than a missing field.
 *
 * So: MERGE instead of replace, and only for the SAME branch.
 *
 * The same-branch guard is not incidental. The replace also served a purpose —
 * scanning a QR code for a table of a DIFFERENT branch must not inherit the
 * previous branch's tax mode, currency or rounding. Blindly spreading `prev`
 * would trade this bug for its mirror image. So when the slug differs we still
 * start from a clean slate; when it matches we keep what the other source knew.
 *
 * A field that is absent (or `null`) in the payload is treated as "this source
 * has no opinion" and leaves the existing value alone — never as a default.
 * Hard defaults are applied ONLY when neither source ever supplied a value.
 */
export interface TableBranchPayload {
  id: string;
  name: string;
  slug: string;
  code?: string | null;
  address?: string | null;
  phone?: string | null;
  img_branches?: string | null;
  banner_desktop?: string | null;
  banner_tablet?: string | null;
  banner_mobile?: string | null;
  logo?: string | null;
  business_hours?: string | null;
  weekly_hours?: WeeklyHoursMap | null;
  /** #1447 — the IANA zone `weekly_hours` is written in. Load-bearing, not
   *  decoration: without it `lib/opening-hours.ts` judges the schedule on the
   *  CUSTOMER's device clock, so a phone in Vietnam reads a Tokyo shop two
   *  hours early and the menu grows a "Cửa hàng đã đóng cửa" banner while the
   *  shop is open. Take-away never hit this — it resolves its branch from
   *  /customer/branches, which has carried `timezone` since #1160. */
  timezone?: string | null;
  seat_capacity?: number | null;
  review_avg_rating?: number | null;
  review_total_count?: number | null;
  /** #1778 — served by the table endpoint since the backend half of #1778.
   *  Absent on an older backend: then the value from `/customer/branches`
   *  must survive rather than degrade to `false`. */
  prices_include_tax?: boolean | null;
  service_charge_rate?: number | null;
  currency_code?: string | null;
  brand?: Brand | null;
}

/** Fields `/customer/branches` supplies that the table payload never carries.
 *  Listed for the test that keeps the two sources from contradicting one
 *  another; nothing at runtime reads it. */
export const BRANCH_FIELDS_ABSENT_FROM_TABLE_PAYLOAD = [
  "service_charge_tax_rate",
  "default_tax_type",
  "split_bill_rounding_mode",
  "locale",
  "effective_order_policy",
] as const satisfies readonly (keyof Branch)[];

export function branchFromTablePayload(prev: Branch, payload: TableBranchPayload): Branch {
  const overlay: Partial<Branch> = {};
  const set = <K extends keyof Branch>(key: K, value: Branch[K] | null | undefined): void => {
    if (value !== null && value !== undefined) overlay[key] = value;
  };

  set("code", payload.code);
  set("address", payload.address);
  set("phone", payload.phone);
  set("img_branches", payload.img_branches);
  set("banner_desktop", payload.banner_desktop);
  set("banner_tablet", payload.banner_tablet);
  set("banner_mobile", payload.banner_mobile);
  set("logo", payload.logo);
  set("business_hours", payload.business_hours);
  set("weekly_hours", payload.weekly_hours);
  set("timezone", payload.timezone);
  set("seat_capacity", payload.seat_capacity);
  set("review_avg_rating", payload.review_avg_rating);
  set("review_total_count", payload.review_total_count);
  set("prices_include_tax", payload.prices_include_tax);
  set("service_charge_rate", payload.service_charge_rate);
  set("currency_code", payload.currency_code);
  set("brand", payload.brand);

  // Same branch → keep everything the other source knew. Different branch →
  // clean slate, so no cross-branch tax/currency/rounding leaks in.
  const isSameBranch = Boolean(prev.slug) && prev.slug === payload.slug;
  const base: Partial<Branch> = isSameBranch ? prev : {};

  const merged: Branch = {
    ...base,
    ...overlay,
    id: payload.id,
    name: payload.name,
    slug: payload.slug,
    // Last-resort defaults — applied only when NEITHER source ever said anything.
    service_charge_rate: overlay.service_charge_rate ?? base.service_charge_rate ?? 0,
    currency_code: overlay.currency_code ?? base.currency_code ?? "JPY",
    review_total_count: overlay.review_total_count ?? base.review_total_count ?? 0,
    brand: overlay.brand ?? base.brand ?? { id: "", slug: "", name: "" },
  };

  return merged;
}
