/**
 * BrandOrderPolicy Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { BrandOrderPolicy as BrandOrderPolicyBase } from './base/BrandOrderPolicy';
import {
  baseBrandOrderPolicySchemas,
  baseBrandOrderPolicyCreateSchema,
  baseBrandOrderPolicyUpdateSchema,
  brandOrderPolicyI18n,
  getBrandOrderPolicyLabel,
  getBrandOrderPolicyFieldLabel,
  getBrandOrderPolicyFieldPlaceholder,
} from './base/BrandOrderPolicy';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface BrandOrderPolicy extends BrandOrderPolicyBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const brandOrderPolicySchemas = { ...baseBrandOrderPolicySchemas };
export const brandOrderPolicyCreateSchema = baseBrandOrderPolicyCreateSchema;
export const brandOrderPolicyUpdateSchema = baseBrandOrderPolicyUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type BrandOrderPolicyCreate = z.infer<typeof brandOrderPolicyCreateSchema>;
export type BrandOrderPolicyUpdate = z.infer<typeof brandOrderPolicyUpdateSchema>;

// Re-export i18n and helpers
export {
  brandOrderPolicyI18n,
  getBrandOrderPolicyLabel,
  getBrandOrderPolicyFieldLabel,
  getBrandOrderPolicyFieldPlaceholder,
};

// Re-export base type for internal use
export type { BrandOrderPolicyBase };
