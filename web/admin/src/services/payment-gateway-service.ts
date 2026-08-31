/**
 * HQ payment gateway / policy / coverage API (Plan 047 T5.2).
 *
 * Every endpoint here is live. The fixtures below exist only for UI work
 * without a backend and are reachable ONLY via
 * `NEXT_PUBLIC_PAYMENT_GATEWAYS_STUB=true` — they are no longer substituted for
 * a 404, because doing that turned a missing route into believable fake numbers
 * on the Overview tab (#F1).
 *
 * The types mirror the backend resources field for field; where they drifted,
 * whole screens rendered blank or printed raw i18n keys (#F4, #F5).
 *
 * Secrets are stripped before any value crosses the service boundary (G4).
 */

import { apiFetch, type PaginatedResponse } from "@/lib/api";
import { redactPaymentSecrets } from "@/services/shop-payment-settings-service";
import type { PaymentConnectionHealth } from "@/types/models/enum/PaymentConnectionHealth";
import type { PaymentGatewayEnvironment } from "@/types/models/enum/PaymentGatewayEnvironment";
import type { PaymentGatewayProviderCode } from "@/types/models/enum/PaymentGatewayProviderCode";
import type { PaymentOptionRail } from "@/types/models/enum/PaymentOptionRail";
import type { PaymentPolicyPreference } from "@/types/models/enum/PaymentPolicyPreference";

// ---------------------------------------------------------------------------
// Safe display types — no credential fields (G4)
// ---------------------------------------------------------------------------

export type PaymentOnboardingStatus =
  | "not_started"
  | "pending"
  | "action_required"
  | "complete";

export interface SafeProviderSummary {
  code: PaymentGatewayProviderCode | string;
  name: string;
}

export interface PaymentGatewayConnectionSummary {
  id: string;
  provider: SafeProviderSummary;
  environment: PaymentGatewayEnvironment | string;
  merchant_display_name: string | null;
  merchant_account_id: string;
  merchant_store_id: string | null;
  charge_model: string;
  health: PaymentConnectionHealth | string;
  health_reason_code: string | null;
  key_fingerprint: string | null;
  is_active: boolean;
  last_validated_at: string | null;
  onboarding_status: PaymentOnboardingStatus;
  onboarding_next_action: string | null;
  /** Plan-048 T3.6 — server-built provider webhook registration URL (null for internal). */
  webhook_url?: string | null;
  updated_at: string | null;
}

export interface PaymentReadinessOverview {
  overall_status: "ready" | "action_required" | "setup_required";
  connections_ready: number;
  connections_total: number;
  shops_ready: number;
  shops_total: number;
  options_enabled: number;
  options_total: number;
  blockers: Array<{ code: string; message: string; href?: string }>;
}

export interface DisconnectImpact {
  can_disconnect: boolean;
  blocked_reason: string | null;
  affected_shops: Array<{ id: string; name: string; slug: string }>;
  affected_devices: Array<{ id: string; name: string; shop_name: string }>;
  affected_options: Array<{ id: string; name: string; rail: string }>;
}

/**
 * Backend shape for `GET /hq/{brand}/payment-options`.
 *
 * This mirrors `HqPaymentOptionPolicyResource` field for field. The previous
 * declaration invented a flat shape (`option_code`, `option_name`,
 * `provider_code`, `rail`, `hq_preference`, and an `effective_preview` OBJECT)
 * that the API has never returned — the real payload nests the catalog fields
 * under `option` and sends `effective_preview` as a STRING. TypeScript could
 * not catch it because the response is cast, not parsed, so the policy table
 * rendered four rows of blank cells with no radio selected, while saving
 * worked and reported success (#F4).
 */
export interface HqPaymentOptionPolicyRow {
  option_id: string;
  option: {
    id: string;
    code: string;
    name: string | null;
    rail: PaymentOptionRail | string;
    method_type?: string | null;
    provider_id?: string;
    provider?: { code: string; name: string | null } | null;
  };
  shop_payment_option_id: string | null;
  preference: PaymentPolicyPreference | string;
  owner_policy: string;
  /** "default_on" | "default_off" | "blocked_upstream" | "inherit_provider_default" */
  effective_preview: string;
  version: number | null;
}

/** `effective_preview` values that mean "the customer can pay with this". */
export function isPolicyEffective(preview: string): boolean {
  return preview === "default_on";
}

/** Mirrors `PaymentGatewayCoverageResource`. */
export interface PaymentCoverageRow {
  shop_id: string;
  shop_name: string;
  shop_slug: string;
  management_model: "hq_managed" | "franchise_owned";
  connection_ready: boolean;
  setup_required: boolean;
  reason_code: string;
  readiness: "ready" | "setup_required" | "action_required" | "blocked";
  connection_health: PaymentConnectionHealth | string | null;
  connection_display: string | null;
  options_effective: number;
  options_total: number;
}

export interface PaymentGatewayFilters {
  page?: number;
  per_page?: number;
  search?: string;
  health?: string;
  environment?: string;
}

export interface PaymentCoverageFilters {
  page?: number;
  per_page?: number;
  search?: string;
  readiness?: string;
}

/**
 * Every field `PaymentGatewayConnectionStoreRequest` marks `required`.
 *
 * The form used to post only `provider_code` + `environment` and the other six
 * were required server-side, so "Bắt đầu onboarding" answered 422 every single
 * time — creating a gateway connection from the HQ UI was impossible (#F2).
 */
export interface CreateGatewayConnectionInput {
  provider_code: string;
  environment: string;
  merchant_account_id: string;
  charge_model: string;
  identity_brand_id: string;
  brand_owner_org_unit_id: string;
  operator_org_unit_id: string;
  ownership_revision: string;
  merchant_display_name?: string;
  merchant_store_id?: string;
  merchant_terminal_id?: string;
}

/** The backend field is `api_secret`; sending `secret` returns 422 (#F8). */
export interface RotateGatewaySecretInput {
  api_secret: string;
}

export interface UpdateGatewayConnectionInput {
  merchant_display_name?: string;
  charge_model?: string;
  is_active?: boolean;
}

export interface UpdateHqOptionPolicyInput {
  option_id: string;
  preference: PaymentPolicyPreference | string;
}

// ---------------------------------------------------------------------------
// Secret stripping (G4) — reuse shop payment redaction list
// ---------------------------------------------------------------------------

function stripSecrets<T>(value: T): T {
  return redactPaymentSecrets(value);
}

function hqBase(brandSlug: string): string {
  return `/api/v1/hq/${brandSlug}`;
}

function toQuery(params: PaymentGatewayFilters | PaymentCoverageFilters): string {
  const sp = new URLSearchParams();
  const record = params as Record<string, string | number | undefined>;
  for (const [k, v] of Object.entries(record)) {
    if (v !== undefined && v !== "") sp.set(k, String(v));
  }
  const q = sp.toString();
  return q ? `?${q}` : "";
}

/**
 * Fixtures are served ONLY when explicitly asked for.
 *
 * This used to also swallow 404/501 from the live API and answer with the
 * fixture. Every backend endpoint on this screen now exists, but while
 * `payment-readiness` did not, the Overview tab rendered STUB_READINESS — "1/2
 * connections, 3/5 shops, 4/6 options" plus a blocker naming a connection id
 * belonging to no brand — with nothing on screen marking it as fake. A brand
 * with 2 connections and 11 shops read as a brand with 2 and 5 (#F1).
 *
 * A route that 404s is a deploy fault and must look like one. Fixtures stay
 * reachable through `NEXT_PUBLIC_PAYMENT_GATEWAYS_STUB=true`, which is a choice
 * someone makes rather than a silent consolation prize.
 */
async function withFallback<T>(fn: () => Promise<T>, fallback: T): Promise<T> {
  if (process.env.NEXT_PUBLIC_PAYMENT_GATEWAYS_STUB === "true") {
    return fallback;
  }
  return fn();
}

// ---------------------------------------------------------------------------
// Stub fixtures (backend not ready)
// ---------------------------------------------------------------------------

const STUB_CONNECTIONS: PaymentGatewayConnectionSummary[] = [
  {
    id: "00000000-0000-4000-8000-000000000001",
    provider: { code: "stripe", name: "Stripe" },
    environment: "sandbox",
    merchant_display_name: "Demo Merchant",
    merchant_account_id: "acct_demo1234",
    merchant_store_id: null,
    charge_model: "destination",
    health: "ready",
    health_reason_code: null,
    key_fingerprint: "sha256:••••ab12",
    is_active: true,
    last_validated_at: new Date().toISOString(),
    onboarding_status: "complete",
    onboarding_next_action: null,
    updated_at: new Date().toISOString(),
  },
  {
    id: "00000000-0000-4000-8000-000000000002",
    provider: { code: "paypay", name: "PayPay" },
    environment: "sandbox",
    merchant_display_name: null,
    merchant_account_id: "—",
    merchant_store_id: null,
    charge_model: "provider_native",
    health: "pending_verification",
    health_reason_code: "CONTRACT_REQUIRED",
    key_fingerprint: null,
    is_active: false,
    last_validated_at: null,
    onboarding_status: "action_required",
    onboarding_next_action: "complete_provider_onboarding",
    updated_at: new Date().toISOString(),
  },
];

