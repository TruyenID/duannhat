/**
 * NotificationAudience Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { NotificationAudience as NotificationAudienceBase } from './base/NotificationAudience';
import {
  baseNotificationAudienceSchemas,
  baseNotificationAudienceCreateSchema,
  baseNotificationAudienceUpdateSchema,
  notificationAudienceI18n,
  getNotificationAudienceLabel,
  getNotificationAudienceFieldLabel,
  getNotificationAudienceFieldPlaceholder,
} from './base/NotificationAudience';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationAudience extends NotificationAudienceBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationAudienceSchemas = { ...baseNotificationAudienceSchemas };
export const notificationAudienceCreateSchema = baseNotificationAudienceCreateSchema;
export const notificationAudienceUpdateSchema = baseNotificationAudienceUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationAudienceCreate = z.infer<typeof notificationAudienceCreateSchema>;
export type NotificationAudienceUpdate = z.infer<typeof notificationAudienceUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationAudienceI18n,
  getNotificationAudienceLabel,
  getNotificationAudienceFieldLabel,
  getNotificationAudienceFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationAudienceBase };
