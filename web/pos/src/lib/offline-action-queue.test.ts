import "fake-indexeddb/auto";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { clearLightActions, readLightActions, resetIdbConnection } from "./idb";
import {
  LIGHT_ACTION_TTL_MS,
  LIGHT_ACTION_TYPES,
  countLightActions,
  enqueueLightAction,
  replayLightActions,
} from "./offline-action-queue";

const isNetworkFailure = (err: unknown) => err instanceof TypeError;

beforeEach(async () => {
  resetIdbConnection();
  await clearLightActions();
});

async function queueStatus(tableId: string, status = "free") {
  return enqueueLightAction({
    type: "table.status",
    payload: { shopSlug: "quan-1", tableId, status },
  });
}

/*
 * RANH GIỚI: hàng đợi này KHÔNG BAO GIỜ được mang hành động tiền.
 *
 * Cùng lý do vì sao KDS cố ý không có hàng đợi cho bump — một hành động tiền
 * đồng bộ muộn một giờ là SAI ở thời điểm nó chạy, không phải là "trễ".
 */
describe("offline-action-queue — union kiểu là ĐÓNG", () => {
  it("chỉ có đúng một kiểu, và nó không dính tiền", () => {
    expect(LIGHT_ACTION_TYPES).toEqual(["table.status"]);
  });

  it("không kiểu nào chạm vào thanh toán / ca thu ngân / dòng đơn", () => {
    const FORBIDDEN = [
      "payment",
      "checkout",
      "till",
      "shift",
      "refund",
      "order.item",
      "invoice",
    ];
    for (const type of LIGHT_ACTION_TYPES) {
      for (const word of FORBIDDEN) {
        expect(type).not.toContain(word);
      }
    }
  });
});

describe("enqueueLightAction", () => {
  it("ghi xuống IndexedDB kèm mốc thời gian", async () => {
    await queueStatus("t-1");
    const rows = await readLightActions();
    expect(rows).toHaveLength(1);
    expect(rows[0].type).toBe("table.status");
    expect(rows[0].payload).toEqual({
      shopSlug: "quan-1",
      tableId: "t-1",
      status: "free",
    });
    expect(rows[0].queuedAt).toBeGreaterThan(0);
  });

  it("hai lần xếp hàng là hai bản ghi riêng", async () => {
    await queueStatus("t-1");
    await queueStatus("t-2");
    expect(await countLightActions()).toBe(2);
  });
});

describe("replayLightActions", () => {
  it("gửi thành công thì xoá khỏi hàng đợi", async () => {
    await queueStatus("t-1");
    const run = vi.fn(async () => {});

    const out = await replayLightActions(run, isNetworkFailure);
    expect(out.replayed).toBe(1);
    expect(run).toHaveBeenCalledTimes(1);
    expect(await countLightActions()).toBe(0);
  });

  it("giữ THỨ TỰ xếp hàng — hai lần đổi cùng một bàn chạy ngược là trạng thái cũ thắng", async () => {
    await queueStatus("t-1", "occupied");
    await new Promise((r) => setTimeout(r, 2));
    await queueStatus("t-1", "free");

    const seen: string[] = [];
    await replayLightActions(async (a) => {
      seen.push(a.payload.status as string);
    }, isNetworkFailure);

    expect(seen).toEqual(["occupied", "free"]);
  });

  it("vẫn mất mạng thì GIỮ LẠI và dừng vòng, không đốt hết bằng timeout", async () => {
    await queueStatus("t-1");
    await queueStatus("t-2");
    const run = vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    });

    const out = await replayLightActions(run, isNetworkFailure);
    expect(out.replayed).toBe(0);
    expect(out.kept).toBe(2);
    expect(run).toHaveBeenCalledTimes(1);
    expect(await countLightActions()).toBe(2);
  });

  it("máy chủ từ chối dứt khoát (4xx) thì BỎ — nếu không hàng đợi không bao giờ cạn", async () => {
    await queueStatus("t-1");
    const out = await replayLightActions(async () => {
      throw new Error("422 bàn không tồn tại");
    }, isNetworkFailure);

    expect(out.rejected).toBe(1);
    expect(await countLightActions()).toBe(0);
  });

  it("quá TTL thì BỎ, không phát lại đè lên sự thật mới hơn", async () => {
    await queueStatus("t-1");
    const run = vi.fn(async () => {});

    const out = await replayLightActions(
      run,
      isNetworkFailure,
      Date.now() + LIGHT_ACTION_TTL_MS + 1,
    );

    expect(out.expired).toBe(1);
    expect(run).not.toHaveBeenCalled();
    expect(await countLightActions()).toBe(0);
  });

  it("hàng đợi rỗng thì không làm gì", async () => {
    const out = await replayLightActions(async () => {}, isNetworkFailure);
    expect(out).toEqual({ replayed: 0, expired: 0, kept: 0, rejected: 0 });
  });
});
