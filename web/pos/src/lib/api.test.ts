import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  AmbiguousMutationError,
  ApiError,
  apiFetch,
  isNetworkError,
  resetAuthFailureStreak,
  setAuthRecoveryHandler,
  setUnauthorizedHandler,
} from "./api";
import { persistSession, clearSession } from "./auth";
import { setCurrentShopSlug } from "./shop-context";
import {
  CLOUD_URL,
  getWorkstationUrl,
  resetUnreachable,
  setMode,
} from "@/services/workstation/base-url-resolver";
import {
  getNetworkStatus,
  isOffline,
  resetNetworkStatus,
} from "./network-status";

const originalFetch = global.fetch;

function mockFetchOnce(status: number, body: unknown = {}): void {
  global.fetch = vi.fn().mockResolvedValueOnce({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  } as Response);
}

function mockFetchStatus(status: number, body: unknown = {}): void {
  global.fetch = vi.fn().mockResolvedValue({
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  } as Response);
}

beforeEach(() => {
  setUnauthorizedHandler(null);
  setAuthRecoveryHandler(null);
  clearSession();
  resetAuthFailureStreak();
  setCurrentShopSlug("");
  // Default to cloud mode so existing tests (which use mockFetchOnce) hit the
  // expected URL. Routing-specific tests below override mode + assert URL.
  setMode("cloud");
  resetUnreachable();
});

afterEach(() => {
  global.fetch = originalFetch;
  setUnauthorizedHandler(null);
  setAuthRecoveryHandler(null);
  clearSession();
  setCurrentShopSlug("");
  setMode("cloud");
});

describe("apiFetch", () => {
  it("returns body on 200", async () => {
    mockFetchOnce(200, { ok: true });
    const result = await apiFetch<{ ok: boolean }>("/test");
    expect(result).toEqual({ ok: true });
  });

  it("returns null on 204", async () => {
    global.fetch = vi.fn().mockResolvedValueOnce({
      ok: true,
      status: 204,
      json: async () => ({}),
    } as Response);
    const result = await apiFetch("/test");
    expect(result).toBeNull();
  });

  it("throws ApiError on 422 validation", async () => {
    mockFetchOnce(422, { message: "validation failed", errors: { name: ["required"] } });
    const err = (await apiFetch("/test").catch((e) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(422);
    expect(err.body.errors).toEqual({ name: ["required"] });
  });

  it("throws ApiError on 500", async () => {
    mockFetchOnce(500, { message: "server error" });
    const err = (await apiFetch("/test").catch((e) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(500);
  });

  it("does NOT throw on 403 — still ApiError but isn't 401 path", async () => {
    mockFetchOnce(403, { message: "forbidden" });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);
    const err = (await apiFetch("/test").catch((e) => e)) as ApiError;
    expect(err.status).toBe(403);
    expect(handler).not.toHaveBeenCalled();
  });

  const pairedDevice = { id: "d1", name: "POS 1", type: "pos", branch_id: "b1" };

  it("on a single transient 401: keeps the session (does NOT log out) — #487", async () => {
    persistSession("test-token", pairedDevice);
    mockFetchStatus(401, { message: "unauthorized" });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    const err = (await apiFetch("/test").catch((e) => e)) as ApiError;
    expect(err.status).toBe(401);
    expect(handler).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe("test-token");
  });

  it("on 3 consecutive 401s: clears session + calls handler once — #487", async () => {
    persistSession("test-token", pairedDevice);
    mockFetchStatus(401, { message: "unauthorized" });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    await apiFetch("/test").catch(() => {});
    await apiFetch("/test").catch(() => {});
    expect(handler).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe("test-token");

    await apiFetch("/test").catch(() => {});
    expect(handler).toHaveBeenCalledTimes(1);
    expect(localStorage.getItem("pos_device_token")).toBeNull();
  });

  it("a success between two 401s resets the streak — #487", async () => {
    persistSession("test-token", pairedDevice);
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    mockFetchStatus(401, {});
    await apiFetch("/test").catch(() => {});
    mockFetchStatus(401, {});
    await apiFetch("/test").catch(() => {});

    // Success clears the streak…
    mockFetchStatus(200, { ok: true });
    await apiFetch("/test");

    // …so the next two 401s are only #1 and #2 — still logged in.
    mockFetchStatus(401, {});
    await apiFetch("/test").catch(() => {});
    mockFetchStatus(401, {});
    await apiFetch("/test").catch(() => {});

    expect(handler).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe("test-token");
  });

  it("a 401 on a request WITHOUT a token never touches the session — #472", async () => {
    // No persistSession → getToken() is null. /test is not a /pos/* path so it
    // still fires; the 401 must not accumulate or clear anything.
    mockFetchStatus(401, {});
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    for (let i = 0; i < 5; i++) await apiFetch("/test").catch(() => {});

    expect(handler).not.toHaveBeenCalled();
  });

  it("401 \"invalid token\" is NOT a kill signal — the workstation says it for any Cloud blip", async () => {
    // CloudVerifier collapses Cloud 401 AND 403 into ErrUnauthorized, and the
    // workstation answers `writeError(w, 401, "invalid token")` for both. A
    // fast path on that string wiped valid pairings mid-shift.
    persistSession("test-token", pairedDevice);
    mockFetchStatus(401, { message: "invalid token" });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    await apiFetch("/test").catch(() => {});
    expect(handler).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe("test-token");
  });

  it("403 without a code is NOT a branch mismatch — both emitters send the code", async () => {
    // Cloud: ResolvesShopContext. Workstation: writeBranchMismatch. A bare
    // 403 from anything else must not clear a paired session.
    persistSession("test-token", pairedDevice);
    mockFetchStatus(403, { message: "device branch mismatch" });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    await apiFetch("/test").catch(() => {});
    expect(handler).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe("test-token");
  });

  it("503 auth verification unavailable keeps token and notifies recovery handler", async () => {
    persistSession("test-token", pairedDevice);
    mockFetchStatus(503, { message: "auth verification unavailable" });
    const unauthorized = vi.fn();
    const recovery = vi.fn();
    setUnauthorizedHandler(unauthorized);
    setAuthRecoveryHandler(recovery);

    await apiFetch("/test").catch(() => {});
    expect(unauthorized).not.toHaveBeenCalled();
    expect(recovery).toHaveBeenCalledTimes(1);
    expect(localStorage.getItem("pos_device_token")).toBe("test-token");
  });

  it("403 BRANCH_MISMATCH clears the session immediately — #472/#487", async () => {
    persistSession("test-token", pairedDevice);
    mockFetchStatus(403, { message: "Device not authorized for this shop.", code: "BRANCH_MISMATCH" });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    const err = (await apiFetch("/test").catch((e) => e)) as ApiError;
    expect(err.status).toBe(403);
    expect(handler).toHaveBeenCalledTimes(1);
    expect(localStorage.getItem("pos_device_token")).toBeNull();
  });

  it("fail-fast: /pos/* without a token throws not-paired and sends NO request — #472", async () => {
    const fetchSpy = vi.fn();
    global.fetch = fetchSpy;
    const err = (await apiFetch("/api/v1/pos/menus").catch((e) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(401);
    expect(err.body.code).toBe("NOT_PAIRED");
    expect(fetchSpy).not.toHaveBeenCalled();
  });

  it("on 401: works safely when no handler registered", async () => {
    persistSession("test-token", pairedDevice);
    mockFetchStatus(401, {});
    setUnauthorizedHandler(null);
    // Three 401s cross the threshold; the null handler must not blow up.
    await apiFetch("/test").catch(() => {});
    await apiFetch("/test").catch(() => {});
    const err = (await apiFetch("/test").catch((e) => e)) as ApiError;
    expect(err.status).toBe(401);
  });

  it("does NOT return a hanging Promise on 401 (regression test for old bug)", async () => {
    mockFetchOnce(401, {});
    setUnauthorizedHandler(() => {});
    // Old behavior: returned `new Promise(() => {})` — hung forever.
    // New behavior: throws ApiError(401) so caller can unwind.
    await expect(
      Promise.race([
        apiFetch("/test"),
        new Promise((_, reject) => setTimeout(() => reject(new Error("timeout")), 1000)),
      ]),
    ).rejects.toBeInstanceOf(ApiError);
  });
});

/**
 * The session policy is the difference between "a cashier re-pairs a terminal
 * mid-shift" and "a cashier stares at a dead POS". Every branch of
 * handleAuthFailure is pinned here, including the ones that must do NOTHING.
 */
describe("apiFetch session policy (#2431)", () => {
  const token = "session-policy-token";
  const pairedDevice = { id: "d1", name: "POS 1", type: "pos", branch_id: "b1" };

  it("EVERY 401 goes through the streak, whatever the body says", async () => {
    // No message and no code may shortcut the #487 tolerance. Three bodies
    // that each used to trip a "definitive" fast path:
    for (const body of [
      { code: "TOKEN_INVALID" },
      { message: "Invalid Device Token" },
      { message: "invalid token" },
    ]) {
      persistSession(token, pairedDevice);
      resetAuthFailureStreak();
      mockFetchStatus(401, body);
      const handler = vi.fn();
      setUnauthorizedHandler(handler);

      await apiFetch("/test").catch(() => {});
      expect(handler, JSON.stringify(body)).not.toHaveBeenCalled();
      expect(localStorage.getItem("pos_device_token"), JSON.stringify(body)).toBe(token);
    }
  });

  it("401 with an unrelated message still needs 3 in a row — the #487 blip tolerance survives", async () => {
    persistSession(token, pairedDevice);
    mockFetchStatus(401, { message: "Unauthenticated." });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    await apiFetch("/test").catch(() => {});
    await apiFetch("/test").catch(() => {});
    expect(handler).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe(token);

    await apiFetch("/test").catch(() => {});
    expect(handler).toHaveBeenCalledTimes(1);
    expect(localStorage.getItem("pos_device_token")).toBeNull();
  });

  it("403 BRANCH_MISMATCH clears even on a request that carried NO token", async () => {
    // A background poll can race pairing; a branch mismatch is a hard config
    // error either way, so the terminal must be sent back to /pairing.
    mockFetchStatus(403, { message: "device branch mismatch", code: "BRANCH_MISMATCH" });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    await apiFetch("/test").catch(() => {});
    expect(handler).toHaveBeenCalledTimes(1);
  });

  it("403 for any OTHER reason leaves the session alone", async () => {
    persistSession(token, pairedDevice);
    mockFetchStatus(403, { message: "This action is unauthorized.", code: "FORBIDDEN" });
    const handler = vi.fn();
    const recovery = vi.fn();
    setUnauthorizedHandler(handler);
    setAuthRecoveryHandler(recovery);

    await apiFetch("/test").catch(() => {});
    expect(handler).not.toHaveBeenCalled();
    expect(recovery).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe(token);
  });

  it("503 recovery passes the server message through to the banner", async () => {
    persistSession(token, pairedDevice);
    mockFetchStatus(503, { message: "auth verification unavailable" });
    const recovery = vi.fn();
    setAuthRecoveryHandler(recovery);

    await apiFetch("/test").catch(() => {});
    expect(recovery).toHaveBeenCalledWith("auth verification unavailable");
  });

  it("503 recovery does NOT feed the 401 streak — a Cloud outage must not log anyone out", async () => {
    persistSession(token, pairedDevice);
    const handler = vi.fn();
    setUnauthorizedHandler(handler);
    setAuthRecoveryHandler(vi.fn());

    mockFetchStatus(503, { message: "auth verification unavailable" });
    await apiFetch("/test").catch(() => {});
    await apiFetch("/test").catch(() => {});
    await apiFetch("/test").catch(() => {});

    // Two more genuine 401s must still be tolerated (streak is at 0, not 3).
    mockFetchStatus(401, { message: "Unauthenticated." });
    await apiFetch("/test").catch(() => {});
    await apiFetch("/test").catch(() => {});

    expect(handler).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe(token);
  });

  it("a plain 503 (workstation down, no auth message) raises NO banner", async () => {
    // The banner tells the cashier to re-pair. Showing it for an unrelated
    // outage would send them re-pairing a terminal whose pairing is fine.
    persistSession(token, pairedDevice);
    mockFetchStatus(503, { message: "Service Unavailable" });
    const recovery = vi.fn();
    const handler = vi.fn();
    setAuthRecoveryHandler(recovery);
    setUnauthorizedHandler(handler);

    await apiFetch("/test").catch(() => {});
    expect(recovery).not.toHaveBeenCalled();
    expect(handler).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe(token);
  });

  it("503 auth-unavailable on a tokenless request raises no banner", async () => {
    mockFetchStatus(503, { message: "auth verification unavailable" });
    const recovery = vi.fn();
    setAuthRecoveryHandler(recovery);

    await apiFetch("/test").catch(() => {});
    expect(recovery).not.toHaveBeenCalled();
  });

  it("503 auth-unavailable is safe with no recovery handler registered", async () => {
    persistSession(token, pairedDevice);
    mockFetchStatus(503, { message: "auth verification unavailable" });
    setAuthRecoveryHandler(null);

    const err = (await apiFetch("/test").catch((e) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(503);
    expect(localStorage.getItem("pos_device_token")).toBe(token);
  });

  it("503 WORKSTATION_NOT_PAIRED leaves the till logged in — #2442", async () => {
    // The WORKSTATION is unpaired; this POS device may be paired perfectly
    // well. Clearing its session would destroy a good pairing over a gap it
    // did not cause. The server message is surfaced verbatim instead.
    persistSession(token, pairedDevice);
    mockFetchStatus(503, {
      message: "workstation is not paired",
      code: "WORKSTATION_NOT_PAIRED",
    });
    const handler = vi.fn();
    const recovery = vi.fn();
    setUnauthorizedHandler(handler);
    setAuthRecoveryHandler(recovery);

    const err = (await apiFetch("/test").catch((e) => e)) as ApiError;
    expect(err.status).toBe(503);
    expect(err.body.code).toBe("WORKSTATION_NOT_PAIRED");
    expect(handler).not.toHaveBeenCalled();
    expect(recovery).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe(token);
  });

  it("repeated 5xx never clears the session", async () => {
    persistSession(token, pairedDevice);
    mockFetchStatus(500, { message: "internal error" });
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    for (let i = 0; i < 5; i += 1) await apiFetch("/test").catch(() => {});
    expect(handler).not.toHaveBeenCalled();
    expect(localStorage.getItem("pos_device_token")).toBe(token);
  });
});

describe("apiFetch routing", () => {
  // Routing tests exercise /pos/* paths, which the #472 fail-fast guard blocks
  // without a token. Pair a device so the requests actually go out.
  beforeEach(() => {
    persistSession("routing-token", {
      id: "d1",
      name: "POS 1",
      type: "pos",
      branch_id: "b1",
    });
  });

  it("hits workstation URL in workstation mode", async () => {
    setMode("workstation");
    const calls: string[] = [];
    global.fetch = vi.fn().mockImplementation(async (url: string) => {
      calls.push(url);
      return { ok: true, status: 200, json: async () => ({}) } as Response;
    });

    await apiFetch("/api/v1/pos/menus");
    expect(calls[0]).toBe(`${getWorkstationUrl()}/api/v1/pos/menus`);
  });

  it("hits Cloud URL in cloud mode", async () => {
    setMode("cloud");
    const calls: string[] = [];
    global.fetch = vi.fn().mockImplementation(async (url: string) => {
      calls.push(url);
      return { ok: true, status: 200, json: async () => ({}) } as Response;
    });

    await apiFetch("/api/v1/pos/orders");
    expect(calls[0]).toBe(`${CLOUD_URL}/api/v1/pos/orders`);
  });

  it("auto mode tries workstation then falls back to Cloud on network error", async () => {
    setMode("auto");
    const calls: string[] = [];
    global.fetch = vi.fn().mockImplementation(async (url: string) => {
      calls.push(url);
      if (url.startsWith(getWorkstationUrl())) {
        throw new TypeError("Failed to fetch"); // simulate workstation down
      }
      return { ok: true, status: 200, json: async () => ({ from: "cloud" }) } as Response;
    });

    const result = await apiFetch<{ from: string }>("/api/v1/pos/menus");
    expect(result).toEqual({ from: "cloud" });
    expect(calls).toHaveLength(2);
    expect(calls[0]).toMatch(new RegExp(`^${getWorkstationUrl()}`));
    expect(calls[1]).toMatch(new RegExp(`^${CLOUD_URL}`));
  });

  it.each([
    ["payment", "POST", "/api/v1/pos/orders/order-1/payments"],
    ["create order", "POST", "/api/v1/pos/orders"],
    ["add item", "POST", "/api/v1/pos/orders/order-1/items"],
    ["update item", "PATCH", "/api/v1/pos/orders/order-1/items/item-1"],
    ["replace order", "PUT", "/api/v1/pos/orders/order-1"],
    ["delete item", "DELETE", "/api/v1/pos/orders/order-1/items/item-1"],
    ["close order", "POST", "/api/v1/pos/orders/order-1/checkout"],
    ["cancel order", "POST", "/api/v1/pos/orders/order-1/void"],
  ])(
    "does not replay an ambiguous %s mutation to Cloud",
    async (_label, method, path) => {
      setMode("auto");
      let sideEffects = 0;
      const calls: string[] = [];
      global.fetch = vi.fn().mockImplementation(async (url: string) => {
        calls.push(url);
        sideEffects += 1; // workstation committed before its response vanished
        if (url.startsWith(getWorkstationUrl())) {
          throw new DOMException("response timed out", "AbortError");
        }
        return { ok: true, status: 200, json: async () => ({}) } as Response;
      });

      const err = await apiFetch(path, {
        method,
        body: method === "DELETE" ? undefined : JSON.stringify({}),
      }).catch((error) => error);

      expect(err).toBeInstanceOf(AmbiguousMutationError);
      expect(err).toMatchObject({
        code: "MUTATION_OUTCOME_UNKNOWN",
        delivery: "unknown",
        reconcileRequired: true,
        method,
        path,
      });
      expect(calls).toHaveLength(1);
      expect(calls[0]).toMatch(new RegExp(`^${getWorkstationUrl()}`));
      expect(sideEffects).toBe(1);
    },
  );

  it.each([
    ["explicit workstation", "workstation", false, getWorkstationUrl()],
    ["explicit Cloud", "cloud", false, CLOUD_URL],
    ["forceCloud override", "workstation", true, CLOUD_URL],
  ] as const)(
    "reports an ambiguous mutation in %s mode without a hidden replay",
    async (_label, mode, forceCloud, expectedBase) => {
      setMode(mode);
      const transportFailure = new TypeError("socket closed after request write");
      global.fetch = vi.fn().mockRejectedValue(transportFailure);

      const err = await apiFetch("/api/v1/pos/orders/order-1/checkout", {
        method: "post",
        forceCloud,
        body: JSON.stringify({}),
      }).catch((error) => error);

      expect(err).toBeInstanceOf(AmbiguousMutationError);
      expect(err).toMatchObject({
        method: "POST",
        path: "/api/v1/pos/orders/order-1/checkout",
        originalError: transportFailure,
      });
      expect(global.fetch).toHaveBeenCalledTimes(1);
      expect(vi.mocked(global.fetch).mock.calls[0][0]).toBe(
        `${expectedBase}/api/v1/pos/orders/order-1/checkout`,
      );
    },
  );

  it("keeps an HTTP mutation failure authoritative instead of calling it ambiguous", async () => {
    setMode("auto");
    mockFetchStatus(500, { message: "committed response failed downstream" });

    const err = await apiFetch("/api/v1/pos/orders/order-1/checkout", {
      method: "POST",
      body: JSON.stringify({}),
    }).catch((error) => error);

    expect(err).toBeInstanceOf(ApiError);
    expect(err).not.toBeInstanceOf(AmbiguousMutationError);
    expect(err).toMatchObject({ status: 500 });
    expect(global.fetch).toHaveBeenCalledTimes(1);
  });

  it("still allows a HEAD read to fail over from workstation to Cloud", async () => {
    setMode("auto");
    const calls: string[] = [];
    global.fetch = vi.fn().mockImplementation(async (url: string) => {
      calls.push(url);
      if (url.startsWith(getWorkstationUrl())) {
        throw new TypeError("workstation offline");
      }
      return { ok: true, status: 200, json: async () => ({}) } as Response;
    });

    await apiFetch("/api/v1/pos/menus", { method: "HEAD" });

    expect(calls).toEqual([
      `${getWorkstationUrl()}/api/v1/pos/menus`,
      `${CLOUD_URL}/api/v1/pos/menus`,
    ]);
  });

  it("workstation mode does NOT fall back on network error", async () => {
    setMode("workstation");
    global.fetch = vi.fn().mockRejectedValue(new TypeError("Failed to fetch"));

    await expect(apiFetch("/api/v1/pos/menus")).rejects.toThrow(TypeError);
    expect(global.fetch).toHaveBeenCalledTimes(1); // no Cloud retry
  });

  it("attaches X-Shop-Slug header when shop context is set", async () => {
    setMode("cloud");
    setCurrentShopSlug("main-shop");
    const captured: Headers[] = [];
    global.fetch = vi.fn().mockImplementation(async (_url: string, init?: RequestInit) => {
      captured.push(new Headers(init?.headers));
      return { ok: true, status: 200, json: async () => ({}) } as Response;
    });

    await apiFetch("/api/v1/pos/menus");

    expect(captured[0].get("X-Shop-Slug")).toBe("main-shop");
  });

  it("omits X-Shop-Slug header when shop context is empty", async () => {
    setMode("cloud");
    setCurrentShopSlug("");
    const captured: Headers[] = [];
    global.fetch = vi.fn().mockImplementation(async (_url: string, init?: RequestInit) => {
      captured.push(new Headers(init?.headers));
      return { ok: true, status: 200, json: async () => ({}) } as Response;
    });

    await apiFetch("/api/v1/pos/menus");

    expect(captured[0].get("X-Shop-Slug")).toBeNull();
  });

  it("auto mode does NOT fall back on HTTP 5xx (authoritative response)", async () => {
    setMode("auto");
    const calls: string[] = [];
    global.fetch = vi.fn().mockImplementation(async (url: string) => {
      calls.push(url);
      return { ok: false, status: 500, json: async () => ({ message: "server boom" }) } as Response;
    });

    await expect(apiFetch("/api/v1/pos/menus")).rejects.toBeInstanceOf(ApiError);
    expect(calls).toHaveLength(1); // single workstation call, no Cloud retry
  });
});

// =========================================================================
//  #284 hardening — error envelope coverage (validation + RFC 7807)
// =========================================================================

describe("ApiError envelopes", () => {
  it("surfaces the first Laravel validation field message instead of the generic line", () => {
    const err = new ApiError(422, {
      message: "The given data was invalid.",
      errors: {
        amount: ["The amount must be at least 1."],
        note: ["The note may not be greater than 255 characters."],
      },
    });

    expect(err.message).toBe("The amount must be at least 1.");
    expect(err.fieldErrors.amount).toEqual(["The amount must be at least 1."]);
    expect(err.firstFieldError()).toBe("The amount must be at least 1.");
  });

  it("parses an RFC 7807 problem+json body (detail, then title)", () => {
    expect(new ApiError(409, { type: "about:blank", title: "Conflict", detail: "Order already closed." }).message)
      .toBe("Order already closed.");
    expect(new ApiError(409, { title: "Conflict" }).message).toBe("Conflict");
  });

  it("keeps the legacy {message} shape and the status fallback byte-compatible", () => {
    expect(new ApiError(404, { message: "Order not found." }).message).toBe("Order not found.");
    expect(new ApiError(500, {}).message).toBe("API Error 500");
    expect(new ApiError(500, {}).fieldErrors).toEqual({});
    expect(new ApiError(500, {}).firstFieldError()).toBeNull();
  });

  it("ignores malformed errors payloads instead of throwing", () => {
    expect(new ApiError(422, { errors: "boom" }).fieldErrors).toEqual({});
    expect(new ApiError(422, { errors: [1, 2] }).fieldErrors).toEqual({});
    expect(new ApiError(422, { errors: { a: [42] } }).fieldErrors).toEqual({});
  });
});

/*
 * #1501 — apiFetch là NGUỒN tín hiệu offline của toàn app.
 *
 * `navigator.onLine === true` gần như không chứng minh gì (Wi-Fi còn nối,
 * workstation đã tắt), nên banner offline chỉ đáng tin nếu chính lời gọi API
 * là thứ báo cáo. Gỡ mấy dòng `markApiOutcome` trong `api.ts` thì mọi test
 * khác vẫn xanh và banner sẽ im lặng vĩnh viễn — khối này là chỗ duy nhất
 * chứng minh dây nối đó còn.
 */
describe("apiFetch → network-status (#1501)", () => {
  beforeEach(() => {
    resetNetworkStatus();
  });

  it("thành công ⇒ chạm được máy chủ", async () => {
    mockFetchOnce(200, { ok: true });
    await apiFetch("/test");
    expect(getNetworkStatus().consecutiveNetworkFailures).toBe(0);
    expect(getNetworkStatus().lastSyncedAt).not.toBeNull();
  });

  it("lỗi MẠNG ⇒ đếm về phía offline", async () => {
    global.fetch = vi.fn().mockRejectedValue(new TypeError("Failed to fetch"));
    await expect(apiFetch("/test")).rejects.toBeInstanceOf(TypeError);
    expect(getNetworkStatus().consecutiveNetworkFailures).toBe(1);

    await expect(apiFetch("/test")).rejects.toBeInstanceOf(TypeError);
    expect(isOffline(getNetworkStatus())).toBe(true);
  });

  it("500 KHÔNG phải offline — máy chủ trả lời được nghĩa là có mạng", async () => {
    mockFetchStatus(500, { message: "boom" });
    await expect(apiFetch("/test")).rejects.toBeInstanceOf(ApiError);
    expect(getNetworkStatus().consecutiveNetworkFailures).toBe(0);
    expect(isOffline(getNetworkStatus())).toBe(false);
  });

  it("LAN hỏng mạng rồi Cloud trả 422 ⇒ vẫn là CÓ mạng", async () => {
    setMode("auto");
    resetUnreachable();
    global.fetch = vi
      .fn()
      .mockRejectedValueOnce(new TypeError("Failed to fetch"))
      .mockResolvedValueOnce({
        ok: false,
        status: 422,
        json: async () => ({ message: "invalid" }),
      } as Response);

    await expect(apiFetch("/test")).rejects.toBeInstanceOf(ApiError);
    expect(getNetworkStatus().consecutiveNetworkFailures).toBe(0);
  });

  it("LAN hỏng mạng rồi Cloud cũng hỏng mạng ⇒ đúng là mất kết nối", async () => {
    setMode("auto");
    resetUnreachable();
    global.fetch = vi.fn().mockRejectedValue(new TypeError("Failed to fetch"));

    await expect(apiFetch("/test")).rejects.toBeInstanceOf(TypeError);
    expect(getNetworkStatus().consecutiveNetworkFailures).toBe(1);
  });
});

/**
 * #2951 × #1501 — bọc lỗi KHÔNG được đổi phân loại của nó.
 *
 * `AmbiguousMutationError` được ném ra đúng khi `isNetworkError` vừa đúng ở
 * tầng dưới. Nếu predicate không nhận ra bản đã bọc thì hai chỗ hỏng cùng lúc,
 * và cả hai đều IM LẶNG:
 *
 *  - `use-tables.ts` chỉ `enqueueLightAction` khi `isNetworkError(error)` ⇒ đổi
 *    trạng thái bàn lúc mất mạng không bao giờ vào hàng đợi;
 *  - `replayLightActions` coi "không phải lỗi mạng" là "máy chủ đã từ chối" và
 *    XOÁ hành động ⇒ việc đã xếp hàng bốc hơi ở lượt phát lại đầu tiên.
 *
 * Bài này ghim chiều đó. Nó KHÔNG nói rằng mọi lệnh ghi đều được phát lại —
 * đường tiền không đi qua hàng đợi nhẹ và vẫn phải dừng để đối soát.
 */
describe("#2951 lỗi ghi mơ hồ vẫn là lỗi truyền", () => {
  it("isNetworkError nhận ra bản đã bọc, nên hàng đợi offline GIỮ chứ không xoá", () => {
    const wrapped = new AmbiguousMutationError(
      "POST",
      "/api/v1/pos/tables/t1/status",
      "vi",
      new TypeError("Failed to fetch"),
    );

    expect(isNetworkError(wrapped)).toBe(true);
  });

  it("một câu trả lời dứt khoát của máy chủ thì KHÔNG phải lỗi truyền", () => {
    expect(isNetworkError(new ApiError(422, "unprocessable", undefined))).toBe(
      false,
    );
  });
});
