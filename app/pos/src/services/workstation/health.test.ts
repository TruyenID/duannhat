import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { checkWorkstationHealth } from "./health";

/**
 * The health probe is the shell's ONLY gate: a URL that passes is stored and
 * the WebView opens on it. It must be forgiving of shape (any 2xx counts — the
 * payload belongs to pos-web) and unforgiving of time (a tablet on a shop
 * Wi-Fi must not hang on a dead IP).
 */
const originalFetch = global.fetch;

beforeEach(() => {
  vi.useRealTimers();
});

afterEach(() => {
  global.fetch = originalFetch;
  vi.restoreAllMocks();
});

type FetchImpl = (url: string, init?: RequestInit) => Promise<unknown>;

function mockFetch(impl: FetchImpl) {
  const spy = vi.fn<FetchImpl>(impl);
  global.fetch = spy as unknown as typeof fetch;
  return spy;
}

describe("checkWorkstationHealth", () => {
  it("hits /api/lan/health on the normalized base", async () => {
    const spy = mockFetch(async () => ({ ok: true }));

    await expect(checkWorkstationHealth("192.168.1.10:8080/")).resolves.toBe(true);
    expect(spy.mock.calls[0][0]).toBe("http://192.168.1.10:8080/api/lan/health");
  });

  it("accepts any 2xx — the body is pos-web's business, not the shell's", async () => {
    mockFetch(async () => ({ ok: true, status: 204 }));
    await expect(checkWorkstationHealth("http://ws.local")).resolves.toBe(true);
  });

  it("rejects a non-2xx (a captive portal or the wrong device answering)", async () => {
    mockFetch(async () => ({ ok: false, status: 404 }));
    await expect(checkWorkstationHealth("http://ws.local")).resolves.toBe(false);
  });

  it("returns false instead of throwing when the host is unreachable", async () => {
    mockFetch(async () => {
      throw new TypeError("Network request failed");
    });
    await expect(checkWorkstationHealth("http://10.0.0.9:8080")).resolves.toBe(false);
  });

  it("short-circuits an empty / whitespace URL without touching the network", async () => {
    const spy = mockFetch(async () => ({ ok: true }));

    await expect(checkWorkstationHealth("")).resolves.toBe(false);
    await expect(checkWorkstationHealth("   ")).resolves.toBe(false);
    expect(spy).not.toHaveBeenCalled();
  });

  it("aborts on timeout rather than hanging the setup screen", async () => {
    // Resolve only when the caller's AbortSignal fires — i.e. prove the timer
    // actually aborts, without waiting the full default budget.
    mockFetch(
      (_url, init) =>
        new Promise((_resolve, reject) => {
          init?.signal?.addEventListener("abort", () => reject(new Error("aborted")));
        }),
    );

    await expect(checkWorkstationHealth("http://10.0.0.9:8080", 10)).resolves.toBe(false);
  });

  it("passes an AbortSignal on every request", async () => {
    const spy = mockFetch(async () => ({ ok: true }));

    await checkWorkstationHealth("http://ws.local");
    expect(spy.mock.calls[0][1]).toMatchObject({ method: "GET" });
    expect((spy.mock.calls[0][1] as RequestInit).signal).toBeInstanceOf(AbortSignal);
  });
});