const STUB_READINESS: PaymentReadinessOverview = {
  overall_status: "action_required",
  connections_ready: 1,
  connections_total: 2,
  shops_ready: 3,
  shops_total: 5,
  options_enabled: 4,
  options_total: 6,
  blockers: [
    {
      code: "GATEWAY_SETUP_REQUIRED",
      message: "PayPay connection requires provider onboarding.",
      href: "gateways/00000000-0000-4000-8000-000000000002",
    },
  ],
};

function stubPolicy(
  id: string,
  code: string,
  name: string,
  provider: string,
  rail: string,
  preference: string,
  preview: string
): HqPaymentOptionPolicyRow {
  return {
    option_id: id,
    option: { id, code, name, rail, provider: { code: provider, name: provider } },
    shop_payment_option_id: null,
    preference,
    owner_policy: preference === "blocked" ? "denied" : "allowed",
    effective_preview: preview,
    version: 1,
  };
}

const STUB_POLICIES: HqPaymentOptionPolicyRow[] = [
  stubPolicy("opt-cash", "cash.tender.v1", "Cash", "internal", "cash", "enabled", "default_on"),
  stubPolicy(
    "opt-stripe-card",
    "stripe.card.v1",
    "Card (Stripe)",
    "stripe",
    "card",
    "enabled",
    "default_on"
  ),
  stubPolicy(
    "opt-paypay",
    "paypay.wallet.v1",
    "PayPay Wallet",
    "paypay",
    "wallet",
    "disabled",
    "default_off"
  ),
  stubPolicy(
    "opt-emoney",
    "sbps.emoney.v1",
    "E-Money (SBPS)",
    "sbps",
    "e_money",
    "blocked",
    "blocked_upstream"
  ),
];

const STUB_COVERAGE: PaymentCoverageRow[] = [
  {
    shop_id: "shop-1",
    shop_name: "Shibuya Main",
    shop_slug: "shibuya-main",
    management_model: "hq_managed",
    connection_ready: true,
    setup_required: false,
    reason_code: "connection_ready",
    readiness: "ready",
    connection_health: "ready",
    connection_display: "Stripe · acct_demo1234",
    options_effective: 3,
    options_total: 4,
  },
  {
    shop_id: "shop-2",
    shop_name: "Omotesando Franchise",
    shop_slug: "omotesando-franchise",
    management_model: "franchise_owned",
    connection_ready: false,
    setup_required: true,
    reason_code: "connection_required",
    readiness: "setup_required",
    connection_health: null,
    connection_display: null,
    options_effective: 1,
    options_total: 4,
  },
];

function stubPaginated<T>(rows: T[], filters: { page?: number; per_page?: number } = {}) {
  const page = filters.page ?? 1;
  const perPage = filters.per_page ?? 25;
  const start = (page - 1) * perPage;
  const slice = rows.slice(start, start + perPage);
  return {
    data: slice,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: page,
      last_page: Math.max(1, Math.ceil(rows.length / perPage)),
      per_page: perPage,
      total: rows.length,
      from: rows.length ? start + 1 : null,
      to: rows.length ? Math.min(start + perPage, rows.length) : null,
    },
  };
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

