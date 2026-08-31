/**
 * HQ table-defaults domain types (issue #890) — brand-scoped zone/table
 * TEMPLATES that a shop copies into real zones/tables via
 * POST /api/v1/shops/{shopSlug}/tables/defaults/apply.
 */

// ============================================================================
// Zone Template
// ============================================================================

/** Target branch embedded on template resources. Null = all branches. */
export interface TemplateBranchSummary {
  id: string;
  name: string;
}

/** Shape of a ZoneTemplate as returned by the backend ZoneTemplateResource JSON. */
export interface ZoneTemplateResource {
  id: string;
  code: string;
  name: string;
  description: string | null;
  display_order: number;
  is_active: boolean;
  /** Chi nhánh áp dụng — null = tất cả chi nhánh (brand-wide). */
  branch?: TemplateBranchSummary | null;
  table_templates_count?: number;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export interface CreateZoneTemplateInput {
  code: string;
  name: string;
  description?: string | null;
  display_order?: number;
  /** Chi nhánh áp dụng — null/omitted = tất cả chi nhánh. */
  branch_id?: string | null;
}

export interface UpdateZoneTemplateInput {
  code?: string;
  name?: string;
  description?: string | null;
  display_order?: number;
  is_active?: boolean;
  branch_id?: string | null;
}

export interface ZoneTemplateFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
  with_trashed?: boolean;
  sort?: string;
}

// ============================================================================
// Table Template
// ============================================================================

/** Embedded zone template on a TableTemplateResource. */
export interface TableTemplateZoneSummary {
  id: string;
  code: string;
  name: string;
}

/** Shape of a TableTemplate as returned by the backend TableTemplateResource JSON. */
export interface TableTemplateResource {
  id: string;
  code: string;
  name: string | null;
  seat_count: number;
  is_active: boolean;
  /** Chi nhánh áp dụng — null = tất cả chi nhánh (brand-wide). */
  branch?: TemplateBranchSummary | null;
  zone_template: TableTemplateZoneSummary;
  created_at: string;
  updated_at: string;
  deleted_at?: string | null;
}

export interface CreateTableTemplateInput {
  zone_template_id: string;
  code: string;
  name?: string | null;
  seat_count?: number;
  /** Chi nhánh áp dụng — null/omitted = tất cả chi nhánh. */
  branch_id?: string | null;
}

export interface UpdateTableTemplateInput {
  zone_template_id?: string;
  code?: string;
  name?: string | null;
  seat_count?: number;
  is_active?: boolean;
  branch_id?: string | null;
}

export interface TableTemplateFilters {
  page?: number;
  per_page?: number;
  zone_template_id?: string;
  search?: string;
  is_active?: boolean;
  with_trashed?: boolean;
  sort?: string;
}

// ============================================================================
// Shop-side "apply HQ defaults"
// ============================================================================

/** GET /shops/{shopSlug}/tables/defaults/preview */
export interface TableDefaultsPreview {
  zones: { create: number; skip: number };
  tables: { create: number; skip: number };
}

/** POST /shops/{shopSlug}/tables/defaults/apply */
export interface TableDefaultsApplyResult {
  zones_created: number;
  zones_skipped: number;
  tables_created: number;
  tables_skipped: number;
}
