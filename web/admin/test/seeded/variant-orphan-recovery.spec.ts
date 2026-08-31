/**
 * Seeded E2E — #2488: giá trị tuỳ chọn mồ côi sau khi xoá SKU.
 *
 * Diễn lại đúng sự cố của khách Betoya trên sản phẩm Rượu Gin (2026-08-11) và
 * chứng minh cả ba bản vá bằng UI thật + backend thật:
 *
 *   1. Xoá một SKU bỏ lại giá trị mồ côi → banner nói ra sự chênh lệch
 *      (trước đây màn hình hiện "2 chip / 1 dòng" và im lặng).
 *   2. Nút 「不足バリエーションを作成」 khôi phục ĐÚNG hàng SKU cũ — cùng id,
 *      giữ nguyên giá — rồi banner tự ẩn.
 *   3. Thêm lại nhãn trùng bị chặn bằng NHÃN (`翠ジン`), không phải slug nội
 *      bộ (`value_i51sxu`) — thông điệp cũ chính là thứ đẩy khách vào việc
 *      bịa tên `翠ジン -` trên production.
 *
 * Nhãn tiếng Nhật + slug dạng băm là chi tiết CÓ CHỦ ĐÍCH: kanji/katakana không
 * phân rã được sang ASCII nên slug rơi về `value_<hash>`, và đó đúng là hình
 * dạng dữ liệu production đã gây sự cố.
 *
 * Yêu cầu (giống peripheral-devices.spec.ts — mặc định trỏ docker seed Betoya):
 *   - backend chạy với SSO_DEV_BYPASS=true và subject của token nằm trong
 *     SSO_DEV_BYPASS_SUBJECTS
 *   - DB đã `migrate:fresh --seed`
 *   - admin-web dev server với TEMPO_BACKEND_URL trỏ backend đó
 *
 * Env:
 *   PLAYWRIGHT_SEEDED_TOKEN  dev-bypass token (default "dev:<famgia admin sub>")
 *   PLAYWRIGHT_SEEDED_ORG    console org id   (default Famgia console org)
 */
import { expect, test, type Page } from "@playwright/test";
import { signInAs } from "../fixtures/session";

const TOKEN = process.env.PLAYWRIGHT_SEEDED_TOKEN ?? "dev:019e8a3b-8001-7a00-8001-000000000001";
const ORG = process.env.PLAYWRIGHT_SEEDED_ORG ?? "00000000-aaaa-4aaa-aaaa-000000000001";
const BRAND = "betoya";
const API = `/api/v1/hq/${BRAND}`;

// Mỗi lượt chạy một tên riêng để lượt trước chết giữa chừng không làm bẩn
// lượt sau (slug sản phẩm phải unique trong brand).
const RUN = String(Date.now()).slice(-8);

// Laravel trả HTML (redirect) cho lỗi validation nếu thiếu Accept header —
// mọi request API trong spec phải khai rõ để lỗi hiện ra thành JSON đọc được.
const JSON_HEADERS = { Accept: "application/json", "Content-Type": "application/json" };

/** Dọn mọi sản phẩm E2E-2488 còn sót — của lượt này lẫn các lượt chết trước. */
async function cleanupTestProducts(page: Page): Promise<void> {
  const res = await page.request.get(`${API}/products?search=E2E-2488&per_page=100`, { headers: JSON_HEADERS });
  if (!res.ok()) return;
  const body = (await res.json()) as { data?: Array<{ id: string; name?: string }> };
  for (const row of body.data ?? []) {
    await page.request.delete(`${API}/products/${row.id}`, { headers: JSON_HEADERS });
  }
}

test.describe("#2488 — giá trị mồ côi sau khi xoá SKU", () => {
  test.beforeEach(async ({ page }) => {
    await signInAs(page, { role: "brand_admin", token: TOKEN, orgId: ORG, locale: "ja" });
    await cleanupTestProducts(page);
  });

  test.afterEach(async ({ page }) => {
    await cleanupTestProducts(page);
  });

  test("banner nói ra chênh lệch, một cú bấm khôi phục SKU kèm giá cũ, lỗi trùng nói bằng nhãn", async ({
    page,
  }) => {
    test.setTimeout(120_000);

    // ── Setup qua API: sản phẩm mới + thuộc tính 2 giá trị ─────────────────
    // Sản phẩm MỚI chứ không mượn Rượu Gin của seed: sản phẩm seed nằm trong
    // menu, nên xoá SKU của nó bị chặn SKU_IN_MENU (đúng thiết kế #2466) và
    // spec sẽ đo nhầm cái rào đó thay vì đo bản vá này.
    const types = (await (
      await page.request.get(`${API}/product-types?per_page=1`, { headers: JSON_HEADERS })
    ).json()) as { data: Array<{ id: string }> };
    expect(types.data.length, "seed phải có ít nhất một loại sản phẩm").toBeGreaterThan(0);

    const created = await page.request.post(`${API}/products`, {
      headers: JSON_HEADERS,
      data: {
        name: `E2E-2488-${RUN}`,
        slug: `e2e-2488-${RUN}`,
        product_type_id: types.data[0].id,
        options: [
          {
            key: "kich_thuoc",
            name: "サイズ",
            position: 1,
            values: [
              { value: "value_1deglb5", label: "メガ翠ジン" },
              { value: "value_i51sxu", label: "翠ジン" },
            ],
          },
        ],
        // value_indices trỏ vào mảng values ở trên: một SKU cho mỗi giá trị,
        // giá ¥450 cho 翠ジン ngay từ đầu — con số phải sống sót qua xoá+khôi phục.
        skus: [
          { value_indices: [0], selling_price: 990 },
          { value_indices: [1], selling_price: 450 },
        ],
      },
    });
    expect(created.ok(), `tạo sản phẩm: ${created.status()}`).toBeTruthy();
    const productId = ((await created.json()) as { data: { id: string } }).data.id;
    const productUrl = `/hq/${BRAND}/products/${productId}`;

    // Đảm bảo đủ 2 SKU (tuỳ đường create có tự sinh tổ hợp hay không).
    const listSkus = async () =>
      ((await (await page.request.get(`${API}/products/${productId}/skus`, { headers: JSON_HEADERS })).json()) as {
        data: Array<{ id: string; sku: string; selling_price: string; option_value1?: { label?: string } }>;
      }).data;
    let skus = await listSkus();
    if (skus.length < 2) {
      const gen = await page.request.post(`${API}/products/${productId}/skus/generate-combinations`, { headers: JSON_HEADERS });
      expect(gen.ok(), `generate lần đầu: ${gen.status()}`).toBeTruthy();
      skus = await listSkus();
    }
    expect(skus.length, "phải có đúng 2 SKU trước khi diễn sự cố").toBe(2);

    const suiSku = skus.find((s) => Number(s.selling_price) === 450);
    expect(suiSku, "phải tìm được SKU ¥450 của giá trị 翠ジン").toBeTruthy();

    // ── Diễn sự cố: xoá SKU. Giá trị tuỳ chọn ở lại — mồ côi. ──────────────
    const deleted = await page.request.delete(`${API}/skus/${suiSku!.id}`, { headers: JSON_HEADERS });
    expect(deleted.ok(), `xoá SKU: ${deleted.status()}`).toBeTruthy();

    // ── Bản vá 1+2: banner + nút khôi phục ─────────────────────────────────
    await page.goto(productUrl, { waitUntil: "domcontentloaded" });

    const banner = page.locator('[data-slot="missing-combinations-banner"]');
    await expect(banner, "banner phải nói ra sự chênh lệch 2 giá trị / 1 biến thể").toBeVisible();
    // Con số trong banner phải là con số THẬT, không phải chuỗi tĩnh.
    await expect(banner).toContainText("2");
    await expect(banner).toContainText("1");
    await expect(page.getByText(/バリエーション一覧（1件）/)).toBeVisible();

    await banner.getByRole("button", { name: "不足バリエーションを作成" }).click();

    // Nút xong việc thì banner phải TỰ ẨN — hai con số đã khớp lại.
    await expect(banner).toBeHidden({ timeout: 10_000 });
    await expect(page.getByText(/バリエーション一覧（2件）/)).toBeVisible();

    // Khôi phục nghĩa là CÙNG hàng cũ quay lại: id không đổi, giá ¥450 còn nguyên.
    // Đây là lý do bản vá là nối nút cho generate-combinations chứ không phải
    // bắt người dùng gõ lại từ đầu.
    const after = await listSkus();
    const restored = after.find((s) => s.id === suiSku!.id);
    expect(restored, "phải là ĐÚNG hàng cũ quay lại, không phải hàng mới").toBeTruthy();
    expect(Number(restored!.selling_price), "giá ¥450 phải sống sót").toBe(450);

    // ── Bản vá 3: lỗi trùng nói bằng NHÃN, không phải slug ─────────────────
    // Khách thấy 翠ジン "thiếu" nên gõ lại nó — giá trị vẫn sống nên bị chặn.
    // Câu chặn phải đọc được: nhãn 翠ジン, không phải value_i51sxu.
    await page.getByRole("button", { name: "編集" }).first().click();
    const addValue = page.getByPlaceholder("値を追加");
    await expect(addValue).toBeVisible();
    await addValue.fill("翠ジン");
    await addValue.press("Enter");
    // Trang có hai nút 保存 (form thuộc tính + "商品を保存" ở header khớp mờ) —
    // lấy nút trong form đang mở, tức nút cuối theo thứ tự DOM.
    await page.getByRole("button", { name: "保存", exact: true }).last().click();

    const toast = page.getByText(/A value named '翠ジン' already exists/);
    await expect(toast, "thông điệp phải mang nhãn người dùng gõ").toBeVisible();
    await expect(
      page.getByText(/value_i51sxu/),
      "slug nội bộ không được lộ ra ở bất kỳ đâu trên màn hình"
    ).toHaveCount(0);
  });
});
