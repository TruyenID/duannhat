/**
 * NotificationRule Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { NotificationRule as NotificationRuleBase } from './base/NotificationRule';
import {
  baseNotificationRuleSchemas,
  baseNotificationRuleCreateSchema,
  baseNotificationRuleUpdateSchema,
  notificationRuleI18n,
  getNotificationRuleLabel,
  getNotificationRuleFieldLabel,
  getNotificationRuleFieldPlaceholder,
} from './base/NotificationRule';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface NotificationRule extends NotificationRuleBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const notificationRuleSchemas = { ...baseNotificationRuleSchemas };
export const notificationRuleCreateSchema = baseNotificationRuleCreateSchema;
export const notificationRuleUpdateSchema = baseNotificationRuleUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type NotificationRuleCreate = z.infer<typeof notificationRuleCreateSchema>;
export type NotificationRuleUpdate = z.infer<typeof notificationRuleUpdateSchema>;

// Re-export i18n and helpers
export {
  notificationRuleI18n,
  getNotificationRuleLabel,
  getNotificationRuleFieldLabel,
  getNotificationRuleFieldPlaceholder,
};

// Re-export base type for internal use
export type { NotificationRuleBase };
