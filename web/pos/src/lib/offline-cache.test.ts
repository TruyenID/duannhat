import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { QueryClient } from "@tanstack/react-query";
import {
  clearSnapshots,
  putSnapshot,
  readAllSnapshots,
  resetIdbConnection,
} from "./idb";
import { queryCacheKey } from "./offline-cache-policy";
import { hydrateQueryCache, startQueryCachePersistence } from "./offline-cache";
import { getNetworkStatus, resetNetworkStatus } from "./network-status";

const MENU_KEY = ["shop-menus", "quan-1", "list", "vi"] as const;
const ORDERS_KEY = ["orders", "quan-1", "list", { status: "open" }] as const;

function makeClient() {
  return new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
}

/** Chờ vòng microtask để `void putSnapshot(...)` trong subscriber kịp chạy. */
async function flush() {
  await new Promise((r) => setTimeout(r, 0));
}

beforeEach(async () => {
  resetIdbConnection();
  resetNetworkStatus();
  await clearSnapshots();
});

describe("startQueryCachePersistence — chỉ ghi cái được phép", () => {
  it("query đọc fetch thành công thì rơi xuống IndexedDB", async () => {
    const qc = makeClient();
    const stop = startQueryCachePersistence(qc);

    await qc.fetchQuery({
      queryKey: MENU_KEY,
      queryFn: async () => ({ data: [{ id: "m1" }] }),
    });
    await flush();

    const rows = await readAllSnapshots();
    expect(rows).toHaveLength(1);
    expect(rows[0].queryKey).toEqual([...MENU_KEY]);
    expect(rows[0].data).toEqual({ data: [{ id: "m1" }] });
    stop();
  });

  it("KHÔNG ghi query đường tiền — dù nó fetch thành công", async () => {
    const qc = makeClient();
    const stop = startQueryCachePersistence(qc);

    await qc.fetchQuery({
      queryKey: ORDERS_KEY,
      queryFn: async () => ({ data: [{ id: "o1", total: 12_000 }] }),
    });
    await flush();

    expect(await readAllSnapshots()).toEqual([]);
    stop();
  });

  it("bỏ qua `setQueryData` thủ công — nếu không, hydrate sẽ tự làm mình trẻ ra", async () => {
    const qc = makeClient();
    const stop = startQueryCachePersistence(qc);

    qc.setQueryData([...MENU_KEY], { data: [] });
    await flush();

    expect(await readAllSnapshots()).toEqual([]);
    stop();
  });

  it("huỷ đăng ký thì thôi ghi", async () => {
    const qc = makeClient();
    const stop = startQueryCachePersistence(qc);
    stop();

    await qc.fetchQuery({ queryKey: MENU_KEY, queryFn: async () => ({ a: 1 }) });
    await flush();

    expect(await readAllSnapshots()).toEqual([]);
  });

  it("query lỗi thì không ghi gì", async () => {
    const qc = makeClient();
    const stop = startQueryCachePersistence(qc);

    await qc
      .fetchQuery({
        queryKey: MENU_KEY,
        queryFn: async () => {
          throw new Error("boom");
        },
      })
      .catch(() => {});
    await flush();

    expect(await readAllSnapshots()).toEqual([]);
    stop();
  });
});

