/**
 * plan-051 (#1149 / #1150) — void-matrix resolution rules.
 *
 * This is the client mirror of the backend's VoidableStatusResolver. If the two
 * drift, the shop settings screen shows a matrix that does not match what the
 * POS will actually allow — the manager unticks "served", saves, and the till
 * keeps voiding served items (or vice versa). These tests pin the three
 * resolution branches and the `pending` hard floor.
 *
 * @vitest-environment node
 */

import { describe, expect, it } from "vitest";
import {
  DEFAULT_STOCK_DEDUCTION_TIMING,
  STOCK_DEDUCTION_TIMINGS,
  VOID_MATRIX_STATUSES,
  deriveLegacyItemEditFlag,
  resolveServerVoidableStatuses,
  sameStatusList,
  type VoidMatrixSettings,
} from "./void-matrix";

/** Only the fields the resolver reads — the rest of the payload is irrelevant. */
function settings(overrides: Partial<VoidMatrixSettings> = {}): VoidMatrixSettings {
  return { allow_item_edit_any_status: false, ...overrides };
}

describe("resolveServerVoidableStatuses — pending hard floor", () => {
  it("keeps pending voidable even when the server omits it entirely", () => {
    const result = resolveServerVoidableStatuses(
      settings({ effective_item_voidable_statuses: ["preparing", "ready"] })
    );
    expect(result).toEqual(["pending", "preparing", "ready"]);
  });

  it("keeps pending voidable when the server sends an explicitly empty list", () => {
    // An empty list is a real answer from the API ("nothing beyond the floor"),
    // NOT the same as `null` — it must not fall through to the legacy flag.
    expect(
      resolveServerVoidableStatuses(
        settings({ allow_item_edit_any_status: true, effective_item_voidable_statuses: [] })
      )
    ).toEqual(["pending"]);
  });

  it("keeps pending voidable when every source is missing", () => {
    expect(resolveServerVoidableStatuses(settings())).toEqual(["pending"]);
  });
});

describe("resolveServerVoidableStatuses — source precedence", () => {
  it("prefers the server-resolved effective list over the raw column", () => {
    const result = resolveServerVoidableStatuses(
      settings({
        effective_item_voidable_statuses: ["pending", "served"],
        item_voidable_statuses: ["pending", "preparing", "ready", "served"],
      })
    );
    expect(result).toEqual(["pending", "served"]);
  });

  it("falls back to the raw column when the effective list is absent", () => {
    const result = resolveServerVoidableStatuses(
      settings({ item_voidable_statuses: ["pending", "ready"] })
    );
    expect(result).toEqual(["pending", "ready"]);
  });

  it("treats a null raw column as unconfigured and reads the legacy flag", () => {
    expect(
      resolveServerVoidableStatuses(
        settings({ allow_item_edit_any_status: true, item_voidable_statuses: null })
      )
    ).toEqual([...VOID_MATRIX_STATUSES]);
  });
});

describe("resolveServerVoidableStatuses — legacy #1148 flag fallback", () => {
  it("maps allow_item_edit_any_status=true to the full matrix", () => {
    expect(resolveServerVoidableStatuses(settings({ allow_item_edit_any_status: true }))).toEqual([
      "pending",
      "preparing",
      "ready",
      "served",
    ]);
  });

  it("maps allow_item_edit_any_status=false to pending-only", () => {
    expect(resolveServerVoidableStatuses(settings({ allow_item_edit_any_status: false }))).toEqual([
      "pending",
    ]);
  });
});

describe("resolveServerVoidableStatuses — normalisation", () => {
  it("returns canonical display order regardless of the order received", () => {
    const result = resolveServerVoidableStatuses(
      settings({ effective_item_voidable_statuses: ["served", "pending", "preparing"] })
    );
    expect(result).toEqual(["pending", "preparing", "served"]);
  });

  it("drops statuses outside the matrix instead of rendering an unknown row", () => {
    const result = resolveServerVoidableStatuses(
      settings({ effective_item_voidable_statuses: ["pending", "cancelled", "voided", "served"] })
    );
    expect(result).toEqual(["pending", "served"]);
  });

  it("de-duplicates a repeated status", () => {
    const result = resolveServerVoidableStatuses(
      settings({ effective_item_voidable_statuses: ["ready", "ready", "ready"] })
    );
    expect(result).toEqual(["pending", "ready"]);
  });
});

describe("sameStatusList — unsaved-changes comparison", () => {
  it("ignores ordering", () => {
    expect(sameStatusList(["pending", "served"], ["served", "pending"])).toBe(true);
  });

  it("detects an added status", () => {
    expect(sameStatusList(["pending", "served"], ["pending"])).toBe(false);
  });

  it("detects a removed status", () => {
    expect(sameStatusList(["pending"], ["pending", "ready"])).toBe(false);
  });

  it("treats two empty lists as equal", () => {
    expect(sameStatusList([], [])).toBe(true);
  });
});

describe("deriveLegacyItemEditFlag — lossy boolean mirror", () => {
  it("is true only for the lossless all-four matrix", () => {
    expect(deriveLegacyItemEditFlag([...VOID_MATRIX_STATUSES])).toBe(true);
  });

  it("is false for any narrower matrix — the strict legacy reading is the safe one", () => {
    expect(deriveLegacyItemEditFlag(["pending", "preparing", "ready"])).toBe(false);
    expect(deriveLegacyItemEditFlag(["pending"])).toBe(false);
  });

  it("round-trips: full matrix → flag → full matrix", () => {
    const flag = deriveLegacyItemEditFlag([...VOID_MATRIX_STATUSES]);
    expect(resolveServerVoidableStatuses(settings({ allow_item_edit_any_status: flag }))).toEqual([
      ...VOID_MATRIX_STATUSES,
    ]);
  });

  it("round-trips conservatively: partial matrix → flag → pending-only", () => {
    const flag = deriveLegacyItemEditFlag(["pending", "served"]);
    expect(resolveServerVoidableStatuses(settings({ allow_item_edit_any_status: flag }))).toEqual([
      "pending",
    ]);
  });
});

describe("stock deduction timing (#1150)", () => {
  it("defaults to on_close — the least surprising, post-sale deduction", () => {
    expect(DEFAULT_STOCK_DEDUCTION_TIMING).toBe("on_close");
  });

  it("offers exactly the three timings the backend enum accepts", () => {
    expect(STOCK_DEDUCTION_TIMINGS).toEqual(["on_close", "on_preparing", "on_add"]);
  });

  it("lists the default as a selectable option", () => {
    expect(STOCK_DEDUCTION_TIMINGS).toContain(DEFAULT_STOCK_DEDUCTION_TIMING);
  });
});
