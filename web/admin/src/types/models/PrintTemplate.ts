/**
 * Print template registry — plan-053 M4 (#1171).
 *
 * Mirrors the backend contract of
 *   GET/POST /api/v1/hq/{brandSlug}/print-templates/...
 *   GET/POST /api/v1/shops/{shopSlug}/print-templates/...
 * (backend/app/Http/Controllers/Api/V1/{HQ,Shop}/*PrintTemplateController.php).
 *
 * A template PRESENTS, it never COMPUTES: the definition positions blocks and
 * authors free text, while money/tax blocks are rendered by the engine. The
 * editor therefore never lets a user type into a `locked` block.
 */

/**
 * The 14 slips a brand can author (backend `config/print_blocks.php` → kinds),
 * in that file's order so the two lists diff by eye.
 *
 * `qualified_simplified_invoice` (適格簡易請求書) was missing here while the API
 * has been serving it (#2040 sweep). Nothing renders off this array — it only
 * derives `PrintTemplateKind` — so it was a type that quietly lied rather than
 * a broken screen. It still has no `print_templates.kind.*` label, which is a
 * separate gap in `src/i18n/*.json`.
 */
export const PRINT_TEMPLATE_KINDS = [
  "receipt",
  "kitchen",
  "runner",
  "delta_qr",
  "remaining",
  "red_invoice",
  "vat_invoice",
  "qualified_simplified_invoice",
  "void_notice",
  "debt_slip",
  "shift_open",
  "shift_report",
  "chain_report",
  "table_paid",
] as const;

export type PrintTemplateKind = (typeof PRINT_TEMPLATE_KINDS)[number];

export type PrintTemplateScope = "system" | "brand" | "shop";
export type PrintTemplateStatus = "draft" | "published" | "retired";

/** `locked` = untouchable · `toggleable` = only `enabled` · `free` = authored. */
export type PrintBlockMutability = "locked" | "toggleable" | "free";

export type PrintBlockType = "text" | "image" | "params" | "line_items" | "qr" | "locked";

export type PrintLocale = "ja" | "en" | "vi";

export const PRINT_LOCALES: PrintLocale[] = ["ja", "en", "vi"];

/**
 * The two real rolls. The backend's `PreviewRequest::PAPERS` maps them to the
 * column counts (32 / 48) — the client never does that arithmetic itself,
 * because a column count is a layout decision and layout belongs to the one
 * renderer that also drives the printer.
 */
export type PaperSize = "58mm" | "80mm";

export const PRINT_PAPERS: PaperSize[] = ["58mm", "80mm"];

/** PHP serialises an empty i18n map as `[]`, so both shapes reach the client. */
export type PrintBlockI18n = Partial<Record<PrintLocale, string>> | unknown[];

export interface PrintBlock {
  id: string;
  type?: PrintBlockType;
  enabled?: boolean;
  align?: "left" | "center" | "right";
  bold?: boolean;
  fallback?: boolean;
  i18n?: PrintBlockI18n;
  source?: string;
  fields?: string[];
  columns?: string[];
  max_width_dots?: number;
}

/**
 * `receiptline_dialect?: string` was REMOVED here (#2061) along with the Cloud
 * key that produced it. Nothing read it on any side — no validator checked it,
 * the Go parser ignored it — and this envelope is a house format, not OFSC
 * ReceiptLine. Definitions cached before the removal may still carry the key;
 * nothing in this app reads unknown envelope keys, so they are unaffected.
 */
export interface PrintTemplateDefinition {
  schema: string;
  paper?: { columns_58mm: number; columns_80mm: number };
  blocks: PrintBlock[];
}

/**
 * The server's description of what this kind's editor may draw.
 *
 * Every field here used to have a hand-copied twin in this file
 * (`PRINT_BLOCK_MUTABILITY`, `PRINT_BLOCK_EDITABLE_PROPS`, `PRINT_SOURCES`,
 * `PRINT_PARAM_FIELDS`, `PRINT_ITEM_COLUMNS`), because the catalog was an HQ-only
 * read and the shop editor had no permission for it. Those mirrors drifted from
 * `backend/config/print_blocks.php` four times, always silently (#1181 ×2,
 * #2000, #2040). #2043 put the catalog on the shop surface too, so there is one
 * source again and the mirrors are gone.
 */
