/**
 * FloatingSection Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { FloatingSection as FloatingSectionBase } from './base/FloatingSection';
import type { FloatingSectionProduct } from './FloatingSectionProduct';
import {
  baseFloatingSectionSchemas,
  baseFloatingSectionCreateSchema,
  baseFloatingSectionUpdateSchema,
  floatingSectionI18n,
  getFloatingSectionLabel,
  getFloatingSectionFieldLabel,
  getFloatingSectionFieldPlaceholder,
} from './base/FloatingSection';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface FloatingSection extends Omit<FloatingSectionBase, 'products'> {
  /** Distinct product count — present when the list/detail endpoint eager-counts it. */
  products_count?: number;
  // Uses the editable FloatingSectionProduct sibling (richer `product` field)
  // instead of the omnify base one — see FloatingSectionProduct.ts.
  products?: FloatingSectionProduct[];
  // Per-locale name (Astrotomic). Hand-declared because the omnify TS codegen
  // does not emit locale fields yet (omnify-jp/omnify-go#53) — mirrors
  // menu-service.ts. Top-level `name` still holds the default-locale mirror.
  translations?: Record<string, { name?: string | null }>;
}

// ============================================================================
// Service input types (used by floating-section-service.ts)
// ============================================================================

export interface FloatingSectionFilters {
  page?: number;
  per_page?: number;
  search?: string;
  is_active?: boolean;
}

export interface CreateFloatingSectionInput {
  name: string;
  is_active?: boolean;
  priority?: number;
  start_date?: string | null;
  end_date?: string | null;
  // Per-locale translation payload (Astrotomic) — mirrors CreateMenuInput.
  // Backend validates ja.name/en.name/vi.name; top-level `name` is the
  // default-locale mirror. Built via buildI18nPayload() in the form dialog.
  ja?: Record<string, string>;
  en?: Record<string, string>;
  vi?: Record<string, string>;
}

export interface UpdateFloatingSectionInput {
  name?: string;
  is_active?: boolean;
  priority?: number;
  start_date?: string | null;
  end_date?: string | null;
  ja?: Record<string, string>;
  en?: Record<string, string>;
  vi?: Record<string, string>;
}

export interface AddFloatingSectionProductsInput {
  product_ids: string[];
}

export interface ReorderFloatingSectionProductsInput {
  ordered_ids: string[];
}

export interface CloneFloatingSectionToBranchInput {
  branch_id: string;
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const floatingSectionSchemas = { ...baseFloatingSectionSchemas };
export const floatingSectionCreateSchema = baseFloatingSectionCreateSchema;
export const floatingSectionUpdateSchema = baseFloatingSectionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type FloatingSectionCreate = z.infer<typeof floatingSectionCreateSchema>;
export type FloatingSectionUpdate = z.infer<typeof floatingSectionUpdateSchema>;

// Re-export i18n and helpers
export {
  floatingSectionI18n,
  getFloatingSectionLabel,
  getFloatingSectionFieldLabel,
  getFloatingSectionFieldPlaceholder,
};

// Re-export base type for internal use
export type { FloatingSectionBase };
