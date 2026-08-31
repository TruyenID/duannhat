import { expect, test, type Page } from "@playwright/test";

const localized = {
  ja: { branch: "神保町店", menu: "神保町店メニュー", category: "主菜", product: "牛肉フォー", description: "香り豊かな牛肉フォー", option: "サイズ", regular: "普通", large: "大盛り", topping: "追加トッピング", egg: "卵" },
  en: { branch: "Jimbocho Store", menu: "Jimbocho Store Menu", category: "Main dishes", product: "Beef Pho", description: "Aromatic beef pho", option: "Size", regular: "Regular", large: "Large", topping: "Extra toppings", egg: "Egg" },
  vi: { branch: "Cửa hàng Jimbocho", menu: "Menu cửa hàng Jimbocho", category: "Món chính", product: "Phở bò", description: "Phở bò thơm ngon", option: "Kích cỡ", regular: "Thường", large: "Lớn", topping: "Topping thêm", egg: "Trứng" },
} as const;

async function mockCustomerApi(
  page: Page,
  locale: keyof typeof localized,
  options: {
    failMenuAttempts?: number;
    failureStatus?: number;
    abortMenuAttempts?: number;
    emptyMenu?: boolean;
    modifierBoundaries?: boolean;
    hostileContent?: boolean;
    outsideHours?: boolean;
  } = {},
) {
  const seenLanguages: string[] = [];
  let menuHits = 0;
  await page.route("**/api/v1/**", async (route) => {
    seenLanguages.push(route.request().headers()["accept-language"] ?? "");
    const requested = route.request().headers()["accept-language"]?.slice(0, 2) as keyof typeof localized | undefined;
    const text = localized[requested && requested in localized ? requested : locale];
    const url = route.request().url();
    const path = new URL(url).pathname.replace(/\/$/, "");
    if (path === "/api/v1/customer/branches/jimbocho/menu") {
      menuHits += 1;
      if (menuHits <= (options.abortMenuAttempts ?? 0)) {
        return route.abort("internetdisconnected");
      }
      if (menuHits <= (options.failMenuAttempts ?? 0)) {
        return route.fulfill({ status: options.failureStatus ?? 503, json: { message: "Menu temporarily unavailable" } });
      }
      if (options.outsideHours) {
        return route.fulfill({ status: 404, json: {
          message: "Online ordering is currently outside service hours.",
          code: "menu_outside_service_hours",
          availability: { branch_name: text.branch, menu_name: text.menu, timezone: "Asia/Tokyo", next_opens_at: "2026-07-23T07:00:00+09:00", next_closes_at: "2026-07-23T22:00:00+09:00" },
        } });
      }
      return route.fulfill({ json: { data: {
        menu_id: "00000000-0000-4000-8000-000000000010", menu_name: text.menu,
        schedule_start_time: null, schedule_end_time: null, cart_timeout_minutes: 15,
        cart_deadline_iso: new Date(Date.now() + 3_600_000).toISOString(),
        categories: options.emptyMenu ? [] : [{ id: "section-main", name: text.category, items: [{
          id: "00000000-0000-4000-8000-000000000020",
          sku_id: "00000000-0000-4000-8000-000000000021",
          name: options.hostileContent ? "Café e\u0301 🍜 <script>window.__xss=1</script>" : text.product,
          description: options.hostileContent ? "<img src=x onerror=window.__xss=1> safe" : text.description, price: 1200, image: null,
          options: [{ id: "size", name: text.option, type: "single", required: true, variants: [
            { id: "regular", sku_id: "00000000-0000-4000-8000-000000000021", name: text.regular, price: 0, default: true },
            { id: "large", sku_id: "00000000-0000-4000-8000-000000000022", name: text.large, price: 300 },
          ] }],
          toppingGroups: options.modifierBoundaries ? [
            { id: "required", name: "Required sauce", min_select: 1, max_select: 1, max_qty_per_item: 1, items: [
              { id: "red", sku_id: "00000000-0000-4000-8000-000000000031", name: "Red sauce", price: 100 },
              { id: "green", sku_id: "00000000-0000-4000-8000-000000000032", name: "Green sauce", price: 200 },
            ] },
            { id: "multi", name: "Choose extras", min_select: 0, max_select: 2, max_qty_per_item: 1, items: [
              { id: "one", sku_id: "00000000-0000-4000-8000-000000000033", name: "Extra one", price: 10 },
              { id: "two", sku_id: "00000000-0000-4000-8000-000000000034", name: "Extra two", price: 20 },
              { id: "three", sku_id: "00000000-0000-4000-8000-000000000035", name: "Extra three", price: 30 },
            ] },
            { id: "stack", name: "Stackable", min_select: 0, max_select: 1, max_qty_per_item: 2, items: [
              { id: "stack-one", sku_id: "00000000-0000-4000-8000-000000000036", name: "Stack one", price: 50 },
            ] },
          ] : [{ id: "toppings", name: text.topping, min_select: 0, max_select: 2, max_qty_per_item: 1, items: [
              { id: "egg", sku_id: "00000000-0000-4000-8000-000000000023", name: text.egg, price: 100 },
            ] }],
        }] }],
      } } });
    }
    if (path === "/api/v1/customer/branches") {
      return route.fulfill({ json: { data: [{
        id: "00000000-0000-4000-8000-000000000099", slug: "hongo", code: "HONGO",
        name: requested === "ja" ? "本郷店" : requested === "vi" ? "Cửa hàng Hongo" : "Hongo Store", address: null, phone: null, img_branches: null, logo: null,
        seat_capacity: null, business_hours: null, weekly_hours: null,
        currency_code: "JPY", prices_include_tax: true, service_charge_rate: 0,
        effective_order_policy: { prep_before_payment: true, customer_email_required: false, phone_country: "JP", confirmation_timeout_minutes: 3, source: { prep_before_payment: "default", customer_email_required: "default" } },
        brand: { id: "brand-1", slug: "betoya", name: "Betoya" },
      }, {
        id: "00000000-0000-4000-8000-000000000001", slug: "jimbocho", code: "JIMBOCHO",
        name: text.branch, address: null, phone: null, img_branches: null, logo: null,
        seat_capacity: null, business_hours: null, weekly_hours: null,
        currency_code: "JPY", prices_include_tax: true, service_charge_rate: 0,
        effective_order_policy: { prep_before_payment: true, customer_email_required: false, phone_country: "JP", confirmation_timeout_minutes: 3, source: { prep_before_payment: "default", customer_email_required: "default" } },
        brand: { id: "brand-1", slug: "betoya", name: "Betoya" },
      }] } });
    }
    return route.fulfill({ json: { data: [] } });
  });
  return { seenLanguages, menuHits: () => menuHits };
}

for (const locale of ["ja", "en", "vi"] as const) {
  test(`${locale} renders localized branch, menu, category, product and detail on desktop/mobile`, async ({ page }) => {
    const { seenLanguages } = await mockCustomerApi(page, locale);
    const text = localized[locale];
    await page.goto(`/${locale}/takeaway/jimbocho`);
    await expect(page.getByText(text.branch, { exact: true }).first()).toBeVisible();
    await expect(page.getByRole("heading", { name: text.menu })).toBeVisible();
    await expect(page.getByRole("heading", { name: text.category })).toBeVisible();
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", new RegExp(`/${locale}/takeaway/jimbocho$`));
    for (const alternate of ["ja", "en", "vi", "x-default"]) {
      await expect(page.locator(`link[rel="alternate"][hreflang="${alternate}"]`)).toHaveCount(1);
    }
    await page.getByText(text.product, { exact: true }).filter({ visible: true }).click();
    const dialog = page.getByRole("dialog");
    await expect(dialog.getByRole("heading", { name: text.product })).toBeVisible();
    await expect(dialog.getByText(text.description, { exact: true })).toBeVisible();
    await expect(dialog.getByText(text.option, { exact: true })).toBeVisible();
    await expect(dialog.getByText(text.topping, { exact: true })).toBeVisible();
    expect(seenLanguages.filter(Boolean)).toEqual(expect.arrayContaining([locale]));
    if (locale === "en") {
      await expect(page.locator("body")).not.toContainText(/[\u3040-\u30ff\u3400-\u9fff]/);
      await expect(page.locator("body")).not.toContainText("Unknown");
    }
  });
}

