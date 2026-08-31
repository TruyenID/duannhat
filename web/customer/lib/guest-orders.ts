"use client";

/**
 * Guest order persistence (takeaway flow).
 *
 * Logged-in customers have their orders on the BE (/me/orders); guests
 * don't. To let unauthenticated customers re-check status after closing
 * the tab, we mirror a thin pointer (id + code + shop slug + type) into
 * localStorage with a 3-day TTL.
 *
 * Lifecycle:
 *   - Saved by checkout flows after successful POST /orders (guest only).
 *   - Read by /orders list page + /orders/[id] detail page (live status
 *     fetched from BE per id).
 *   - Read by Header to decide whether to show "Lịch sử đơn hàng" CTA.
 *   - Auto-pruned on every read: entries older than TTL drop off.
 *   - Claimed-and-cleared after login if BE claim endpoint succeeds.
 *
 * Privacy: only public order IDs are stored. No PII, no payment data.
 */

const STORAGE_KEY = "tempo:guest-orders"; // Changed from "tempo-guest-orders" to match actual usage
const OLD_STORAGE_KEY = "tempo-guest-orders"; // Backward compat migration
const TTL_MS = 3 * 24 * 60 * 60 * 1000; // 3 days

export interface GuestOrder {
  id: string;
  code: string;
  /** Branch slug — used to link back to the right shop's menu page. */
  shop: string;
  /** plan-031: Takeaway orders ONLY (per plan scope). */
  type: "takeaway";
  /** ISO timestamp — basis for TTL pruning. */
  createdAt: string;
}

function isExpired(order: GuestOrder, now: number): boolean {
  const ts = Date.parse(order.createdAt);
  if (Number.isNaN(ts)) return true; // malformed entry → drop
  return now - ts > TTL_MS;
}

function readRaw(): GuestOrder[] {
  if (typeof window === "undefined") return [];
  try {
    // Try new key first
    let raw = window.localStorage.getItem(STORAGE_KEY);

    // Migrate from old key if new key is empty
    if (!raw) {
      const oldRaw = window.localStorage.getItem(OLD_STORAGE_KEY);
      if (oldRaw) {
        window.localStorage.setItem(STORAGE_KEY, oldRaw);
        window.localStorage.removeItem(OLD_STORAGE_KEY);
        raw = oldRaw;
      }
    }

    if (!raw) return [];
    const parsed = JSON.parse(raw);
    if (!Array.isArray(parsed)) return [];
    // Migration: auto-fix old entries missing `type` field by setting default "takeaway"
    return parsed
      .map((o) => {
        if (
          !!o &&
          typeof o === "object" &&
          typeof o.id === "string" &&
          typeof o.code === "string" &&
          typeof o.shop === "string" &&
          typeof o.createdAt === "string"
        ) {
          // Auto-migrate old entries: set type = "takeaway" if missing
          if (!o.type || o.type !== "takeaway") {
            return { ...o, type: "takeaway" as const };
          }
          return o as GuestOrder;
        }
        return null;
      })
      .filter((o): o is GuestOrder => o !== null);
  } catch {
    return [];
  }
}

function writeRaw(orders: GuestOrder[]): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(orders));
    // Notify listeners (Header, /orders, v.v.) rằng guest orders đã thay đổi.
    // Quan trọng: dispatch async để tránh React warning
    // "Cannot update a component while rendering a different component".
    // removeGuestOrder() có thể được gọi bên trong 1 effect khác; nếu
    // dispatch event sync thì listener sẽ setState ngay trong call stack đó.
    if (typeof window !== "undefined") {
      setTimeout(() => {
        window.dispatchEvent(new Event("tempo:guest-orders-updated"));
      }, 0);
    }
  } catch {
    /* quota exceeded — drop silently */
  }
}

/**
 * Return all non-expired guest orders, sorted newest-first. Pruning is
 * eager: any entry older than TTL gets removed from storage as a side
 * effect of this read.
 */
export function loadGuestOrders(): GuestOrder[] {
  const now = Date.now();
  const all = readRaw();
  if (all.length === 0) return [];

  const alive = all.filter((o) => !isExpired(o, now));
  if (alive.length !== all.length) {
    writeRaw(alive);
  }

  return alive.sort(
    (a, b) => Date.parse(b.createdAt) - Date.parse(a.createdAt),
  );
}

/**
 * Idempotent — re-saving the same id replaces the previous entry. Handy
 * for the retry-with-same-order flow where the same order might be
 * persisted twice across attempts.
 * plan-031: Takeaway orders ONLY (per plan scope).
 */
export function saveGuestOrder(
  order: Omit<GuestOrder, "createdAt" | "type"> & {
    type?: "takeaway";
    createdAt?: string;
  },
): void {
  // Validation: ID phải là non-empty string
  // NOTE: Không validate strict UUID format vì backend có thể trả về UUID 37 chars (bug)
  // hoặc ULID format. Chấp nhận mọi non-empty string ID.
  if (!order.id || typeof order.id !== "string" || order.id.trim().length === 0) {
    console.error("[guest-orders] invalid order id — skipping save:", order.id);
    return;
  }

  const existing = readRaw();

  const next: GuestOrder = {
    id: order.id,
    code: order.code,
    shop: order.shop,
    type: order.type ?? "takeaway",
    createdAt: order.createdAt ?? new Date().toISOString(),
  };

  const filtered = existing.filter((o) => o.id !== next.id);
  filtered.push(next);
  writeRaw(filtered.filter((o) => !isExpired(o, Date.now())));
}

export function removeGuestOrder(id: string): void {
  writeRaw(readRaw().filter((o) => o.id !== id));
}

export function clearGuestOrders(): void {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.removeItem(STORAGE_KEY);
    if (typeof window !== "undefined") {
      setTimeout(() => {
        window.dispatchEvent(new Event("tempo:guest-orders-updated"));
      }, 0);
    }
  } catch {
    /* ignore */
  }
}
