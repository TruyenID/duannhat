/**
 * Notification Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { Notification as NotificationBase } from './base/Notification';
import {
  baseNotificationSchemas,
  baseNotificationCreateSchema,
  baseNotificationUpdateSchema,
  notificationI18n,
  getNotificationLabel,
  getNotificationFieldLabel,
  getNotificationFieldPlaceholder,
} from './base/Notification';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Notification extends NotificationBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationSchemas = { ...baseNotificationSchemas };
export const notificationCreateSchema = baseNotificationCreateSchema;
export const notificationUpdateSchema = baseNotificationUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationCreate = z.infer<typeof notificationCreateSchema>;
export type NotificationUpdate = z.infer<typeof notificationUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationI18n,
  getNotificationLabel,
  getNotificationFieldLabel,
  getNotificationFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationBase };
