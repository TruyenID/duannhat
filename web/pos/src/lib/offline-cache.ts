/**
 * #1501 — nối IndexedDB với TanStack Query.
 *
 * Hai chiều, và chỉ hai chiều:
 *
 *   - **GHI**: mọi query ĐỌC-được-phép (xem `offline-cache-policy.ts`) fetch
 *     thành công thì ảnh chụp của nó rơi xuống IndexedDB.
 *   - **ĐỌC**: lúc mở app, các ảnh chụp được nạp lại vào query cache **kèm
 *     `updatedAt` là thời điểm chụp**, không phải "bây giờ".
 *
 * Chi tiết `updatedAt` là cả cơ chế revalidate, nên nó đáng một đoạn riêng:
 * đặt đúng tuổi thật thì TanStack thấy ngay dữ liệu đã quá `staleTime` (30s)
 * và tự refetch khi query được mount / khi có mạng lại. Nếu hydrate bằng
 * `Date.now()` thì cache trông như vừa lấy về, POS ngồi im 30 giây với dữ liệu
 * có thể đã một ngày tuổi, và **không cần thêm dòng "revalidate" nào cả** —
 * viết sai chỗ này thì phải tự dựng lại logic revalidate ở nơi khác.
 *
 * Vì sao không dùng `@tanstack/react-query-persist-client`: nó dehydrate CẢ
 * cache rồi mới lọc, tức mặc định là "lưu tất" và ranh giới tiền trở thành
 * một callback dễ quên. Ở đây mặc định là KHÔNG lưu gì.
 */
import type { QueryClient } from "@tanstack/react-query";
import { putSnapshot, readAllSnapshots, type QuerySnapshot } from "./idb";
import { isCacheableQueryKey, queryCacheKey } from "./offline-cache-policy";
import { seedLastSyncedAt } from "./network-status";

export interface HydrateResult {
  /** Số query được nạp lại từ IndexedDB. */
  restored: number;
  /** Số ảnh chụp bị bỏ qua (đã có dữ liệu mới hơn, hoặc policy đã đổi). */
  skipped: number;
  /** epoch ms của ảnh chụp MỚI NHẤT được nạp — tuổi dữ liệu để banner in ra. */
  newestCachedAt: number | null;
}

function isValidSnapshot(snapshot: unknown): snapshot is QuerySnapshot {
  if (typeof snapshot !== "object" || snapshot === null) return false;
  const s = snapshot as Partial<QuerySnapshot>;
  return (
    Array.isArray(s.queryKey) &&
    typeof s.cachedAt === "number" &&
    s.data !== undefined
  );
}

/**
 * Nạp ảnh chụp từ IndexedDB vào query cache.
 *
 * Gọi một lần lúc khởi động. Bất đồng bộ, nên nó CHẠY ĐUA với những lần fetch
 * đầu tiên — và đó là lý do có bước so `dataUpdatedAt`: nếu query đã có dữ
 * liệu tươi hơn (fetch về trước khi IndexedDB kịp mở) thì ảnh chụp bị bỏ. Thiếu
 * bước này, một lần hydrate chậm sẽ **đè dữ liệu vừa lấy về bằng dữ liệu cũ**,
 * và triệu chứng là màn hình tự nhảy lùi một nhịp — gần như không tài nào lần
 * ra từ báo cáo của thu ngân.
 */
export async function hydrateQueryCache(
  queryClient: QueryClient,
): Promise<HydrateResult> {
  const snapshots = await readAllSnapshots();
  let restored = 0;
  let skipped = 0;
  let newestCachedAt: number | null = null;

  for (const snapshot of snapshots) {
    if (!isValidSnapshot(snapshot)) {
      skipped += 1;
      continue;
    }
    // Policy có thể đã siết lại kể từ lần ghi (ví dụ một root bị chuyển sang
    // nhóm tiền). Bản ghi cũ KHÔNG được hưởng luật cũ.
    if (!isCacheableQueryKey(snapshot.queryKey)) {
      skipped += 1;
      continue;
    }

    const key = [...snapshot.queryKey];
    const state = queryClient.getQueryState(key);
    if (state?.data !== undefined && state.dataUpdatedAt >= snapshot.cachedAt) {
      skipped += 1;
      continue;
    }

    queryClient.setQueryData(key, snapshot.data, {
      updatedAt: snapshot.cachedAt,
    });
    restored += 1;
    if (newestCachedAt === null || snapshot.cachedAt > newestCachedAt) {
      newestCachedAt = snapshot.cachedAt;
    }
  }

  seedLastSyncedAt(newestCachedAt);
  return { restored, skipped, newestCachedAt };
}

/**
 * Theo dõi query cache và ghi ảnh chụp của các query được phép.
 *
 * Trả về hàm huỷ đăng ký.
 */
export function startQueryCachePersistence(
  queryClient: QueryClient,
): () => void {
  return queryClient.getQueryCache().subscribe((event) => {
    if (event.type !== "updated") return;
    const action = event.action;
    if (action.type !== "success") return;
    // `setQueryData` cũng phát action "success" với `manual: true` — kể cả lần
    // hydrate ngay trên kia. Ghi lại chính thứ vừa đọc ra là vô nghĩa, và tệ
    // hơn: nó làm `cachedAt` trẻ ra mà dữ liệu thì không mới hơn chút nào.
    if (action.manual === true) return;

    const query = event.query;
    if (!isCacheableQueryKey(query.queryKey)) return;

    const data = query.state.data;
    if (data === undefined) return;

    void putSnapshot(queryCacheKey(query.queryKey), {
      queryKey: [...query.queryKey],
      cachedAt: query.state.dataUpdatedAt,
      data,
    });
  });
}
