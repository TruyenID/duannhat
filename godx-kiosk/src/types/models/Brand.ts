/**
 * Brand Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Brand as BrandBase } from './base/Brand';
import {
  baseBrandSchemas,
  baseBrandCreateSchema,
  baseBrandUpdateSchema,
  brandI18n,
  getBrandLabel,
  getBrandFieldLabel,
  getBrandFieldPlaceholder,
} from './base/Brand';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Brand extends BrandBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const brandSchemas = { ...baseBrandSchemas };
export const brandCreateSchema = baseBrandCreateSchema;
export const brandUpdateSchema = baseBrandUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type BrandCreate = z.infer<typeof brandCreateSchema>;
export type BrandUpdate = z.infer<typeof brandUpdateSchema>;

// Re-export i18n and helpers
export {
  brandI18n,
  getBrandLabel,
  getBrandFieldLabel,
  getBrandFieldPlaceholder,
};

// Re-export base type for internal use
export type { BrandBase };
