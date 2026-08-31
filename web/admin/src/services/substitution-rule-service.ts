/**
 * Substitution Rule Service — pure TypeScript, no React dependency.
 *
 * CRUD for material substitution rules (HQ brand scope):
 *   GET    /api/v1/hq/{brandSlug}/material-substitution-rules?primary_material_id=...
 *   POST   /api/v1/hq/{brandSlug}/material-substitution-rules
 *   PUT    /api/v1/hq/{brandSlug}/material-substitution-rules/{id}
 *   DELETE /api/v1/hq/{brandSlug}/material-substitution-rules/{id}
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";

// =========================================================================
//  Types
// =========================================================================

export interface SubstitutionRule {
  id: string;
  primary_material_id: string;
  substitute_material_id: string;
  conversion_factor: number | string;
  priority: number;
  is_active: boolean;
  notes: string | null;
  organization_id: string;
  brand_id: string;
  created_at: string;
  updated_at: string;
  /** Eager-loaded substitute material (when backend includes it). */
  substitute_material?: { id: string; sku: string | null; name?: string };
}

export interface SubstitutionRuleInput {
  primary_material_id: string;
  substitute_material_id: string;
  conversion_factor: number;
  priority?: number;
  is_active?: boolean;
  notes?: string | null;
}

// =========================================================================
//  Helpers
// =========================================================================

function hqUrl(brandSlug: string, path = ""): string {
  return `/api/v1/hq/${brandSlug}/material-substitution-rules${path}`;
}

// =========================================================================
//  Service
// =========================================================================

export const substitutionRuleService = {
  list: (brandSlug: string, materialId: string) => {
    const params = new URLSearchParams({
      primary_material_id: materialId,
      per_page: "100",
    });
    return apiFetch<PaginatedResponse<SubstitutionRule>>(
      `${hqUrl(brandSlug)}?${params.toString()}`
    );
  },

  create: (brandSlug: string, input: SubstitutionRuleInput) =>
    apiFetch<{ data: SubstitutionRule }>(hqUrl(brandSlug), {
      method: "POST",
      body: JSON.stringify(input),
    }),

  update: (brandSlug: string, id: string, input: Partial<SubstitutionRuleInput>) =>
    apiFetch<{ data: SubstitutionRule }>(hqUrl(brandSlug, `/${id}`), {
      method: "PUT",
      body: JSON.stringify(input),
    }),

  delete: (brandSlug: string, id: string) =>
    apiFetch<void>(hqUrl(brandSlug, `/${id}`), { method: "DELETE" }),
};
