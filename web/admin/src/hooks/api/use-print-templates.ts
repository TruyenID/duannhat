/**
 * Print template hooks — plan-053 M4 (#1171).
 *
 * Write paths surface backend conflicts VERBATIM instead of retrying: a 409
 * `PRINT_TEMPLATE_DRAFT_STALE` means another editor changed this draft and the
 * loser has to reload (TR-09 — auto-merging two layouts produces a slip nobody
 * designed), and a 422 `PRINT_TEMPLATE_INVALID` carries every violation at once
 * so the author fixes them in one pass.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ApiError } from "@/lib/api";
import {
  printTemplateBrandService,
  printTemplateShopService,
  type PreviewInput,
  type PublishInput,
  type SaveDraftInput,
} from "@/services/print-template-service";
import type { PrintTemplateViolation } from "@/types/models/PrintTemplate";

export const printTemplateKeys = {
  brandAll: (brandSlug: string) => ["print-templates", "brand", brandSlug] as const,
  brandList: (brandSlug: string) => ["print-templates", "brand", brandSlug, "list"] as const,
  brandDetail: (brandSlug: string, kind: string) =>
    ["print-templates", "brand", brandSlug, "detail", kind] as const,
  brandHistory: (brandSlug: string, kind: string) =>
    ["print-templates", "brand", brandSlug, "history", kind] as const,
  brandDiff: (brandSlug: string, kind: string, from: number, to?: number) =>
    ["print-templates", "brand", brandSlug, "diff", kind, from, to ?? "latest"] as const,
  shopAll: (shopSlug: string) => ["print-templates", "shop", shopSlug] as const,
  shopList: (shopSlug: string) => ["print-templates", "shop", shopSlug, "list"] as const,
  shopDetail: (shopSlug: string, kind: string) =>
    ["print-templates", "shop", shopSlug, "detail", kind] as const,
  /**
   * The DEFINITION is part of the key, not just the slug: one definition always
   * renders to one slip, so a rendered preview never goes stale — it is only
   * ever superseded by a different definition. That is why the query below can
   * be `staleTime: Infinity` and why flipping tabs costs no request.
   */
  preview: (
    scope: "brand" | "shop",
    slug: string,
    kind: string,
    input: PreviewInput | null
  ) =>
    [
      "print-templates",
      scope,
      slug,
      "preview",
      kind,
      input?.paper ?? null,
      input?.locale ?? null,
      input?.definition ?? null,
    ] as const,
};

// =========================================================================
//  Error helpers — the two conflict shapes the editor must render
// =========================================================================

/** Publish-validation violations, or `null` when the error is something else. */
export function violationsOf(error: unknown): PrintTemplateViolation[] | null {
  if (!(error instanceof ApiError) || error.status !== 422) return null;
  const errors = error.body?.errors;
  return Array.isArray(errors) ? (errors as PrintTemplateViolation[]) : null;
}

/** The 409 conflict code (`PRINT_TEMPLATE_DRAFT_STALE` …), or `null`. */
export function conflictCodeOf(error: unknown): string | null {
  if (!(error instanceof ApiError) || error.status !== 409) return null;
  const code = error.body?.code;
  return typeof code === "string" ? code : "PRINT_TEMPLATE_CONFLICT";
}

// =========================================================================
//  Brand (HQ) layer
// =========================================================================

export function useBrandPrintTemplates(brandSlug: string) {
  return useQuery({
    queryKey: printTemplateKeys.brandList(brandSlug),
    queryFn: () => printTemplateBrandService.list(brandSlug),
    enabled: !!brandSlug,
  });
}

export function useBrandPrintTemplate(brandSlug: string, kind: string) {
  return useQuery({
    queryKey: printTemplateKeys.brandDetail(brandSlug, kind),
    queryFn: () => printTemplateBrandService.get(brandSlug, kind),
    enabled: !!brandSlug && !!kind,
  });
}

export function useBrandPrintTemplateHistory(brandSlug: string, kind: string) {
  return useQuery({
    queryKey: printTemplateKeys.brandHistory(brandSlug, kind),
    queryFn: () => printTemplateBrandService.history(brandSlug, kind),
    enabled: !!brandSlug && !!kind,
  });
}

export function useBrandPrintTemplateDiff(
  brandSlug: string,
  kind: string,
  from: number,
  to: number | undefined,
  enabled = true
) {
  return useQuery({
    queryKey: printTemplateKeys.brandDiff(brandSlug, kind, from, to),
    queryFn: () => printTemplateBrandService.diff(brandSlug, kind, from, to),
    enabled: enabled && !!brandSlug && !!kind,
  });
}

