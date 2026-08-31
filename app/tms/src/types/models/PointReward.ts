/**
 * PointReward Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PointReward as PointRewardBase } from './base/PointReward';
import {
  basePointRewardSchemas,
  basePointRewardCreateSchema,
  basePointRewardUpdateSchema,
  pointRewardI18n,
  getPointRewardLabel,
  getPointRewardFieldLabel,
  getPointRewardFieldPlaceholder,
} from './base/PointReward';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PointReward extends PointRewardBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const pointRewardSchemas = { ...basePointRewardSchemas };
export const pointRewardCreateSchema = basePointRewardCreateSchema;
export const pointRewardUpdateSchema = basePointRewardUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PointRewardCreate = z.infer<typeof pointRewardCreateSchema>;
export type PointRewardUpdate = z.infer<typeof pointRewardUpdateSchema>;

// Re-export i18n and helpers
export {
  pointRewardI18n,
  getPointRewardLabel,
  getPointRewardFieldLabel,
  getPointRewardFieldPlaceholder,
};

// Re-export base type for internal use
export type { PointRewardBase };
