import { beforeEach, describe, expect, it, vi } from "vitest";

// resolver chạy `void hydrate()` lúc import -> cần mock AsyncStorage.
vi.mock("@react-native-async-storage/async-storage", () => ({
  default: {
    getItem: vi.fn().mockResolvedValue(null),
    setItem: vi.fn().mockResolvedValue(undefined),
    removeItem: vi.fn().mockResolvedValue(undefined),
  },
}));

// Discovery chỉ chạy khi operator bật chế độ dự phòng; test điều khiển trực
// tiếp giá trị `current()` để mô phỏng "đã tìm thấy / chưa tìm thấy WS".
let discovered: { proxyUrl: string } | null = null;
vi.mock("./discovery", () => ({
  workstationDiscovery: { current: () => discovered },
}));

import {
  CLOUD_URL,
  isLanFallbackEnabled,
  isUsingWorkstation,
  markCloudUnreachable,
  normalizeWorkstationUrl,
  onLanFallbackChange,
  resetUnreachable,
  resolveBaseUrl,
  resolveWorkstationUrl,
  setLanFallbackEnabled,
  setManualUrl,
  shouldScanWorkstation,
} from "./base-url-resolver";

const WS_URL = "http://192.168.1.249:8080";

beforeEach(async () => {
  discovered = null;
  resetUnreachable();
  await setManualUrl(null);
  await setLanFallbackEnabled(false);
});

describe("normalizeWorkstationUrl", () => {
  it("prepends http:// when scheme is missing (fix cho 'Network request failed')", () => {
    expect(normalizeWorkstationUrl("192.168.1.249:8080")).toBe(
      "http://192.168.1.249:8080",
    );
  });

  it("giữ nguyên http:// / https:// có sẵn", () => {
    expect(normalizeWorkstationUrl("http://10.0.0.5:8080")).toBe(
      "http://10.0.0.5:8080",
    );
    expect(normalizeWorkstationUrl("https://ws.local")).toBe("https://ws.local");
  });

  it("strip trailing slash để an toàn khi nối /api/...", () => {
    expect(normalizeWorkstationUrl("192.168.1.249:8080/")).toBe(
      "http://192.168.1.249:8080",
    );
    expect(normalizeWorkstationUrl("http://ws:8080///")).toBe("http://ws:8080");
  });

  it("trim khoảng trắng", () => {
    expect(normalizeWorkstationUrl("  192.168.1.249:8080  ")).toBe(
      "http://192.168.1.249:8080",
    );
  });

  it("trả null cho input rỗng / null / undefined", () => {
    expect(normalizeWorkstationUrl("")).toBeNull();
    expect(normalizeWorkstationUrl("   ")).toBeNull();
    expect(normalizeWorkstationUrl(null)).toBeNull();
    expect(normalizeWorkstationUrl(undefined)).toBeNull();
  });
});

describe("resolveBaseUrl — cloud-first (issue #44)", () => {
  it("trả Cloud khi mọi thứ bình thường, kể cả khi đã tìm thấy workstation", () => {
    discovered = { proxyUrl: WS_URL };
    expect(resolveBaseUrl()).toBe(CLOUD_URL);
    expect(isUsingWorkstation()).toBe(false);
  });

  it("trả Cloud khi có manual URL — LAN không còn được ưu tiên", async () => {
    await setManualUrl(WS_URL);
    expect(resolveBaseUrl()).toBe(CLOUD_URL);
  });

  it("chuyển sang workstation sau khi Cloud lỗi", () => {
    discovered = { proxyUrl: WS_URL };
    markCloudUnreachable();
    expect(resolveBaseUrl()).toBe(WS_URL);
    expect(isUsingWorkstation()).toBe(true);
  });

  it("vẫn ở Cloud khi Cloud lỗi nhưng không biết workstation nào", () => {
    markCloudUnreachable();
    expect(resolveBaseUrl()).toBe(CLOUD_URL);
  });

  it("giữ nguyên workstation trong lúc Cloud sập, KHÔNG kẹt về Cloud chết (finding #2)", () => {
    // Cloud đang known-down. Dù workstation vừa chớp lỗi trên một request,
    // resolver vẫn phải tiếp tục nhắm workstation — không có backoff LAN để
    // đẩy traffic ngược về leg cloud đã chết.
    discovered = { proxyUrl: WS_URL };
    markCloudUnreachable();
    expect(resolveBaseUrl()).toBe(WS_URL);
    // Gọi lại nhiều lần (mô phỏng burst request) vẫn ra workstation.
    expect(resolveBaseUrl()).toBe(WS_URL);
  });

  it("resetUnreachable kéo traffic về Cloud ngay", () => {
    discovered = { proxyUrl: WS_URL };
    markCloudUnreachable();
    expect(resolveBaseUrl()).toBe(WS_URL);
    resetUnreachable();
    expect(resolveBaseUrl()).toBe(CLOUD_URL);
  });
});