export const paymentGatewayService = {
  async getReadiness(brandSlug: string): Promise<PaymentReadinessOverview> {
    return withFallback(async () => {
      const res = await apiFetch<{ data: PaymentReadinessOverview }>(
        `${hqBase(brandSlug)}/payment-readiness`
      );
      return stripSecrets(res.data);
    }, STUB_READINESS);
  },

  async listConnections(
    brandSlug: string,
    filters: PaymentGatewayFilters = {}
  ): Promise<PaginatedResponse<PaymentGatewayConnectionSummary>> {
    return withFallback(
      async () => {
        const res = await apiFetch<PaginatedResponse<PaymentGatewayConnectionSummary>>(
          `${hqBase(brandSlug)}/payment-gateways${toQuery(filters as Record<string, string | number | undefined>)}`
        );
        return { ...res, data: res.data.map((row) => stripSecrets(row)) };
      },
      stubPaginated(STUB_CONNECTIONS, filters)
    );
  },

  async getConnection(
    brandSlug: string,
    connectionId: string
  ): Promise<PaymentGatewayConnectionSummary> {
    return withFallback(
      async () => {
        const res = await apiFetch<{ data: PaymentGatewayConnectionSummary }>(
          `${hqBase(brandSlug)}/payment-gateways/${connectionId}`
        );
        return stripSecrets(res.data);
      },
      STUB_CONNECTIONS.find((c) => c.id === connectionId) ?? STUB_CONNECTIONS[0]
    );
  },

  async createConnection(
    brandSlug: string,
    input: CreateGatewayConnectionInput
  ): Promise<{ data: PaymentGatewayConnectionSummary; onboarding_url?: string | null }> {
    const res = await apiFetch<{
      data: PaymentGatewayConnectionSummary;
      onboarding_url?: string | null;
    }>(`${hqBase(brandSlug)}/payment-gateways`, {
      method: "POST",
      body: JSON.stringify(input),
    });
    return { ...res, data: stripSecrets(res.data) };
  },

  async updateConnection(
    brandSlug: string,
    connectionId: string,
    input: UpdateGatewayConnectionInput
  ): Promise<PaymentGatewayConnectionSummary> {
    const res = await apiFetch<{ data: PaymentGatewayConnectionSummary }>(
      `${hqBase(brandSlug)}/payment-gateways/${connectionId}`,
      { method: "PATCH", body: JSON.stringify(input) }
    );
    return stripSecrets(res.data);
  },

  async validateConnection(
    brandSlug: string,
    connectionId: string
  ): Promise<PaymentGatewayConnectionSummary> {
    const res = await apiFetch<{ data: PaymentGatewayConnectionSummary }>(
      `${hqBase(brandSlug)}/payment-gateways/${connectionId}/validate`,
      { method: "POST" }
    );
    return stripSecrets(res.data);
  },

  async rotateConnectionSecret(
    brandSlug: string,
    connectionId: string,
    input: RotateGatewaySecretInput
  ): Promise<{ key_fingerprint: string | null; rotated_at: string | null }> {
    const res = await apiFetch<{
      data: { key_fingerprint: string | null; rotated_at: string | null };
    }>(`${hqBase(brandSlug)}/payment-gateways/${connectionId}/rotate`, {
      method: "POST",
      body: JSON.stringify(input),
    });
    return stripSecrets(res.data);
  },

  async getDisconnectImpact(
    brandSlug: string,
    connectionId: string
  ): Promise<DisconnectImpact> {
    return withFallback(
      async () => {
        const res = await apiFetch<{ data: DisconnectImpact }>(
          `${hqBase(brandSlug)}/payment-gateways/${connectionId}/disconnect-impact`
        );
        return stripSecrets(res.data);
      },
      {
        can_disconnect: false,
        blocked_reason: "Connection is referenced by active shop policies.",
        affected_shops: [
          { id: "shop-1", name: "Shibuya Main", slug: "shibuya-main" },
        ],
        affected_devices: [
          { id: "dev-1", name: "POS-01", shop_name: "Shibuya Main" },
        ],
        affected_options: [
          { id: "opt-stripe-card", name: "Card (Stripe)", rail: "card" },
        ],
      }
    );
  },

  async disconnectConnection(brandSlug: string, connectionId: string): Promise<void> {
    await apiFetch<void>(`${hqBase(brandSlug)}/payment-gateways/${connectionId}`, {
      method: "DELETE",
    });
  },

  async listOptionPolicies(brandSlug: string): Promise<HqPaymentOptionPolicyRow[]> {
    return withFallback(async () => {
      const res = await apiFetch<{ data: HqPaymentOptionPolicyRow[] }>(
        `${hqBase(brandSlug)}/payment-options`
      );
      return stripSecrets(res.data);
    }, STUB_POLICIES);
  },

  async updateOptionPolicy(
    brandSlug: string,
    input: UpdateHqOptionPolicyInput
  ): Promise<HqPaymentOptionPolicyRow> {
    const res = await apiFetch<{ data: HqPaymentOptionPolicyRow }>(
      `${hqBase(brandSlug)}/payment-options/${input.option_id}`,
      { method: "PATCH", body: JSON.stringify({ preference: input.preference }) }
    );
    return stripSecrets(res.data);
  },

  async listCoverage(
    brandSlug: string,
    filters: PaymentCoverageFilters = {}
  ): Promise<PaginatedResponse<PaymentCoverageRow>> {
    return withFallback(
      async () => {
        const res = await apiFetch<PaginatedResponse<PaymentCoverageRow>>(
          `${hqBase(brandSlug)}/payment-coverage${toQuery(filters as Record<string, string | number | undefined>)}`
        );
        return { ...res, data: res.data.map((row) => stripSecrets(row)) };
      },
      stubPaginated(STUB_COVERAGE, filters)
    );
  },
};
