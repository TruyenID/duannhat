/**
 * Print template service — plan-053 M4 (#1171). Pure TypeScript, no React.
 *
 * Two surfaces, deliberately separate because they are different jobs:
 *   - BRAND (`/hq/{brandSlug}/print-templates`) authors the WHOLE slip and
 *     decides what it delegates to shops (`shop_editable`).
 *   - SHOP (`/shops/{shopSlug}/print-templates`) publishes a partial OVERLAY
 *     that may only touch the delegated paths.
 */

import { apiFetch } from "@/lib/api";
import type {
  BrandPrintTemplateDetail,
  BrandPrintTemplateSummary,
  PaperSize,
  PrintLocale,
  PrintTemplateDefinition,
  PrintTemplateDiff,
  PrintTemplateVersion,
  ShopPrintTemplateDetail,
  ShopPrintTemplateSummary,
} from "@/types/models/PrintTemplate";

export interface SaveDraftInput {
  definition: PrintTemplateDefinition;
  /** Brand scope only — the allow-list delegated to shops. */
  shop_editable?: string[];
  notes?: string | null;
  /** TR-09 optimistic lock — required once a draft exists. */
  lock_token?: string | null;
}

export interface PublishInput {
  /** BRANCH-LOCAL wall clock (#1091): 'Y-m-d' or 'Y-m-d H:i'. Null = now. */
  effective_from?: string | null;
  notes?: string | null;
}

/**
 * TR-32 (T4.3) — what the preview endpoint needs to draw the slip.
 *
 * The `definition` travels in the body on purpose: it is the editor's UNSAVED
 * state, and the alternative — save first, then GET — writes on every preview,
 * bumping the optimistic-lock token and 409ing whichever other tab is open on
 * the same draft (TR-09).
 */
export interface PreviewInput {
  definition: PrintTemplateDefinition;
  paper: PaperSize;
  locale: PrintLocale;
}

function hqUrl(brandSlug: string, path = ""): string {
  return `/api/v1/hq/${brandSlug}/print-templates${path}`;
}

function shopUrl(shopSlug: string, path = ""): string {
  return `/api/v1/shops/${shopSlug}/print-templates${path}`;
}

function previewQuery(input: PreviewInput): string {
  return `?${new URLSearchParams({ paper: input.paper, locale: input.locale }).toString()}`;
}

export const printTemplateBrandService = {
  list: (brandSlug: string) => apiFetch<{ data: BrandPrintTemplateSummary[] }>(hqUrl(brandSlug)),

  get: (brandSlug: string, kind: string) =>
    apiFetch<{ data: BrandPrintTemplateDetail }>(hqUrl(brandSlug, `/${kind}`)),

  history: (brandSlug: string, kind: string) =>
    apiFetch<{ data: PrintTemplateVersion[] }>(hqUrl(brandSlug, `/${kind}/history`)),

  /** `from = 0` compares against the system default — the honest baseline. */
  diff: (brandSlug: string, kind: string, from: number, to?: number) => {
    const params = new URLSearchParams({ from: String(from) });
    if (to !== undefined) params.set("to", String(to));
    return apiFetch<{ data: PrintTemplateDiff }>(
      hqUrl(brandSlug, `/${kind}/diff?${params.toString()}`)
    );
  },

  /**
   * The slip as the PRINTER's renderer draws it, returned as SVG source.
   *
   * This is the only preview path. Admin-web used to redraw the slip in
   * TypeScript, which put two implementations of the same layout rules in the
   * codebase — and the one a brand approves from was the one that never
   * touched a printer.
   */
  preview: (brandSlug: string, kind: string, input: PreviewInput) =>
    apiFetch(hqUrl(brandSlug, `/${kind}/preview${previewQuery(input)}`), {
      method: "POST",
      body: JSON.stringify({ definition: input.definition }),
      responseType: "text",
    }),

  saveDraft: (brandSlug: string, kind: string, input: SaveDraftInput) =>
    apiFetch<{ data: PrintTemplateVersion }>(hqUrl(brandSlug, `/${kind}/draft`), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  publish: (brandSlug: string, kind: string, input: PublishInput) =>
    apiFetch<{ data: PrintTemplateVersion }>(hqUrl(brandSlug, `/${kind}/publish`), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  retire: (brandSlug: string, kind: string, versionId: string) =>
    apiFetch<{ data: PrintTemplateVersion }>(
      hqUrl(brandSlug, `/${kind}/versions/${versionId}/retire`),
      { method: "POST" }
    ),

  /** TR-38 — republish an old definition as a NEW version, never an un-publish. */
  rollback: (brandSlug: string, kind: string, versionId: string, effectiveFrom?: string | null) =>
    apiFetch<{ data: PrintTemplateVersion }>(
      hqUrl(brandSlug, `/${kind}/versions/${versionId}/rollback`),
      { method: "POST", body: JSON.stringify({ effective_from: effectiveFrom ?? null }) }
    ),
};

export const printTemplateShopService = {
  list: (shopSlug: string) => apiFetch<{ data: ShopPrintTemplateSummary[] }>(shopUrl(shopSlug)),

  get: (shopSlug: string, kind: string) =>
    apiFetch<{ data: ShopPrintTemplateDetail }>(shopUrl(shopSlug, `/${kind}`)),

  /**
   * The shop editor holds the whole RESOLVED slip, so it posts the whole thing;
   * the backend filters it through the brand allow-list before rendering, the
   * same way publish does. A preview that showed an edit publish would strip
   * would just move the disagreement one layer down.
   */
  preview: (shopSlug: string, kind: string, input: PreviewInput) =>
    apiFetch(shopUrl(shopSlug, `/${kind}/preview${previewQuery(input)}`), {
      method: "POST",
      body: JSON.stringify({ definition: input.definition }),
      responseType: "text",
    }),

  saveDraft: (shopSlug: string, kind: string, input: SaveDraftInput) =>
    apiFetch<{ data: ShopPrintTemplateDetail["draft"] }>(shopUrl(shopSlug, `/${kind}/draft`), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  publish: (shopSlug: string, kind: string, input: PublishInput) =>
    apiFetch<{ data: { id: string; version: number; status: string } }>(
      shopUrl(shopSlug, `/${kind}/publish`),
      { method: "POST", body: JSON.stringify(input) }
    ),
};
