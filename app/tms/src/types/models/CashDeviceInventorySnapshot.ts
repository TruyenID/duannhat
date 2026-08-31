/**
 * CashDeviceInventorySnapshot Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { CashDeviceInventorySnapshot as CashDeviceInventorySnapshotBase } from './base/CashDeviceInventorySnapshot';
import {
  baseCashDeviceInventorySnapshotSchemas,
  baseCashDeviceInventorySnapshotCreateSchema,
  baseCashDeviceInventorySnapshotUpdateSchema,
  cashDeviceInventorySnapshotI18n,
  getCashDeviceInventorySnapshotLabel,
  getCashDeviceInventorySnapshotFieldLabel,
  getCashDeviceInventorySnapshotFieldPlaceholder,
} from './base/CashDeviceInventorySnapshot';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CashDeviceInventorySnapshot extends CashDeviceInventorySnapshotBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const cashDeviceInventorySnapshotSchemas = { ...baseCashDeviceInventorySnapshotSchemas };
export const cashDeviceInventorySnapshotCreateSchema = baseCashDeviceInventorySnapshotCreateSchema;
export const cashDeviceInventorySnapshotUpdateSchema = baseCashDeviceInventorySnapshotUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CashDeviceInventorySnapshotCreate = z.infer<typeof cashDeviceInventorySnapshotCreateSchema>;
export type CashDeviceInventorySnapshotUpdate = z.infer<typeof cashDeviceInventorySnapshotUpdateSchema>;

// Re-export i18n and helpers
export {
  cashDeviceInventorySnapshotI18n,
  getCashDeviceInventorySnapshotLabel,
  getCashDeviceInventorySnapshotFieldLabel,
  getCashDeviceInventorySnapshotFieldPlaceholder,
};

// Re-export base type for internal use
export type { CashDeviceInventorySnapshotBase };
