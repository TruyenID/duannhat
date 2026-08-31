/**
 * AuditLog Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { AuditLog as AuditLogBase } from './base/AuditLog';
import {
  baseAuditLogSchemas,
  baseAuditLogCreateSchema,
  baseAuditLogUpdateSchema,
  auditLogI18n,
  getAuditLogLabel,
  getAuditLogFieldLabel,
  getAuditLogFieldPlaceholder,
} from './base/AuditLog';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface AuditLog extends AuditLogBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const auditLogSchemas = { ...baseAuditLogSchemas };
export const auditLogCreateSchema = baseAuditLogCreateSchema;
export const auditLogUpdateSchema = baseAuditLogUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type AuditLogCreate = z.infer<typeof auditLogCreateSchema>;
export type AuditLogUpdate = z.infer<typeof auditLogUpdateSchema>;

// Re-export i18n and helpers
export {
  auditLogI18n,
  getAuditLogLabel,
  getAuditLogFieldLabel,
  getAuditLogFieldPlaceholder,
};

// Re-export base type for internal use
export type { AuditLogBase };
