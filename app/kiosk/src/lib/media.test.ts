import { describe, expect, it, vi } from "vitest";

// resolveMediaUrl → base-url-resolver imports AsyncStorage (react-native) +
// discovery at module load; mock both so vitest doesn't parse RN's Flow source.
vi.mock("@react-native-async-storage/async-storage", () => ({
  default: {
    getItem: vi.fn().mockResolvedValue(null),
    setItem: vi.fn().mockResolvedValue(undefined),
    removeItem: vi.fn().mockResolvedValue(undefined),
  },
}));
vi.mock("../services/workstation/discovery", () => ({
  workstationDiscovery: { current: () => null },
}));

import { normalizeOrderImages, resolveMediaUrl } from "./media";
import { CLOUD_URL } from "../services/workstation/base-url-resolver";
import type { Order } from "../types/kiosk";

describe("resolveMediaUrl", () => {
  it("rewrites a MinIO object URL to the same-origin media proxy", () => {
    expect(resolveMediaUrl("http://localhost:5490/tempo/gallery-fixtures/bun-cha.jpg")).toBe(
      `${CLOUD_URL}/api/v1/media/gallery-fixtures/bun-cha.jpg`,
    );
  });

  it("rewrites https + nested paths and keeps the path after the bucket", () => {
    expect(resolveMediaUrl("https://localhost:5490/tempo/a/b/c.png")).toBe(
      `${CLOUD_URL}/api/v1/media/a/b/c.png`,
    );
  });

  it("passes through a real CDN / non-MinIO URL untouched (prod no-op)", () => {
    const cdn = "https://cdn.example.com/tempo/x.jpg";
    expect(resolveMediaUrl(cdn)).toBe(cdn);
  });

  it("returns undefined for null / undefined / empty", () => {
    expect(resolveMediaUrl(null)).toBeUndefined();
    expect(resolveMediaUrl(undefined)).toBeUndefined();
    expect(resolveMediaUrl("")).toBeUndefined();
  });
});

describe("normalizeOrderImages", () => {
  it("rewrites every line-item image on the order", () => {
    const order = {
      id: "o1",
      items: [
        { id: "i1", name: "A", quantity: 1, unit_price: 100, image_url: "http://localhost:5490/tempo/a.jpg" },
        { id: "i2", name: "B", quantity: 1, unit_price: 100, image_url: undefined },
      ],
    } as unknown as Order;

    normalizeOrderImages(order);

    expect(order.items[0]!.image_url).toBe(`${CLOUD_URL}/api/v1/media/a.jpg`);
    expect(order.items[1]!.image_url).toBeUndefined();
  });

  it("tolerates a null order (order-not-found response)", () => {
    expect(normalizeOrderImages(null)).toBeNull();
  });
});
