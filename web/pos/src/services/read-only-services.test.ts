import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { shopService } from "./shop-service";
import { staffService } from "./staff-service";
import { tableService } from "./table-service";

/*
 * #284 — ba service đọc này ở mức phủ 0% khi đo lại DoD của epic.
 *
 * Chúng mỏng, nhưng cái mỏng không có nghĩa là không hỏng được: thứ dễ vỡ ở đây
 * là HỢP ĐỒNG URL. Cả ba đều nhận `shopSlug` rồi CỐ Ý không dùng — phạm vi cửa
 * hàng đi bằng header `X-Shop-Slug`, tham số giữ lại chỉ để khoá cache của
 * TanStack Query. Ai đó "dọn dẹp" bằng cách nhét slug trở lại đường dẫn sẽ vẫn
 * biên dịch được, vẫn chạy, và hỏng đúng lúc một máy POS trỏ nhầm cửa hàng.
 */

const originalFetch = global.fetch;
const fetchMock = vi.fn();

function mockOk(body: unknown = { data: [] }): void {
  fetchMock.mockResolvedValueOnce({
    ok: true,
    status: 200,
    json: async () => body,
  } as Response);
}

function requestedUrl(): string {
  return String(fetchMock.mock.calls[0][0]);
}

beforeEach(() => {
  global.fetch = fetchMock;
  fetchMock.mockReset();
  localStorage.setItem("pos_device_token", "test-token");
});

afterEach(() => {
  global.fetch = originalFetch;
});

describe("shopService", () => {
  it("GETs /pos/shop — slug KHÔNG nằm trong đường dẫn", async () => {
    mockOk({ data: { id: "s1", slug: "kichijoji", name: "X", code: null, is_headquarters: false } });
    await shopService.getBySlug("kichijoji");

    expect(requestedUrl()).toContain("/api/v1/pos/shop");
    expect(requestedUrl()).not.toContain("kichijoji");
  });
});

describe("staffService", () => {
  it("GETs /pos/staff", async () => {
    mockOk();
    await staffService.list();

    expect(requestedUrl()).toContain("/api/v1/pos/staff");
  });
});

describe("tableService", () => {
  it("mặc định per_page=100 — một nhà hàng đủ bàn trong MỘT trang", async () => {
    mockOk({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } });
    await tableService.list("kichijoji");

    expect(requestedUrl()).toContain("per_page=100");
  });

  it("người gọi ghi đè được per_page, và bộ lọc đi vào query", async () => {
    mockOk({ data: [], meta: { current_page: 2, last_page: 2, per_page: 20, total: 30 } });
    await tableService.list("kichijoji", { status: "occupied", zone_id: "z1", search: "A", page: 2, per_page: 20 });

    const url = requestedUrl();
    expect(url).toContain("per_page=20");
    expect(url).toContain("status=occupied");
    expect(url).toContain("zone_id=z1");
    expect(url).toContain("search=A");
    expect(url).toContain("page=2");
  });

  it("bỏ qua bộ lọc rỗng thay vì gửi tham số trắng", async () => {
    mockOk({ data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } });
    await tableService.list("kichijoji", { search: "" });

    expect(requestedUrl()).not.toContain("search=");
  });

  it("changeStatus MÃ HOÁ id bàn — id có dấu / sẽ tạo ra đường dẫn khác", async () => {
    mockOk({ data: { id: "a/b" } });
    await tableService.changeStatus("kichijoji", "a/b", "free");

    const url = requestedUrl();
    expect(url).toContain("/api/v1/pos/tables/a%2Fb/status");
    expect(url).not.toContain("/tables/a/b/");

    const init = fetchMock.mock.calls[0][1] as RequestInit;
    expect(init.method).toBe("POST");
    expect(JSON.parse(String(init.body))).toEqual({ status: "free" });
  });
});
