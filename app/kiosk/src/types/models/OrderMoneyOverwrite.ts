/**
 * OrderMoneyOverwrite Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { OrderMoneyOverwrite as OrderMoneyOverwriteBase } from './base/OrderMoneyOverwrite';
import {
  baseOrderMoneyOverwriteSchemas,
  baseOrderMoneyOverwriteCreateSchema,
  baseOrderMoneyOverwriteUpdateSchema,
  orderMoneyOverwriteI18n,
  getOrderMoneyOverwriteLabel,
  getOrderMoneyOverwriteFieldLabel,
  getOrderMoneyOverwriteFieldPlaceholder,
} from './base/OrderMoneyOverwrite';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface OrderMoneyOverwrite extends OrderMoneyOverwriteBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const orderMoneyOverwriteSchemas = { ...baseOrderMoneyOverwriteSchemas };
export const orderMoneyOverwriteCreateSchema = baseOrderMoneyOverwriteCreateSchema;
export const orderMoneyOverwriteUpdateSchema = baseOrderMoneyOverwriteUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type OrderMoneyOverwriteCreate = z.infer<typeof orderMoneyOverwriteCreateSchema>;
export type OrderMoneyOverwriteUpdate = z.infer<typeof orderMoneyOverwriteUpdateSchema>;

// Re-export i18n and helpers
export {
  orderMoneyOverwriteI18n,
  getOrderMoneyOverwriteLabel,
  getOrderMoneyOverwriteFieldLabel,
  getOrderMoneyOverwriteFieldPlaceholder,
};

// Re-export base type for internal use
export type { OrderMoneyOverwriteBase };
