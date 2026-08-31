/**
 * NotificationChannelRoute Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { NotificationChannelRoute as NotificationChannelRouteBase } from './base/NotificationChannelRoute';
import {
  baseNotificationChannelRouteSchemas,
  baseNotificationChannelRouteCreateSchema,
  baseNotificationChannelRouteUpdateSchema,
  notificationChannelRouteI18n,
  getNotificationChannelRouteLabel,
  getNotificationChannelRouteFieldLabel,
  getNotificationChannelRouteFieldPlaceholder,
} from './base/NotificationChannelRoute';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationChannelRoute extends NotificationChannelRouteBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationChannelRouteSchemas = { ...baseNotificationChannelRouteSchemas };
export const notificationChannelRouteCreateSchema = baseNotificationChannelRouteCreateSchema;
export const notificationChannelRouteUpdateSchema = baseNotificationChannelRouteUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationChannelRouteCreate = z.infer<typeof notificationChannelRouteCreateSchema>;
export type NotificationChannelRouteUpdate = z.infer<typeof notificationChannelRouteUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationChannelRouteI18n,
  getNotificationChannelRouteLabel,
  getNotificationChannelRouteFieldLabel,
  getNotificationChannelRouteFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationChannelRouteBase };
