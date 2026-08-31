/**
 * GenealogyLink Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { GenealogyLink as GenealogyLinkBase } from './base/GenealogyLink';
import {
  baseGenealogyLinkSchemas,
  baseGenealogyLinkCreateSchema,
  baseGenealogyLinkUpdateSchema,
  genealogyLinkI18n,
  getGenealogyLinkLabel,
  getGenealogyLinkFieldLabel,
  getGenealogyLinkFieldPlaceholder,
} from './base/GenealogyLink';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface GenealogyLink extends GenealogyLinkBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const genealogyLinkSchemas = { ...baseGenealogyLinkSchemas };
export const genealogyLinkCreateSchema = baseGenealogyLinkCreateSchema;
export const genealogyLinkUpdateSchema = baseGenealogyLinkUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type GenealogyLinkCreate = z.infer<typeof genealogyLinkCreateSchema>;
export type GenealogyLinkUpdate = z.infer<typeof genealogyLinkUpdateSchema>;

// Re-export i18n and helpers
export {
  genealogyLinkI18n,
  getGenealogyLinkLabel,
  getGenealogyLinkFieldLabel,
  getGenealogyLinkFieldPlaceholder,
};

// Re-export base type for internal use
export type { GenealogyLinkBase };
