/**
 * Local stub for HQ notification provider credentials.
 *
 * Backend endpoint for per-brand provider config does not exist yet
 * (plan-012/023 shipped the channel routing matrix only). This stub lets
 * the Providers tab work end-to-end against localStorage so the UI ships
 * before the backend lands. Swap for an apiFetch-backed service once
 * `/api/v1/hq/{brand}/notifications/providers` is implemented.
 */

export type ProviderChannel = "email" | "sms" | "push";

export type EmailProvider = "sendgrid" | "ses" | "smtp";
export type SmsProvider = "twilio";
export type PushProvider = "fcm";

export interface EmailConfig {
  provider: EmailProvider | "";
  api_key: string;
  from_email: string;
  // SMTP-only
  smtp_host?: string;
  smtp_port?: string;
  smtp_user?: string;
}

export interface SmsConfig {
  provider: SmsProvider | "";
  account_sid: string;
  auth_token: string;
  from_number: string;
}

export interface PushConfig {
  provider: PushProvider | "";
  server_key: string;
  project_id: string;
}

export interface ProvidersState {
  email: EmailConfig;
  sms: SmsConfig;
  push: PushConfig;
}

export const EMPTY_STATE: ProvidersState = {
  email: { provider: "", api_key: "", from_email: "" },
  sms: { provider: "", account_sid: "", auth_token: "", from_number: "" },
  push: { provider: "", server_key: "", project_id: "" },
};

const storageKey = (brandSlug: string) => `notif-providers:${brandSlug}`;

export function loadProviders(brandSlug: string): ProvidersState {
  if (typeof window === "undefined") return EMPTY_STATE;
  try {
    const raw = window.localStorage.getItem(storageKey(brandSlug));
    if (!raw) return EMPTY_STATE;
    const parsed = JSON.parse(raw) as Partial<ProvidersState>;
    return {
      email: { ...EMPTY_STATE.email, ...(parsed.email ?? {}) },
      sms: { ...EMPTY_STATE.sms, ...(parsed.sms ?? {}) },
      push: { ...EMPTY_STATE.push, ...(parsed.push ?? {}) },
    };
  } catch {
    return EMPTY_STATE;
  }
}

export function saveProviders(brandSlug: string, state: ProvidersState): void {
  if (typeof window === "undefined") return;
  window.localStorage.setItem(storageKey(brandSlug), JSON.stringify(state));
}

export function maskSecret(value: string): string {
  if (!value) return "";
  if (value.length <= 4) return "•".repeat(value.length);
  return "•".repeat(Math.max(8, value.length - 4)) + value.slice(-4);
}
