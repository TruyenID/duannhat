// Plan 047 — device-effective payment policy snapshot (no secrets).

export type PaymentOptionRail =
  | 'cash'
  | 'card'
  | 'wallet'
  | 'qr'
  | 'e_money'
  | 'bank_transfer';

/** One row from GET /api/v1/kiosk/effective-payment-options */
export interface EffectivePaymentOptionRow {
  id: string;
  display_name: string;
  provider: string;
  rail: PaymentOptionRail | string;
  effective: boolean;
  source: string;
  reason: string;
  error_code: string | null;
  connection_id: string | null;
  connection_option_id: string | null;
  shop_option_id: string | null;
  owner_scope: string | null;
  shop_preference: string;
  device_preference: string;
  trace: unknown[];
}

export interface EffectivePaymentOptionsSnapshot {
  revision: number;
  snapshot_hash: string | null;
  ownership_revision: string | null;
  published_at: string | null;
  options: EffectivePaymentOptionRow[];
}

/** Client selection stamped onto payment-flow state and payment create payload. */
export interface SelectedPaymentOption {
  optionId: string;
  connectionId: string | null;
  connectionOptionId: string | null;
  policyRevision: number;
  rail: string;
  route: import('./kiosk').PaymentMethod;
  displayName: string;
  provider: string;
}
