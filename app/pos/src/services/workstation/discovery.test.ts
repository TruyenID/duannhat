import { describe, expect, it } from "vitest";
import {
  serviceToWorkstation,
  sortWorkstations,
  workstationListKey,
  type ZeroconfService,
} from "./discovery";
import type { WorkstationInfo } from "./types";

/**
 * A resolved mDNS record becomes the URL a tablet loads for the rest of the
 * shift. Getting this wrong points a terminal at the wrong machine — or at a
 * link-local/IPv6 address it cannot route to — and the failure looks like "the
 * POS is broken", never like "discovery picked the wrong address".
 */
function svc(overrides: Partial<ZeroconfService> = {}): ZeroconfService {
  return {
    name: "ws-app-hongo",
    host: "ws-app.local.",
    port: 8080,
    addresses: ["192.168.1.10"],
    txt: {},
    ...overrides,
  };
}

describe("serviceToWorkstation", () => {
  it("builds http://<ipv4>:<port> from the resolved record", () => {
    expect(serviceToWorkstation(svc())).toEqual({
      name: "ws-app-hongo",
      branchId: "",
      baseUrl: "http://192.168.1.10:8080",
      version: "0.0.0",
    });
  });

  it("prefers the TXT proxy_url when the workstation advertises one", () => {
    const got = serviceToWorkstation(
      svc({ txt: { proxy_url: "https://hongo.godx.jp/" } }),
    );
    expect(got?.baseUrl).toBe("https://hongo.godx.jp");
  });

  it("reads name / branch_id / version out of TXT", () => {
    const got = serviceToWorkstation(
      svc({ txt: { name: "本郷店", branch_id: "b-1", version: "0.4.0" } }),
    );
    expect(got).toMatchObject({ name: "本郷店", branchId: "b-1", version: "0.4.0" });
  });

  it("falls back name → txt.store → service name", () => {
    expect(serviceToWorkstation(svc({ txt: { store: "Hongo" } }))?.name).toBe("Hongo");
    expect(serviceToWorkstation(svc({ txt: {} }))?.name).toBe("ws-app-hongo");
  });

  it("skips non-IPv4 addresses — an IPv6 literal would need brackets", () => {
    const got = serviceToWorkstation(
      svc({ addresses: ["fe80::1", "2001:db8::1", "192.168.1.11"] }),
    );
    expect(got?.baseUrl).toBe("http://192.168.1.11:8080");
  });

  it("returns null when there is no usable address", () => {
    expect(serviceToWorkstation(svc({ addresses: ["fe80::1"] }))).toBeNull();
    expect(serviceToWorkstation(svc({ addresses: [] }))).toBeNull();
    expect(serviceToWorkstation(svc({ addresses: undefined }))).toBeNull();
  });

  it("returns null when the record carries no port", () => {
    expect(serviceToWorkstation(svc({ port: undefined }))).toBeNull();
  });

  it("survives a record with no TXT block at all", () => {
    expect(serviceToWorkstation(svc({ txt: undefined }))).toMatchObject({
      baseUrl: "http://192.168.1.10:8080",
      branchId: "",
      version: "0.0.0",
    });
  });

  it("normalizes a scheme-less proxy_url instead of producing a relative URL", () => {
    expect(serviceToWorkstation(svc({ txt: { proxy_url: "ws.example:9000" } }))?.baseUrl).toBe(
      "http://ws.example:9000",
    );
  });
});

function ws(overrides: Partial<WorkstationInfo> = {}): WorkstationInfo {
  return {
    name: "Hongo",
    branchId: "b-1",
    baseUrl: "http://192.168.1.10:8080",
    version: "0.4.0",
    ...overrides,
  };
}

/**
 * The order the setup screen offers. `recompute()` cannot be unit-tested (it
 * only runs with the native mDNS client attached), so the ordering rule lives
 * here as a free function and is pinned directly.
 */
describe("sortWorkstations", () => {
  it("puts the NEWEST first, compared numerically — 0.10.0 beats 0.9.0", () => {
    const sorted = sortWorkstations([
      ws({ name: "old", version: "0.9.0" }),
      ws({ name: "new", version: "0.10.0" }),
    ]);
    expect(sorted.map((w) => w.version)).toEqual(["0.10.0", "0.9.0"]);
  });

  it("breaks a version tie by name so the list does not jitter between scans", () => {
    const sorted = sortWorkstations([
      ws({ name: "b-shop" }),
      ws({ name: "a-shop" }),
      ws({ name: "c-shop" }),
    ]);
    expect(sorted.map((w) => w.name)).toEqual(["a-shop", "b-shop", "c-shop"]);
  });

  it("ranks a workstation with no advertised version last", () => {
    const sorted = sortWorkstations([
      ws({ name: "unknown", version: "0.0.0" }),
      ws({ name: "known", version: "0.4.0" }),
    ]);
    expect(sorted.map((w) => w.name)).toEqual(["known", "unknown"]);
  });

  it("does not mutate the caller's array", () => {
    const input = [ws({ name: "b", version: "0.1.0" }), ws({ name: "a", version: "0.2.0" })];
    sortWorkstations(input);
    expect(input.map((w) => w.name)).toEqual(["b", "a"]);
  });
});

/**
 * The change key decides whether listeners are told anything at all. Keying on
 * baseUrl alone silently froze the setup screen's name/version columns.
 */
describe("workstationListKey", () => {
  it("changes when a listed workstation is RENAMED at the same address", () => {
    expect(workstationListKey([ws({ name: "Hongo" })])).not.toBe(
      workstationListKey([ws({ name: "Hongo Ekimae" })]),
    );
  });

  it("changes when a listed workstation is UPGRADED at the same address", () => {
    expect(workstationListKey([ws({ version: "0.4.0" })])).not.toBe(
      workstationListKey([ws({ version: "0.5.0" })]),
    );
  });

  it("changes when a listed workstation re-pairs to another branch", () => {
    expect(workstationListKey([ws({ branchId: "b-1" })])).not.toBe(
      workstationListKey([ws({ branchId: "b-2" })]),
    );
  });

  it("is stable when nothing moved — no spurious re-render", () => {
    expect(workstationListKey([ws(), ws({ baseUrl: "http://192.168.1.11:8080" })])).toBe(
      workstationListKey([ws(), ws({ baseUrl: "http://192.168.1.11:8080" })]),
    );
  });

  it("changes when a workstation joins or leaves", () => {
    expect(workstationListKey([ws()])).not.toBe(workstationListKey([]));
    expect(workstationListKey([ws()])).not.toBe(
      workstationListKey([ws(), ws({ baseUrl: "http://192.168.1.11:8080" })]),
    );
  });

  it("distinguishes two workstations that differ only by order", () => {
    const a = ws({ name: "a", baseUrl: "http://192.168.1.10:8080" });
    const b = ws({ name: "b", baseUrl: "http://192.168.1.11:8080" });
    expect(workstationListKey([a, b])).not.toBe(workstationListKey([b, a]));
  });
});
