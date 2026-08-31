/**
 * Material Unit Service — pure TypeScript, no React dependency.
 *
 * Nested under a parent material:
 *   GET    /api/v1/hq/{brandSlug}/materials/{material}/units
 *   POST   /api/v1/hq/{brandSlug}/materials/{material}/units
 *   PUT    /api/v1/hq/{brandSlug}/materials/{material}/units/{unit}
 *   DELETE /api/v1/hq/{brandSlug}/materials/{material}/units/{unit}
 */

import { apiFetch } from "@/lib/api";

export interface MaterialUnit {
  id: string;
  material_id: string;
  unit: string;
  ratio: number | string;
  is_base: boolean;
  created_at: string;
  updated_at: string;
}

export interface MaterialUnitInput {
  unit: string;
  ratio: number;
  is_base?: boolean;
}

function url(brandSlug: string, materialId: string, suffix = ""): string {
  return `/api/v1/hq/${brandSlug}/materials/${materialId}/units${suffix}`;
}

export const materialUnitService = {
  list: (brandSlug: string, materialId: string) =>
    apiFetch<{ data: MaterialUnit[] }>(url(brandSlug, materialId)),

  create: (brandSlug: string, materialId: string, input: MaterialUnitInput) =>
    apiFetch<{ data: MaterialUnit }>(url(brandSlug, materialId), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  update: (
    brandSlug: string,
    materialId: string,
    unitId: string,
    input: Partial<MaterialUnitInput>
  ) =>
    apiFetch<{ data: MaterialUnit }>(url(brandSlug, materialId, `/${unitId}`), {
      method: "PUT",
      body: JSON.stringify(input),
    }),

  delete: (brandSlug: string, materialId: string, unitId: string) =>
    apiFetch<null>(url(brandSlug, materialId, `/${unitId}`), { method: "DELETE" }),
};
