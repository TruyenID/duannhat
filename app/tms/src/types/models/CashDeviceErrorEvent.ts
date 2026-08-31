/**
 * CashDeviceErrorEvent Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { CashDeviceErrorEvent as CashDeviceErrorEventBase } from './base/CashDeviceErrorEvent';
import {
  baseCashDeviceErrorEventSchemas,
  baseCashDeviceErrorEventCreateSchema,
  baseCashDeviceErrorEventUpdateSchema,
  cashDeviceErrorEventI18n,
  getCashDeviceErrorEventLabel,
  getCashDeviceErrorEventFieldLabel,
  getCashDeviceErrorEventFieldPlaceholder,
} from './base/CashDeviceErrorEvent';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CashDeviceErrorEvent extends CashDeviceErrorEventBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const cashDeviceErrorEventSchemas = { ...baseCashDeviceErrorEventSchemas };
export const cashDeviceErrorEventCreateSchema = baseCashDeviceErrorEventCreateSchema;
export const cashDeviceErrorEventUpdateSchema = baseCashDeviceErrorEventUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CashDeviceErrorEventCreate = z.infer<typeof cashDeviceErrorEventCreateSchema>;
export type CashDeviceErrorEventUpdate = z.infer<typeof cashDeviceErrorEventUpdateSchema>;

// Re-export i18n and helpers
export {
  cashDeviceErrorEventI18n,
  getCashDeviceErrorEventLabel,
  getCashDeviceErrorEventFieldLabel,
  getCashDeviceErrorEventFieldPlaceholder,
};

// Re-export base type for internal use
export type { CashDeviceErrorEventBase };
