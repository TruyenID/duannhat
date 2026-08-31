import { test, beforeEach } from "node:test";
import assert from "node:assert/strict";

// ---------------------------------------------------------------------------
// Auth-surface coverage — plan-009 test-gap audit.
//
// `guest-orders.ts` is the DATA LAYER of the post-login "claim guest orders"
// flow: `context/auth-context.tsx` calls `loadGuestOrders()` the moment a
// user transitions null → logged-in, POSTs the ids to /me/orders/claim, then
// `clearGuestOrders()` on success. Zero automated coverage existed on this
// surface; these tests lock the high-risk paths — idempotency, TTL pruning,
// malformed/corrupt-entry handling, storage-key migration, and dedup — so a
// refactor of the claim flow can never silently duplicate, resurrect, or drop
// a customer's orders.
//
// Runner: node:test (repo convention). Because the module reads
// `window.localStorage`, we install a minimal in-memory shim BEFORE importing.
// ---------------------------------------------------------------------------

const STORAGE_KEY = "tempo:guest-orders";
const OLD_STORAGE_KEY = "tempo-guest-orders";
const TTL_MS = 3 * 24 * 60 * 60 * 1000; // 3 days — mirrors the module constant

// --- in-memory localStorage shim -------------------------------------------
const store = new Map<string, string>();
const localStorageShim = {
  getItem: (k: string): string | null => (store.has(k) ? store.get(k)! : null),
  setItem: (k: string, v: string): void => {
    store.set(k, String(v));
  },
  removeItem: (k: string): void => {
    store.delete(k);
  },
  clear: (): void => store.clear(),
};

// Install window + Event globals the module touches. dispatchEvent is a no-op
// so the module's fire-and-forget "guest-orders-updated" notifications (queued
// via setTimeout) can't crash the test process after a test finishes.
(globalThis as unknown as { window: unknown }).window = {
  localStorage: localStorageShim,
  dispatchEvent: () => true,
};
(globalThis as unknown as { localStorage: unknown }).localStorage = localStorageShim;
if (typeof (globalThis as { Event?: unknown }).Event === "undefined") {
  (globalThis as { Event: unknown }).Event = class {
    type: string;
    constructor(type: string) {
      this.type = type;
    }
  };
}

// The invalid-id guard below deliberately reports via console.error; silence it
// so an expected rejection doesn't read like a failing run.
console.error = () => {};

const {
  loadGuestOrders,
  saveGuestOrder,
  removeGuestOrder,
  clearGuestOrders,
} = await import("./guest-orders.ts");

// Build a valid stored entry `ageMs` milliseconds old.
function entry(id: string, ageMs = 0, extra: Record<string, unknown> = {}) {
  return {
    id,
    code: `C-${id}`,
    shop: "hongo",
    type: "takeaway",
    createdAt: new Date(Date.now() - ageMs).toISOString(),
    ...extra,
  };
}

function seed(raw: unknown, key = STORAGE_KEY) {
  store.set(key, JSON.stringify(raw));
}

beforeEach(() => {
  store.clear();
});

// ---------------------------------------------------------------------------
// saveGuestOrder — idempotency + validation (the retry-same-order path)
// ---------------------------------------------------------------------------

test("saveGuestOrder: re-saving the same id replaces, never duplicates (idempotent)", () => {
  saveGuestOrder({ id: "ord-1", code: "OLD", shop: "hongo" });
  saveGuestOrder({ id: "ord-1", code: "NEW", shop: "sakura" });

  const all = loadGuestOrders();
  assert.equal(all.length, 1, "same id must collapse to one entry");
  assert.equal(all[0].code, "NEW");
  assert.equal(all[0].shop, "sakura");
});

test("saveGuestOrder: distinct ids accumulate", () => {
  saveGuestOrder({ id: "ord-1", code: "A", shop: "hongo" });
  saveGuestOrder({ id: "ord-2", code: "B", shop: "hongo" });
  assert.equal(loadGuestOrders().length, 2);
});

test("saveGuestOrder: defaults type=takeaway and stamps createdAt when omitted", () => {
  const before = Date.now();
  saveGuestOrder({ id: "ord-1", code: "A", shop: "hongo" });
  const [o] = loadGuestOrders();
  assert.equal(o.type, "takeaway");
  const ts = Date.parse(o.createdAt);
  assert.ok(ts >= before && ts <= Date.now() + 1000, "createdAt must be ~now");
});

test("saveGuestOrder: empty / whitespace / non-string id is rejected (no write)", () => {
  saveGuestOrder({ id: "", code: "A", shop: "hongo" });
  saveGuestOrder({ id: "   ", code: "A", shop: "hongo" });
  // @ts-expect-error — intentionally passing a non-string to exercise the guard
  saveGuestOrder({ id: null, code: "A", shop: "hongo" });
  assert.equal(store.get(STORAGE_KEY), undefined, "no invalid entry may be persisted");
  assert.equal(loadGuestOrders().length, 0);
});

test("saveGuestOrder: a would-be-expired createdAt is dropped by the TTL filter on write", () => {
  saveGuestOrder({
    id: "old",
    code: "A",
    shop: "hongo",
    createdAt: new Date(Date.now() - TTL_MS - 1000).toISOString(),
  });
  assert.equal(loadGuestOrders().length, 0, "expired entry must not persist");
});

// ---------------------------------------------------------------------------
// loadGuestOrders — TTL pruning (eager, side-effecting)
// ---------------------------------------------------------------------------

