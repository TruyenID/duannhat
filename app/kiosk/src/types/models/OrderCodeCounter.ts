/**
 * OrderCodeCounter Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { OrderCodeCounter as OrderCodeCounterBase } from './base/OrderCodeCounter';
import {
  baseOrderCodeCounterSchemas,
  baseOrderCodeCounterCreateSchema,
  baseOrderCodeCounterUpdateSchema,
  orderCodeCounterI18n,
  getOrderCodeCounterLabel,
  getOrderCodeCounterFieldLabel,
  getOrderCodeCounterFieldPlaceholder,
} from './base/OrderCodeCounter';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface OrderCodeCounter extends OrderCodeCounterBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const orderCodeCounterSchemas = { ...baseOrderCodeCounterSchemas };
export const orderCodeCounterCreateSchema = baseOrderCodeCounterCreateSchema;
export const orderCodeCounterUpdateSchema = baseOrderCodeCounterUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type OrderCodeCounterCreate = z.infer<typeof orderCodeCounterCreateSchema>;
export type OrderCodeCounterUpdate = z.infer<typeof orderCodeCounterUpdateSchema>;

// Re-export i18n and helpers
export {
  orderCodeCounterI18n,
  getOrderCodeCounterLabel,
  getOrderCodeCounterFieldLabel,
  getOrderCodeCounterFieldPlaceholder,
};

// Re-export base type for internal use
export type { OrderCodeCounterBase };
