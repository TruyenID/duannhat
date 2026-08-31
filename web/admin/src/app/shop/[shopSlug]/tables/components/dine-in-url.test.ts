import { afterEach, describe, expect, it, vi } from "vitest";
import {
  buildDineInUrl,
  PRODUCTION_CUSTOMER_WEB_URL,
  resolveCustomerWebBaseUrl,
} from "./dine-in-url";

describe("resolveCustomerWebBaseUrl", () => {
  it("prefers the runtime URL over everything else", () => {
    expect(
      resolveCustomerWebBaseUrl({
        runtimeUrl: "https://menu.staging.example.jp",
        configuredUrl: "https://baked-at-build-time.example.jp",
        runtimeOrigin: "https://admin.example.jp",
        nodeEnv: "production",
      })
    ).toBe("https://menu.staging.example.jp");
  });

  it("follows the runtime URL when the domain changes, with no rebuild", () => {
    // Same build (same configuredUrl), different deployment env — this is the
    // whole point of reading the value at request time.
    const options = {
      configuredUrl: "https://menu.old-domain.jp",
      nodeEnv: "production",
    };

    expect(
      resolveCustomerWebBaseUrl({ ...options, runtimeUrl: "https://menu.new-domain.jp" })
    ).toBe("https://menu.new-domain.jp");
  });

  it("IGNORES the build-time env in production — a deploy console must not re-point printed QR codes (#1004)", () => {
    expect(
      resolveCustomerWebBaseUrl({
        configuredUrl: "https://menu.example.jp",
        nodeEnv: "production",
      })
    ).toBe(PRODUCTION_CUSTOMER_WEB_URL);
  });

  it("is byte-for-byte the pre-existing behaviour when no runtime URL is set", () => {
    // The regression guard for shipping this change: production output must not
    // move for any deployment that has not opted in via CUSTOMER_WEB_URL.
    for (const configuredUrl of [
      undefined,
      "",
      "https://menu.example.jp",
      "https://main.d3bw22hyw76201.amplifyapp.com",
    ]) {
      expect(
        resolveCustomerWebBaseUrl({
          configuredUrl,
          runtimeOrigin: "https://tempo.godx.jp",
          nodeEnv: "production",
        })
      ).toBe(PRODUCTION_CUSTOMER_WEB_URL);
    }
  });

  it("rejects a stale Amplify hostname from the runtime env", () => {
    expect(
      resolveCustomerWebBaseUrl({
        runtimeUrl: "https://main.d3bw22hyw76201.amplifyapp.com",
        nodeEnv: "production",
      })
    ).toBe(PRODUCTION_CUSTOMER_WEB_URL);
  });

  it("rejects a stale Amplify hostname outside production too", () => {
    expect(
      resolveCustomerWebBaseUrl({
        configuredUrl: "https://main.d3bw22hyw76201.amplifyapp.com",
        runtimeOrigin: "http://localhost:5430",
        nodeEnv: "development",
      })
    ).toBe("http://localhost:5430");
  });

  it("refuses a relative value rather than printing an admin-only QR", () => {
    expect(
      resolveCustomerWebBaseUrl({
        runtimeUrl: "/dine-in",
        nodeEnv: "production",
      })
    ).toBe(PRODUCTION_CUSTOMER_WEB_URL);
  });

  it("still uses the canonical customer domain when production config is missing", () => {
    expect(
      resolveCustomerWebBaseUrl({
        runtimeOrigin: "https://tempo.godx.jp",
        nodeEnv: "production",
      })
    ).toBe(PRODUCTION_CUSTOMER_WEB_URL);
  });

  it("uses the configured customer-web origin outside production", () => {
    expect(
      resolveCustomerWebBaseUrl({
        configuredUrl: "http://localhost:5450",
        runtimeOrigin: "http://localhost:5430",
        nodeEnv: "development",
      })
    ).toBe("http://localhost:5450");
  });

  it("falls back to the current origin outside production", () => {
    expect(
      resolveCustomerWebBaseUrl({
        runtimeOrigin: "http://localhost:5430",
        nodeEnv: "development",
      })
    ).toBe("http://localhost:5430");
  });

  it("trims trailing slashes off whichever candidate wins", () => {
    expect(
      resolveCustomerWebBaseUrl({
        runtimeUrl: "https://menu.example.jp///",
        nodeEnv: "production",
      })
    ).toBe("https://menu.example.jp");
  });
});

describe("buildDineInUrl", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("builds the exact customer route shared by preview and printable poster", () => {
    vi.stubEnv("NEXT_PUBLIC_CUSTOMER_WEB_URL", "http://localhost:5450///");

    expect(buildDineInUrl("aeon-mall-tsudanuma", "qr-token-903")).toBe(
      "http://localhost:5450/dine-in/aeon-mall-tsudanuma/table/qr-token-903"
    );
  });

  it("encodes the runtime URL when the layout supplied one", () => {
    vi.stubEnv("NEXT_PUBLIC_CUSTOMER_WEB_URL", "http://localhost:5450");

    expect(
      buildDineInUrl("aeon-mall-tsudanuma", "qr-token-903", "https://menu.staging.example.jp")
    ).toBe("https://menu.staging.example.jp/dine-in/aeon-mall-tsudanuma/table/qr-token-903");
  });

  it("ignores an empty runtime URL (the unset default)", () => {
    vi.stubEnv("NEXT_PUBLIC_CUSTOMER_WEB_URL", "http://localhost:5450");

    expect(buildDineInUrl("aeon-mall-tsudanuma", "qr-token-903", "")).toBe(
      "http://localhost:5450/dine-in/aeon-mall-tsudanuma/table/qr-token-903"
    );
  });
});