test("loadGuestOrders: prunes entries older than the 3-day TTL and rewrites storage", () => {
  seed([entry("fresh", 1000), entry("stale", TTL_MS + 60_000)]);
  const alive = loadGuestOrders();
  assert.deepEqual(
    alive.map((o) => o.id),
    ["fresh"],
    "only the fresh entry survives",
  );
  // eager prune: storage itself is rewritten to drop the stale entry
  const persisted = JSON.parse(store.get(STORAGE_KEY)!);
  assert.equal(persisted.length, 1);
  assert.equal(persisted[0].id, "fresh");
});

test("loadGuestOrders: TTL boundary — exactly TTL old survives, just over is pruned", () => {
  // isExpired: now - ts > TTL  → strictly greater. Exactly-TTL is NOT expired.
  //
  // #1200 — the clock must be FROZEN for this one. `entry()` stamps
  // `Date.now() - ageMs` when the fixture is built, `loadGuestOrders()` reads
  // `Date.now()` again a moment later, and the predicate then works out to
  // `readTime - buildTime > 0` — so the "exactly TTL" entry expired as soon as
  // a single millisecond passed. It failed about one run in five. A boundary
  // cannot be asserted against a clock that moves underneath it.
  const realNow = Date.now;
  const frozen = realNow();
  Date.now = () => frozen;
  try {
    seed([entry("edge", TTL_MS), entry("past", TTL_MS + 1)]);
    const ids = loadGuestOrders().map((o) => o.id);
    assert.ok(ids.includes("edge"), "exactly-TTL-old entry is kept");
    assert.ok(!ids.includes("past"), "just-over-TTL entry is dropped");
  } finally {
    Date.now = realNow;
  }
});

test("loadGuestOrders: sorts surviving entries newest-first", () => {
  seed([entry("older", 10_000), entry("newest", 100), entry("middle", 5_000)]);
  assert.deepEqual(
    loadGuestOrders().map((o) => o.id),
    ["newest", "middle", "older"],
  );
});

// ---------------------------------------------------------------------------
// loadGuestOrders — corrupt / malformed input (failure paths)
// ---------------------------------------------------------------------------

test("loadGuestOrders: unparseable JSON → [] (never throws)", () => {
  store.set(STORAGE_KEY, "{not-json");
  assert.deepEqual(loadGuestOrders(), []);
});

test("loadGuestOrders: non-array payload → []", () => {
  seed({ id: "x" });
  assert.deepEqual(loadGuestOrders(), []);
});

test("loadGuestOrders: entries missing required string fields are dropped", () => {
  seed([
    entry("good"),
    { id: "no-code", shop: "hongo", createdAt: new Date().toISOString() },
    { code: "no-id", shop: "hongo", createdAt: new Date().toISOString() },
    { id: 42, code: "num-id", shop: "hongo", createdAt: new Date().toISOString() },
    null,
    "garbage",
  ]);
  const ids = loadGuestOrders().map((o) => o.id);
  assert.deepEqual(ids, ["good"]);
});

test("loadGuestOrders: malformed createdAt (unparseable date) is treated as expired and dropped", () => {
  seed([
    { id: "bad-date", code: "A", shop: "hongo", type: "takeaway", createdAt: "not-a-date" },
    entry("ok"),
  ]);
  assert.deepEqual(
    loadGuestOrders().map((o) => o.id),
    ["ok"],
  );
});

// ---------------------------------------------------------------------------
// loadGuestOrders — migration paths
// ---------------------------------------------------------------------------

test("loadGuestOrders: migrates data from the legacy storage key to the new key", () => {
  seed([entry("legacy")], OLD_STORAGE_KEY);
  const alive = loadGuestOrders();
  assert.deepEqual(alive.map((o) => o.id), ["legacy"]);
  // old key emptied, new key populated
  assert.equal(store.get(OLD_STORAGE_KEY), undefined, "old key removed after migration");
  assert.ok(store.get(STORAGE_KEY), "new key populated after migration");
});

test("loadGuestOrders: back-fills missing/invalid `type` field to 'takeaway'", () => {
  seed([
    { id: "no-type", code: "A", shop: "hongo", createdAt: new Date().toISOString() },
    { id: "wrong-type", code: "B", shop: "hongo", type: "dine_in", createdAt: new Date().toISOString() },
  ]);
  const all = loadGuestOrders();
  assert.equal(all.length, 2);
  for (const o of all) assert.equal(o.type, "takeaway");
});

// ---------------------------------------------------------------------------
// removeGuestOrder + clearGuestOrders (post-claim cleanup)
// ---------------------------------------------------------------------------

test("removeGuestOrder: removes only the target id, leaves the rest intact", () => {
  seed([entry("a"), entry("b"), entry("c")]);
  removeGuestOrder("b");
  assert.deepEqual(
    loadGuestOrders().map((o) => o.id).sort(),
    ["a", "c"],
  );
});

test("removeGuestOrder: unknown id is a no-op", () => {
  seed([entry("a")]);
  removeGuestOrder("does-not-exist");
  assert.equal(loadGuestOrders().length, 1);
});

test("clearGuestOrders: wipes storage (the successful-claim cleanup)", () => {
  seed([entry("a"), entry("b")]);
  clearGuestOrders();
  assert.equal(store.get(STORAGE_KEY), undefined);
  assert.deepEqual(loadGuestOrders(), []);
});
