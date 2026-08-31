/**
 * NotificationSchedule Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { NotificationSchedule as NotificationScheduleBase } from './base/NotificationSchedule';
import {
  baseNotificationScheduleSchemas,
  baseNotificationScheduleCreateSchema,
  baseNotificationScheduleUpdateSchema,
  notificationScheduleI18n,
  getNotificationScheduleLabel,
  getNotificationScheduleFieldLabel,
  getNotificationScheduleFieldPlaceholder,
} from './base/NotificationSchedule';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationSchedule extends NotificationScheduleBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationScheduleSchemas = { ...baseNotificationScheduleSchemas };
export const notificationScheduleCreateSchema = baseNotificationScheduleCreateSchema;
export const notificationScheduleUpdateSchema = baseNotificationScheduleUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationScheduleCreate = z.infer<typeof notificationScheduleCreateSchema>;
export type NotificationScheduleUpdate = z.infer<typeof notificationScheduleUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationScheduleI18n,
  getNotificationScheduleLabel,
  getNotificationScheduleFieldLabel,
  getNotificationScheduleFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationScheduleBase };
