import { describe, it, expect } from "vitest";
import { applyItemStatus } from "../use-item-status-mutation";
import { makeItem } from "@/test/fixtures/kds";

/**
 * Direct unit coverage for the optimistic status mapper. The hook-level tests
 * exercise `applyItemStatus` indirectly through the mark and revert mutations,
 * but never assert the full timestamp matrix — most importantly that leaving
 * `served` clears `served_at`. A stale `served_at` on a reverted item would let
 * a downstream reader (offline IDB snapshot, or any consumer treating
 * `served_at != null` as "is served") treat the item as still served even
 * though the kitchen just pulled it back — the "revert → serve skip" gap.
 */
describe("applyItemStatus timestamp gating", () => {
  it("clears served_at + ready_at when reverting served → preparing (no serve-skip)", () => {
    const served = makeItem({
      id: "i-1",
      status: "served",
      started_preparing_at: "2026-05-26T10:00:00Z",
      ready_at: "2026-05-26T10:05:00Z",
      served_at: "2026-05-26T10:10:00Z",
    });

    const next = applyItemStatus(served, "preparing");

    expect(next.status).toBe("preparing");
    // The reverted item must NOT look served or ready anymore.
    expect(next.served_at).toBeNull();
    expect(next.ready_at).toBeNull();
    // Still preparing → prep timestamp is preserved.
    expect(next.started_preparing_at).toBe("2026-05-26T10:00:00Z");
  });

  it("clears every downstream timestamp when reverting served → pending", () => {
    const served = makeItem({
      id: "i-1",
      status: "served",
      started_preparing_at: "2026-05-26T10:00:00Z",
      ready_at: "2026-05-26T10:05:00Z",
      served_at: "2026-05-26T10:10:00Z",
    });

    const next = applyItemStatus(served, "pending");

    expect(next.status).toBe("pending");
    expect(next.served_at).toBeNull();
    expect(next.ready_at).toBeNull();
    expect(next.started_preparing_at).toBeNull();
  });

  it("clears served_at but keeps ready_at when reverting served → ready-equivalent path (ready target)", () => {
    // A ready target keeps ready_at (still ready) but must drop served_at.
    const served = makeItem({
      id: "i-1",
      status: "served",
      started_preparing_at: "2026-05-26T10:00:00Z",
      ready_at: "2026-05-26T10:05:00Z",
      served_at: "2026-05-26T10:10:00Z",
    });

    const next = applyItemStatus(served, "ready");

    expect(next.status).toBe("ready");
    expect(next.served_at).toBeNull();
    expect(next.ready_at).toBe("2026-05-26T10:05:00Z");
    expect(next.started_preparing_at).toBe("2026-05-26T10:00:00Z");
  });

  it("preserves ready_at + served_at when advancing forward to served", () => {
    const ready = makeItem({
      id: "i-1",
      status: "ready",
      started_preparing_at: "2026-05-26T10:00:00Z",
      ready_at: "2026-05-26T10:05:00Z",
      served_at: null,
    });

    const next = applyItemStatus(ready, "served");

    expect(next.status).toBe("served");
    // ready_at is kept for served (isReadyOrBeyond); served_at flows through
    // from the source (server fills the real value on refetch).
    expect(next.ready_at).toBe("2026-05-26T10:05:00Z");
    expect(next.started_preparing_at).toBe("2026-05-26T10:00:00Z");
  });

  it("keeps ready_at when marking ready but drops any stale served_at", () => {
    const preparing = makeItem({
      id: "i-1",
      status: "preparing",
      started_preparing_at: "2026-05-26T10:00:00Z",
      ready_at: "2026-05-26T10:05:00Z",
      served_at: "2026-05-26T10:10:00Z", // stale — should not survive a ready target
    });

    const next = applyItemStatus(preparing, "ready");

    expect(next.status).toBe("ready");
    expect(next.ready_at).toBe("2026-05-26T10:05:00Z");
    expect(next.served_at).toBeNull();
  });

  it("does not mutate the source item (returns a new object)", () => {
    const item = makeItem({ id: "i-1", status: "ready", ready_at: "2026-05-26T10:05:00Z" });
    const snapshot = { ...item };

    const next = applyItemStatus(item, "preparing");

    expect(next).not.toBe(item);
    // Source object untouched.
    expect(item).toEqual(snapshot);
  });
});
