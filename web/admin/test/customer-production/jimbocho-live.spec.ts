import { expect, test, type Locator, type Page, type TestInfo } from "@playwright/test";

const expected = {
  ja: { branch: "神保町店", menu: "神保町店 メニュー", product: "フォー", forbidden: /Aromatic beef pho|Phở bò thơm ngon/ },
  en: { branch: "Jimbocho Store", menu: "Jimbocho Store Menu", product: "Pho", forbidden: /[\u3040-\u30ff\u3400-\u9fff]/ },
  vi: { branch: "Cửa hàng Jimbocho", menu: "Menu cửa hàng Jimbocho", product: "Phở", forbidden: /[\u3040-\u30ff\u3400-\u9fff]/ },
} as const;

async function assertNoHorizontalOverflow(page: Page) {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow, "page must not overflow horizontally").toBeLessThanOrEqual(1);
}

async function openAddableProduct(page: Page): Promise<{ dialog: Locator; addButton: Locator }> {
  const products = page.locator("main h3:visible");
  const count = Math.min(await products.count(), 12);
  for (let index = 0; index < count; index += 1) {
    await products.nth(index).click();
    const dialog = page.getByRole("dialog").first();
    await expect(dialog).toBeVisible();
    const addButton = dialog.locator("button").filter({ hasText: /カートに追加|Add to cart|Thêm vào giỏ/ }).last();
    if (await addButton.isEnabled()) return { dialog, addButton };
    await page.keyboard.press("Escape");
    await expect(dialog).toBeHidden();
  }
  throw new Error("No addable production product was found without changing required modifiers");
}

for (const locale of ["ja", "en", "vi"] as const) {
  test(`${locale} live listing, product, cart and metadata are localized without submitting an order`, async ({ page }, testInfo: TestInfo) => {
    const menuResponses: Array<{ status: number; language: string | null; url: string }> = [];
    page.on("response", (response) => {
      if (response.url().includes("/api/v1/customer/branches/jimbocho/menu")) {
        menuResponses.push({
          status: response.status(),
          language: response.headers()["content-language"] ?? null,
          url: response.url(),
        });
      }
    });

    const startedAt = Date.now();
    await page.goto(`/${locale}/takeaway/jimbocho`, { waitUntil: "domcontentloaded" });
    await expect(page.locator("html")).toHaveAttribute("lang", locale);
    await expect(page.getByText(expected[locale].branch, { exact: true }).first()).toBeVisible();
    await expect(page.getByRole("heading", { name: expected[locale].menu })).toBeVisible();
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", new RegExp(`/${locale}/takeaway/jimbocho$`));
    for (const alternate of ["ja", "en", "vi", "x-default"]) {
      await expect(page.locator(`link[rel="alternate"][hreflang="${alternate}"]`)).toHaveCount(1);
    }
    await expect(page.locator("body")).not.toContainText("Unknown");
    await expect(page.locator("body")).not.toContainText(expected[locale].forbidden);
    await assertNoHorizontalOverflow(page);

    await expect(page.locator("main h3:visible").filter({ hasText: expected[locale].product }).first()).toBeVisible();
    const { dialog, addButton } = await openAddableProduct(page);
    await expect(dialog).not.toContainText("Unknown");
    await expect(dialog.getByRole("button").filter({ has: page.locator("svg") }).first()).toBeVisible();

    await addButton.dblclick();
    await expect(dialog).toBeHidden();

    const cartButton = page.locator("button").filter({ hasText: /カートを見る|View cart|Xem giỏ hàng/ }).last();
    await expect(cartButton).toBeVisible();
    await expect.poll(() => page.evaluate(() => {
      const raw = localStorage.getItem("betoya-cart-takeaway");
      return raw ? (JSON.parse(raw) as unknown[]).length : 0;
    })).toBe(1);

    await page.reload({ waitUntil: "domcontentloaded" });
    await expect(cartButton).toBeVisible();
    await cartButton.click();
    await expect(page.getByRole("dialog")).toBeVisible();
    await expect(page.getByRole("dialog")).not.toContainText("Unknown");
    await page.keyboard.press("Escape");

    await page.goBack();
    await page.goForward();
    await expect(page.locator("html")).toHaveAttribute("lang", locale);
    await expect(page.getByRole("heading", { name: expected[locale].menu })).toBeVisible();
    expect(Date.now() - startedAt, "live rendered journey budget").toBeLessThan(30_000);
    expect(menuResponses.some((response) => response.status === 200)).toBeTruthy();
    expect(menuResponses.every((response) => !response.language || response.language === locale)).toBeTruthy();

    await testInfo.attach(`${locale}-live-network.json`, {
      body: Buffer.from(JSON.stringify(menuResponses, null, 2)),
      contentType: "application/json",
    });
  });
}

test("live locale switch keeps the Jimbocho identity and populated cart on the real API", async ({ page }) => {
  await page.goto("/en/takeaway/jimbocho", { waitUntil: "domcontentloaded" });
  await expect(page.getByRole("heading", { name: expected.en.menu })).toBeVisible();

  const { addButton } = await openAddableProduct(page);
  await addButton.click();
  await expect.poll(() => page.evaluate(() => {
    const raw = localStorage.getItem("betoya-cart-takeaway");
    return raw ? (JSON.parse(raw) as unknown[]).length : 0;
  })).toBe(1);
  const before = await page.evaluate(() => JSON.parse(localStorage.getItem("betoya-cart-takeaway") ?? "[]")[0]);

  const openMenu = page.getByRole("button", { name: "Open menu" });
  if (await openMenu.isVisible()) await openMenu.click();
  const language = page.getByRole("button", { name: "Language" });
  await language.focus();
  await page.keyboard.press("Enter");
  const vietnamese = page.getByRole("menuitemradio", { name: /Tiếng Việt/ });
  await vietnamese.focus();
  await page.keyboard.press("Enter");

  await expect(page).toHaveURL(/\/vi\/takeaway\/jimbocho$/);
  await expect(page.getByRole("heading", { name: expected.vi.menu })).toBeVisible();
  const after = await page.evaluate(() => JSON.parse(localStorage.getItem("betoya-cart-takeaway") ?? "[]")[0]);
  expect(after.id).toBe(before.id);
  expect(after.product.sku_id).toBe(before.product.sku_id);
  expect(after.quantity).toBe(before.quantity);
  expect(await page.evaluate(() => localStorage.getItem("betoya-selected-branch"))).toBe("jimbocho");
  await expect(page.locator("body")).not.toContainText("Unknown");
});
