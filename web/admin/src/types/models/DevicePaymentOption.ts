/**
 * DevicePaymentOption Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { DevicePaymentOption as DevicePaymentOptionBase } from './base/DevicePaymentOption';
import {
  baseDevicePaymentOptionSchemas,
  baseDevicePaymentOptionCreateSchema,
  baseDevicePaymentOptionUpdateSchema,
  devicePaymentOptionI18n,
  getDevicePaymentOptionLabel,
  getDevicePaymentOptionFieldLabel,
  getDevicePaymentOptionFieldPlaceholder,
} from './base/DevicePaymentOption';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface DevicePaymentOption extends DevicePaymentOptionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const devicePaymentOptionSchemas = { ...baseDevicePaymentOptionSchemas };
export const devicePaymentOptionCreateSchema = baseDevicePaymentOptionCreateSchema;
export const devicePaymentOptionUpdateSchema = baseDevicePaymentOptionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type DevicePaymentOptionCreate = z.infer<typeof devicePaymentOptionCreateSchema>;
export type DevicePaymentOptionUpdate = z.infer<typeof devicePaymentOptionUpdateSchema>;

// Re-export i18n and helpers
export {
  devicePaymentOptionI18n,
  getDevicePaymentOptionLabel,
  getDevicePaymentOptionFieldLabel,
  getDevicePaymentOptionFieldPlaceholder,
};

// Re-export base type for internal use
export type { DevicePaymentOptionBase };
