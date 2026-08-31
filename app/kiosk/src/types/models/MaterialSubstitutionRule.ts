/**
 * MaterialSubstitutionRule Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { MaterialSubstitutionRule as MaterialSubstitutionRuleBase } from './base/MaterialSubstitutionRule';
import {
  baseMaterialSubstitutionRuleSchemas,
  baseMaterialSubstitutionRuleCreateSchema,
  baseMaterialSubstitutionRuleUpdateSchema,
  materialSubstitutionRuleI18n,
  getMaterialSubstitutionRuleLabel,
  getMaterialSubstitutionRuleFieldLabel,
  getMaterialSubstitutionRuleFieldPlaceholder,
} from './base/MaterialSubstitutionRule';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MaterialSubstitutionRule extends MaterialSubstitutionRuleBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const materialSubstitutionRuleSchemas = { ...baseMaterialSubstitutionRuleSchemas };
export const materialSubstitutionRuleCreateSchema = baseMaterialSubstitutionRuleCreateSchema;
export const materialSubstitutionRuleUpdateSchema = baseMaterialSubstitutionRuleUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MaterialSubstitutionRuleCreate = z.infer<typeof materialSubstitutionRuleCreateSchema>;
export type MaterialSubstitutionRuleUpdate = z.infer<typeof materialSubstitutionRuleUpdateSchema>;

// Re-export i18n and helpers
export {
  materialSubstitutionRuleI18n,
  getMaterialSubstitutionRuleLabel,
  getMaterialSubstitutionRuleFieldLabel,
  getMaterialSubstitutionRuleFieldPlaceholder,
};

// Re-export base type for internal use
export type { MaterialSubstitutionRuleBase };
