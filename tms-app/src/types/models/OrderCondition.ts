/**
 * OrderCondition Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { OrderCondition as OrderConditionBase } from './base/OrderCondition';
import {
  baseOrderConditionSchemas,
  baseOrderConditionCreateSchema,
  baseOrderConditionUpdateSchema,
  orderConditionI18n,
  getOrderConditionLabel,
  getOrderConditionFieldLabel,
  getOrderConditionFieldPlaceholder,
} from './base/OrderCondition';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface OrderCondition extends OrderConditionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const orderConditionSchemas = { ...baseOrderConditionSchemas };
export const orderConditionCreateSchema = baseOrderConditionCreateSchema;
export const orderConditionUpdateSchema = baseOrderConditionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type OrderConditionCreate = z.infer<typeof orderConditionCreateSchema>;
export type OrderConditionUpdate = z.infer<typeof orderConditionUpdateSchema>;

// Re-export i18n and helpers
export {
  orderConditionI18n,
  getOrderConditionLabel,
  getOrderConditionFieldLabel,
  getOrderConditionFieldPlaceholder,
};

// Re-export base type for internal use
export type { OrderConditionBase };
