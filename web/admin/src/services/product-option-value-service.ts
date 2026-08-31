/**
 * Product Option Value Service — pure TypeScript, no React dependency.
 *
 * URL convention:
 *   GET    /api/v1/hq/{brandSlug}/product-options/{optionId}/values
 *   POST   /api/v1/hq/{brandSlug}/product-options/{optionId}/values
 *   GET    /api/v1/hq/{brandSlug}/product-option-values/{valueId}
 *   PUT    /api/v1/hq/{brandSlug}/product-option-values/{valueId}
 *   DELETE /api/v1/hq/{brandSlug}/product-option-values/{valueId}
 *
 * Hooks live in src/hooks/api/use-product-option-values.ts.
 */

import { apiFetch } from "@/lib/api";
import type { ProductOptionValue } from "./product-service";

export type { ProductOptionValue };

export interface CreateProductOptionValueInput {
  value: string;
  label: string;
  position?: number;
  is_active?: boolean;
}

export interface UpdateProductOptionValueInput {
  value?: string;
  label?: string;
  position?: number;
  is_active?: boolean;
}

function nestedUrl(brandSlug: string, optionId: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/product-options/${optionId}/values${path}`;
}

function flatUrl(brandSlug: string, valueId: string, path: string = ""): string {
  return `/api/v1/hq/${brandSlug}/product-option-values/${valueId}${path}`;
}

export const productOptionValueService = {
  list: (brandSlug: string, optionId: string) =>
    apiFetch<{ data: ProductOptionValue[] }>(nestedUrl(brandSlug, optionId)),

  getById: (brandSlug: string, valueId: string) =>
    apiFetch<{ data: ProductOptionValue }>(flatUrl(brandSlug, valueId)),

  create: (brandSlug: string, optionId: string, data: CreateProductOptionValueInput) =>
    apiFetch<{ data: ProductOptionValue }>(nestedUrl(brandSlug, optionId), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, valueId: string, data: UpdateProductOptionValueInput) =>
    apiFetch<{ data: ProductOptionValue }>(flatUrl(brandSlug, valueId), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, valueId: string) =>
    apiFetch<null>(flatUrl(brandSlug, valueId), { method: "DELETE" }),

  forceDelete: (brandSlug: string, valueId: string) =>
    apiFetch<null>(flatUrl(brandSlug, valueId, "?force=true"), { method: "DELETE" }),
};
