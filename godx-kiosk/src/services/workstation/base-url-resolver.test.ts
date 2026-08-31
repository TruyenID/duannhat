import { describe, expect, it, vi } from "vitest";

// resolver chạy `void hydrateManualUrl()` lúc import -> cần mock AsyncStorage.
vi.mock("@react-native-async-storage/async-storage", () => ({
  default: {
    getItem: vi.fn().mockResolvedValue(null),
    setItem: vi.fn().mockResolvedValue(undefined),
    removeItem: vi.fn().mockResolvedValue(undefined),
  },
}));
vi.mock("./discovery", () => ({
  workstationDiscovery: { current: () => null },
}));

import { normalizeWorkstationUrl } from "./base-url-resolver";

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