test("customer can customize, add, reopen, edit and continue to checkout without losing localization", async ({ page }) => {
  await mockCustomerApi(page, "en");
  await page.goto("/en/takeaway/jimbocho");
  await page.getByText("Beef Pho", { exact: true }).filter({ visible: true }).click();
  await page.getByRole("button", { name: /Large/ }).click();
  await page.getByRole("button", { name: /Egg/ }).click();
  await page.getByRole("button", { name: "Add to cart" }).click();

  await page.getByText("View cart", { exact: true }).click();
  await expect(page.getByText("Beef Pho", { exact: true }).last()).toBeVisible();
  await expect(page.getByText("Size: Large", { exact: true })).toBeVisible();
  await expect(page.getByText("Egg", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Edit", exact: true }).click();
  await page.getByRole("button", { name: /Regular/ }).click();
  await page.getByRole("button", { name: "Update item" }).click();
  await expect(page.getByText("Size: Regular", { exact: true })).toBeVisible();
  await expect(page.getByText("Size: Large", { exact: true })).toHaveCount(0);
  await page.getByRole("button", { name: "Confirm order" }).click();
  await expect(page).toHaveURL(/\/en\/checkout$/);
  await expect(page.locator("body")).not.toContainText(/[\u3040-\u30ff\u3400-\u9fff]/);
});

test("cart survives refresh and supports accessible remove plus truthful empty recovery", async ({ page }) => {
  await mockCustomerApi(page, "en");
  await page.goto("/en/takeaway/jimbocho");
  await page.getByText("Beef Pho", { exact: true }).filter({ visible: true }).click();
  await page.getByRole("button", { name: "Add to cart" }).click();
  await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem("betoya-cart-takeaway") ?? "[]").length)).toBe(1);

  await page.reload();
  await expect(page).toHaveURL(/\/en\/takeaway\/jimbocho$/);
  await page.getByText("View cart", { exact: true }).click();
  await expect(page.getByText("Beef Pho", { exact: true }).last()).toBeVisible();
  await page.getByRole("button", { name: "Remove item?", exact: true }).click();
  const confirmation = page.getByRole("dialog", { name: "Remove item?" });
  await expect(confirmation).toContainText("Do you want to remove this item from the cart?");
  await confirmation.getByRole("button", { name: "Remove", exact: true }).click();
  await expect(page.getByText("Cart is empty", { exact: true })).toBeVisible();
  await expect(page.getByText("View cart", { exact: true })).toHaveCount(0);
});

test("customer sees a localized cold-start failure and can retry to a complete menu", async ({ page }) => {
  // The nested layout performs one server-side menu fetch for metadata before
  // the client fetch. Fail both so this exercises the rendered cold-start UI.
  const api = await mockCustomerApi(page, "en", { failMenuAttempts: 2 });
  await page.goto("/en/takeaway/jimbocho");
  await expect(page.getByText("Failed to load menu. Please try again.", { exact: true })).toBeVisible();
  await expect(page.getByText("Beef Pho", { exact: true })).toHaveCount(0);
  await page.getByRole("button", { name: "Retry", exact: true }).click();
  await expect(page.getByRole("heading", { name: "Jimbocho Store Menu" })).toBeVisible();
  await expect(page.locator("h3:visible", { hasText: "Beef Pho" }).first()).toBeVisible();
  expect(api.menuHits()).toBe(3);
});

test("customer sees a truthful localized empty-menu state without broken product controls", async ({ page }) => {
  await mockCustomerApi(page, "en", { emptyMenu: true });
  await page.goto("/en/takeaway/jimbocho");
  await expect(page.getByText("No dishes found", { exact: true })).toBeVisible();
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(page.getByText("View cart", { exact: true })).toHaveCount(0);
  await expect(page.locator("body")).not.toContainText("Unknown");
});

test("unknown shop slug renders the localized not-found recovery state", async ({ page }) => {
  await mockCustomerApi(page, "en");
  await page.goto("/en/takeaway/not-a-real-shop");
  await expect(page.getByText("Store not found", { exact: true })).toBeVisible();
  await expect(page.getByText(/not-a-real-shop/)).toBeVisible();
  await expect(page.getByRole("link", { name: "Back" })).toHaveAttribute("href", "/en/select-branch");
});