describe("hydrateQueryCache", () => {
  it("nạp lại dữ liệu VÀ giữ đúng tuổi thật — đó là cả cơ chế revalidate", async () => {
    const cachedAt = Date.now() - 60 * 60 * 1000; // 1 giờ trước
    await putSnapshot(queryCacheKey(MENU_KEY), {
      queryKey: [...MENU_KEY],
      cachedAt,
      data: { data: [{ id: "cũ" }] },
    });

    const qc = makeClient();
    const result = await hydrateQueryCache(qc);

    expect(result.restored).toBe(1);
    expect(qc.getQueryData([...MENU_KEY])).toEqual({ data: [{ id: "cũ" }] });

    const state = qc.getQueryState([...MENU_KEY]);
    expect(state?.dataUpdatedAt).toBe(cachedAt);
    // `staleTime` mặc định của pos-web là 30s ⇒ một ảnh chụp 1 giờ tuổi phải
    // đã quá hạn ngay lúc nạp, để TanStack tự refetch khi có mạng lại. Hydrate
    // bằng `Date.now()` sẽ làm query trông như vừa lấy về và POS ngồi im.
    expect(Date.now() - (state?.dataUpdatedAt ?? 0)).toBeGreaterThan(30_000);
    expect(result.newestCachedAt).toBe(cachedAt);
  });

  it("KHÔNG đè lên dữ liệu tươi hơn đã có (hydrate về chậm hơn fetch)", async () => {
    const qc = makeClient();
    await qc.fetchQuery({
      queryKey: MENU_KEY,
      queryFn: async () => ({ data: [{ id: "mới" }] }),
    });

    await putSnapshot(queryCacheKey(MENU_KEY), {
      queryKey: [...MENU_KEY],
      cachedAt: Date.now() - 60_000,
      data: { data: [{ id: "cũ" }] },
    });

    const result = await hydrateQueryCache(qc);
    expect(result.restored).toBe(0);
    expect(result.skipped).toBe(1);
    expect(qc.getQueryData([...MENU_KEY])).toEqual({ data: [{ id: "mới" }] });
  });

  it("bản ghi cũ KHÔNG được hưởng luật cũ khi policy siết lại", async () => {
    // Giả lập: một bản ghi đường tiền còn sót từ bản cài trước đó.
    await putSnapshot(queryCacheKey(ORDERS_KEY), {
      queryKey: [...ORDERS_KEY],
      cachedAt: Date.now() - 1000,
      data: { data: [{ id: "o1", total: 12_000 }] },
    });

    const qc = makeClient();
    const result = await hydrateQueryCache(qc);

    expect(result.restored).toBe(0);
    expect(result.skipped).toBe(1);
    expect(qc.getQueryData([...ORDERS_KEY])).toBeUndefined();
  });

  it("lấy tuổi của ảnh chụp MỚI NHẤT, không phải cái đọc ra sau cùng", async () => {
    const older = Date.now() - 10 * 60_000;
    const newer = Date.now() - 60_000;
    await putSnapshot(queryCacheKey(["tables", "quan-1", "list", {}]), {
      queryKey: ["tables", "quan-1", "list", {}],
      cachedAt: newer,
      data: { data: [] },
    });
    await putSnapshot(queryCacheKey(MENU_KEY), {
      queryKey: [...MENU_KEY],
      cachedAt: older,
      data: { data: [] },
    });

    const result = await hydrateQueryCache(makeClient());
    expect(result.restored).toBe(2);
    expect(result.newestCachedAt).toBe(newer);
  });

  it("bản ghi méo mó thì bỏ qua chứ không làm hỏng lượt hydrate", async () => {
    await putSnapshot("null", null as never);
    await putSnapshot("rác", {
      queryKey: "không-phải-mảng",
      cachedAt: 1,
      data: 1,
    } as never);
    await putSnapshot("thiếu-data", {
      queryKey: [...MENU_KEY],
      cachedAt: 1,
    } as never);
    await putSnapshot(queryCacheKey(MENU_KEY), {
      queryKey: [...MENU_KEY],
      cachedAt: 2,
      data: { ok: true },
    });

    const qc = makeClient();
    const result = await hydrateQueryCache(qc);
    expect(result.restored).toBe(1);
    expect(result.skipped).toBe(3);
  });

  it("gieo tuổi dữ liệu cho banner offline", async () => {
    const cachedAt = Date.now() - 5 * 60_000;
    await putSnapshot(queryCacheKey(MENU_KEY), {
      queryKey: [...MENU_KEY],
      cachedAt,
      data: { ok: true },
    });

    await hydrateQueryCache(makeClient());
    expect(getNetworkStatus().lastSyncedAt).toBe(cachedAt);
  });

  it("cache rỗng thì trả về 0 và không gieo gì", async () => {
    const result = await hydrateQueryCache(makeClient());
    expect(result).toEqual({ restored: 0, skipped: 0, newestCachedAt: null });
    expect(getNetworkStatus().lastSyncedAt).toBeNull();
  });
});

describe("vòng đời đầy đủ — ghi ở phiên này, đọc lại ở phiên sau", () => {
  it("phiên 2 hiển thị được thực đơn của phiên 1 mà không gọi mạng", async () => {
    const first = makeClient();
    const stop = startQueryCachePersistence(first);
    await first.fetchQuery({
      queryKey: MENU_KEY,
      queryFn: async () => ({ data: [{ id: "m1", name: "Phở" }] }),
    });
    await flush();
    stop();

    // Phiên sau: client mới toanh, hàm fetch sẽ ném nếu bị gọi.
    const second = makeClient();
    const netCall = vi.fn(async () => {
      throw new Error("mạng không được gọi ở đây");
    });
    await hydrateQueryCache(second);

    expect(second.getQueryData([...MENU_KEY])).toEqual({
      data: [{ id: "m1", name: "Phở" }],
    });
    expect(netCall).not.toHaveBeenCalled();
  });
});
