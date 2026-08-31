import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it, vi } from "vitest";
import {
  clearLightActions,
  clearSnapshots,
  deleteLightAction,
  putLightAction,
  putSnapshot,
  readAllSnapshots,
  readLightActions,
  resetIdbConnection,
} from "./idb";

beforeEach(async () => {
  resetIdbConnection();
  await clearSnapshots();
  await clearLightActions();
});

describe("idb — ảnh chụp query", () => {
  it("ghi rồi đọc lại, giữ nguyên query key và tuổi", async () => {
    await putSnapshot("k1", {
      queryKey: ["shop-menus", "quan-1", "list", "vi"],
      cachedAt: 1_700_000_000_000,
      data: { data: [{ id: "m1" }] },
    });

    const rows = await readAllSnapshots();
    expect(rows).toHaveLength(1);
    expect(rows[0].queryKey).toEqual(["shop-menus", "quan-1", "list", "vi"]);
    expect(rows[0].cachedAt).toBe(1_700_000_000_000);
    expect(rows[0].data).toEqual({ data: [{ id: "m1" }] });
  });

  it("ghi lại cùng khoá thì THAY THẾ, không nhân đôi", async () => {
    await putSnapshot("k1", { queryKey: ["tables", "q"], cachedAt: 1, data: 1 });
    await putSnapshot("k1", { queryKey: ["tables", "q"], cachedAt: 2, data: 2 });

    const rows = await readAllSnapshots();
    expect(rows).toHaveLength(1);
    expect(rows[0].cachedAt).toBe(2);
  });
});

describe("idb — hàng đợi hành động nhẹ", () => {
  it("trả về theo THỨ TỰ XẾP HÀNG, không theo thứ tự khoá", async () => {
    // Hai lần đổi trạng thái cùng một bàn mà phát lại ngược thứ tự thì trạng
    // thái CŨ thắng — nên thứ tự là một phần của hợp đồng, không phải may rủi.
    await putLightAction({ id: "z", type: "t", payload: {}, queuedAt: 300 });
    await putLightAction({ id: "a", type: "t", payload: {}, queuedAt: 100 });
    await putLightAction({ id: "m", type: "t", payload: {}, queuedAt: 200 });

    expect((await readLightActions()).map((a) => a.id)).toEqual(["a", "m", "z"]);
  });

  it("xoá theo id", async () => {
    await putLightAction({ id: "a", type: "t", payload: {}, queuedAt: 1 });
    await putLightAction({ id: "b", type: "t", payload: {}, queuedAt: 2 });
    await deleteLightAction("a");
    expect((await readLightActions()).map((r) => r.id)).toEqual(["b"]);
  });
});

describe("idb — best-effort, không bao giờ ném ra ngoài", () => {
  it("IndexedDB không mở được thì đọc trả rỗng, ghi im lặng", async () => {
    // Chế độ riêng tư / webview cũ: `indexedDB.open` ném ngay. Đường thành
    // công của POS không được phụ thuộc vào cache, nên mọi hàm phải nuốt.
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const open = vi
      .spyOn(indexedDB, "open")
      .mockImplementation(() => {
        throw new Error("SecurityError");
      });
    resetIdbConnection();

    await expect(
      putSnapshot("k", { queryKey: ["tables"], cachedAt: 1, data: 1 }),
    ).resolves.toBeUndefined();
    await expect(readAllSnapshots()).resolves.toEqual([]);
    await expect(readLightActions()).resolves.toEqual([]);
    await expect(
      putLightAction({ id: "a", type: "t", payload: {}, queuedAt: 1 }),
    ).resolves.toBeUndefined();
    await expect(deleteLightAction("a")).resolves.toBeUndefined();
    await expect(clearSnapshots()).resolves.toBeUndefined();
    await expect(clearLightActions()).resolves.toBeUndefined();
    expect(warn).toHaveBeenCalled();

    open.mockRestore();
    warn.mockRestore();
    resetIdbConnection();
  });

  it("một lần mở hỏng không đóng băng vĩnh viễn các lần sau", async () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const open = vi.spyOn(indexedDB, "open").mockImplementationOnce(() => {
      throw new Error("SecurityError");
    });
    resetIdbConnection();

    expect(await readAllSnapshots()).toEqual([]);
    open.mockRestore();

    // Lần sau phải thử mở lại chứ không dùng promise đã reject.
    await putSnapshot("k", { queryKey: ["tables"], cachedAt: 5, data: "ok" });
    expect(await readAllSnapshots()).toHaveLength(1);
    warn.mockRestore();
  });
});
