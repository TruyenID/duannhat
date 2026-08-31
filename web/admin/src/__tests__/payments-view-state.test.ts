/**
 * Plan-047 G11/G13/G15 — payments view state resolver and i18n key coverage.
 */

import { describe, it, expect } from "vitest";
import { ApiError } from "@/lib/api";
import {
  PAYMENTS_STATE_TEST_IDS,
  resolvePaymentsViewState,
} from "@/app/shop/[shopSlug]/settings/payments/lib/payments-view-state";
import ja from "@/i18n/ja.json";
import en from "@/i18n/en.json";
import vi from "@/i18n/vi.json";

describe("G11 distinct error states", () => {
  it("maps 401 to unauthorized", () => {
    const state = resolvePaymentsViewState({
      isLoading: false,
      isError: true,
      error: new ApiError(401, { code: "UNAUTHENTICATED", message: "Unauthorized" }),
      hasData: false,
    });
    expect(state.kind).toBe("unauthorized");
    expect(state.statusCode).toBe(401);
  });

  it("maps 403 to forbidden", () => {
    const state = resolvePaymentsViewState({
      isLoading: false,
      isError: true,
      error: new ApiError(403, { code: "FORBIDDEN", message: "Forbidden" }),
      hasData: false,
    });
    expect(state.kind).toBe("forbidden");
  });

  it("maps 409 to conflict error state", () => {
    const state = resolvePaymentsViewState({
      isLoading: false,
      isError: true,
      error: new ApiError(409, { code: "POLICY_WIDEN_BLOCKED", message: "Conflict" }),
      hasData: false,
    });
    expect(state.kind).toBe("error");
    expect(state.statusCode).toBe(409);
  });

  it("maps setup prerequisite without spinner state", () => {
    const state = resolvePaymentsViewState({
      isLoading: false,
      isError: false,
      hasData: false,
      setupRequired: true,
    });
    expect(state.kind).toBe("prerequisite");
    expect(state.errorCode).toBe("GATEWAY_SETUP_REQUIRED");
  });

  it("maps provider action required distinctly from generic error", () => {
    const state = resolvePaymentsViewState({
      isLoading: false,
      isError: false,
      hasData: true,
      providerActionRequired: true,
      providerActionCode: "GATEWAY_VALIDATION_PENDING",
    });
    expect(state.kind).toBe("provider_action");
    expect(state.errorCode).toBe("GATEWAY_VALIDATION_PENDING");
  });
});

describe("G13 navigation layout keys exist for desktop/mobile payments settings", () => {
  it("includes payments settings navigation strings in all locales", () => {
    for (const bundle of [ja, en, vi] as const) {
      expect(bundle["shop.payments.nav.connection"]).toBeTruthy();
      expect(bundle["shop.payments.nav.options"]).toBeTruthy();
      expect(bundle["shop.payments.nav.devices"]).toBeTruthy();
    }
  });
});

describe("G15 i18n strings for long provider labels", () => {
  it("includes payment settings title and provider setup copy in ja/en/vi", () => {
    expect(ja["shop.payments.title"]).toBeTruthy();
    expect(en["shop.payments.title"]).toBeTruthy();
    expect(vi["shop.payments.title"]).toBeTruthy();
    expect(ja["shop.payments.connection.setup_required_desc"]).toBeTruthy();
    expect(en["shop.payments.connection.setup_required_desc"]).toBeTruthy();
    expect(vi["shop.payments.connection.setup_required_desc"]).toBeTruthy();
  });
});

describe("G14 accessibility state test ids are unique", () => {
  it("exposes one test id per primary view kind", () => {
    const ids = Object.values(PAYMENTS_STATE_TEST_IDS);
    expect(new Set(ids).size).toBe(ids.length);
  });
});
