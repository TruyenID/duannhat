/**
 * ExpiryAlert Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ExpiryAlert as ExpiryAlertBase } from './base/ExpiryAlert';
import {
  baseExpiryAlertSchemas,
  baseExpiryAlertCreateSchema,
  baseExpiryAlertUpdateSchema,
  expiryAlertI18n,
  getExpiryAlertLabel,
  getExpiryAlertFieldLabel,
  getExpiryAlertFieldPlaceholder,
} from './base/ExpiryAlert';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ExpiryAlert extends ExpiryAlertBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const expiryAlertSchemas = { ...baseExpiryAlertSchemas };
export const expiryAlertCreateSchema = baseExpiryAlertCreateSchema;
export const expiryAlertUpdateSchema = baseExpiryAlertUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ExpiryAlertCreate = z.infer<typeof expiryAlertCreateSchema>;
export type ExpiryAlertUpdate = z.infer<typeof expiryAlertUpdateSchema>;

// Re-export i18n and helpers
export {
  expiryAlertI18n,
  getExpiryAlertLabel,
  getExpiryAlertFieldLabel,
  getExpiryAlertFieldPlaceholder,
};

// Re-export base type for internal use
export type { ExpiryAlertBase };
