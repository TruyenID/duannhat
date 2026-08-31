/**
 * PeripheralDevice — LAN peripheral registered under a branch.
 *
 * The omnify TS target DOES emit this model now (#1263 turned
 * peripheral_devices into a schema), so ./base/PeripheralDevice exists and the
 * generated barrel expects the usual model exports from here. The bridge block
 * at the bottom provides them. The interface below stays hand-written on
 * purpose — see the note there.
 *
 * A peripheral is a machine the workstation drives over the LAN: a Verifone P400
 * card terminal (`payment_terminal`), a Glory 釣銭機 cash changer (`coin_changer`),
 * or a printer. For the network types the reachable address lives in `metadata`
 * (`host` + optional `port`), which syncs DOWN to the workstation so it can talk
 * to the machine without any per-POS `.env` edit.
 */

/** Registry device types the backend accepts (PeripheralDeviceService::ALLOWED_TYPES). */
export type PeripheralDeviceType =
  | "receipt_printer"
  | "kitchen_printer"
  | "bar_printer"
  | "payment_terminal"
  | "coin_changer"
  | "pos"
  | "workstation"
  | "kiosk";

/** Types that connect over the LAN and therefore require `metadata.host`. */
export const NETWORK_PERIPHERAL_TYPES: PeripheralDeviceType[] = ["payment_terminal", "coin_changer"];

export function isNetworkPeripheralType(type: string): boolean {
  return NETWORK_PERIPHERAL_TYPES.includes(type as PeripheralDeviceType);
}

/** Free-form metadata; network devices carry the LAN address here. */
export interface PeripheralDeviceMetadata {
  host?: string;
  port?: number;
  /**
   * Device model. For a coin_changer this is the Glory model; for a
   * payment_terminal it is free text ("stera terminal", "StarPay-α") that the
   * backend matches against vendor templates to PREFILL `accepts` on create.
   */
  model?: string;
  /**
   * #1156 — subset of the org's tender vocabulary (`till_tender_types.tender_key`)
   * this physical device accepts under the shop's acquirer contract.
   * payment_terminal / coin_changer only; validated server-side against the
   * org's ACTIVE org-wide vocabulary.
   */
  accepts?: string[];
  /**
   * #2422 — coin_changer only. How long the 釣銭機 waits for the customer to
   * finish inserting cash before it gives up. **On timeout the machine KEEPS
   * the deposited cash**, so this is an operational number a shop tunes, not a
   * constant. Absent = the workstation's 300s default.
   */
  deposit_timeout_seconds?: number;
  [key: string]: unknown;
}

/** Glory cash-changer models reachable via the YRT-R08-MN adapter. */
export const GLORY_MODELS = ["RT-R08", "RT-RAD-300", "RT-RAD-380"];

/** #2422 — deposit-timeout bounds, mirrored from PeripheralDeviceService. */
export const DEPOSIT_TIMEOUT_DEFAULT_SECONDS = 300;
export const DEPOSIT_TIMEOUT_MIN_SECONDS = 30;
export const DEPOSIT_TIMEOUT_MAX_SECONDS = 86400;

/**
 * #1156 — Vendor tender-acceptance templates, mirrored from
 * backend/config/tender_templates.php. Used ONLY to hint the operator what the
 * backend will prefill onto `metadata.accepts` when a payment_terminal is
 * registered with a matching `metadata.model` and no explicit accepts — the
 * backend applies the actual prefill (intersected with the org's active
 * vocabulary) on create.
 */
export const TENDER_TEMPLATES: Record<string, string[]> = {
  stera: [
    "credit",
    "paypay",
    "rakuten_pay",
    "d_barai",
    "au_pay",
    "merpay",
    "id",
    "ic",
    "edy",
    "waon",
    "nanaco",
    "quicpay",
  ],
  starpay: ["credit", "paypay", "au_pay", "wechat_pay", "alipay", "unionpay"],
};

/**
 * Resolve the vendor template matching a free-text model string, mirroring
 * TenderTemplateService::acceptsForModel — a template matches when its slug
 * equals, or is contained in, the lowercased model. First declared match wins.
 */
export function tenderTemplateForModel(model: string | null | undefined): {
  slug: string;
  accepts: string[];
} | null {
  if (!model || model.trim() === "") return null;
  const normalized = model.trim().toLowerCase();
  for (const [slug, accepts] of Object.entries(TENDER_TEMPLATES)) {
    if (normalized === slug || normalized.includes(slug)) {
      return { slug, accepts };
    }
  }
  return null;
}

export interface PeripheralDevice {
  id: string;
  organization_id: string;
  branch_id: string;
  name: string;
  type: PeripheralDeviceType;
  is_active: boolean;
  metadata: PeripheralDeviceMetadata | null;
  registered_by_device_id: string | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

// ============================================================================
// Bridge to the generated base (#1279)
// ============================================================================
//
// #1263 made peripheral_devices an Omnify schema, so src/types/models/index.ts
// was regenerated expecting this file to re-export the usual model surface.
// This file predates that and had none of it, which is why admin-web's dev
// went red on eight TS2724/TS2305 errors in the barrel.
//
// The `PeripheralDevice` interface above is deliberately NOT replaced by the
// generated one. Two reasons, and the second is a real defect:
//
//   * The hand-written shape is stricter and load-bearing: `type` is the
//     PeripheralDeviceType union rather than bare `string`, and `metadata` is
//     PeripheralDeviceMetadata rather than `unknown` — the host/port the
//     workstation dials live in there.
//   * Nothing consumes the generated Create/Update schemas yet, which is why
//     re-exporting them here is safe today.

import type { z } from 'zod';
import {
  basePeripheralDeviceSchemas,
  basePeripheralDeviceCreateSchema,
  basePeripheralDeviceUpdateSchema,
  peripheralDeviceI18n,
  getPeripheralDeviceLabel,
  getPeripheralDeviceFieldLabel,
  getPeripheralDeviceFieldPlaceholder,
} from './base/PeripheralDevice';

export const peripheralDeviceSchemas = { ...basePeripheralDeviceSchemas };
export const peripheralDeviceCreateSchema = basePeripheralDeviceCreateSchema;
export const peripheralDeviceUpdateSchema = basePeripheralDeviceUpdateSchema;

export type PeripheralDeviceCreate = z.infer<typeof peripheralDeviceCreateSchema>;
export type PeripheralDeviceUpdate = z.infer<typeof peripheralDeviceUpdateSchema>;

export {
  peripheralDeviceI18n,
  getPeripheralDeviceLabel,
  getPeripheralDeviceFieldLabel,
  getPeripheralDeviceFieldPlaceholder,
};
