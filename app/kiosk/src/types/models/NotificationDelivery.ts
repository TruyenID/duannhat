/**
 * NotificationDelivery Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { NotificationDelivery as NotificationDeliveryBase } from './base/NotificationDelivery';
import {
  baseNotificationDeliverySchemas,
  baseNotificationDeliveryCreateSchema,
  baseNotificationDeliveryUpdateSchema,
  notificationDeliveryI18n,
  getNotificationDeliveryLabel,
  getNotificationDeliveryFieldLabel,
  getNotificationDeliveryFieldPlaceholder,
} from './base/NotificationDelivery';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationDelivery extends NotificationDeliveryBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationDeliverySchemas = { ...baseNotificationDeliverySchemas };
export const notificationDeliveryCreateSchema = baseNotificationDeliveryCreateSchema;
export const notificationDeliveryUpdateSchema = baseNotificationDeliveryUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationDeliveryCreate = z.infer<typeof notificationDeliveryCreateSchema>;
export type NotificationDeliveryUpdate = z.infer<typeof notificationDeliveryUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationDeliveryI18n,
  getNotificationDeliveryLabel,
  getNotificationDeliveryFieldLabel,
  getNotificationDeliveryFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationDeliveryBase };
