import { describe, it, expect, beforeEach, vi, type Mock } from "vitest";
import {
  markPreparing,
  markReady,
  markServed,
  revertItem,
  bumpAll,
} from "../operations";
import { ApiError } from "@/lib/api";

// Mock apiFetch at the module level
vi.mock("@/lib/api", async (importOriginal) => {
  // eslint-disable-next-line @typescript-eslint/consistent-type-imports
  const actual = await importOriginal<typeof import("@/lib/api")>();
  return {
    ...actual,
    apiFetch: vi.fn(),
  };
});

// We need to import apiFetch after mocking to get the mock reference
import { apiFetch } from "@/lib/api";

const mockApiFetch = apiFetch as Mock;

beforeEach(() => {
  vi.clearAllMocks();
});

describe("markPreparing", () => {
  it("calls correct URL, method, and idempotency header", async () => {
    mockApiFetch.mockResolvedValueOnce({ data: { id: "i-1", status: "preparing" } });

    await markPreparing("o-1", "i-1", { idempotencyKey: "key-123" });

    expect(mockApiFetch).toHaveBeenCalledWith(
      "/api/v1/kds/orders/o-1/items/i-1/mark-preparing",
      expect.objectContaining({
        method: "POST",
        headers: expect.objectContaining({ "Idempotency-Key": "key-123" }),
      }),
    );
  });
});

describe("markReady", () => {
  it("calls correct URL, method, and idempotency header", async () => {
    mockApiFetch.mockResolvedValueOnce({ data: { id: "i-1", status: "ready" } });

    await markReady("o-1", "i-1", { idempotencyKey: "key-456" });

    expect(mockApiFetch).toHaveBeenCalledWith(
      "/api/v1/kds/orders/o-1/items/i-1/mark-ready",
      expect.objectContaining({
        method: "POST",
        headers: expect.objectContaining({ "Idempotency-Key": "key-456" }),
      }),
    );
  });
});

describe("markServed", () => {
  it("calls correct URL, method, and idempotency header", async () => {
    mockApiFetch.mockResolvedValueOnce({ data: { id: "i-1", status: "served" } });

    await markServed("o-1", "i-1", { idempotencyKey: "key-789" });

    expect(mockApiFetch).toHaveBeenCalledWith(
      "/api/v1/kds/orders/o-1/items/i-1/mark-served",
      expect.objectContaining({
        method: "POST",
        headers: expect.objectContaining({ "Idempotency-Key": "key-789" }),
      }),
    );
  });
});

describe("revertItem", () => {
  it("calls correct URL with 'to: pending' body", async () => {
    mockApiFetch.mockResolvedValueOnce({ data: { id: "i-1", status: "pending" } });

    await revertItem("o-1", "i-1", "pending", { idempotencyKey: "key-rev" });

    expect(mockApiFetch).toHaveBeenCalledWith(
      "/api/v1/kds/orders/o-1/items/i-1/revert",
      expect.objectContaining({
        method: "POST",
        headers: expect.objectContaining({ "Idempotency-Key": "key-rev" }),
        body: JSON.stringify({ to: "pending" }),
      }),
    );
  });

  it("calls correct URL with 'to: preparing' body", async () => {
    mockApiFetch.mockResolvedValueOnce({ data: { id: "i-1", status: "preparing" } });

    await revertItem("o-1", "i-1", "preparing", { idempotencyKey: "key-rev2" });

    expect(mockApiFetch).toHaveBeenCalledWith(
      "/api/v1/kds/orders/o-1/items/i-1/revert",
      expect.objectContaining({
        body: JSON.stringify({ to: "preparing" }),
      }),
    );
  });
});

describe("bumpAll", () => {
  it("calls correct URL with 'scope: pending' body", async () => {
    mockApiFetch.mockResolvedValueOnce({ data: { id: "o-1" } });

    await bumpAll("o-1", "pending", { idempotencyKey: "key-bump" });

    expect(mockApiFetch).toHaveBeenCalledWith(
      "/api/v1/kds/orders/o-1/bump-all",
      expect.objectContaining({
        method: "POST",
        headers: expect.objectContaining({ "Idempotency-Key": "key-bump" }),
        body: JSON.stringify({ scope: "pending" }),
      }),
    );
  });

  it("calls correct URL with 'scope: preparing' body", async () => {
    mockApiFetch.mockResolvedValueOnce({ data: { id: "o-1" } });

    await bumpAll("o-1", "preparing", { idempotencyKey: "key-bump2" });

    expect(mockApiFetch).toHaveBeenCalledWith(
      "/api/v1/kds/orders/o-1/bump-all",
      expect.objectContaining({
        body: JSON.stringify({ scope: "preparing" }),
      }),
    );
  });
});

describe("ApiError RFC 7807 parsing", () => {
  it("populates code, detail, context, remediation from RFC 7807 body", () => {
    const body = {
      type: "https://example.com/errors/kds",
      title: "Invalid transition",
      status: 409,
      code: "KDS_E002",
      detail: "Cannot mark item as served from pending state",
      context: { current_status: "pending", requested: "served" },
      remediation: "Mark item as preparing first",
    };

    const err = new ApiError(409, body, body.detail);

    expect(err.status).toBe(409);
    expect(err.code).toBe("KDS_E002");
    expect(err.detail).toBe("Cannot mark item as served from pending state");
    expect(err.context).toEqual({ current_status: "pending", requested: "served" });
    expect(err.remediation).toBe("Mark item as preparing first");
    expect(err.message).toBe("Cannot mark item as served from pending state");
  });

  it("sets all RFC 7807 fields to null when body is not an object", () => {
    const err = new ApiError(500, null, "500");

    expect(err.code).toBeNull();
    expect(err.detail).toBeNull();
    expect(err.context).toBeNull();
    expect(err.remediation).toBeNull();
  });

  it("sets nullable fields to null when missing from body", () => {
    const body = { message: "Legacy error" };
    const err = new ApiError(500, body, "Legacy error");

    expect(err.code).toBeNull();
    expect(err.detail).toBeNull();
    expect(err.context).toBeNull();
    expect(err.remediation).toBeNull();
  });

  it("handles all KDS error code patterns (E001..E008)", () => {
    const codes = [
      "KDS_E001",
      "KDS_E002",
      "KDS_E003",
      "KDS_E004",
      "KDS_E005",
      "KDS_E006",
      "KDS_E007",
      "KDS_E008",
    ];

    for (const code of codes) {
      const err = new ApiError(409, { code, detail: "error" }, "error");
      expect(err.code).toBe(code);
    }
  });

  it("handles context being null explicitly in body", () => {
    const body = { code: "KDS_E001", detail: "finalized", context: null };
    const err = new ApiError(409, body, "finalized");
    expect(err.context).toBeNull();
  });
});
