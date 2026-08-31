import { openDB, type IDBPDatabase } from "idb";
import type { KdsOrder } from "@/services/kds/orders";

const DB_NAME = "kds_cache";
const DB_VERSION = 1;
const STORE_ORDERS = "orders_snapshot";

export interface OrdersSnapshot {
  fetched_at: string; // ISO timestamp
  orders: KdsOrder[];
}

interface CacheSchema {
  [STORE_ORDERS]: {
    key: string; // always "latest"
    value: OrdersSnapshot;
  };
}

let dbPromise: Promise<IDBPDatabase<CacheSchema>> | null = null;

function getDB(): Promise<IDBPDatabase<CacheSchema>> {
  if (!dbPromise) {
    dbPromise = openDB<CacheSchema>(DB_NAME, DB_VERSION, {
      upgrade(db) {
        if (!db.objectStoreNames.contains(STORE_ORDERS)) {
          db.createObjectStore(STORE_ORDERS);
        }
      },
    });
  }
  return dbPromise;
}

/**
 * Persist the latest orders snapshot to IndexedDB. Called by useOrders on
 * every successful fetch — allows offline boot to render last known state.
 */
export async function saveOrdersSnapshot(snapshot: OrdersSnapshot): Promise<void> {
  try {
    const db = await getDB();
    await db.put(STORE_ORDERS, snapshot, "latest");
  } catch (err) {
    // Silent failure — cache is best-effort. Don't break the success path.
    console.warn("[idb] save orders snapshot failed", err);
  }
}

/**
 * Load the latest cached orders snapshot. Returns null if cache empty or
 * IndexedDB unavailable (e.g., private browsing, certain old browsers).
 */
export async function loadOrdersSnapshot(): Promise<OrdersSnapshot | null> {
  try {
    const db = await getDB();
    const snap = await db.get(STORE_ORDERS, "latest");
    return snap ?? null;
  } catch (err) {
    console.warn("[idb] load orders snapshot failed", err);
    return null;
  }
}

/** Clear the cache. Called by AuthProvider on logout. */
export async function clearOrdersSnapshot(): Promise<void> {
  try {
    const db = await getDB();
    await db.delete(STORE_ORDERS, "latest");
  } catch (err) {
    console.warn("[idb] clear orders snapshot failed", err);
  }
}