export interface PrintTemplateCatalog {
  /** Ordered block ids this kind is composed of — also its allow-list. */
  blocks: string[];
  required: string[];
  sources: string[];
  param_fields: string[];
  mutability: Record<string, PrintBlockMutability>;
  /**
   * Props a definition may set, per block. Publish rejects anything else
   * (`PROP_NOT_EDITABLE`), so the editor draws exactly these controls.
   */
  editable_props: Record<string, string[]>;
  /** Allowed values of an enumerated prop, e.g. `items.columns`. */
  prop_enums: Record<string, Record<string, string[]>>;
}

export interface PrintTemplateVersion {
  id: string;
  kind: PrintTemplateKind;
  scope: PrintTemplateScope;
  version: number;
  status: PrintTemplateStatus;
  effective_from: string | null;
  shop_editable: string[] | null;
  notes: string | null;
  parent_version_id: string | null;
  created_by: string | null;
  published_by: string | null;
  published_at: string | null;
  updated_at: string | null;
  /** Optimistic-lock token (TR-09) — echo it back on the next draft save. */
  lock_token: string;
  definition?: PrintTemplateDefinition;
}

/** One row of the HQ list screen. */
export interface BrandPrintTemplateSummary {
  kind: PrintTemplateKind;
  /** TR-01 — nothing published yet, the code-shipped default is printing. */
  is_system_default: boolean;
  published_version: number | null;
  published_at: string | null;
  effective_from: string | null;
  has_draft: boolean;
  shop_editable: string[];
  required_blocks: string[];
}

export interface BrandPrintTemplateDetail {
  kind: PrintTemplateKind;
  catalog: PrintTemplateCatalog;
  system_default: PrintTemplateDefinition;
  published: PrintTemplateVersion | null;
  draft: PrintTemplateVersion | null;
}

export interface PrintTemplateDiffChange {
  path: string;
  op: "added" | "removed" | "changed";
  from: unknown;
  to: unknown;
}

export interface PrintTemplateDiff {
  from_version: number;
  to_version: number | null;
  changes: PrintTemplateDiffChange[];
}

/** One row of the shop list screen. */
export interface ShopPrintTemplateSummary {
  kind: PrintTemplateKind;
  shop_editable: string[];
  /** Empty allow-list — the brand locked this slip completely. */
  is_locked_by_brand: boolean;
  brand_version: number | null;
  override_version: number | null;
  /** TR-02 — the paths this shop actually changes, after allow-list filtering. */
  overridden_paths: string[];
  effective_scope: PrintTemplateScope;
}

export interface ResolvedPrintTemplate {
  kind: PrintTemplateKind;
  scope: PrintTemplateScope;
  version: number | null;
  effective_from: string | null;
  checksum: string;
  is_system_default: boolean;
  shop_overridden_paths: string[];
  definition: PrintTemplateDefinition;
  updated_at: string | null;
}

export interface ShopPrintTemplateDetail {
  kind: PrintTemplateKind;
  shop_editable: string[];
  /** #2043 — the same catalog HQ gets, served under `shop.manage`. */
  catalog: PrintTemplateCatalog;
  resolved: ResolvedPrintTemplate;
  draft: {
    id: string;
    version: number;
    definition: PrintTemplateDefinition;
    notes: string | null;
    updated_at: string | null;
    lock_token: string;
  } | null;
}

/** A publish-validation violation (422 `PRINT_TEMPLATE_INVALID`). */
export interface PrintTemplateViolation {
  code: string;
  path: string;
  message: string;
}

// =========================================================================
//  #2043 — the catalog mirrors are GONE
// =========================================================================
//
// This file used to carry five hand-copies of `backend/config/print_blocks.php`:
// `PRINT_BLOCK_EDITABLE_PROPS`, `PRINT_BLOCK_MUTABILITY`, `PRINT_SOURCES`,
// `PRINT_PARAM_FIELDS`, `PRINT_ITEM_COLUMNS`. They existed for ONE screen — the
// shop override editor, whose user holds `shop.manage` and could not call the
// HQ-only catalog read — and they drifted four times, always silently: tsc was
// happy, the tests were green, and the editor simply drew no switch for a block
// or no checkbox for a param field (#1181 ×2, #2000, #2040).
//
// The shop surface now serves the catalog itself (`GET /shops/{slug}/
// print-templates/{kind}` → `data.catalog`), so every one of those lists comes
// off the wire and there is nothing left to keep in step.
//
// What could NOT be deleted, and why:
//
//   `PRINT_TEMPLATE_KINDS`  derives the `PrintTemplateKind` union that types the
//                           whole service layer. A union type cannot be built
//                           from a runtime response.
//   `PRINT_BLOCK_IDS`       below — the set of names this app must be able to
//                           SPEAK, not a description of what it may edit.
//
// Both are still copies, but they fail LOUDLY: a missing kind is a type error at
// the call site, and a missing block id is a raw `print_templates.block.x` key
// on screen. Neither can produce the silent shape that cost us #2040 — an editor
// that quietly refuses to offer a control the backend would have accepted.

