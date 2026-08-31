import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  OFFLINE_FAILURE_THRESHOLD,
  getNetworkStatus,
  installNetworkListeners,
  isOffline,
  markApiOutcome,
  resetNetworkStatus,
  seedLastSyncedAt,
} from "./network-status";

beforeEach(() => {
  resetNetworkStatus();
});

afterEach(() => {
  vi.useRealTimers();
});

describe("network-status — bộ đếm thất bại", () => {
  it("một lỗi mạng lẻ CHƯA phải offline (chống nhấp nháy)", () => {
    markApiOutcome("network-error");
    expect(getNetworkStatus().consecutiveNetworkFailures).toBe(1);
    expect(isOffline(getNetworkStatus())).toBe(false);
  });

  it(`${OFFLINE_FAILURE_THRESHOLD} lỗi mạng liên tiếp ⇒ offline`, () => {
    for (let i = 0; i < OFFLINE_FAILURE_THRESHOLD; i += 1) {
      markApiOutcome("network-error");
    }
    expect(isOffline(getNetworkStatus())).toBe(true);
  });

  it("chạm được máy chủ thì reset bộ đếm và ghi mốc đồng bộ", () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-08-03T10:20:00Z"));

    markApiOutcome("network-error");
    markApiOutcome("network-error");
    expect(isOffline(getNetworkStatus())).toBe(true);

    markApiOutcome("reached-server");
    expect(getNetworkStatus().consecutiveNetworkFailures).toBe(0);
    expect(getNetworkStatus().lastSyncedAt).toBe(
      new Date("2026-08-03T10:20:00Z").getTime(),
    );
    expect(isOffline(getNetworkStatus())).toBe(false);
  });
});

describe("network-status — seedLastSyncedAt", () => {
  it("gieo tuổi cache khi phiên chưa hề chạm máy chủ", () => {
    seedLastSyncedAt(1_000);
    expect(getNetworkStatus().lastSyncedAt).toBe(1_000);
  });

  it("KHÔNG đè lên một lần đồng bộ thật — fetch thành công luôn mới hơn ảnh chụp", () => {
    markApiOutcome("reached-server");
    const real = getNetworkStatus().lastSyncedAt;
    seedLastSyncedAt(1_000);
    expect(getNetworkStatus().lastSyncedAt).toBe(real);
  });

  it("null thì không làm gì", () => {
    seedLastSyncedAt(null);
    expect(getNetworkStatus().lastSyncedAt).toBeNull();
  });
});

describe("network-status — tín hiệu trình duyệt", () => {
  it("navigator.onLine === false ⇒ offline ngay, không cần đợi bộ đếm", () => {
    const remove = installNetworkListeners();
    window.dispatchEvent(new Event("offline"));
    expect(getNetworkStatus().browserOnline).toBe(false);
    expect(isOffline(getNetworkStatus())).toBe(true);
    remove();
  });

  it("sự kiện `online` KHÔNG tự xoá bộ đếm thất bại", () => {
    // Cắm lại dây vào một hub chết: card mạng lên, máy chủ vẫn không với tới.
    // Nếu `online` reset bộ đếm thì banner tắt trong khi POS vẫn gọi gì cũng
    // hỏng — đúng lúc thu ngân cần nó nhất.
    const remove = installNetworkListeners();
    markApiOutcome("network-error");
    markApiOutcome("network-error");
    window.dispatchEvent(new Event("offline"));
    window.dispatchEvent(new Event("online"));

    expect(getNetworkStatus().browserOnline).toBe(true);
    expect(getNetworkStatus().consecutiveNetworkFailures).toBe(2);
    expect(isOffline(getNetworkStatus())).toBe(true);
    remove();
  });

  it("không có `navigator` (môi trường không phải trình duyệt) thì coi như online", () => {
    vi.stubGlobal("navigator", undefined);
    resetNetworkStatus();
    expect(getNetworkStatus().browserOnline).toBe(true);
    vi.unstubAllGlobals();
    resetNetworkStatus();
  });

  it("không có `window` thì `installNetworkListeners` là no-op gỡ được", () => {
    vi.stubGlobal("window", undefined);
    const remove = installNetworkListeners();
    expect(() => remove()).not.toThrow();
    vi.unstubAllGlobals();
  });

  it("gỡ listener thì sự kiện sau đó không còn tác dụng", () => {
    const remove = installNetworkListeners();
    remove();
    window.dispatchEvent(new Event("offline"));
    expect(getNetworkStatus().browserOnline).toBe(true);
  });
});
