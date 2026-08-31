/**
 * NotificationTemplate Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { NotificationTemplate as NotificationTemplateBase } from './base/NotificationTemplate';
import {
  baseNotificationTemplateSchemas,
  baseNotificationTemplateCreateSchema,
  baseNotificationTemplateUpdateSchema,
  notificationTemplateI18n,
  getNotificationTemplateLabel,
  getNotificationTemplateFieldLabel,
  getNotificationTemplateFieldPlaceholder,
} from './base/NotificationTemplate';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationTemplate extends NotificationTemplateBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationTemplateSchemas = { ...baseNotificationTemplateSchemas };
export const notificationTemplateCreateSchema = baseNotificationTemplateCreateSchema;
export const notificationTemplateUpdateSchema = baseNotificationTemplateUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationTemplateCreate = z.infer<typeof notificationTemplateCreateSchema>;
export type NotificationTemplateUpdate = z.infer<typeof notificationTemplateUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationTemplateI18n,
  getNotificationTemplateLabel,
  getNotificationTemplateFieldLabel,
  getNotificationTemplateFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationTemplateBase };
