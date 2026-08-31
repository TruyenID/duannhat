/**
 * GatewayPayout Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { GatewayPayout as GatewayPayoutBase } from './base/GatewayPayout';
import {
  baseGatewayPayoutSchemas,
  baseGatewayPayoutCreateSchema,
  baseGatewayPayoutUpdateSchema,
  gatewayPayoutI18n,
  getGatewayPayoutLabel,
  getGatewayPayoutFieldLabel,
  getGatewayPayoutFieldPlaceholder,
} from './base/GatewayPayout';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface GatewayPayout extends GatewayPayoutBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const gatewayPayoutSchemas = { ...baseGatewayPayoutSchemas };
export const gatewayPayoutCreateSchema = baseGatewayPayoutCreateSchema;
export const gatewayPayoutUpdateSchema = baseGatewayPayoutUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type GatewayPayoutCreate = z.infer<typeof gatewayPayoutCreateSchema>;
export type GatewayPayoutUpdate = z.infer<typeof gatewayPayoutUpdateSchema>;

// Re-export i18n and helpers
export {
  gatewayPayoutI18n,
  getGatewayPayoutLabel,
  getGatewayPayoutFieldLabel,
  getGatewayPayoutFieldPlaceholder,
};

// Re-export base type for internal use
export type { GatewayPayoutBase };
