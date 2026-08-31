import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// Mock @sentry/react BEFORE importing initSentry so we can observe the
// init call. vi.mock is hoisted automatically by vitest.
vi.mock("@sentry/react", () => ({
  init: vi.fn(),
  captureException: vi.fn(),
  captureMessage: vi.fn(),
  addBreadcrumb: vi.fn(),
  browserTracingIntegration: vi.fn(() => ({ name: "BrowserTracing" })),
}));

import * as Sentry from "@sentry/react";
import { initSentry } from "./sentry";

describe("initSentry", () => {
  const initSpy = vi.mocked(Sentry.init);
  const originalEnv = { ...import.meta.env };

  beforeEach(() => {
    initSpy.mockClear();
  });

  afterEach(() => {
    // Restore env after each test
    Object.assign(import.meta.env, originalEnv);
  });

  it("is a no-op when VITE_SENTRY_DSN is not set", () => {
    // @ts-expect-error vitest allows mutating import.meta.env
    delete import.meta.env.VITE_SENTRY_DSN;
    initSentry();
    expect(initSpy).not.toHaveBeenCalled();
  });

  it("initialises Sentry with the DSN when set", () => {
    // @ts-expect-error — import.meta.env is typed readonly; the test has to
    // set the DSN before initSentry() reads it.
    import.meta.env.VITE_SENTRY_DSN = "https://example@sentry.io/1";
    initSentry();
    expect(initSpy).toHaveBeenCalledTimes(1);
    const cfg = initSpy.mock.calls[0]![0]!;
    expect(cfg.dsn).toBe("https://example@sentry.io/1");
    expect(cfg.sendDefaultPii).toBe(false);
    // Sampling kept low so a 24/7 tablet doesn't burn quota
    expect(cfg.tracesSampleRate).toBeLessThanOrEqual(0.1);
  });

  it("beforeBreadcrumb redacts the device_token if it appears in a console message", () => {
    // @ts-expect-error — import.meta.env is typed readonly; the test has to
    // set the DSN before initSentry() reads it.
    import.meta.env.VITE_SENTRY_DSN = "https://example@sentry.io/1";
    initSentry();
    const cfg = initSpy.mock.calls[0]![0]!;
    const out = cfg.beforeBreadcrumb!(
      {
        category: "console",
        message: `kds_device_token: "eyJabc123very.secret.token"`,
      },
      {},
    );
    expect(out).toBeTruthy();
    expect(out!.message).not.toContain("eyJabc123very.secret.token");
    expect(out!.message).toContain("<redacted>");
  });

  it("beforeBreadcrumb passes non-console breadcrumbs through unchanged", () => {
    // @ts-expect-error — import.meta.env is typed readonly; the test has to
    // set the DSN before initSentry() reads it.
    import.meta.env.VITE_SENTRY_DSN = "https://example@sentry.io/1";
    initSentry();
    const cfg = initSpy.mock.calls[0]![0]!;
    const original = { category: "navigation", message: "from /a to /b" };
    const out = cfg.beforeBreadcrumb!(original, {});
    expect(out).toBe(original);
  });
});