test("required, exact-one, max-select and stack quantity boundaries are enforced with localized feedback", async ({ page }) => {
  await mockCustomerApi(page, "en", { modifierBoundaries: true });
  await page.goto("/en/takeaway/jimbocho");
  await page.getByText("Beef Pho", { exact: true }).filter({ visible: true }).click();
  const dialog = page.getByRole("dialog");
  const add = dialog.getByRole("button", { name: "Add to cart", exact: true });
  await expect(add).toBeDisabled();
  await expect(dialog.getByText("Please choose: Required sauce", { exact: true })).toBeVisible();

  await dialog.getByRole("button", { name: /Red sauce/ }).click();
  await expect(add).toBeEnabled();
  await dialog.getByRole("button", { name: /Green sauce/ }).click();
  await expect(dialog.getByRole("button", { name: /Red sauce/ })).toHaveAttribute("aria-pressed", "false");
  await expect(dialog.getByRole("button", { name: /Green sauce/ })).toHaveAttribute("aria-pressed", "true");

  await dialog.getByRole("button", { name: /Extra one/ }).click();
  await dialog.getByRole("button", { name: /Extra two/ }).click();
  await dialog.getByRole("button", { name: /Extra three/ }).click();
  await expect(dialog.getByRole("button", { name: /Extra one/ })).toHaveAttribute("aria-pressed", "true");
  await expect(dialog.getByRole("button", { name: /Extra two/ })).toHaveAttribute("aria-pressed", "true");
  await expect(dialog.getByRole("button", { name: /Extra three/ })).toHaveAttribute("aria-pressed", "false");

  const stackAdd = dialog.getByRole("button", { name: "Increase quantity: Stack one" });
  await stackAdd.click();
  await stackAdd.click();
  await stackAdd.click();
  await expect(dialog.getByText("2", { exact: true })).toBeVisible();
  await add.click();
  await page.getByText("View cart", { exact: true }).click();
  await expect(page.getByText("Required sauce: Green sauce", { exact: true })).toBeVisible();
  await expect(page.getByText("Extra three", { exact: true })).toHaveCount(0);
});

test("combining Unicode and script-like catalog values render as inert text", async ({ page }) => {
  await mockCustomerApi(page, "en", { hostileContent: true });
  await page.goto("/en/takeaway/jimbocho");
  const hostileProduct = page.getByText("Café e\u0301 🍜 <script>window.__xss=1</script>", { exact: true }).filter({ visible: true }).first();
  await expect(hostileProduct).toBeVisible();
  await expect(page.locator("script", { hasText: "window.__xss=1" })).toHaveCount(0);
  expect(await page.evaluate(() => (window as typeof window & { __xss?: number }).__xss)).toBeUndefined();
  await hostileProduct.click();
  await expect(page.getByRole("dialog")).toContainText("<img src=x onerror=window.__xss=1> safe");
  expect(await page.evaluate(() => (window as typeof window & { __xss?: number }).__xss)).toBeUndefined();
});

test("locale switch refetches menu and preserves identifiers, quantity and cart localization", async ({ page }) => {
  const api = await mockCustomerApi(page, "en");
  await page.goto("/en/takeaway/jimbocho");
  await page.getByText("Beef Pho", { exact: true }).filter({ visible: true }).click();
  await page.getByRole("button", { name: "Increase quantity", exact: true }).click();
  await page.getByRole("button", { name: "Add to cart", exact: true }).click();
  await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem("betoya-cart-takeaway") ?? "[]").length)).toBe(1);
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
  await expect(page.getByRole("heading", { name: "Menu cửa hàng Jimbocho" })).toBeVisible();
  await page.getByText("Xem giỏ hàng", { exact: true }).click();
  await expect(page.getByText("Phở bò", { exact: true }).last()).toBeVisible();
  const after = await page.evaluate(() => JSON.parse(localStorage.getItem("betoya-cart-takeaway") ?? "[]")[0]);
  expect(after.id).toBe(before.id);
  expect(after.product.sku_id).toBe(before.product.sku_id);
  expect(after.quantity).toBe(2);
  expect(api.seenLanguages).toEqual(expect.arrayContaining(["en", "vi"]));
});

test("modal focus is trapped, Escape returns focus, html lang is correct and 320px has no overflow", async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 700 });
  await mockCustomerApi(page, "en");
  await page.goto("/en/takeaway/jimbocho");
  await expect(page.locator("html")).toHaveAttribute("lang", "en");
  const product = page.locator('[data-menu-item][aria-label="Beef Pho"]:visible').first();
  await product.click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole("button", { name: "Close" })).toBeFocused();
  await page.keyboard.press("Shift+Tab");
  await expect(dialog.locator(":focus")).toHaveCount(1);
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(product).toBeFocused();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);

  await page.getByRole("button", { name: "Open menu" }).click();
  await page.getByRole("button", { name: "Language" }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByRole("menu", { name: "Language" })).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(page.getByRole("button", { name: "Language" })).toBeFocused();
});

for (const scenario of [
  { name: "422", options: { failMenuAttempts: 2, failureStatus: 422 } },
  { name: "offline", options: { abortMenuAttempts: 2 } },
] as const) {
  test(`${scenario.name} cold start is distinguishable and retry recovers without stale content`, async ({ page }) => {
    await mockCustomerApi(page, "en", scenario.options);
    await page.goto("/en/takeaway/jimbocho");
    await expect(page.getByText("Failed to load menu. Please try again.", { exact: true })).toBeVisible();
    await expect(page.getByText("Beef Pho", { exact: true })).toHaveCount(0);
    await page.getByRole("button", { name: "Retry", exact: true }).click();
    await expect(page.getByRole("heading", { name: "Jimbocho Store Menu" })).toBeVisible();
  });
}

test("inactive or expired menu renders the localized service-hours recovery", async ({ page }) => {
  await mockCustomerApi(page, "en", { outsideHours: true });
  await page.goto("/en/takeaway/jimbocho");
  await expect(page.getByRole("heading", { name: "Online ordering is currently closed" })).toBeVisible();
  await expect(page.getByText("Asia/Tokyo", { exact: true })).toBeVisible();
  await expect(page.getByRole("link", { name: "Choose another store" })).toHaveAttribute("href", "/en/select-branch?next=takeaway");
  await expect(page.getByText("View cart", { exact: true })).toHaveCount(0);
});

test("back, forward and direct takeaway deep links preserve locale, shop identity and cart", async ({ page }) => {
  await mockCustomerApi(page, "en");
  await page.goto("/en/takeaway/jimbocho");
  await page.getByText("Beef Pho", { exact: true }).filter({ visible: true }).click();
  await page.getByRole("button", { name: "Add to cart", exact: true }).click();
  await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem("betoya-cart-takeaway") ?? "[]").length)).toBe(1);
  await page.goto("/en/select-branch?next=takeaway");
  await page.goBack();
  await expect(page).toHaveURL(/\/en\/takeaway\/jimbocho$/);
  await expect(page.getByText("View cart", { exact: true })).toBeVisible();
  await page.goForward();
  await expect(page).toHaveURL(/\/en\/select-branch\?next=takeaway$/);
  await page.goBack();
  await expect(page.getByRole("heading", { name: "Jimbocho Store Menu" })).toBeVisible();
  await expect(page.locator("body")).not.toContainText("Unknown");
});

test("branch switch with a populated cart requires confirmation and clears cross-branch items", async ({ page }) => {
  await mockCustomerApi(page, "en");
  await page.goto("/en/takeaway/jimbocho");
  await page.getByText("Beef Pho", { exact: true }).filter({ visible: true }).click();
  await page.getByRole("button", { name: "Add to cart", exact: true }).click();
  await expect(page.getByText("View cart", { exact: true })).toBeVisible();

  await page.goto("/en/select-branch?next=takeaway");
  await page.getByRole("button", { name: /Hongo Store/ }).click();
  const confirmation = page.getByRole("dialog", { name: "Switch branch?" });
  await expect(confirmation).toContainText("You have 1 items in your cart. Switching branch will clear your cart.");
  await confirmation.getByRole("button", { name: "Cancel" }).click();
  expect(await page.evaluate(() => JSON.parse(localStorage.getItem("betoya-cart-takeaway") ?? "[]").length)).toBe(1);

  await page.getByRole("button", { name: /Hongo Store/ }).click();
  await page.getByRole("dialog", { name: "Switch branch?" }).getByRole("button", { name: "Confirm switch" }).click();
  await expect.poll(() => page.evaluate(() => JSON.parse(localStorage.getItem("betoya-cart-takeaway") ?? "[]").length)).toBe(0);
  await expect(page).toHaveURL(/\/en\/takeaway\/hongo$/);
});
