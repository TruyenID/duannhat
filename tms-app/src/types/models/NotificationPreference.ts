/**
 * NotificationPreference Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { NotificationPreference as NotificationPreferenceBase } from './base/NotificationPreference';
import {
  baseNotificationPreferenceSchemas,
  baseNotificationPreferenceCreateSchema,
  baseNotificationPreferenceUpdateSchema,
  notificationPreferenceI18n,
  getNotificationPreferenceLabel,
  getNotificationPreferenceFieldLabel,
  getNotificationPreferenceFieldPlaceholder,
} from './base/NotificationPreference';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationPreference extends NotificationPreferenceBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationPreferenceSchemas = { ...baseNotificationPreferenceSchemas };
export const notificationPreferenceCreateSchema = baseNotificationPreferenceCreateSchema;
export const notificationPreferenceUpdateSchema = baseNotificationPreferenceUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationPreferenceCreate = z.infer<typeof notificationPreferenceCreateSchema>;
export type NotificationPreferenceUpdate = z.infer<typeof notificationPreferenceUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationPreferenceI18n,
  getNotificationPreferenceLabel,
  getNotificationPreferenceFieldLabel,
  getNotificationPreferenceFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationPreferenceBase };
