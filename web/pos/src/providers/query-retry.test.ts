import { describe, expect, it } from "vitest";
import { ApiError } from "@/lib/api";
import {
  retryDelay,
  shouldRetryMutation,
  shouldRetryQuery,
} from "./query-retry";

describe("shouldRetryQuery", () => {
  it("retries on 5xx up to 3 times", () => {
    const err = new ApiError(500, {});
    expect(shouldRetryQuery(0, err)).toBe(true);
    expect(shouldRetryQuery(1, err)).toBe(true);
    expect(shouldRetryQuery(2, err)).toBe(true);
    expect(shouldRetryQuery(3, err)).toBe(false);
  });

  it("retries on 503 Service Unavailable", () => {
    expect(shouldRetryQuery(0, new ApiError(503, {}))).toBe(true);
  });

  it("does NOT retry on 400 Bad Request", () => {
    expect(shouldRetryQuery(0, new ApiError(400, {}))).toBe(false);
  });

  it("does NOT retry on 401 Unauthorized", () => {
    expect(shouldRetryQuery(0, new ApiError(401, {}))).toBe(false);
  });

  it("does NOT retry on 403 Forbidden", () => {
    expect(shouldRetryQuery(0, new ApiError(403, {}))).toBe(false);
  });

  it("does NOT retry on 404 Not Found", () => {
    expect(shouldRetryQuery(0, new ApiError(404, {}))).toBe(false);
  });

  it("does NOT retry on 409 Conflict", () => {
    expect(shouldRetryQuery(0, new ApiError(409, {}))).toBe(false);
  });

  it("does NOT retry on 422 Validation", () => {
    expect(shouldRetryQuery(0, new ApiError(422, {}))).toBe(false);
  });

  it("retries on TypeError (network error from fetch)", () => {
    expect(shouldRetryQuery(0, new TypeError("Failed to fetch"))).toBe(true);
    expect(shouldRetryQuery(2, new TypeError("Failed to fetch"))).toBe(true);
    expect(shouldRetryQuery(3, new TypeError("Failed to fetch"))).toBe(false);
  });

  it("retries on generic Error (abort, timeout, etc)", () => {
    expect(shouldRetryQuery(0, new Error("aborted"))).toBe(true);
  });
});

describe("shouldRetryMutation", () => {
  it("never automatically replays a write with an unknown commit outcome", () => {
    expect(shouldRetryMutation()).toBe(false);
    expect(shouldRetryMutation()).toBe(false);
  });
});

describe("retryDelay", () => {
  it("returns exponential backoff: 500ms → 1s → 2s → 4s", () => {
    expect(retryDelay(0)).toBe(500);
    expect(retryDelay(1)).toBe(1000);
    expect(retryDelay(2)).toBe(2000);
    expect(retryDelay(3)).toBe(4000);
  });

  it("caps at 4000ms", () => {
    expect(retryDelay(4)).toBe(4000);
    expect(retryDelay(10)).toBe(4000);
  });
});
