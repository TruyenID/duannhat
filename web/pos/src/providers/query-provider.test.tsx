import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { useQuery, useQueryClient } from "@tanstack/react-query";
import {
  clearSnapshots,
  putSnapshot,
  readAllSnapshots,
  resetIdbConnection,
} from "@/lib/idb";
import { queryCacheKey } from "@/lib/offline-cache-policy";
import { QueryProvider } from "./query-provider";

const MENU_KEY = ["shop-menus", "quan-1", "list", "vi"] as const;
const TILL_KEY = ["till", "quan-1", "current"] as const;

beforeEach(async () => {
  resetIdbConnection();
  await clearSnapshots();
});

function MenuProbe() {
  const data = useQuery({
    queryKey: MENU_KEY,
    // Không bao giờ resolve: mô phỏng mất mạng, để mọi thứ hiện ra được đều
    // chắc chắn đến TỪ CACHE chứ không từ fetch.
    queryFn: () => new Promise<{ name: string }>(() => {}),
  }).data;

  return <div data-testid="menu">{data?.name ?? "—"}</div>;
}

describe("QueryProvider — #1501 hydrate cache đọc lúc mở app", () => {
  it("nạp ảnh chụp từ IndexedDB vào query cache khi mount", async () => {
    await putSnapshot(queryCacheKey(MENU_KEY), {
      queryKey: [...MENU_KEY],
      cachedAt: Date.now() - 120_000,
      data: { name: "Thực đơn đã lưu" },
    });

    render(
      <QueryProvider>
        <MenuProbe />
      </QueryProvider>,
    );

    expect(screen.getByTestId("menu")).toHaveTextContent("—");
    await waitFor(() =>
      expect(screen.getByTestId("menu")).toHaveTextContent("Thực đơn đã lưu"),
    );
  });

  it("query đọc fetch thành công thì được ghi lại; query tiền thì KHÔNG", async () => {
    function Writer() {
      const qc = useQueryClient();
      void qc.fetchQuery({
        queryKey: MENU_KEY,
        queryFn: async () => ({ name: "tươi" }),
      });
      void qc.fetchQuery({
        queryKey: TILL_KEY,
        queryFn: async () => ({ session_id: "s1", cash: 250_000 }),
      });
      return null;
    }

    render(
      <QueryProvider>
        <Writer />
      </QueryProvider>,
    );

    await waitFor(async () => {
      const rows = await readAllSnapshots();
      expect(rows).toHaveLength(1);
    });

    const rows = await readAllSnapshots();
    expect(rows[0].queryKey[0]).toBe("shop-menus");
    // Ca thu ngân không bao giờ được nằm trong IndexedDB của một tab trình duyệt.
    expect(rows.some((r) => r.queryKey[0] === "till")).toBe(false);
  });

  it("gỡ mount thì huỷ đăng ký — không rò subscriber sang client sau", async () => {
    const { unmount } = render(
      <QueryProvider>
        <div />
      </QueryProvider>,
    );
    unmount();
    expect(await readAllSnapshots()).toEqual([]);
  });
});