/**
 * Block ids this app carries a display NAME for (`print_templates.block.*`).
 *
 * Purely a translation catalogue: nothing branches on it, and being wrong here
 * cannot change what a user may edit — the editor's behaviour comes entirely
 * from `PrintTemplateCatalog`. `print-template-i18n-coverage.test.ts` pins it as
 * a bijection against ja/en/vi so a block can neither lose its name nor keep a
 * name after being renamed away.
 */
export const PRINT_BLOCK_IDS: readonly string[] = [
  "logo",
  "store_info",
  "title",
  "header_text",
  "issued_at",
  "split_banner",
  "order_meta",
  "customer_header",
  "order_note",
  "column_header",
  "items",
  "batch_total",
  "tax_legend",
  "subtotal",
  "discounts",
  "service_charge",
  "tax_breakdown",
  "grand_total",
  "payments",
  "change_due",
  "remaining",
  "registration_number",
  "invoice_number",
  "reprint_marker",
  "red_invoice_marker",
  "vat_disclaimer",
  "void_marker",
  "shift_meta",
  "float_count",
  "denomination_table",
  "tender_summary",
  "variance",
  "chain_summary",
  "sales_summary",
  "non_cash_change",
  "discount_summary",
  "acct_correction",
  "check_count",
  "cash_movement",
  "void_summary",
  "shift_signature",
  "debt_summary",
  "debt_signature",
  "paid_summary",
  "qr_block",
  "footer_text",
  "greeting",
];

/** The props the server says this block may set — `[]` means engine-owned. */
export function editablePropsOf(catalog: PrintTemplateCatalog, blockId: string): string[] {
  return catalog.editable_props[blockId] ?? [];
}

/** Normalise the `i18n` prop, which PHP may hand over as an empty array. */
export function toI18nMap(value: PrintBlockI18n | undefined): Record<PrintLocale, string> {
  const map: Record<PrintLocale, string> = { ja: "", en: "", vi: "" };
  if (!value || Array.isArray(value)) return map;
  for (const locale of PRINT_LOCALES) {
    const text = (value as Partial<Record<PrintLocale, string>>)[locale];
    if (typeof text === "string") map[locale] = text;
  }
  return map;
}

// ============================================================================
// Bridge to the generated base
// ----------------------------------------------------------------------------
// `printtemplates` became an Omnify schema, so the generated barrel
// (types/models/index.ts) re-exports the standard model surface from HERE. Without
// this block `pnpm typecheck` fails on every one of those names — the same break
// #1279 hit on PeripheralDevice. Pattern copied from Printer.ts.
// ============================================================================

import { z } from 'zod';
import type { PrintTemplate as PrintTemplateBase } from './base/PrintTemplate';
import {
  basePrintTemplateSchemas,
  basePrintTemplateCreateSchema,
  basePrintTemplateUpdateSchema,
  printTemplateI18n,
  getPrintTemplateLabel,
  getPrintTemplateFieldLabel,
  getPrintTemplateFieldPlaceholder,
} from './base/PrintTemplate';

export const printTemplateSchemas = { ...basePrintTemplateSchemas };
export const printTemplateCreateSchema = basePrintTemplateCreateSchema;
export const printTemplateUpdateSchema = basePrintTemplateUpdateSchema;

export type PrintTemplateCreate = z.infer<typeof printTemplateCreateSchema>;
export type PrintTemplateUpdate = z.infer<typeof printTemplateUpdateSchema>;

export {
  printTemplateI18n,
  getPrintTemplateLabel,
  getPrintTemplateFieldLabel,
  getPrintTemplateFieldPlaceholder,
};

// PrintTemplate.ts had no interface of its own — unlike PrintJob, its hand-written
// half is all helper types — so the model interface comes straight from the base.
export interface PrintTemplate extends PrintTemplateBase {}

export type { PrintTemplateBase };
