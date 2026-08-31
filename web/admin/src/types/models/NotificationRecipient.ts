/**
 * NotificationRecipient Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { NotificationRecipient as NotificationRecipientBase } from './base/NotificationRecipient';
import {
  baseNotificationRecipientSchemas,
  baseNotificationRecipientCreateSchema,
  baseNotificationRecipientUpdateSchema,
  notificationRecipientI18n,
  getNotificationRecipientLabel,
  getNotificationRecipientFieldLabel,
  getNotificationRecipientFieldPlaceholder,
} from './base/NotificationRecipient';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationRecipient extends NotificationRecipientBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationRecipientSchemas = { ...baseNotificationRecipientSchemas };
export const notificationRecipientCreateSchema = baseNotificationRecipientCreateSchema;
export const notificationRecipientUpdateSchema = baseNotificationRecipientUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationRecipientCreate = z.infer<typeof notificationRecipientCreateSchema>;
export type NotificationRecipientUpdate = z.infer<typeof notificationRecipientUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationRecipientI18n,
  getNotificationRecipientLabel,
  getNotificationRecipientFieldLabel,
  getNotificationRecipientFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationRecipientBase };
