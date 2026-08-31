import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@sentry/react", () => ({
  init: vi.fn(),
  captureException: vi.fn(),
  captureMessage: vi.fn(),
  addBreadcrumb: vi.fn(),
  browserTracingIntegration: vi.fn(() => ({ name: "BrowserTracing" })),
}));

import * as Sentry from "@sentry/react";
import { initSentry } from "./sentry";

describe("initSentry (pos-web)", () => {
  const initSpy = vi.mocked(Sentry.init);
  const originalDsn = (import.meta.env as Record<string, unknown>).VITE_SENTRY_DSN;

  beforeEach(() => {
    initSpy.mockClear();
  });

  afterEach(() => {
    if (originalDsn === undefined) {
      // @ts-expect-error vitest allows mutating import.meta.env
      delete import.meta.env.VITE_SENTRY_DSN;
    } else {
      // @ts-expect-error vitest allows mutating import.meta.env
      import.meta.env.VITE_SENTRY_DSN = originalDsn;
    }
  });

  it("is a no-op when VITE_SENTRY_DSN is not set", () => {
    // @ts-expect-error vitest allows mutating import.meta.env
    delete import.meta.env.VITE_SENTRY_DSN;
    initSentry();
    expect(initSpy).not.toHaveBeenCalled();
  });

  it("initialises Sentry with the DSN + low traces sample rate", () => {
    // @ts-expect-error vitest allows mutating import.meta.env
    import.meta.env.VITE_SENTRY_DSN = "https://k@sentry.io/1";
    initSentry();
    expect(initSpy).toHaveBeenCalledTimes(1);
    const cfg = initSpy.mock.calls[0]![0]!;
    expect(cfg.dsn).toBe("https://k@sentry.io/1");
    expect(cfg.sendDefaultPii).toBe(false);
    // POS sees moderate traffic; sample rate stays low to keep quota predictable
    expect(cfg.tracesSampleRate).toBeLessThanOrEqual(0.2);
  });

  it("beforeBreadcrumb redacts Bearer tokens from console messages", () => {
    // @ts-expect-error vitest allows mutating import.meta.env
    import.meta.env.VITE_SENTRY_DSN = "https://k@sentry.io/1";
    initSentry();
    const cfg = initSpy.mock.calls[0]![0]!;
    const out = cfg.beforeBreadcrumb!(
      {
        category: "console",
        message: 'fetch failed: Authorization: Bearer eyJsecret.payload.tok',
      },
      {},
    );
    expect(out).toBeTruthy();
    expect(out!.message).not.toContain("eyJsecret.payload.tok");
    expect(out!.message).toContain("<redacted>");
  });

  it("beforeSend scrubs Bearer tokens from exception messages", () => {
    // @ts-expect-error vitest allows mutating import.meta.env
    import.meta.env.VITE_SENTRY_DSN = "https://k@sentry.io/1";
    initSentry();
    const cfg = initSpy.mock.calls[0]![0]!;
    const event = {
      exception: {
        values: [{ value: 'fetch failed Authorization: Bearer eyJsecret.payload.tok' }],
      },
    } as Parameters<NonNullable<typeof cfg.beforeSend>>[0];
    const out = cfg.beforeSend!(event, {});
    expect(out).toBeTruthy();
    const scrubbed = (out as { exception?: { values?: { value?: string }[] } }).exception?.values?.[0]?.value ?? "";
    expect(scrubbed).not.toContain("eyJsecret.payload.tok");
    expect(scrubbed).toContain("<redacted>");
  });

  it("beforeSend scrubs email addresses from componentStack passed via extra", () => {
    // @ts-expect-error vitest allows mutating import.meta.env
    import.meta.env.VITE_SENTRY_DSN = "https://k@sentry.io/1";
    initSentry();
    const cfg = initSpy.mock.calls[0]![0]!;
    const event = {
      extra: {
        componentStack: '\n    in CustomerCard (at /pos/components/order-cart.tsx)\n  email: alice@example.com',
      },
    } as Parameters<NonNullable<typeof cfg.beforeSend>>[0];
    const out = cfg.beforeSend!(event, {});
    const stack = (out as { extra?: { componentStack?: string } }).extra?.componentStack ?? "";
    expect(stack).not.toContain("alice@example.com");
    expect(stack).toContain("<email-redacted>");
  });

  it("beforeBreadcrumb redacts `token=...` cookie fragments", () => {
    // @ts-expect-error vitest allows mutating import.meta.env
    import.meta.env.VITE_SENTRY_DSN = "https://k@sentry.io/1";
    initSentry();
    const cfg = initSpy.mock.calls[0]![0]!;
    const out = cfg.beforeBreadcrumb!(
      {
        category: "console",
        message: 'cookie: token=abc.def.ghi; SameSite=Lax',
      },
      {},
    );
    expect(out!.message).toContain("token=<redacted>");
    expect(out!.message).not.toContain("abc.def.ghi");
  });
});
