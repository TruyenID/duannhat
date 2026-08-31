import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import {
  ageInMinutes,
  priorityColorClass,
  isSnapshotStale,
  SNAPSHOT_STALE_THRESHOLD_MINUTES,
} from "../utils";

describe("ageInMinutes", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-05-28T12:00:00Z"));
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("returns 0 for null/undefined", () => {
    expect(ageInMinutes(null)).toBe(0);
    expect(ageInMinutes(undefined)).toBe(0);
  });

  it("returns minutes elapsed since an ISO string", () => {
    expect(ageInMinutes("2026-05-28T11:53:00Z")).toBe(7);
  });

  it("accepts a Date instance", () => {
    expect(ageInMinutes(new Date("2026-05-28T11:30:00Z"))).toBe(30);
  });

  it("clamps to 0 for future timestamps (clock skew)", () => {
    expect(ageInMinutes("2026-05-28T12:05:00Z")).toBe(0);
  });
});

describe("priorityColorClass", () => {
  it("normal → green border", () => {
    expect(priorityColorClass("normal")).toBe("border-green-500");
  });
  it("warning → amber border", () => {
    expect(priorityColorClass("warning")).toBe("border-amber-500");
  });
  it("critical → red border + pulse", () => {
    expect(priorityColorClass("critical")).toBe("border-red-500 animate-pulse");
  });
});

describe("isSnapshotStale", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-05-28T12:00:00Z"));
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("matches the documented constant", () => {
    expect(SNAPSHOT_STALE_THRESHOLD_MINUTES).toBe(5);
  });

  it("is false strictly below the threshold", () => {
    // 4 min ago
    expect(isSnapshotStale("2026-05-28T11:56:00Z")).toBe(false);
  });

  it("is true at the threshold (>=)", () => {
    expect(isSnapshotStale("2026-05-28T11:55:00Z")).toBe(true);
  });

  it("is true well past the threshold", () => {
    expect(isSnapshotStale("2026-05-28T11:30:00Z")).toBe(true);
  });

  it("respects a custom threshold override", () => {
    // 7 min ago — stale at default 5, fresh at custom 10
    expect(isSnapshotStale("2026-05-28T11:53:00Z", 10)).toBe(false);
    expect(isSnapshotStale("2026-05-28T11:53:00Z", 5)).toBe(true);
  });
});