function useBrandInvalidator(brandSlug: string, kind: string) {
  const qc = useQueryClient();
  return () => {
    void qc.invalidateQueries({ queryKey: printTemplateKeys.brandAll(brandSlug) });
    void qc.invalidateQueries({ queryKey: printTemplateKeys.brandDetail(brandSlug, kind) });
  };
}

export function useSaveBrandPrintTemplateDraft(brandSlug: string, kind: string) {
  const invalidate = useBrandInvalidator(brandSlug, kind);
  return useMutation({
    mutationFn: (input: SaveDraftInput) =>
      printTemplateBrandService.saveDraft(brandSlug, kind, input),
    onSuccess: invalidate,
  });
}

export function usePublishBrandPrintTemplate(brandSlug: string, kind: string) {
  const invalidate = useBrandInvalidator(brandSlug, kind);
  return useMutation({
    mutationFn: (input: PublishInput) => printTemplateBrandService.publish(brandSlug, kind, input),
    onSuccess: invalidate,
  });
}

export function useRetireBrandPrintTemplate(brandSlug: string, kind: string) {
  const invalidate = useBrandInvalidator(brandSlug, kind);
  return useMutation({
    mutationFn: (versionId: string) => printTemplateBrandService.retire(brandSlug, kind, versionId),
    onSuccess: invalidate,
  });
}

export function useRollbackBrandPrintTemplate(brandSlug: string, kind: string) {
  const invalidate = useBrandInvalidator(brandSlug, kind);
  return useMutation({
    mutationFn: (vars: { versionId: string; effectiveFrom?: string | null }) =>
      printTemplateBrandService.rollback(brandSlug, kind, vars.versionId, vars.effectiveFrom),
    onSuccess: invalidate,
  });
}

// =========================================================================
//  Shop (branch override) layer
// =========================================================================

export function useShopPrintTemplates(shopSlug: string) {
  return useQuery({
    queryKey: printTemplateKeys.shopList(shopSlug),
    queryFn: () => printTemplateShopService.list(shopSlug),
    enabled: !!shopSlug,
  });
}

export function useShopPrintTemplate(shopSlug: string, kind: string) {
  return useQuery({
    queryKey: printTemplateKeys.shopDetail(shopSlug, kind),
    queryFn: () => printTemplateShopService.get(shopSlug, kind),
    enabled: !!shopSlug && !!kind,
  });
}

function useShopInvalidator(shopSlug: string, kind: string) {
  const qc = useQueryClient();
  return () => {
    void qc.invalidateQueries({ queryKey: printTemplateKeys.shopAll(shopSlug) });
    void qc.invalidateQueries({ queryKey: printTemplateKeys.shopDetail(shopSlug, kind) });
  };
}

export function useSaveShopPrintTemplateDraft(shopSlug: string, kind: string) {
  const invalidate = useShopInvalidator(shopSlug, kind);
  return useMutation({
    mutationFn: (input: SaveDraftInput) =>
      printTemplateShopService.saveDraft(shopSlug, kind, input),
    onSuccess: invalidate,
  });
}

export function usePublishShopPrintTemplate(shopSlug: string, kind: string) {
  const invalidate = useShopInvalidator(shopSlug, kind);
  return useMutation({
    mutationFn: (input: PublishInput) => printTemplateShopService.publish(shopSlug, kind, input),
    onSuccess: invalidate,
  });
}

// =========================================================================
//  Preview (TR-32, T4.3) — the slip drawn by the PRINTER's renderer
// =========================================================================

/**
 * Fetch the SVG preview of `definition` from the server.
 *
 * There is no client-side fallback, deliberately. Admin-web shipped one for a
 * milestone — a TypeScript re-implementation of the layout rules — and it was
 * the preview a brand approved from while the printer followed different code.
 * Two renderers drift; the drift lands on the screen whose whole job is to be
 * trusted. If the endpoint is unreachable the panel says so and offers a retry,
 * which is a worse preview than none only if you believe a wrong one is better.
 *
 * `retry: false` because the two ways this fails — 403 and a definition the
 * server rejects — are both answers, not blips, and re-asking hides them.
 */
export function usePrintTemplatePreview(
  scope: "brand" | "shop",
  slug: string,
  kind: string,
  input: PreviewInput | null
) {
  return useQuery({
    queryKey: printTemplateKeys.preview(scope, slug, kind, input),
    queryFn: () =>
      scope === "brand"
        ? printTemplateBrandService.preview(slug, kind, input as PreviewInput)
        : printTemplateShopService.preview(slug, kind, input as PreviewInput),
    enabled: !!slug && !!kind && input !== null,
    // One definition, one slip — a cached render can never be out of date.
    staleTime: Infinity,
    retry: false,
  });
}
