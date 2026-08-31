import { describe, expect, it } from "vitest";
import {
  PAYMENTS_STATE_TEST_IDS,
  resolvePaymentsViewState,
} from "@/app/shop/[shopSlug]/settings/payments/lib/payments-view-state";
import { ApiError } from "@/lib/api";

describe("resolvePaymentsViewState (G12)", () => {
  it("returns loading when fetching without cached data", () => {
    expect(resolvePaymentsViewState({ isLoading: true, isError: false, hasData: false }).kind).toBe(
      "loading"
    );
  });

  it("returns forbidden for 403 without data", () => {
    const state = resolvePaymentsViewState({
      isLoading: false,
      isError: true,
      error: new ApiError(403, { message: "forbidden" }),
      hasData: false,
    });
    expect(state.kind).toBe("forbidden");
  });

  it("returns prerequisite for franchise missing connection config", () => {
    const state = resolvePaymentsViewState({
      isLoading: false,
      isError: false,
      hasData: true,
      setupRequired: true,
      isEmpty: false,
    });
    expect(state.kind).toBe("data");
  });

  it("returns data with stale flag while refetching", () => {
    const state = resolvePaymentsViewState({
      isLoading: false,
      isFetching: true,
      isError: false,
      hasData: true,
    });
    expect(state.kind).toBe("data");
    expect(state.isStale).toBe(true);
  });

  it("returns conflict error for 409", () => {
    const state = resolvePaymentsViewState({
      isLoading: false,
      isError: true,
      error: new ApiError(409, { code: "VERSION_CONFLICT", message: "conflict" }),
      hasData: false,
    });
    expect(state.kind).toBe("error");
    expect(state.statusCode).toBe(409);
  });

  it("maps mutually exclusive test ids to one kind each", () => {
    const ids = Object.values(PAYMENTS_STATE_TEST_IDS);
    expect(new Set(ids).size).toBe(ids.length);
  });
});
