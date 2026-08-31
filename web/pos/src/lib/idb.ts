/**
 * #1501 (tầng 2 của #1170) — kho IndexedDB best-effort cho pos-web.
 *
 * Mẫu lấy từ `godx-kds/src/lib/idb.ts`, khác hai điểm có chủ đích:
 *
 *   1. **Mỗi bản ghi mang `cachedAt`.** Một màn POS hiện số liệu cũ mà không
 *      nói là cũ thì tệ hơn màn báo lỗi — banner offline phải in được "dữ liệu
 *      lúc HH:mm", nên tuổi dữ liệu là một phần của bản ghi, không phải thứ
 *      suy ra sau.
 *   2. **Không có store nào cho tiền.** Cái gì được ghi vào đây do
 *      `offline-cache-policy.ts` quyết định, và policy đó là danh sách CHO
 *      PHÉP, không phải danh sách CẤM.
 *
 * Mọi hàm ở đây **nuốt lỗi**: IndexedDB không có (chế độ riêng tư, webview
 * cũ), quota đầy, hay DB bị người dùng xoá đều chỉ có nghĩa là "không có
 * cache", không bao giờ có nghĩa là "POS hỏng". Đường thành công của app
 * không được phụ thuộc vào cache.
 */
import { openDB, type IDBPDatabase } from "idb";

const DB_NAME = "pos_web_cache";
const DB_VERSION = 1;

export const STORE_QUERY_SNAPSHOTS = "query_snapshots";
export const STORE_LIGHT_ACTIONS = "light_actions";

/** Ảnh chụp một query đọc-được-phép-cache, kèm tuổi của nó. */
export interface QuerySnapshot {
  /**
   * Query key NGUYÊN VẸN. Lưu cả mảng chứ không chỉ chuỗi đã serialize vì lúc
   * hydrate phải gọi `setQueryData(key, …)` với đúng mảng đó — dựng lại key từ
   * chuỗi là một cách âm thầm làm lệch cache.
   */
  queryKey: readonly unknown[];
  /** epoch ms của lần fetch sinh ra bản ghi này. */
  cachedAt: number;
  data: unknown;
}

/**
 * Một hành động NHẸ, KHÔNG dính tiền, đang chờ mạng.
 *
 * Kiểu hành động được `offline-action-queue.ts` giới hạn bằng union đóng —
 * xem lý do ranh giới ở đó.
 */
export interface LightAction {
  id: string;
  type: string;
  payload: Record<string, unknown>;
  queuedAt: number;
}

interface CacheSchema {
  [STORE_QUERY_SNAPSHOTS]: { key: string; value: QuerySnapshot };
  [STORE_LIGHT_ACTIONS]: { key: string; value: LightAction };
}

let dbPromise: Promise<IDBPDatabase<CacheSchema>> | null = null;

function getDB(): Promise<IDBPDatabase<CacheSchema>> {
  if (!dbPromise) {
    // Bọc trong async IIFE để một lỗi NÉM ĐỒNG BỘ của `indexedDB.open`
    // (chế độ riêng tư của Safari ném ngay tại chỗ) cũng thành promise bị
    // reject, thay vì thoát ra khỏi `getDB` trước khi `.catch` bên dưới kịp
    // gắn — nếu không, `dbPromise` giữ nguyên giá trị cũ và mọi lần gọi sau
    // đều đi lại đúng đường ném đó.
    dbPromise = (async () =>
      openDB<CacheSchema>(DB_NAME, DB_VERSION, {
        upgrade(db) {
          if (!db.objectStoreNames.contains(STORE_QUERY_SNAPSHOTS)) {
            db.createObjectStore(STORE_QUERY_SNAPSHOTS);
          }
          if (!db.objectStoreNames.contains(STORE_LIGHT_ACTIONS)) {
            db.createObjectStore(STORE_LIGHT_ACTIONS);
          }
        },
      }))();
    // Một lần mở hỏng không được đóng băng vĩnh viễn mọi lần sau: xoá promise
    // đã reject để lần gọi kế tiếp thử lại (ví dụ user vừa thoát chế độ riêng tư).
    dbPromise.catch(() => {
      dbPromise = null;
    });
  }
  return dbPromise;
}

/** Seam cho test: quên connection đang nhớ. */
export function resetIdbConnection(): void {
  dbPromise = null;
}

function warn(op: string, err: unknown): void {
  // Không im lặng hoàn toàn: cache hỏng là chuyện bình thường, nhưng hỏng
  // mãi mà không ai biết thì tầng offline chỉ tồn tại trên giấy.
  console.warn(`[idb] ${op} failed`, err);
}

// ---------------------------------------------------------------------------
//  Query snapshots
// ---------------------------------------------------------------------------

export async function putSnapshot(
  cacheKey: string,
  snapshot: QuerySnapshot,
): Promise<void> {
  try {
    const db = await getDB();
    await db.put(STORE_QUERY_SNAPSHOTS, snapshot, cacheKey);
  } catch (err) {
    warn("putSnapshot", err);
  }
}

export async function readAllSnapshots(): Promise<QuerySnapshot[]> {
  try {
    const db = await getDB();
    return await db.getAll(STORE_QUERY_SNAPSHOTS);
  } catch (err) {
    warn("readAllSnapshots", err);
    return [];
  }
}

export async function clearSnapshots(): Promise<void> {
  try {
    const db = await getDB();
    await db.clear(STORE_QUERY_SNAPSHOTS);
  } catch (err) {
    warn("clearSnapshots", err);
  }
}

// ---------------------------------------------------------------------------
//  Light action queue
// ---------------------------------------------------------------------------

export async function putLightAction(action: LightAction): Promise<void> {
  try {
    const db = await getDB();
    await db.put(STORE_LIGHT_ACTIONS, action, action.id);
  } catch (err) {
    warn("putLightAction", err);
  }
}

export async function readLightActions(): Promise<LightAction[]> {
  try {
    const db = await getDB();
    const rows = await db.getAll(STORE_LIGHT_ACTIONS);
    // Thứ tự phát lại phải là thứ tự xếp hàng: hai lần đổi trạng thái cùng một
    // bàn mà chạy ngược thứ tự thì trạng thái CŨ thắng.
    return rows.sort((a, b) => a.queuedAt - b.queuedAt);
  } catch (err) {
    warn("readLightActions", err);
    return [];
  }
}

export async function deleteLightAction(id: string): Promise<void> {
  try {
    const db = await getDB();
    await db.delete(STORE_LIGHT_ACTIONS, id);
  } catch (err) {
    warn("deleteLightAction", err);
  }
}

export async function clearLightActions(): Promise<void> {
  try {
    const db = await getDB();
    await db.clear(STORE_LIGHT_ACTIONS);
  } catch (err) {
    warn("clearLightActions", err);
  }
}
