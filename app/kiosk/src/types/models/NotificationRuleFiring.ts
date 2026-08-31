/**
 * NotificationRuleFiring Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { NotificationRuleFiring as NotificationRuleFiringBase } from './base/NotificationRuleFiring';
import {
  baseNotificationRuleFiringSchemas,
  baseNotificationRuleFiringCreateSchema,
  baseNotificationRuleFiringUpdateSchema,
  notificationRuleFiringI18n,
  getNotificationRuleFiringLabel,
  getNotificationRuleFiringFieldLabel,
  getNotificationRuleFiringFieldPlaceholder,
} from './base/NotificationRuleFiring';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationRuleFiring extends NotificationRuleFiringBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationRuleFiringSchemas = { ...baseNotificationRuleFiringSchemas };
export const notificationRuleFiringCreateSchema = baseNotificationRuleFiringCreateSchema;
export const notificationRuleFiringUpdateSchema = baseNotificationRuleFiringUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationRuleFiringCreate = z.infer<typeof notificationRuleFiringCreateSchema>;
export type NotificationRuleFiringUpdate = z.infer<typeof notificationRuleFiringUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationRuleFiringI18n,
  getNotificationRuleFiringLabel,
  getNotificationRuleFiringFieldLabel,
  getNotificationRuleFiringFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationRuleFiringBase };
