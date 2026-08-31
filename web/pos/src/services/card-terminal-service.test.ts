import { describe, it, expect, vi, beforeEach } from "vitest";

// The P400 is addressed like every other LAN device — straight at the
// workstation, not through apiFetch — so the seam under test is global fetch.
const resolver = vi.hoisted(() => ({
  hasWorkstation: vi.fn(() => true),
  getWorkstationUrl: vi.fn(() => "http://192.168.1.50:6969/"),
}));
vi.mock("./workstation/base-url-resolver", () => resolver);
vi.mock("@/lib/auth", () => ({ getToken: () => "tok" }));

import {
  cardTerminalService,
  cardTerminalAvailable,
  type TerminalSnapshot,
} from "./card-terminal-service";

/**
 * Shim keeping the existing cases readable: they were written against a mock
 * that answered `(path, opts)` and returned the parsed body, which is exactly
 * what the service still consumes — only the transport underneath changed.
 */
const mockFetch = vi.fn();
vi.stubGlobal("fetch", (url: string, init?: RequestInit) => {
  const path = url.replace("http://192.168.1.50:6969", "");
  return Promise.resolve(mockFetch(path, init)).then((body) => ({
    ok: true,
    status: 200,
    json: async () => body,
  })) as Promise<Response>;
});

function snap(status: TerminalSnapshot["status"], over: Partial<TerminalSnapshot> = {}): { data: TerminalSnapshot } {
  return {
    data: { session_id: "S1", order_id: "o1", status, payment_id: "", amount: 3000, error: "", ...over },
  };
}

describe("cardTerminalService.chargeAndWait", () => {
  beforeEach(() => mockFetch.mockReset());

  it("starts a charge then polls until approved", async () => {
    const seq: TerminalSnapshot["status"][] = ["queued", "processing", "approved"];
    let i = 0;
    mockFetch.mockImplementation((path: string, opts?: { method?: string }) => {
      if (opts?.method === "POST") {
        expect(path).toBe("/api/v1/pos/terminal/charge");
        return Promise.resolve({ data: { session_id: "S1", order_id: "o1", total: 3000 } });
      }
      const s = seq[i++] ?? "approved";
      return Promise.resolve(snap(s, s === "approved" ? { payment_id: "pay-1" } : {}));
    });

    const onSession = vi.fn();
    const result = await cardTerminalService.chargeAndWait("o1", { pollMs: 0, onSession });

    expect(onSession).toHaveBeenCalledWith("S1");
    expect(result.status).toBe("approved");
    expect(result.payment_id).toBe("pay-1");
    // 1 start + 3 status polls.
    expect(mockFetch).toHaveBeenCalledTimes(4);
  });

  it("resolves declined with the terminal error", async () => {
    mockFetch.mockImplementation((_path: string, opts?: { method?: string }) => {
      if (opts?.method === "POST") {
        return Promise.resolve({ data: { session_id: "S1", order_id: "o1", total: 1000 } });
      }
      return Promise.resolve(snap("declined", { error: "card declined" }));
    });

    const result = await cardTerminalService.chargeAndWait("o1", { pollMs: 0 });
    expect(result.status).toBe("declined");
    expect(result.error).toBe("card declined");
  });

  it("aborts polling when the signal is set", async () => {
    const ctrl = new AbortController();
    mockFetch.mockImplementation((_path: string, opts?: { method?: string }) => {
      if (opts?.method === "POST") {
        return Promise.resolve({ data: { session_id: "S1", order_id: "o1", total: 1000 } });
      }
      ctrl.abort(); // abort mid-poll
      return Promise.resolve(snap("processing"));
    });

    await expect(
      cardTerminalService.chargeAndWait("o1", { pollMs: 0, signal: ctrl.signal }),
    ).rejects.toMatchObject({ name: "AbortError" });
  });
});

describe("cardTerminalService — no answer is not a decline", () => {
  beforeEach(() => mockFetch.mockReset());

  // Left out of isTerminal(), `unknown` makes the poll spin forever on a session
  // the workstation has already closed.
  it("treats unknown as a terminal state and stops polling", async () => {
    const seq: TerminalSnapshot["status"][] = ["processing", "unknown"];
    let i = 0;
    mockFetch.mockImplementation((_path: string, opts?: { method?: string }) => {
      if (opts?.method === "POST") {
        return Promise.resolve({ data: { session_id: "S1", order_id: "o1", total: 3000 } });
      }
      const s = seq[i++] ?? "unknown";
      return Promise.resolve(
        snap(s, s === "unknown" ? { error: "the card terminal stopped reporting" } : {}),
      );
    });

    const result = await cardTerminalService.chargeAndWait("o1", { pollMs: 0 });

    expect(result.status).toBe("unknown");
    expect(result.error).toContain("stopped reporting");
    expect(mockFetch).toHaveBeenCalledTimes(3); // start + 2 polls, no spin
  });

  // A blip is not an answer: the P400 may still be running, so keep asking.
  it("rides out a transient status failure and still resolves", async () => {
    const seq: Array<"boom" | TerminalSnapshot["status"]> = ["boom", "processing", "approved"];
    let i = 0;
    mockFetch.mockImplementation(async (_path: string, opts?: { method?: string }) => {
      if (opts?.method === "POST") {
        return { data: { session_id: "S1", order_id: "o1", total: 3000 } };
      }
      const s = seq[i++] ?? "approved";
      if (s === "boom") throw new Error("network");
      return snap(s, s === "approved" ? { payment_id: "pay-1" } : {});
    });

    const result = await cardTerminalService.chargeAndWait("o1", { pollMs: 1 });
    expect(result.status).toBe("approved");
  });

  // The one bound the client owns: a workstation that never answers again.
  // It must RETURN unknown, never throw — callers turn a thrown error into
  // "card declined", and that is the one answer a possibly-captured card must
  // never get.
  it("gives up on a permanently unreachable workstation as unknown, without throwing", async () => {
    mockFetch.mockResolvedValueOnce({ data: { session_id: "S1", order_id: "o1", total: 3000 } });
    mockFetch.mockRejectedValueOnce(new Error("net::ERR_CONNECTION_REFUSED"));

    // One unanswered poll is already past the give-up budget here, so the very
    // first failure settles it — no second request is made.
    const result = await cardTerminalService.chargeAndWait("o1", {
      pollMs: 1,
      unreachableGiveUpMs: 1,
    });

    expect(result.status).toBe("unknown");
    expect(result.session_id).toBe("S1");
    expect(result.amount).toBe(3000);
    expect(result.payment_id).toBe("");
  });
});

describe("cardTerminalService — the terminal is LAN hardware, not an API route", () => {
  beforeEach(() => {
    mockFetch.mockReset();
    resolver.hasWorkstation.mockReturnValue(true);
    resolver.getWorkstationUrl.mockReturnValue("http://192.168.1.50:6969/");
  });

  // The regression this replaces: the charge went through apiFetch, so the
  // Cloud/LAN toggle decided whether card payments worked. In Cloud mode it hit
  // the backend — which has no /pos/terminal/* route — and the cashier got a raw
  // framework 404. The printer and the 釣銭機 never had that problem because they
  // address the workstation directly; the P400 was the one exception.
  it("always addresses the workstation, whatever the API mode says", async () => {
    const calls: string[] = [];
    vi.stubGlobal("fetch", (url: string) => {
      calls.push(url);
      return Promise.resolve({
        ok: true,
        status: 200,
        json: async () => ({ data: { session_id: "S1", order_id: "o1", total: 3000 } }),
      } as unknown as Response);
    });

    await cardTerminalService.start("o1");

    expect(calls).toHaveLength(1);
    expect(calls[0]).toBe(
      "http://192.168.1.50:6969/api/v1/pos/terminal/charge",
    );
  });

  // What genuinely blocks the P400 is having no workstation to drive it — it
  // sits on the shop LAN behind NAT and Cloud has no route that reaches it. So
  // the UI gate is "is there a workstation", not "which mode am I in".
  it("reports unavailable only when no workstation is configured", () => {
    expect(cardTerminalAvailable()).toBe(true);
    resolver.hasWorkstation.mockReturnValue(false);
    expect(cardTerminalAvailable()).toBe(false);
  });

  it("fails as unreachable rather than firing at a Cloud host", async () => {
    resolver.hasWorkstation.mockReturnValue(false);
    const spy = vi.fn();
    vi.stubGlobal("fetch", spy);

    await expect(cardTerminalService.start("o1")).rejects.toMatchObject({
      status: 0,
    });
    expect(spy).not.toHaveBeenCalled();
  });
});
