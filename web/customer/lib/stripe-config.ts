"use client";

import { useEffect, useState } from "react";

import { apiFetch } from "@/lib/api";

export interface StripeConfig {
  publishable_key: string;
  // #815 — the global `currency` field was removed. The charge currency is
  // per-branch (branch.currency_code / order.charge_currency), never a global.
}

let cached: Promise<StripeConfig> | null = null;

export function loadStripeConfig(): Promise<StripeConfig> {
  if (!cached) {
    cached = apiFetch<{ data: StripeConfig }>("/api/v1/customer/stripe/config")
      .then((res) => {
        // #1703 — the checkout screen can only tell the customer "card payment
        // is unavailable"; it must not name a build-time variable, because the
        // key has not come from one since #815. Whoever operates the shop still
        // needs the real cause, so log the one place it can actually be fixed:
        // the endpoint below reads `config('services.stripe.key')`, i.e. the
        // backend's own STRIPE_KEY — never a NEXT_PUBLIC_* var in this app.
        if (!res.data?.publishable_key) {
          console.warn(
            "[stripe] GET /api/v1/customer/stripe/config returned an empty publishable_key — " +
              "set STRIPE_KEY in the BACKEND env (config/services.php `stripe.key`) and clear " +
              "the config cache. Nothing in customer-web reads a NEXT_PUBLIC_STRIPE_* variable.",
          );
        }
        return res.data;
      })
      .catch((err) => {
        cached = null;
        throw err;
      });
  }
  return cached;
}

export interface UseStripeConfigResult {
  config: StripeConfig | null;
  loading: boolean;
  error: string | null;
}

export function useStripeConfig(): UseStripeConfigResult {
  const [config, setConfig] = useState<StripeConfig | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    loadStripeConfig()
      .then((c) => {
        if (cancelled) return;
        setConfig(c);
        setLoading(false);
      })
      .catch((err) => {
        if (cancelled) return;
        setError(err instanceof Error ? err.message : String(err));
        setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  return { config, loading, error };
}
