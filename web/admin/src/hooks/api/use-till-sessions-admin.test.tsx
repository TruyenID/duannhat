/**
 * #1178 — manager exit-door mutations must SURFACE why the API said no.
 *
 * The manual-settle / force-abandon endpoints reject for reasons the manager
 * can act on: 422 validation (`errors`), 409 guards (VARIANCE_REASON_REQUIRED,
 * SHIFT_NOT_EXPIRED, unpaid orders still open), 403 policy. The hooks used to
 * swallow the response body, so every one of them showed the same bare
 * "Manual-settle failed" line — which is exactly what made #1178 take a code
 * read to diagnose instead of a screenshot.
 *
 * These tests pin the toast contract: the title is always the generic label,
 * and the DESCRIPTION carries the API's reason, with the 422 field errors
 * winning over the envelope `message`.
 */

import { beforeEach, describe, expect, it, vi } from "vitest";
import { toast } from "sonner";
import { ApiError } from "@/lib/api";
import { tillSessionAdminService } from "@/services/till-session-admin-service";
import type * as TillSessionAdminModule from "@/services/till-session-admin-service";
import {
  useForceAbandonSession,
  useManualSettleSession,
} from "@/hooks/api/use-till-sessions-admin";
import { renderHookWithProviders, waitFor } from "@/test/test-utils";

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock("@/services/till-session-admin-service", async (importOriginal) => {
  const actual = await importOriginal<typeof TillSessionAdminModule>();
  return {
    ...actual,
    tillSessionAdminService: {
      ...actual.tillSessionAdminService,
      manualSettle: vi.fn(),
      forceAbandon: vi.fn(),
    },
  };
});

const manualSettle = vi.mocked(tillSessionAdminService.manualSettle);
const forceAbandon = vi.mocked(tillSessionAdminService.forceAbandon);

const SETTLE_PAYLOAD = {
  closing_counts: [{ denomination_id: "d10000", quantity: 0 }],
  tender_details: [],
  manual_settle_reason: "Thu ngân nghỉ việc, quản lý đếm lại két sau 3 ngày.",
};

/** Fire the manual-settle mutation and wait for its onError toast to land. */
async function settleAndFail(error: unknown) {
  manualSettle.mockRejectedValue(error);
  const { result } = renderHookWithProviders(() => useManualSettleSession("test-shop"));
  result.current.mutate({ id: "sess-1", data: SETTLE_PAYLOAD });
  await waitFor(() => expect(toast.error).toHaveBeenCalled());
  return vi.mocked(toast.error).mock.calls[0];
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("useManualSettleSession — onError surfacing", () => {
  it("puts the first 422 field error in the toast description", async () => {
    const [title, options] = await settleAndFail(
      new ApiError(422, {
        message: "The given data was invalid.",
        errors: {
          closing_counts: ["The closing counts field is required."],
          manual_settle_reason: ["The manual settle reason must be at least 10 characters."],
        },
      })
    );

    expect(title).toBe("Manual-settle failed");
    expect(options?.description).toContain("The closing counts field is required.");
  });

  it("joins multiple field errors so none is silently dropped", async () => {
    const [, options] = await settleAndFail(
      new ApiError(422, {
        message: "The given data was invalid.",
        errors: {
          closing_counts: ["Count every denomination."],
          closing_note: ["A variance reason is required."],
        },
      })
    );

    expect(options?.description).toContain("Count every denomination.");
    expect(options?.description).toContain("A variance reason is required.");
  });

  it("prefers the field errors over the generic envelope message", async () => {
    const [, options] = await settleAndFail(
      new ApiError(422, {
        message: "The given data was invalid.",
        errors: { closing_counts: ["Count every denomination."] },
      })
    );

    // "The given data was invalid." tells the manager nothing actionable.
    expect(options?.description).not.toContain("The given data was invalid.");
  });

  it("falls back to the envelope message on a 409 guard (no errors map)", async () => {
    const [title, options] = await settleAndFail(
      new ApiError(409, {
        message: "Shift still has unpaid orders.",
        code: "SHIFT_NOT_EXPIRED",
      })
    );

    expect(title).toBe("Manual-settle failed");
    expect(options?.description).toBe("Shift still has unpaid orders.");
  });

  it("leaves the description undefined when the API gave us nothing", async () => {
    const [title, options] = await settleAndFail(new Error("network down"));

    // A non-ApiError carries no server reason — show the generic label alone
    // rather than leaking a stack-ish message into the toast body.
    expect(title).toBe("Manual-settle failed");
    expect(options?.description).toBeUndefined();
  });

  it("does not fire an error toast on success", async () => {
    manualSettle.mockResolvedValue({
      data: { session_code: "WS-20260720-034441-8cee06" },
    } as Awaited<ReturnType<typeof tillSessionAdminService.manualSettle>>);

    const { result } = renderHookWithProviders(() => useManualSettleSession("test-shop"));
    result.current.mutate({ id: "sess-1", data: SETTLE_PAYLOAD });

    await waitFor(() => expect(toast.success).toHaveBeenCalled());
    expect(toast.error).not.toHaveBeenCalled();
  });
});

describe("useForceAbandonSession — onError surfacing", () => {
  it("shares the same 422 field-error surfacing", async () => {
    forceAbandon.mockRejectedValue(
      new ApiError(422, {
        message: "The given data was invalid.",
        errors: { reason_detail: ["Explain why this shift is being abandoned."] },
      })
    );

    const { result } = renderHookWithProviders(() => useForceAbandonSession("test-shop"));
    result.current.mutate({
      id: "sess-1",
      data: { reason_code: "end_of_employment", reason_detail: "x" },
    });

    await waitFor(() => expect(toast.error).toHaveBeenCalled());
    const [title, options] = vi.mocked(toast.error).mock.calls[0];
    expect(title).toBe("Force-abandon failed");
    expect(options?.description).toContain("Explain why this shift is being abandoned.");
  });
});