describe("resolveWorkstationUrl — đích LAN cho in hoá đơn", () => {
  it("null khi không có discovery lẫn manual URL", () => {
    expect(resolveWorkstationUrl()).toBeNull();
  });

  it("ưu tiên mDNS hơn manual URL", async () => {
    await setManualUrl("http://10.0.0.5:8080");
    discovered = { proxyUrl: WS_URL };
    expect(resolveWorkstationUrl()).toBe(WS_URL);
  });

  it("dùng manual URL khi mDNS chưa thấy gì", async () => {
    await setManualUrl("http://10.0.0.5:8080");
    expect(resolveWorkstationUrl()).toBe("http://10.0.0.5:8080");
  });

  it("độc lập với backoff — in vẫn phải ra LAN dù Cloud đang khoẻ", () => {
    discovered = { proxyUrl: WS_URL };
    resetUnreachable();
    expect(resolveBaseUrl()).toBe(CLOUD_URL);
    expect(resolveWorkstationUrl()).toBe(WS_URL);
  });
});

describe("LAN fallback opt-in", () => {
  it("mặc định tắt", () => {
    expect(isLanFallbackEnabled()).toBe(false);
  });

  it("bật/tắt được và báo cho subscriber", async () => {
    const seen: boolean[] = [];
    const unsub = onLanFallbackChange((v) => seen.push(v));
    expect(seen).toEqual([false]); // fire ngay với giá trị hiện tại

    await setLanFallbackEnabled(true);
    expect(isLanFallbackEnabled()).toBe(true);
    expect(seen).toEqual([false, true]);

    await setLanFallbackEnabled(false);
    expect(seen).toEqual([false, true, false]);
    unsub();

    await setLanFallbackEnabled(true);
    expect(seen).toEqual([false, true, false]); // đã unsubscribe
  });

  it("đổi chế độ xoá backoff để traffic về Cloud ngay", async () => {
    discovered = { proxyUrl: WS_URL };
    markCloudUnreachable();
    expect(resolveBaseUrl()).toBe(WS_URL);
    await setLanFallbackEnabled(false);
    expect(resolveBaseUrl()).toBe(CLOUD_URL);
  });
});

describe("shouldScanWorkstation — gate mDNS (Phase A, finding #10)", () => {
  it("KHÔNG quét khi standby tắt, dù đã pair + có branch", async () => {
    await setLanFallbackEnabled(false);
    expect(shouldScanWorkstation(true, "branch-1")).toBe(false);
  });

  it("chỉ quét khi authenticated + có branch + standby BẬT", async () => {
    await setLanFallbackEnabled(true);
    expect(shouldScanWorkstation(true, "branch-1")).toBe(true);
    // Thiếu bất kỳ điều kiện nào → không quét.
    expect(shouldScanWorkstation(false, "branch-1")).toBe(false);
    expect(shouldScanWorkstation(true, "")).toBe(false);
  });
});
