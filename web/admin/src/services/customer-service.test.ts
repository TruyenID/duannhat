import { describe, it, expect, vi, beforeEach } from "vitest";
import { customerService } from "./customer-service";

const mockFetch = vi.fn();
globalThis.fetch = mockFetch;

function mockOk(data: unknown, status = 200) {
  mockFetch.mockResolvedValueOnce({
    ok: true,
    status,
    json: () => Promise.resolve(data),
  });
}

function mockNoContent() {
  mockFetch.mockResolvedValueOnce({
    ok: true,
    status: 204,
    json: () => Promise.resolve(null),
  });
}

beforeEach(() => {
  mockFetch.mockReset();
});

describe("customerService", () => {
  it("list builds correct URL with filters", async () => {
    mockOk({ data: [], links: {}, meta: {} });

    await customerService.list("shop-1", { search: "a", page: 2 });

    const url = mockFetch.mock.calls[0][0] as string;
    expect(url).toContain("/api/v1/shops/shop-1/customers");
    expect(url).toContain("page=2");
    expect(url).toContain("search=a");
  });

  it("create sends POST with JSON body", async () => {
    const body = { first_name: "Test", last_name: "Customer" };
    mockOk({ data: { id: "1", ...body } });

    await customerService.create("shop-1", body);

    const [, opts] = mockFetch.mock.calls[0];
    expect(opts.method).toBe("POST");
    expect(JSON.parse(opts.body)).toEqual(body);
  });

  it("delete sends DELETE and returns null on 204", async () => {
    mockNoContent();

    const result = await customerService.delete("shop-1", "cust-1");

    const [url, opts] = mockFetch.mock.calls[0];
    expect(url).toContain("/api/v1/shops/shop-1/customers/cust-1");
    expect(opts.method).toBe("DELETE");
    expect(result).toBeNull();
  });
});
