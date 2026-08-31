/**
 * NotificationEmailSuppression Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { NotificationEmailSuppression as NotificationEmailSuppressionBase } from './base/NotificationEmailSuppression';
import {
  baseNotificationEmailSuppressionSchemas,
  baseNotificationEmailSuppressionCreateSchema,
  baseNotificationEmailSuppressionUpdateSchema,
  notificationEmailSuppressionI18n,
  getNotificationEmailSuppressionLabel,
  getNotificationEmailSuppressionFieldLabel,
  getNotificationEmailSuppressionFieldPlaceholder,
} from './base/NotificationEmailSuppression';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationEmailSuppression extends NotificationEmailSuppressionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationEmailSuppressionSchemas = { ...baseNotificationEmailSuppressionSchemas };
export const notificationEmailSuppressionCreateSchema = baseNotificationEmailSuppressionCreateSchema;
export const notificationEmailSuppressionUpdateSchema = baseNotificationEmailSuppressionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationEmailSuppressionCreate = z.infer<typeof notificationEmailSuppressionCreateSchema>;
export type NotificationEmailSuppressionUpdate = z.infer<typeof notificationEmailSuppressionUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationEmailSuppressionI18n,
  getNotificationEmailSuppressionLabel,
  getNotificationEmailSuppressionFieldLabel,
  getNotificationEmailSuppressionFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationEmailSuppressionBase };
