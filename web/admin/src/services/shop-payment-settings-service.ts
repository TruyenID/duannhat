/**
 * Shop payment gateway settings — plan-047 T5.3 / T5.4 API client.
 *
 * Wired to the REAL backend response shapes (see
 * backend/app/Http/Controllers/Api/V1/Shop/ShopPaymentConfigurationController.php,
 * EffectivePaymentOptionsController.php and
 * backend/app/Services/Payment/Policy/Admin/{PaymentPolicyEvaluationService,
 * EffectivePaymentOptionsPresenter}.php):
 *
 *   GET   /api/v1/shops/{shop}/payment-configuration
 *         → { data: { ownership, connections[], options[], revision,
 *                     snapshot_hash, setup_required, connection_mutable } }
 *   PATCH /api/v1/shops/{shop}/payment-options/{option}
 *         { preference, connection_id?, change_reason? }
 *         → { data: { option, revision } }
 *   GET   /api/v1/shops/{shop}/devices/{device}/payment-options
 *         → { data: { revision, snapshot_hash, ownership_revision,
 *                     published_at, options[] } }
 *   PATCH /api/v1/shops/{shop}/devices/{device}/payment-options
 *         { option_id, preference: "inherit"|"disabled", change_reason? }
 *         → { data: { option, revision } }
 *
 * Policy invariants enforced by the backend (mirrored in the UI):
 *   - Shop preference may only be `inherit` or `disabled` (cannot widen).
 *   - Device preference may only be `inherit` or `disabled`.
 *
 * No secret fields are modeled or returned to the UI layer.
 */

import { apiFetch } from "@/lib/api";
import type { DevicePaymentOptionPreference } from "@/types/models/enum/DevicePaymentOptionPreference";
import type { PaymentPolicyPreference } from "@/types/models/enum/PaymentPolicyPreference";

// ---------------------------------------------------------------------------
// Types (exact backend presenter shapes)
// ---------------------------------------------------------------------------

/** BranchManagementProjection presented by EffectivePaymentOptionsPresenter::ownership(). */
export interface PaymentConfigurationOwnership {
  /** "hq_managed" | "franchise_owned" | "unresolved" (fail-closed default). */
  management_model: string;
  brand_owner_org_unit_id: string | null;
  operator_org_unit_id: string | null;
  ownership_revision: string | null;
  /** Reason code when unresolved, e.g. "ownership_source_unavailable". */
  reason: string | null;
}

/** EffectivePaymentOptionsPresenter::connection(). */
export interface PaymentConnectionSummary {
  id: string;
  /** Provider code: "stripe" | "paypay" | "internal" | … */
  provider: string;
  environment: string;
  owner_scope: string;
  merchant_display_name: string | null;
  merchant_account_id: string;
  health: string;
  health_reason_code: string | null;
  is_active: boolean;
  last_validated_at: string | null;
}

/** One policy-resolver trace entry (layer decision). */
export interface PaymentPolicyTraceEntry {
  layer: string;
  decision: string;
  reason: string;
}

/** EffectivePaymentOptionsPresenter::option(). */
export interface EffectivePaymentOptionRow {
  /** Catalog option id — the {option} route parameter for PATCH. */
  id: string;
  display_name: string;
  provider: string;
  rail: string;
  method_type: string | null;
  effective: boolean;
  /** "effective" or the denying layer ("ownership", "shop", "device", …). */
  source: string;
  reason: string;
  error_code: string | null;
  connection_id: string | null;
  connection_option_id: string | null;
  shop_option_id: string | null;
  owner_scope: string | null;
  operator_org_unit_id: string | null;
  shop_preference: PaymentPolicyPreference;
  device_preference: DevicePaymentOptionPreference;
  trace: PaymentPolicyTraceEntry[];
}

export interface ShopPaymentConfiguration {
  ownership: PaymentConfigurationOwnership;
  connections: PaymentConnectionSummary[];
  options: EffectivePaymentOptionRow[];
  revision: number;
  snapshot_hash: string | null;
  setup_required: boolean;
  connection_mutable: boolean;
}

/** PaymentPolicyEvaluationService::evaluate() shape (device GET). */
export interface EffectiveOptionsEvaluation {
  revision: number;
  snapshot_hash: string | null;
  ownership_revision: string | null;
  published_at: string | null;
  options: EffectivePaymentOptionRow[];
}

export interface UpdateShopPaymentOptionInput {
  /** Backend rejects "enabled"/"blocked" — a shop can only inherit or disable. */
  preference: PaymentPolicyPreference;
  connection_id?: string | null;
  change_reason?: string;
}

export interface UpdateDevicePaymentOptionInput {
  option_id: string;
  preference: DevicePaymentOptionPreference;
  change_reason?: string;
}

export interface PaymentOptionMutationResult {
  option: EffectivePaymentOptionRow | null;
  revision: number;
}

/**
 * plan-054 D9 — the shop-level PayPay (customer-web QR) off switch.
 *
 *   GET   /api/v1/shops/{shop}/settings/paypay
 *   PATCH /api/v1/shops/{shop}/settings/paypay  { preference }
 *
 * Why this is not just a row in `options[]` above: that list is assembled from
 * `payment_gateway_connection_options`, and the PayPay QR connection option is
 * created lazily by the backend at the FIRST PayPay checkout. Before that sale
 * the capability is absent from the configuration payload, so the generic
 * preference select has nothing to render — and an off switch that only
 * appears after the money has already moved is not an off switch.
 *
 * Both endpoints write the same `shop_payment_options` row the generic
 * `PATCH /payment-options/{option}` writes, so the two controls cannot
 * disagree; the mutation invalidates the whole shop-payment-settings namespace
 * to keep the list beside it in step.
 */
export interface ShopPayPaySwitchState {
  /** Only "inherit" | "disabled" — a shop can never widen past the brand. */
  preference: PaymentPolicyPreference;
  available_preferences: PaymentPolicyPreference[];
  /** What customer-web will actually do for this shop right now. */
  effective_enabled: boolean;
  /** False when PayPay is unavailable for reasons the shop cannot change. */
  brand_enabled: boolean;
  /** "credentials_missing" | "currency_unsupported" | "disabled_for_shop" | null */
  reason: string | null;
}

export interface UpdateShopPayPaySwitchInput {
  preference: PaymentPolicyPreference;
}

/** Fields that must never be sent to or rendered from the client. */
export const PAYMENT_SECRET_FIELD_NAMES = [
  "secret",
  "api_key",
  "apiKey",
  "client_secret",
  "clientSecret",
  "webhook_secret",
  "webhookSecret",
  "private_key",
  "privateKey",
  "access_token",
  "accessToken",
  "refresh_token",
  "refreshToken",
  "credential",
  "credentials",
  "password",
  "pan",
  "cvv",
] as const;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function shopUrl(shopSlug: string, path: string): string {
  return `/api/v1/shops/${shopSlug}${path}`;
}

/** Strip secret-like keys from arbitrary API payloads before logging or display. */
export function redactPaymentSecrets<T>(value: T): T {
  if (value === null || value === undefined) return value;
  if (Array.isArray(value)) {
    return value.map((item) => redactPaymentSecrets(item)) as T;
  }
  if (typeof value === "object") {
    const out: Record<string, unknown> = {};
    for (const [key, val] of Object.entries(value as Record<string, unknown>)) {
      const lower = key.toLowerCase();
      if (PAYMENT_SECRET_FIELD_NAMES.some((s) => lower.includes(s.toLowerCase()))) {
        continue;
      }
      out[key] = redactPaymentSecrets(val);
    }
    return out as T;
  }
  return value;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

export const shopPaymentSettingsService = {
  getConfiguration(shopSlug: string) {
    return apiFetch<{ data: ShopPaymentConfiguration }>(
      shopUrl(shopSlug, "/payment-configuration")
    ).then((res) => ({ data: redactPaymentSecrets(res.data) }));
  },

  updateShopOption(shopSlug: string, optionId: string, input: UpdateShopPaymentOptionInput) {
    return apiFetch<{ data: PaymentOptionMutationResult }>(
      shopUrl(shopSlug, `/payment-options/${optionId}`),
      {
        method: "PATCH",
        body: JSON.stringify(input),
      }
    ).then((res) => ({ data: redactPaymentSecrets(res.data) }));
  },

  getDeviceOptions(shopSlug: string, deviceId: string) {
    return apiFetch<{ data: EffectiveOptionsEvaluation }>(
      shopUrl(shopSlug, `/devices/${deviceId}/payment-options`)
    ).then((res) => ({ data: redactPaymentSecrets(res.data) }));
  },

  updateDeviceOption(shopSlug: string, deviceId: string, input: UpdateDevicePaymentOptionInput) {
    return apiFetch<{ data: PaymentOptionMutationResult }>(
      shopUrl(shopSlug, `/devices/${deviceId}/payment-options`),
      {
        method: "PATCH",
        body: JSON.stringify(input),
      }
    ).then((res) => ({ data: redactPaymentSecrets(res.data) }));
  },

  getPayPaySwitch(shopSlug: string) {
    return apiFetch<{ data: ShopPayPaySwitchState }>(shopUrl(shopSlug, "/settings/paypay")).then(
      (res) => ({ data: redactPaymentSecrets(res.data) })
    );
  },

  updatePayPaySwitch(shopSlug: string, input: UpdateShopPayPaySwitchInput) {
    return apiFetch<{ data: ShopPayPaySwitchState }>(shopUrl(shopSlug, "/settings/paypay"), {
      method: "PATCH",
      body: JSON.stringify(input),
    }).then((res) => ({ data: redactPaymentSecrets(res.data) }));
  },
};
