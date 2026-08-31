import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const sentry = vi.hoisted(() => ({ captureMessage: vi.fn() }));
vi.mock("@/lib/sentry", () => sentry);

import { shopMenuService } from "./shop-menu-service";

/*
 * #284 — `shop-menu-service` không có một dòng test nào (0% coverage), và hai
 * thứ trong nó đáng test không phải vì con số:
 *
 *  1. `withMock` NUỐT LỖI và trả dữ liệu GIẢ khi `import.meta.env.DEV`. Nếu cờ
 *     đó từng đúng trong một bản dựng ship ra ngoài, một lần API hỏng sẽ hiện
 *     thực đơn bịa cho thu ngân — và không có gì kêu. Ràng buộc "ngoài DEV thì
 *     ném" là thứ giữ nó an toàn, nên nó phải có test.
 *  2. `listByDay` KẸP ngày về 0–6. Truyền `-1` hoặc `9` mà không kẹp thì URL ra
 *     `/by-day/-1` → 404, và triệu chứng ở màn hình là "không có thực đơn nào"
 *     chứ không phải một lỗi đọc được.
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

function lastUrl(): string {
  return String(fetchMock.mock.calls[0][0]);
}

beforeEach(() => {
  global.fetch = fetchMock;
  fetchMock.mockReset();
  sentry.captureMessage.mockReset();
  localStorage.setItem("pos_device_token", "test-token");
});

afterEach(() => {
  global.fetch = originalFetch;
});

describe("listByDay kẹp thứ trong tuần", () => {
  it.each([
    [0, "/by-day/0"],
    [6, "/by-day/6"],
    [-1, "/by-day/0"],
    [9, "/by-day/6"],
    [3.7, "/by-day/3"],
  ])("dayOfWeek=%s → %s", async (day, expected) => {
    mockOk({ data: [], meta: {} });
    await shopMenuService.listByDay("shop-a", day as number);
    expect(lastUrl()).toContain(expected);
  });
});

describe("bộ lọc chỉ đưa vào URL những khoá CÓ giá trị", () => {
  it("bỏ qua khoá rỗng/undefined thay vì gửi chuỗi rỗng", async () => {
    mockOk({ data: [], meta: {} });
    await shopMenuService.list("shop-a", {
      search: "",
      status: "Active",
      page: 2,
    });

    const url = lastUrl();
    expect(url).toContain("status=Active");
    expect(url).toContain("page=2");
    // `search=` rỗng gửi lên là backend lọc theo chuỗi rỗng — khác hẳn "không lọc".
    expect(url).not.toContain("search=");
  });
});

describe("listAllProducts — đi HẾT các trang", () => {
  /*
   * Vì sao nhóm test này tồn tại: màn POS gom sản phẩm theo section rồi dựng
   * thanh section TỪ CHÍNH kết quả đó, nên một câu trả lời thiếu trang KHÔNG
   * hiện ra như "còn nữa" — nó hiện ra như một thực đơn đầy đủ bị hụt vài
   * section. Menu tối của 本郷 (89 món) hỏi 60 dòng thì mất trọn
   * デザート・飲み物, アルコール, 無料サービス; menu PHO EXPRESS hiện 15/26
   * section. Không có gì trên màn hình nói điều đó.
   */

  function page(ids: string[], lastPage: number, total: number) {
    return {
      data: ids.map((id) => ({ id })),
      meta: { current_page: 1, last_page: lastPage, per_page: 100, total },
    };
  }

  function pageParam(callIndex: number): string | null {
    const url = new URL(
      String(fetchMock.mock.calls[callIndex][0]),
      "http://localhost",
    );
    return url.searchParams.get("page");
  }

  it("gộp mọi trang mà `last_page` khai báo", async () => {
    mockOk(page(["a", "b"], 3, 5));
    mockOk(page(["c", "d"], 3, 5));
    mockOk(page(["e"], 3, 5));

    const res = await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(res.data.map((mp) => mp.id)).toEqual(["a", "b", "c", "d", "e"]);
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect([pageParam(0), pageParam(1), pageParam(2)]).toEqual(["1", "2", "3"]);
  });

  it("thực đơn vừa MỘT trang vẫn chỉ tốn một request", async () => {
    mockOk(page(["a"], 1, 1));

    await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("bỏ dòng lặp giữa hai trang — `display_order` không duy nhất", async () => {
    // Ranh giới trang rơi vào giữa một cụm cùng display_order thì server có
    // thể trả lại đúng dòng đó ở trang sau. Grid dùng `mp.id` làm React key,
    // nên để lọt là hai thẻ trùng key cho cùng một món.
    mockOk(page(["a", "b"], 2, 3));
    mockOk(page(["b", "c"], 2, 3));

    const res = await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(res.data.map((mp) => mp.id)).toEqual(["a", "b", "c"]);
  });

  it("dừng ở trần 30 trang khi server khai `last_page` vô lý", async () => {
    // Trần này không phải giới hạn hiển thị: nó là thứ giữ cho một server khai
    // sai `last_page` tốn một số request CÓ HẠN, thay vì quay vòng tới khi cái
    // tablet bỏ cuộc giữa giờ phục vụ.
    for (let i = 0; i < 40; i++) mockOk(page([`p${i}`], 999, 999));

    const res = await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(fetchMock).toHaveBeenCalledTimes(30);
    expect(res.data).toHaveLength(30);
    // Và nó phải KÊU. Một lượt đi có trần mà cắt im lặng chính là lỗi hàm này
    // sinh ra để chữa, chỉ lùi xa hơn một bậc: đứng ở quầy không ai phân biệt
    // được thực đơn 3000 dòng với thực đơn bị cắt.
    expect(sentry.captureMessage).toHaveBeenCalledWith(
      expect.stringContaining("truncated"),
      "error",
    );
  });

  it("thực đơn vừa trong trần thì KHÔNG báo động", async () => {
    mockOk(page(["a"], 2, 2));
    mockOk(page(["b"], 2, 2));

    await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(sentry.captureMessage).not.toHaveBeenCalled();
  });

  it("trang rỗng giữa chừng thì dừng, không đi hết `last_page`", async () => {
    mockOk(page(["a"], 5, 1));
    mockOk(page([], 5, 1));

    await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("server BỎ QUA `page` — cắt im lặng, phải kêu chứ không trả nửa thực đơn", async () => {
    // Lớp lỗi này y hệt cái PR đang chữa, chỉ lùi thêm một bậc. Một proxy đệm
    // sai, một route mất `page` khỏi query, một LAN mirror cũ — cả ba đều trả
    // ĐÚNG trang 1 cho mọi số trang. Khử trùng theo `mp.id` khi đó làm đúng
    // việc của nó (không đẻ thẻ trùng) nhưng hệ quả là thực đơn dừng ở 2/5
    // dòng, và `last_page` = 3 vẫn nằm trong trần nên nhánh báo động cũ KHÔNG
    // chạm tới. Đứng ở quầy: thiếu section, không một dấu hiệu nào.
    mockOk(page(["a", "b"], 3, 5));
    mockOk(page(["a", "b"], 3, 5));
    mockOk(page(["a", "b"], 3, 5));

    const res = await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(res.data.map((mp) => mp.id)).toEqual(["a", "b"]);
    expect(sentry.captureMessage).toHaveBeenCalledWith(
      expect.stringContaining("incomplete"),
      "error",
    );
  });

  it("hai trang liền không thêm được dòng nào thì DỪNG, không nướng 30 lượt", async () => {
    // Cùng một server hỏng như trên, nhìn từ phía chi phí: mỗi trang là ~638KB
    // và màn POS refetch mỗi 60 giây. Đi hết trần nghĩa là 30 lượt tải lại
    // đúng một payload trên máy tablet đang bán hàng.
    for (let i = 0; i < 40; i++) mockOk(page(["a"], 20, 20));

    await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it("thiếu dòng vì THỨ TỰ vỡ ở ranh giới trang cũng bị bắt", async () => {
    // Đây là chuông canh cho chính bản vá backend trong PR này. `ORDER BY
    // display_order` một mình không phải thứ tự toàn phần (104/127 dòng cùng
    // nằm ở 0), nên LIMIT/OFFSET được phép trả "b" hai lần và đánh rơi "c".
    // Nếu tie-break ở MenuService bị revert, trang vẫn trả về đủ số trang và
    // đủ `last_page` — chỉ có tổng số dòng là hụt. Không có phép so này thì
    // regression đó im lặng tuyệt đối.
    mockOk(page(["a", "b"], 2, 4));
    mockOk(page(["b", "d"], 2, 4));

    const res = await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(res.data).toHaveLength(3);
    expect(sentry.captureMessage).toHaveBeenCalledWith(
      expect.stringContaining("incomplete"),
      "error",
    );
  });

  it("đủ dòng thì IM — chuông không được kêu oan", async () => {
    // Rào phải chứng minh cả hai chiều. Một chuông kêu oan mỗi 60 giây trên
    // mọi tablet không bị đem ra tranh luận, nó bị TẮT.
    mockOk(page(["a", "b"], 2, 3));
    mockOk(page(["c"], 2, 3));

    const res = await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(res.data).toHaveLength(3);
    expect(sentry.captureMessage).not.toHaveBeenCalled();
  });

  it("`total` khuyết/không phải số thì KHÔNG kêu — chỉ đếm được mới kết luận được", async () => {
    // Mirror LAN của máy trạm không phát `total`. Suy "thiếu dòng" từ một ô
    // trống là bịa ra một sự cố; im lặng ở đây mới là câu trả lời trung thực.
    mockOk({ data: [{ id: "a" }], meta: { current_page: 1, last_page: 1 } });

    await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(sentry.captureMessage).not.toHaveBeenCalled();
  });

  it("chạm trần thì chỉ kêu MỘT tiếng, không kêu chồng hai loại", async () => {
    for (let i = 0; i < 40; i++) mockOk(page([`p${i}`], 999, 9999));

    await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(sentry.captureMessage).toHaveBeenCalledTimes(1);
    expect(sentry.captureMessage).toHaveBeenCalledWith(
      expect.stringContaining("truncated"),
      "error",
    );
  });

  it("thực đơn RỖNG là trạng thái hợp lệ, không phải sự cố", async () => {
    mockOk(page([], 1, 0));

    const res = await shopMenuService.listAllProducts("shop-a", "menu-1");

    expect(res.data).toEqual([]);
    expect(res.meta.from).toBeNull();
    expect(res.meta.to).toBeNull();
    expect(sentry.captureMessage).not.toHaveBeenCalled();
  });

  it("lỗi ở trang sau NỔI LÊN, không trả về nửa thực đơn", async () => {
    // Nửa thực đơn im lặng chính là lỗi đang sửa. Màn hình có sẵn trạng thái
    // lỗi + nút thử lại; đó mới là câu trả lời trung thực.
    //
    // Phải chạy ở nhánh production: dưới vitest `import.meta.env.DEV` là TRUE,
    // và `mockShopMenu.id` đúng bằng "menu-1", nên cửa hậu DEV sẽ NUỐT lỗi
    // trang 2 rồi trả về 9 món bịa — chính cái bẫy nhóm test dưới ghim.
    vi.stubEnv("DEV", false);
    vi.resetModules();
    const svc = (await import("./shop-menu-service")).shopMenuService;
    mockOk(page(["a"], 2, 2));
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));

    await expect(svc.listAllProducts("shop-a", "menu-1")).rejects.toThrow();

    vi.unstubAllEnvs();
    vi.resetModules();
  });
});

describe("withMock — cửa hậu DEV", () => {
  /*
   * `import.meta.env.DEV` là TRUE dưới `vitest run` — tôi tưởng ngược lại và
   * viết sai test lần đầu. Ghi lại vì nó đổi ý nghĩa của cả nhóm test này: chạy
   * suite KHÔNG đi qua nhánh production, nên phải bắt cờ đó một cách tường minh
   * thì mới kiểm được đường mà bản ship thật sự chạy.
   *
   * An toàn của production nằm TRỌN VẸN ở việc Vite đặt `DEV=false` cho
   * `vite build`. Không có rào nào khác — đó là lý do nhóm test này tồn tại.
   */

  afterEach(() => {
    vi.unstubAllEnvs();
    vi.resetModules();
  });

  /**
   * Nạp LẠI module sau khi đặt cờ.
   *
   * `shop-menu-service` chụp `const DEV = import.meta.env.DEV` ở cấp module,
   * nên `vi.stubEnv` sau khi import không có tác dụng — tôi đã mất một lượt vì
   * điều đó. Bản thân việc chụp lúc nạp là TỐT cho an toàn (không có công tắc
   * runtime nào bật lại cửa hậu), nhưng nó có nghĩa là chỉ nạp lại module mới
   * kiểm được nhánh kia.
   */
  async function serviceWithDev(dev: boolean) {
    vi.stubEnv("DEV", dev);
    vi.resetModules();
    return (await import("./shop-menu-service")).shopMenuService;
  }

  it("PRODUCTION: lỗi API nổi lên, KHÔNG trả dữ liệu giả", async () => {
    const svc = await serviceWithDev(false);
    fetchMock.mockResolvedValueOnce({
      ok: false,
      status: 500,
      json: async () => ({ message: "boom" }),
    } as Response);

    await expect(svc.list("shop-a")).rejects.toThrow();
  });

  it("PRODUCTION: lỗi mạng cũng nổi lên — không âm thầm thành thực đơn giả", async () => {
    const svc = await serviceWithDev(false);
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));

    await expect(svc.getById("shop-a", "menu-1")).rejects.toThrow();
  });

  it("DEV: lỗi API bị NUỐT và thay bằng dữ liệu giả — ghim đúng mối nguy", async () => {
    const svc = await serviceWithDev(true);
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));

    const res = await svc.getById("shop-a", "menu-1");

    // Đây KHÔNG phải hành vi mong muốn ngoài máy dev — nó được ghim để việc nó
    // rò ra production là một thay đổi CÓ Ý THỨC, không phải một lần trượt.
    expect(res).toBeTruthy();
    expect(warn).toHaveBeenCalled();
    warn.mockRestore();
  });
});

