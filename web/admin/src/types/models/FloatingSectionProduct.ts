/**
 * FloatingSectionProduct Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { FloatingSectionProduct as FloatingSectionProductBase } from './base/FloatingSectionProduct';
import type { Product as CatalogProduct } from '@/services/product-service';
import type { FloatingSectionProductSku } from './FloatingSectionProductSku';
import type { MenuToppingGroup } from '@/types/shop';
import {
  baseFloatingSectionProductSchemas,
  baseFloatingSectionProductCreateSchema,
  baseFloatingSectionProductUpdateSchema,
  floatingSectionProductI18n,
  getFloatingSectionProductLabel,
  getFloatingSectionProductFieldLabel,
  getFloatingSectionProductFieldPlaceholder,
} from './base/FloatingSectionProduct';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface FloatingSectionProduct extends Omit<FloatingSectionProductBase, 'product' | 'skus'> {
  // The API now eager-loads galleryFirst + active_skus_count onto the
  // embedded product (see FloatingSectionService::findById), so this uses
  // the richer hand-written Product type instead of the omnify base one,
  // which lacks image_url/active_skus_count. `topping_groups` is also
  // eager-loaded now (FloatingSectionService::loadToppingsForSection) so the
  // shop detail can render + override toppings — same shape as the menu.
  product?: CatalogProduct & { topping_groups?: MenuToppingGroup[] };
  // Uses the editable FloatingSectionProductSku sibling (richer `productSku`
  // field, adds image_url) instead of the omnify base one.
  skus?: FloatingSectionProductSku[];
}

/** One row of the shop floating-section topping-override sync payload —
 *  identical shape to the menu's ShopToppingOverrideSyncRow (owner-agnostic). */
export type { ShopToppingOverrideSyncRow, MenuProductToppingItemOverride } from '@/types/shop';

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const floatingSectionProductSchemas = { ...baseFloatingSectionProductSchemas };
export const floatingSectionProductCreateSchema = baseFloatingSectionProductCreateSchema;
export const floatingSectionProductUpdateSchema = baseFloatingSectionProductUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type FloatingSectionProductCreate = z.infer<typeof floatingSectionProductCreateSchema>;
export type FloatingSectionProductUpdate = z.infer<typeof floatingSectionProductUpdateSchema>;

// Re-export i18n and helpers
export {
  floatingSectionProductI18n,
  getFloatingSectionProductLabel,
  getFloatingSectionProductFieldLabel,
  getFloatingSectionProductFieldPlaceholder,
};

// Re-export base type for internal use
export type { FloatingSectionProductBase };
