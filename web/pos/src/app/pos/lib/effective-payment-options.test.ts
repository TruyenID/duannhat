import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

import {
  checkoutCapableOptions,
  effectiveOptionToPaymentMethod,
  paymentOptionsState,
} from "./effective-payment-options";
import type { EffectivePaymentOption } from "../types";

const POS_SRC = join(import.meta.dirname, "..");

function readPosSources(): string {
  const files: string[] = [];
  const walk = (dir: string) => {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
      if (entry.name.includes(".test.")) continue;
      const full = join(dir, entry.name);
      if (entry.isDirectory()) walk(full);
      else if (/\.(tsx|ts)$/.test(entry.name)) files.push(full);
    }
  };
  walk(POS_SRC);
  return files.map((f) => readFileSync(f, "utf8")).join("\n");
}

function sampleOption(
  overrides: Partial<EffectivePaymentOption> = {},
): EffectivePaymentOption {
  return {
    id: "opt-1",
    display_name: "Cash",
    provider: "internal",
    rail: "cash",
    method_type: "cash",
    effective: true,
    legacy_payment_method_id: "pm-1",
    legacy_payment_method_code: "cash",
    connection_id: "conn-1",
    shop_option_id: "shop-opt-1",
    client: {
      requires_tendered: true,
      immediate_settlement: true,
      supports_pos_checkout: true,
    },
    ...overrides,
  };
}

describe("F6 POS effective options — no hard-coded checkout allowlist", () => {
  it("F6 filters checkout options from resolver payload only", () => {
    const options = checkoutCapableOptions([
      sampleOption({ effective: false }),
      sampleOption({
        id: "opt-qr",
        display_name: "QR Pay",
        rail: "wallet",
        legacy_payment_method_code: "qr",
        client: {
          requires_tendered: false,
          immediate_settlement: true,
          supports_pos_checkout: true,
        },
      }),
      sampleOption({
        id: "opt-transfer",
        legacy_payment_method_code: "transfer",
        client: {
          requires_tendered: false,
          immediate_settlement: false,
          supports_pos_checkout: false,
        },
      }),
    ]);

    expect(options).toHaveLength(1);
    expect(options[0]?.legacy_payment_method_code).toBe("qr");
  });

  it("F6 maps effective option to legacy payment method shape without static codes", () => {
    const mapped = effectiveOptionToPaymentMethod(
      sampleOption({ legacy_payment_method_code: "card" }),
    );
    expect(mapped.code).toBe("card");
    expect(mapped.is_active).toBe(true);
  });

  it("does not throw when an option is missing the client block (lagging workstation)", () => {
    // A workstation LAN binary that predates the client-block mirror fix
    // returns effective options with NO `client`. This must degrade to
    // "not checkout-capable", never a TypeError that white-screens the POS.
    const noClient = sampleOption();
    delete (noClient as { client?: unknown }).client;

    expect(() => checkoutCapableOptions([noClient])).not.toThrow();
    expect(checkoutCapableOptions([noClient])).toHaveLength(0);

    const mapped = effectiveOptionToPaymentMethod(noClient);
    expect(mapped.requires_tendered).toBe(false);
    expect(mapped.is_auto_confirm).toBe(false);
  });

  it("F6 pos checkout sources do not contain static cash/card allowlist arrays", () => {
    const sources = readPosSources();
    expect(sources).not.toMatch(
      /\[\s*['"]cash['"]\s*,\s*['"]card['"]\s*,\s*['"]transfer['"]\s*\]/,
    );
    expect(sources).not.toMatch(/ALLOWED_PAYMENT_METHODS\s*=\s*\[/);
  });
});

describe("paymentOptionsState — a failed fetch is not a missing config", () => {
  const checkoutOption = sampleOption();

  it("loading wins over everything", () => {
    expect(
      paymentOptionsState({ loading: true, error: new Error("x"), options: [] }),
    ).toEqual({ kind: "loading" });
  });

  it("a failed fetch reports error, NOT «nothing configured»", () => {
    // The shipped bug: the terminal was pointed at a host that never issued its
    // token, every /pos/* call failed, and the dialog told the cashier the shop
    // had no payment policy. Wrong person, wrong fix.
    expect(
      paymentOptionsState({
        loading: false,
        error: new Error("Failed to fetch"),
        options: undefined,
      }),
    ).toEqual({ kind: "error" });
  });

  it("a genuinely empty policy reports empty", () => {
    expect(
      paymentOptionsState({ loading: false, error: null, options: [] }),
    ).toEqual({ kind: "empty" });
  });

  it("options that exist but are not checkout-capable are empty, not ready", () => {
    const notCheckout = sampleOption({
      client: {
        requires_tendered: false,
        immediate_settlement: false,
        supports_pos_checkout: false,
      },
    });
    expect(
      paymentOptionsState({ loading: false, options: [notCheckout] }),
    ).toEqual({ kind: "empty" });
  });

  it("returns the checkout-capable subset when ready", () => {
    const state = paymentOptionsState({
      loading: false,
      options: [checkoutOption, sampleOption({ id: "opt-2", effective: false })],
    });
    expect(state.kind).toBe("ready");
    expect(state.kind === "ready" && state.options).toHaveLength(1);
  });

  it("cached options survive a failed background refetch", () => {
    // Mid-shift a refetch can fail while the last good payload is still cached.
    // Blanking the grid there would be worse than the stale list: the cashier
    // has a customer in front of them and the options have not changed.
    const state = paymentOptionsState({
      loading: false,
      error: new Error("network"),
      options: [checkoutOption],
    });
    expect(state).toEqual({ kind: "ready", options: [checkoutOption] });
  });
});
