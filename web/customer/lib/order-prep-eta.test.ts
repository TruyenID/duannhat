import assert from "node:assert/strict";
import { describe, it } from "node:test";

import { computePrepEta } from "./order-prep-eta.ts";

/**
 * Con số khách nhìn để quyết định lúc nào ra quán lấy đồ. Trước đây nó nằm
 * trong thân trang `/orders/{id}` và đọc `Date.now()` bên trong, nên không có
 * cách nào kiểm ngoài việc chạy đúng thời điểm.
 */

const NOW = Date.parse("2026-08-01T10:00:00Z");

const base = {
  placedAt: null,
  estimatedReadyTime: null,
  actualReadyTime: null,
  preparationMinutes: null,
  totalQty: 0,
  nowMs: NOW,
};

describe("computePrepEta", () => {
  it("dùng estimated_ready_time trước tiên", () => {
    const eta = computePrepEta({
      ...base,
      estimatedReadyTime: "2026-08-01T10:12:00Z",
      // placed_at + preparation_minutes cũng đủ dựng một mốc khác — phải thua.
      placedAt: "2026-08-01T09:00:00Z",
      preparationMinutes: 90,
    });
    assert.deepEqual(eta, {
      label: true,
      labelKey: "readyInMinutes",
      params: { minutes: 12 },
    });
  });

  it("tự tính từ placed_at + preparation_minutes khi không có estimate", () => {
    const eta = computePrepEta({
      ...base,
      placedAt: "2026-08-01T09:50:00Z",
      preparationMinutes: 30,
    });
    assert.deepEqual(eta, {
      label: true,
      labelKey: "readyInMinutes",
      params: { minutes: 20 },
    });
  });

  it("quá 60 phút thì chuyển sang giờ tuyệt đối", () => {
    const eta = computePrepEta({
      ...base,
      estimatedReadyTime: "2026-08-01T12:30:00Z",
    });
    assert.equal(eta.label, true);
    if (eta.label) assert.equal(eta.labelKey, "readyAt");
  });

  it("đã quá giờ dự kiến thì KHÔNG báo ETA quá khứ", () => {
    // Bếp trễ. Show "còn ~5 phút" thay vì một mốc đã trôi qua — âm phút thì
    // khách đọc không hiểu gì.
    const eta = computePrepEta({
      ...base,
      estimatedReadyTime: "2026-08-01T09:30:00Z",
    });
    assert.deepEqual(eta, { label: false, fallbackMinutes: 5 });
  });

  it("không có dữ liệu nào thì rơi về heuristic theo số món, chặn trên 60", () => {
    assert.deepEqual(computePrepEta({ ...base, totalQty: 2 }), {
      label: false,
      fallbackMinutes: 21,
    });
    assert.deepEqual(computePrepEta({ ...base, totalQty: 100 }), {
      label: false,
      fallbackMinutes: 60,
    });
  });

  it("bỏ qua timestamp rác thay vì sinh NaN phút", () => {
    const eta = computePrepEta({
      ...base,
      estimatedReadyTime: "not-a-date",
      totalQty: 1,
    });
    assert.deepEqual(eta, { label: false, fallbackMinutes: 18 });
  });

  it("preparation_minutes bằng 0 không được coi là 'xong ngay'", () => {
    const eta = computePrepEta({
      ...base,
      placedAt: "2026-08-01T09:50:00Z",
      preparationMinutes: 0,
      totalQty: 1,
    });
    assert.deepEqual(eta, { label: false, fallbackMinutes: 18 });
  });
});
