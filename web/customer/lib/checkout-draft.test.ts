import { test, beforeEach } from "node:test";
import assert from "node:assert/strict";

// ---------------------------------------------------------------------------
// plan-031 — checkout-draft persistence (the pre-order write side).
//
// A draft is the takeaway order-in-progress held in localStorage between
// /checkout and the POST /orders commit. Its `expires_at` is the client-side
// timeout twin of the server countdown: an expired draft must self-evict on
// read so a stale order can't be committed or paid. These lock that expiry
// gate plus save/verify, per-id isolation, active-draft discovery, and the
// unambiguous draft-code alphabet. Previously untested.
// ---------------------------------------------------------------------------

class MemoryStorage {
  private store = new Map<string, string>();
  get length(): number {
    return this.store.size;
  }
  key(i: number): string | null {
    return Array.from(this.store.keys())[i] ?? null;
  }
  getItem(k: string): string | null {
    return this.store.has(k) ? (this.store.get(k) as string) : null;
  }
  setItem(k: string, v: string): void {
    this.store.set(k, String(v));
  }
  removeItem(k: string): void {
    this.store.delete(k);
  }
  clear(): void {
    this.store.clear();
  }
}

const storage = new MemoryStorage();
(globalThis as unknown as { window: unknown }).window = { localStorage: storage };
console.error = () => {};

const {
  saveCheckoutDraft,
  loadCheckoutDraft,
  removeCheckoutDraft,
  findActiveCheckoutDraft,
  generateDraftCode,
} = await import("./checkout-draft.ts");

type Draft = Parameters<typeof saveCheckoutDraft>[0];

function draft(id: string, expiresInMs: number, extra: Partial<Draft> = {}): Draft {
  return {
    id,
    code: `DR-${id}`,
    shop_slug: "shop",
    items: [],
    payment_method: "counter",
    total: 1000,
    currency_code: "VND",
    created_at: new Date().toISOString(),
    expires_at: new Date(Date.now() + expiresInMs).toISOString(),
    ...extra,
  } as Draft;
}

const MIN = 60 * 1000;

beforeEach(() => {
  storage.clear();
});

// --- save / load roundtrip -----------------------------------------------

test("saveCheckoutDraft persists and returns true; loadCheckoutDraft round-trips", () => {
  const d = draft("abc", 10 * MIN, { customer_name: "Mai", note: "no ice" });
  assert.equal(saveCheckoutDraft(d), true);
  const got = loadCheckoutDraft("abc");
  assert.equal(got?.id, "abc");
  assert.equal(got?.customer_name, "Mai");
  assert.equal(got?.total, 1000);
});

// #1768 — draft.items[].note must round-trip. The counter-pay commit path
// (/order-confirm) POSTs entirely from the draft — a draft-item shape without
// `note` silently drops any per-item request ("không hành", "ít cay") the
// customer typed in the product modal, and the kitchen never sees it. This
// gate locks the field on the persisted shape.
test("saveCheckoutDraft persists items[].note round-trip (#1768)", () => {
  const d = draft("with-notes", 10 * MIN, {
    items: [
      {
        id: "with-notes-i0",
        product_sku_id: "sku-a",
        name: "Phở bò",
        qty: 1,
        unit_price: 60000,
        subtotal: 60000,
        note: "Không hành, ít cay",
      },
      {
        id: "with-notes-i1",
        product_sku_id: "sku-b",
        name: "Trà đá",
        qty: 2,
        unit_price: 5000,
        subtotal: 10000,
      },
    ],
  });
  assert.equal(saveCheckoutDraft(d), true);
  const got = loadCheckoutDraft("with-notes");
  assert.equal(got?.items[0]?.note, "Không hành, ít cay");
  assert.equal(got?.items[1]?.note, undefined, "missing note stays undefined, no default");
});

test("loadCheckoutDraft returns null for an unknown id", () => {
  assert.equal(loadCheckoutDraft("missing"), null);
});

// --- expiry gate (client-side timeout twin) ------------------------------

test("loadCheckoutDraft evicts an expired draft and returns null", () => {
  saveCheckoutDraft(draft("old", -1 * MIN)); // already past expires_at
  assert.equal(loadCheckoutDraft("old"), null);
  // self-eviction: the key is physically gone, not just filtered
  assert.equal(storage.getItem("tempo:checkout-draft:old"), null);
});

test("loadCheckoutDraft treats an unparseable expires_at as expired and evicts", () => {
  saveCheckoutDraft(draft("bad", 10 * MIN, { expires_at: "not-a-timestamp" }));
  assert.equal(loadCheckoutDraft("bad"), null);
  assert.equal(storage.getItem("tempo:checkout-draft:bad"), null);
});

test("loadCheckoutDraft returns null (without crashing) for structurally invalid payloads", () => {
  storage.setItem("tempo:checkout-draft:noitems", JSON.stringify({ id: "noitems", items: "nope", expires_at: new Date(Date.now() + MIN).toISOString() }));
  storage.setItem("tempo:checkout-draft:noid", JSON.stringify({ items: [], expires_at: new Date(Date.now() + MIN).toISOString() }));
  storage.setItem("tempo:checkout-draft:garbage", "{ not json");
  assert.equal(loadCheckoutDraft("noitems"), null);
  assert.equal(loadCheckoutDraft("noid"), null);
  assert.equal(loadCheckoutDraft("garbage"), null);
});

// --- per-id isolation -----------------------------------------------------

test("drafts are isolated by id — one does not clobber another", () => {
  saveCheckoutDraft(draft("a", 10 * MIN, { total: 111 }));
  saveCheckoutDraft(draft("b", 10 * MIN, { total: 222 }));
  assert.equal(loadCheckoutDraft("a")?.total, 111);
  assert.equal(loadCheckoutDraft("b")?.total, 222);
  removeCheckoutDraft("a");
  assert.equal(loadCheckoutDraft("a"), null);
  assert.equal(loadCheckoutDraft("b")?.total, 222, "removing a leaves b intact");
});

// --- findActiveCheckoutDraft (the redirect guard) -------------------------

test("findActiveCheckoutDraft returns an active draft when one exists", () => {
  saveCheckoutDraft(draft("live", 5 * MIN));
  assert.equal(findActiveCheckoutDraft()?.id, "live");
});

test("findActiveCheckoutDraft returns null when every draft is expired", () => {
  saveCheckoutDraft(draft("e1", -1 * MIN));
  saveCheckoutDraft(draft("e2", -2 * MIN));
  assert.equal(findActiveCheckoutDraft(), null);
});

// BUG (plan-031, documented current behaviour — do NOT treat as intended):
// findActiveCheckoutDraft() scans localStorage by numeric index in a forward
// loop, but loadCheckoutDraft() *deletes* expired keys as a side effect. On a
// spec-compliant Storage, removeItem re-compacts indices, so the entry right
// after an evicted one shifts down into the just-visited slot and is skipped.
// Result: an ACTIVE draft ordered after an expired draft can be missed and the
// guard wrongly reports "no active draft", letting the customer start a second
// order. Correct behaviour would return the "alive" draft here. This test locks
// the CURRENT (buggy) output so a future fix visibly flips it. See PR notes.
test("findActiveCheckoutDraft MISSES a live draft that follows an expired one (known bug)", () => {
  saveCheckoutDraft(draft("dead", -1 * MIN)); // index 0 → evicted mid-scan
  saveCheckoutDraft(draft("alive", 5 * MIN)); // index 1 → shifts to 0, skipped
  // Current behaviour: returns null instead of the live "alive" draft.
  assert.equal(findActiveCheckoutDraft(), null);
  // The live draft itself is untouched in storage — it was simply never scanned.
  assert.ok(storage.getItem("tempo:checkout-draft:alive"), "live draft still persisted");
});

// --- generateDraftCode alphabet ------------------------------------------

test("generateDraftCode is DR- plus 4 chars from the unambiguous alphabet", () => {
  const re = /^DR-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/;
  for (let i = 0; i < 500; i++) {
    const code = generateDraftCode();
    assert.match(code, re);
    // never emit the visually ambiguous glyphs I, O, 0, 1
    assert.ok(!/[IO01]/.test(code.slice(3)), `ambiguous glyph in ${code}`);
  }
});
