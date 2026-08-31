/**
 * ShopOrderSetting Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ShopOrderSetting as ShopOrderSettingBase } from './base/ShopOrderSetting';
import {
  baseShopOrderSettingSchemas,
  baseShopOrderSettingCreateSchema,
  baseShopOrderSettingUpdateSchema,
  shopOrderSettingI18n,
  getShopOrderSettingLabel,
  getShopOrderSettingFieldLabel,
  getShopOrderSettingFieldPlaceholder,
} from './base/ShopOrderSetting';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ShopOrderSetting extends ShopOrderSettingBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const shopOrderSettingSchemas = { ...baseShopOrderSettingSchemas };
export const shopOrderSettingCreateSchema = baseShopOrderSettingCreateSchema;
export const shopOrderSettingUpdateSchema = baseShopOrderSettingUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ShopOrderSettingCreate = z.infer<typeof shopOrderSettingCreateSchema>;
export type ShopOrderSettingUpdate = z.infer<typeof shopOrderSettingUpdateSchema>;

// Re-export i18n and helpers
export {
  shopOrderSettingI18n,
  getShopOrderSettingLabel,
  getShopOrderSettingFieldLabel,
  getShopOrderSettingFieldPlaceholder,
};

// Re-export base type for internal use
export type { ShopOrderSettingBase };
