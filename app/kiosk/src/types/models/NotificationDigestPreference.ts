/**
 * NotificationDigestPreference Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { NotificationDigestPreference as NotificationDigestPreferenceBase } from './base/NotificationDigestPreference';
import {
  baseNotificationDigestPreferenceSchemas,
  baseNotificationDigestPreferenceCreateSchema,
  baseNotificationDigestPreferenceUpdateSchema,
  notificationDigestPreferenceI18n,
  getNotificationDigestPreferenceLabel,
  getNotificationDigestPreferenceFieldLabel,
  getNotificationDigestPreferenceFieldPlaceholder,
} from './base/NotificationDigestPreference';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationDigestPreference extends NotificationDigestPreferenceBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationDigestPreferenceSchemas = { ...baseNotificationDigestPreferenceSchemas };
export const notificationDigestPreferenceCreateSchema = baseNotificationDigestPreferenceCreateSchema;
export const notificationDigestPreferenceUpdateSchema = baseNotificationDigestPreferenceUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationDigestPreferenceCreate = z.infer<typeof notificationDigestPreferenceCreateSchema>;
export type NotificationDigestPreferenceUpdate = z.infer<typeof notificationDigestPreferenceUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationDigestPreferenceI18n,
  getNotificationDigestPreferenceLabel,
  getNotificationDigestPreferenceFieldLabel,
  getNotificationDigestPreferenceFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationDigestPreferenceBase };
