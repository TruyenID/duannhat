import { describe, expect, it } from "vitest";
import {
  isStatusVoidable,
  resolveVoidableStatuses,
} from "./voidable-statuses";

describe("resolveVoidableStatuses (plan-051)", () => {
  it("defaults to pending-only when settings are absent", () => {
    expect(resolveVoidableStatuses(undefined)).toEqual(["pending"]);
    expect(resolveVoidableStatuses({})).toEqual(["pending"]);
  });

  it("legacy flag true → all four active statuses", () => {
    expect(
      resolveVoidableStatuses({ allow_item_edit_any_status: true }),
    ).toEqual(["pending", "preparing", "ready", "served"]);
  });

  it("legacy flag false → pending-only", () => {
    expect(
      resolveVoidableStatuses({ allow_item_edit_any_status: false }),
    ).toEqual(["pending"]);
  });

  it("configured matrix wins over the legacy flag", () => {
    expect(
      resolveVoidableStatuses({
        item_voidable_statuses: ["pending", "preparing"],
        allow_item_edit_any_status: true,
      }),
    ).toEqual(["pending", "preparing"]);
  });

  it("null matrix falls back to the legacy flag", () => {
    expect(
      resolveVoidableStatuses({
        item_voidable_statuses: null,
        allow_item_edit_any_status: true,
      }),
    ).toEqual(["pending", "preparing", "ready", "served"]);
  });

  it("server-resolved effective list has highest priority", () => {
    expect(
      resolveVoidableStatuses({
        effective_item_voidable_statuses: ["pending", "served"],
        item_voidable_statuses: ["pending", "preparing", "ready"],
        allow_item_edit_any_status: false,
      }),
    ).toEqual(["pending", "served"]);
  });

  it("pending is a hard floor even when the list omits it", () => {
    expect(
      resolveVoidableStatuses({ item_voidable_statuses: ["preparing"] }),
    ).toEqual(["pending", "preparing"]);
  });

  it("drops unknown statuses from the configured list", () => {
    expect(
      resolveVoidableStatuses({
        item_voidable_statuses: ["pending", "voided", "banana", "ready"],
      }),
    ).toEqual(["pending", "ready"]);
  });
});

describe("isStatusVoidable", () => {
  it("voided lines are terminal regardless of the matrix", () => {
    expect(
      isStatusVoidable("voided", ["pending", "preparing", "ready", "served"]),
    ).toBe(false);
  });

  it("checks membership for active statuses", () => {
    expect(isStatusVoidable("preparing", ["pending", "preparing"])).toBe(true);
    expect(isStatusVoidable("served", ["pending", "preparing"])).toBe(false);
  });
});
