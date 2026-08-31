/**
 * PaymentGatewayConnection Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type {
  PaymentGatewayConnection as PaymentGatewayConnectionBase,
  PaymentGatewayConnectionCreateFormState as PaymentGatewayConnectionCreateFormStateBase,
  PaymentGatewayConnectionUpdateFormState as PaymentGatewayConnectionUpdateFormStateBase,
} from './base/PaymentGatewayConnection';
import {
  basePaymentGatewayConnectionSchemas,
  basePaymentGatewayConnectionCreateSchema,
  basePaymentGatewayConnectionUpdateSchema,
  emptyPaymentGatewayConnectionCreateForm as emptyPaymentGatewayConnectionCreateFormBase,
  buildPaymentGatewayConnectionCreatePayload as buildPaymentGatewayConnectionCreatePayloadBase,
  buildPaymentGatewayConnectionUpdatePayload as buildPaymentGatewayConnectionUpdatePayloadBase,
  paymentGatewayConnectionI18n,
  getPaymentGatewayConnectionLabel,
  getPaymentGatewayConnectionFieldLabel,
  getPaymentGatewayConnectionFieldPlaceholder,
} from './base/PaymentGatewayConnection';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

const hiddenPaymentGatewayConnectionFields = {
  secret_ref: true,
  webhook_secret_ref: true,
  secret_version: true,
  key_fingerprint: true,
} as const;

type HiddenPaymentGatewayConnectionField = keyof typeof hiddenPaymentGatewayConnectionFields;

function omitHiddenPaymentGatewayConnectionFields<T extends object>(
  value: T,
): Omit<T, HiddenPaymentGatewayConnectionField> {
  return Object.fromEntries(
    Object.entries(value).filter(([key]) => !(key in hiddenPaymentGatewayConnectionFields)),
  ) as Omit<T, HiddenPaymentGatewayConnectionField>;
}

export type PaymentGatewayConnection = Omit<
  PaymentGatewayConnectionBase,
  HiddenPaymentGatewayConnectionField
>;

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentGatewayConnectionSchemas = omitHiddenPaymentGatewayConnectionFields(
  basePaymentGatewayConnectionSchemas,
);
export const paymentGatewayConnectionCreateSchema = basePaymentGatewayConnectionCreateSchema.omit(
  hiddenPaymentGatewayConnectionFields,
);
export const paymentGatewayConnectionUpdateSchema = basePaymentGatewayConnectionUpdateSchema.omit(
  hiddenPaymentGatewayConnectionFields,
);

// ============================================================================
// Types
// ============================================================================

export type PaymentGatewayConnectionCreate = z.infer<typeof paymentGatewayConnectionCreateSchema>;
export type PaymentGatewayConnectionUpdate = z.infer<typeof paymentGatewayConnectionUpdateSchema>;
export type PaymentGatewayConnectionCreateFormState = Omit<
  PaymentGatewayConnectionCreateFormStateBase,
  HiddenPaymentGatewayConnectionField
>;
export type PaymentGatewayConnectionUpdateFormState = Omit<
  PaymentGatewayConnectionUpdateFormStateBase,
  HiddenPaymentGatewayConnectionField
>;

export function emptyPaymentGatewayConnectionCreateForm(): PaymentGatewayConnectionCreateFormState {
  return omitHiddenPaymentGatewayConnectionFields(emptyPaymentGatewayConnectionCreateFormBase());
}

export function buildPaymentGatewayConnectionCreatePayload(
  form: PaymentGatewayConnectionCreateFormState,
): PaymentGatewayConnectionCreate {
  const payload = buildPaymentGatewayConnectionCreatePayloadBase(
    form as PaymentGatewayConnectionCreateFormStateBase,
  );

  return omitHiddenPaymentGatewayConnectionFields(payload);
}

export function buildPaymentGatewayConnectionUpdatePayload(
  form: PaymentGatewayConnectionUpdateFormState,
): PaymentGatewayConnectionUpdate {
  const payload = buildPaymentGatewayConnectionUpdatePayloadBase(
    form as PaymentGatewayConnectionUpdateFormStateBase,
  );

  return omitHiddenPaymentGatewayConnectionFields(payload);
}

// Re-export i18n and helpers
export {
  paymentGatewayConnectionI18n,
  getPaymentGatewayConnectionLabel,
  getPaymentGatewayConnectionFieldLabel,
  getPaymentGatewayConnectionFieldPlaceholder,
};