/*
 * #3163 — tải thực đơn THEO SECTION.
 *
 * #3159 chữa "mất section" bằng cách cho POS đi hết các trang, nhưng chi phí
 * vẫn tuyến tính theo số món: menu 89 dòng = 638 KB một vòng, `refetchInterval`
 * 60 giây, menu ~1000 món ⇒ ~7 MB mỗi phút mỗi tablet.
 *
 * Ba bài dưới đây đo ĐÚNG chỗ dễ hỏng nhất của tầng này: URL. Một tham số gõ
 * sai tên hoặc bị rơi không làm gì đỏ — nó chỉ lặng lẽ trả về CẢ thực đơn, tức
 * đúng thứ vừa đi sửa, và trông y hệt lúc chạy đúng.
 */
describe("#3163 — tải theo section", () => {
  it("listSections gọi đúng đường và KHÔNG rơi về mock khi hỏng", async () => {
    mockOk({ data: [{ id: "sec-1", name: "Đồ uống", display_order: 1, products_count: 2 }] });

    const res = await shopMenuService.listSections("shop-a", "menu-1");

    expect(fetchMock.mock.calls[0][0]).toContain("/api/v1/pos/menus/menu-1/sections");
    expect(res.data[0].products_count).toBe(2);
  });

  it("section_id và sku_id ĐI ĐƯỢC tới URL", async () => {
    mockOk({ data: [], meta: { last_page: 1 } });
    await shopMenuService.listProducts("shop-a", "menu-1", { section_id: "sec-1" });
    expect(String(fetchMock.mock.calls[0][0])).toContain("section_id=sec-1");

    fetchMock.mockClear();
    mockOk({ data: [], meta: { last_page: 1 } });
    await shopMenuService.listProducts("shop-a", "menu-1", { sku_id: "sku-9" });
    expect(String(fetchMock.mock.calls[0][0])).toContain("sku_id=sku-9");
  });

  it('"none" (nhóm chưa xếp) KHÔNG bị rơi mất trên đường ra URL', async () => {
    // Ca dễ mất nhất của cả bộ: một lượt "dọn dẹp" đổi `if (filters.section_id)`
    // thành một phép kiểm khác có thể loại `"none"` đi, và lúc đó lưới lặng lẽ
    // trả về CẢ thực đơn thay vì nhóm chưa xếp. Món không thuộc section nào vẫn
    // phải bán được — giấu chúng chính là #3159 ở dạng khác.
    mockOk({ data: [], meta: { last_page: 1 } });

    await shopMenuService.listProducts("shop-a", "menu-1", { section_id: "none" });

    expect(String(fetchMock.mock.calls[0][0])).toContain("section_id=none");
  });
});
