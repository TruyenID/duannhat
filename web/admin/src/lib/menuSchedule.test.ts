import { describe, it, expect } from "vitest";

import {
  daysOfWeekToLabels,
  findOverlappingSchedules,
  labelsToDaysOfWeek,
  schedulesOverlap,
} from "./menuSchedule";

// Bit mapping: bit0=Sun, bit1=Mon … bit6=Sat.
const MON = 1 << 1;
const TUE = 1 << 2;
const WED = 1 << 3;
const ALL = 127;

describe("bitmask helpers", () => {
  it("round-trips labels through the bitmask", () => {
    expect(labelsToDaysOfWeek(["Mon", "Wed"])).toBe(MON | WED);
    expect(daysOfWeekToLabels(MON | WED)).toEqual(["Mon", "Wed"]);
  });
});

describe("schedulesOverlap", () => {
  it("returns false when the windows share no day", () => {
    expect(
      schedulesOverlap(
        { start_time: "09:00", end_time: "12:00", days_of_week: MON },
        { start_time: "09:00", end_time: "12:00", days_of_week: TUE }
      )
    ).toBe(false);
  });

  it("returns true when days and times both intersect", () => {
    expect(
      schedulesOverlap(
        { start_time: "09:00", end_time: "12:00", days_of_week: MON | TUE },
        { start_time: "11:00", end_time: "14:00", days_of_week: TUE }
      )
    ).toBe(true);
  });

  it("returns false when times are adjacent (half-open ranges)", () => {
    expect(
      schedulesOverlap(
        { start_time: "09:00", end_time: "12:00", days_of_week: ALL },
        { start_time: "12:00", end_time: "15:00", days_of_week: ALL }
      )
    ).toBe(false);
  });

  it("normalizes HH:MM and HH:MM:SS before comparing", () => {
    expect(
      schedulesOverlap(
        { start_time: "09:00", end_time: "12:00", days_of_week: ALL },
        { start_time: "11:30:00", end_time: "13:00:00", days_of_week: ALL }
      )
    ).toBe(true);
  });

  it("detects full containment", () => {
    expect(
      schedulesOverlap(
        { start_time: "08:00", end_time: "18:00", days_of_week: WED },
        { start_time: "10:00", end_time: "11:00", days_of_week: WED }
      )
    ).toBe(true);
  });
});

describe("findOverlappingSchedules", () => {
  const existing = [
    { id: "a", start_time: "09:00:00", end_time: "12:00:00", days_of_week: MON | TUE },
    { id: "b", start_time: "13:00:00", end_time: "17:00:00", days_of_week: WED },
  ];

  it("returns the overlapping rows", () => {
    const result = findOverlappingSchedules(
      { start_time: "11:00", end_time: "14:00", days_of_week: TUE },
      existing
    );
    expect(result.map((s) => s.id)).toEqual(["a"]);
  });

  it("returns empty when nothing overlaps", () => {
    const result = findOverlappingSchedules(
      { start_time: "18:00", end_time: "20:00", days_of_week: ALL },
      existing
    );
    expect(result).toEqual([]);
  });

  it("skips the schedule being edited via excludeId", () => {
    // Same window as row "a" — would self-overlap without the exclusion.
    const result = findOverlappingSchedules(
      { start_time: "09:00", end_time: "12:00", days_of_week: MON | TUE },
      existing,
      "a"
    );
    expect(result).toEqual([]);
  });
});
