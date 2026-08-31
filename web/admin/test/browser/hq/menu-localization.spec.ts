import { expect, test, type Locator, type Page, type Route } from "@playwright/test";
import { installEchoStub } from "../../fixtures/echo";
import { defaultNotificationHandlers, mockApi, type RouteHandler } from "../../fixtures/msw";
import { signInAs } from "../../fixtures/session";

const BRAND = "playwright-brand";
const MENU_ID = "00000000-0000-4000-8000-000000000101";
const SECTION_ID = "00000000-0000-4000-8000-000000000201";
const BRANCH_ID = "00000000-0000-4000-8000-000000000301";

function menu(overrides: Record<string, unknown> = {}) {
  return {
    id: MENU_ID,
    organization_id: "00000000-0000-0000-0000-000000000001",
    brand_id: "brand-1",
    branch_id: null,
    name: "Lunch Menu",
    description: "Lunch description",
    translations: {
      ja: { name: "ランチメニュー", description: "ランチの説明" },
      en: { name: "Lunch Menu", description: "Lunch description" },
      vi: { name: "Thực đơn trưa", description: "Mô tả bữa trưa" },
    },
    status: "Draft",
    priority: 10,
    valid_from: null,
    valid_to: null,
    is_master: true,
    master_menu_id: null,
    master_menu: null,
    last_synced_at: null,
    menu_products_count: 0,
    cloned_menus_count: 0,
    menu_products: [],
    menuSections: [],
    has_schedules: false,
    cart_timeout_minutes: null,
    hq_brand_timeout_minutes: null,
    created_by_id: "test-user-1",
    approved_by_id: null,
    approved_at: null,
    rejected_by_id: null,
    rejected_at: null,
    rejection_reason: null,
    created_at: "2026-07-22T00:00:00.000Z",
    updated_at: "2026-07-22T00:00:00.000Z",
    deleted_at: null,
    ...overrides,
  };
}

async function fillTranslated(field: Locator, values: { ja: string; en: string; vi: string }) {
  for (const locale of ["ja", "en", "vi"] as const) {
    await field.getByRole("button", { name: locale.toUpperCase(), exact: true }).click();
    await field.locator("input[data-translatable], textarea[data-translatable]").fill(values[locale]);
  }
}

function field(dialog: Locator, label: string) {
  return dialog.locator("label").filter({ hasText: label }).locator("..");
}

async function setup(page: Page, handlers: RouteHandler[]) {
  await installEchoStub(page);
  await signInAs(page, { role: "brand_admin", locale: "en" });
  await mockApi(page, [
    ...handlers,
    ...defaultNotificationHandlers,
    {
      path: "**/api/v1/me/context",
      json: {
        user: { id: "test-user-1" },
        context: { brand: { id: "brand-1", slug: BRAND }, branch: null },
      },
    },
    {
      path: `**/api/v1/hq/${BRAND}`,
      json: { data: { id: "brand-1", slug: BRAND, name: "Playwright Brand" } },
    },
    {
      path: "**/api/v1/me/brands",
      json: { data: [{ id: "brand-1", slug: BRAND, name: "Playwright Brand" }] },
    },
  ]);
}

test("master menu create and edit submits all locales and reloads the rendered values", async ({ page }) => {
  let saved = menu();
  let createPayload: Record<string, unknown> | undefined;
  let updatePayload: Record<string, unknown> | undefined;
  const list = () => ({
    data: [saved],
    meta: { current_page: 1, last_page: 1, total: 1, per_page: 25, from: 1, to: 1 },
  });

  await setup(page, [
    { path: `**/api/v1/hq/${BRAND}/shops**`, json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET", json: list },
    {
      path: `**/api/v1/hq/${BRAND}/master-menus`, method: "POST",
      json: (route: Route) => {
        const payload = route.request().postDataJSON() as Record<string, unknown>;
        createPayload = payload;
        saved = menu({
          name: (payload.en as { name: string }).name,
          description: (payload.en as { description: string }).description,
          translations: { ja: payload.ja, en: payload.en, vi: payload.vi },
        });
        return { data: saved };
      }, status: 201,
    },
    {
      path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}`, method: "PUT",
      json: (route: Route) => {
        const payload = route.request().postDataJSON() as Record<string, unknown>;
        updatePayload = payload;
        saved = menu({
          name: (payload.en as { name: string }).name,
          description: (payload.en as { description: string }).description,
          translations: { ja: payload.ja, en: payload.en, vi: payload.vi },
        });
        return { data: saved };
      },
    },
  ]);

  await page.goto(`/hq/${BRAND}/menus`);
  await page.getByRole("button", { name: "New Master" }).click();
  let dialog = page.locator('[data-slot="master-menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), { ja: "朝食", en: "Breakfast", vi: "Bữa sáng" });
  await fillTranslated(field(dialog, "Description"), { ja: "朝の説明", en: "Morning menu", vi: "Menu buổi sáng" });
  await dialog.getByRole("button", { name: "Create", exact: true }).click();

  await expect.poll(() => createPayload).toBeTruthy();
  expect(createPayload).toMatchObject({
    name: "朝食", description: "朝の説明",
    ja: { name: "朝食", description: "朝の説明" },
    en: { name: "Breakfast", description: "Morning menu" },
    vi: { name: "Bữa sáng", description: "Menu buổi sáng" },
  });
  await expect(page.getByText("Breakfast", { exact: true })).toBeVisible();

  const row = page.getByRole("row").filter({ hasText: "Breakfast" });
  await row.getByRole("button").last().click();
  await page.getByRole("menuitem", { name: "Edit" }).click();
  dialog = page.locator('[data-slot="master-menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), { ja: "夕食", en: "Dinner", vi: "Bữa tối" });
  await fillTranslated(field(dialog, "Description"), { ja: "夜の説明", en: "Evening menu", vi: "Menu buổi tối" });
  await dialog.getByRole("button", { name: "Save", exact: true }).click();

  await expect.poll(() => updatePayload).toBeTruthy();
  expect(updatePayload).toMatchObject({
    ja: { name: "夕食", description: "夜の説明" },
    en: { name: "Dinner", description: "Evening menu" },
    vi: { name: "Bữa tối", description: "Menu buổi tối" },
  });
  await page.reload();
  await expect(page.getByText("Dinner", { exact: true })).toBeVisible();
  await expect(page.getByText("Breakfast", { exact: true })).toHaveCount(0);
});

test("branch menu selects a shop, creates and edits all locales, then survives reload", async ({ page }) => {
  let saved = menu({
    branch_id: BRANCH_ID,
    is_master: false,
    name: "Lunch Menu",
  });
  let createPayload: Record<string, unknown> | undefined;
  let updatePayload: Record<string, unknown> | undefined;
  const list = () => ({
    data: [saved],
    meta: { current_page: 1, last_page: 1, total: 1, per_page: 25, from: 1, to: 1 },
  });

  await setup(page, [
    {
      path: "**/api/v1/me/shops**",
      json: {
        data: [{
          id: BRANCH_ID,
          name: "Jimbocho",
          slug: "jimbocho",
          is_active: true,
          deleted_at: null,
          updated_at: "2026-07-22T00:00:00.000Z",
          brand_name: "Playwright Brand",
        }],
      },
    },
    { path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET", json: list },
    {
      path: `**/api/v1/hq/${BRAND}/menus`, method: "POST",
      json: (route: Route) => {
        const payload = route.request().postDataJSON() as Record<string, unknown>;
        createPayload = payload;
        saved = menu({
          branch_id: payload.branch_id,
          is_master: false,
          name: (payload.en as { name: string }).name,
          description: (payload.en as { description: string }).description,
          translations: { ja: payload.ja, en: payload.en, vi: payload.vi },
        });
        return { data: saved };
      }, status: 201,
    },
    {
      path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}`, method: "PUT",
      json: (route: Route) => {
        const payload = route.request().postDataJSON() as Record<string, unknown>;
        updatePayload = payload;
        saved = menu({
          branch_id: BRANCH_ID,
          is_master: false,
          name: (payload.en as { name: string }).name,
          description: (payload.en as { description: string }).description,
          translations: { ja: payload.ja, en: payload.en, vi: payload.vi },
        });
        return { data: saved };
      },
    },
  ]);

  await page.goto(`/hq/${BRAND}/menus`);
  await page.getByRole("button", { name: "New", exact: true }).click();
  let dialog = page.locator('[data-slot="menu-form-dialog"]');
  await dialog.getByRole("combobox").click();
  await page.getByRole("option", { name: "Jimbocho", exact: true }).click();
  await fillTranslated(field(dialog, "Name"), {
    ja: "神保町ランチ", en: "Jimbocho Lunch", vi: "Bữa trưa Jimbocho",
  });
  await fillTranslated(field(dialog, "Description"), {
    ja: "店舗ランチ", en: "Branch lunch menu", vi: "Menu trưa chi nhánh",
  });
  await dialog.getByRole("button", { name: "Create", exact: true }).click();

  await expect.poll(() => createPayload).toBeTruthy();
  expect(createPayload).toMatchObject({
    branch_id: BRANCH_ID,
    is_master: false,
    name: "神保町ランチ",
    description: "店舗ランチ",
    ja: { name: "神保町ランチ", description: "店舗ランチ" },
    en: { name: "Jimbocho Lunch", description: "Branch lunch menu" },
    vi: { name: "Bữa trưa Jimbocho", description: "Menu trưa chi nhánh" },
  });
  await expect(page.getByText("Jimbocho Lunch", { exact: true })).toBeVisible();

  const row = page.getByRole("row").filter({ hasText: "Jimbocho Lunch" });
  await row.getByRole("button").last().click();
  await page.getByRole("menuitem", { name: "Edit" }).click();
  dialog = page.locator('[data-slot="menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), {
    ja: "神保町ディナー", en: "Jimbocho Dinner", vi: "Bữa tối Jimbocho",
  });
  await fillTranslated(field(dialog, "Description"), {
    ja: "店舗ディナー", en: "Branch dinner menu", vi: "Menu tối chi nhánh",
  });
  await dialog.getByRole("button", { name: "Save", exact: true }).click();

  await expect.poll(() => updatePayload).toBeTruthy();
  expect(updatePayload).toMatchObject({
    name: "神保町ディナー",
    description: "店舗ディナー",
    valid_from: null,
    valid_to: null,
    ja: { name: "神保町ディナー", description: "店舗ディナー" },
    en: { name: "Jimbocho Dinner", description: "Branch dinner menu" },
    vi: { name: "Bữa tối Jimbocho", description: "Menu tối chi nhánh" },
  });
  await page.reload();
  await expect(page.getByText("Jimbocho Dinner", { exact: true })).toBeVisible();
  await expect(page.getByText("Jimbocho Lunch", { exact: true })).toHaveCount(0);
});

test("menu section edit saves ja en vi after layout submit and survives reload", async ({ page }) => {
  let sectionPayload: Record<string, unknown> | undefined;
  let section = {
    id: SECTION_ID, name: "Starters",
    translations: { ja: { name: "前菜" }, en: { name: "Starters" }, vi: { name: "Khai vị" } },
  };
  const detail = () => ({ data: menu({ menuSections: [section] }) });

  await setup(page, [
    { path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}`, method: "GET", json: detail },
    { path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}/schedules**`, json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/products**`, json: { data: [], meta: { total: 0 } } },
    { path: `**/api/v1/hq/${BRAND}/categories**`, json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/product-types**`, json: { data: [] } },
    {
      path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}/layout`, method: "PUT",
      json: { data: menu({ menuSections: [section] }) },
    },
    {
      path: `**/api/v1/hq/${BRAND}/menu-sections/${SECTION_ID}`, method: "PUT",
      json: (route: Route) => {
        const payload = route.request().postDataJSON() as Record<string, unknown>;
        sectionPayload = payload;
        section = {
          id: SECTION_ID,
          name: (payload.en as { name: string }).name,
          translations: { ja: payload.ja, en: payload.en, vi: payload.vi } as typeof section.translations,
        };
        return { data: section };
      },
    },
    { path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}/sections`, method: "PUT", json: { data: null } },
  ]);

  await page.goto(`/hq/${BRAND}/menus/${MENU_ID}/items`);
  const heading = page.getByText("Starters", { exact: true });
  await expect(heading).toBeVisible();
  const sectionHeader = heading.locator("xpath=../..");
  await sectionHeader.getByRole("button").nth(1).click();
  const editor = page.locator("input[data-translatable]").locator("..");
  await fillTranslated(editor, { ja: "主菜", en: "Main dishes", vi: "Món chính" });
  await editor.locator("xpath=..").getByRole("button").filter({ has: page.locator("svg") }).first().click();
  await page.getByRole("button", { name: "Save", exact: true }).click();

  await expect.poll(() => sectionPayload).toBeTruthy();
  expect(sectionPayload).toMatchObject({
    name: "主菜", ja: { name: "主菜" }, en: { name: "Main dishes" }, vi: { name: "Món chính" },
  });
  await page.reload();
  await expect(page.getByText("Main dishes", { exact: true })).toBeVisible();
  await expect(page.getByText("Starters", { exact: true })).toHaveCount(0);
});

test("dirty localized menu form guards every cancel path and preserves values until discard", async ({ page }) => {
  await setup(page, [
    { path: "**/api/v1/me/shops**", json: { data: [] } },
    {
      path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET",
      json: { data: [menu()], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } },
    },
  ]);

  await page.goto(`/hq/${BRAND}/menus`);
  await page.getByRole("button", { name: "New Master" }).click();
  const dialog = page.locator('[data-slot="master-menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), {
    ja: "未保存メニュー", en: "Unsaved Menu", vi: "Menu chưa lưu",
  });

  await dialog.getByRole("button", { name: "Cancel", exact: true }).click();
  await expect(page.getByRole("alertdialog")).toContainText("Unsaved changes");
  await page.getByRole("button", { name: "Continue editing", exact: true }).click();
  await expect(dialog).toBeVisible();
  await field(dialog, "Name").getByRole("button", { name: "EN", exact: true }).click();
  await expect(field(dialog, "Name").locator("input[data-translatable]")).toHaveValue("Unsaved Menu");

  await dialog.getByRole("button", { name: "Cancel", exact: true }).click();
  await page.getByRole("button", { name: "Exit without saving", exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(page.getByText("Unsaved Menu", { exact: true })).toHaveCount(0);
});

test("localized validation remains recoverable without false success", async ({ page }) => {
  let attempts = 0;
  await setup(page, [
    { path: "**/api/v1/me/shops**", json: { data: [] } },
    {
      path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET",
      json: { data: [menu()], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } },
    },
    {
      path: `**/api/v1/hq/${BRAND}/master-menus`, method: "POST", status: 422,
      json: (route: Route) => {
        attempts += 1;
        expect(route.request().postDataJSON()).toMatchObject({ en: { name: "Invalid English" } });
        return { message: "The given data was invalid.", errors: { "en.name": ["English name is already used."] } };
      },
    },
  ]);

  await page.goto(`/hq/${BRAND}/menus`);
  await page.getByRole("button", { name: "New Master" }).click();
  const dialog = page.locator('[data-slot="master-menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), {
    ja: "無効な名前", en: "Invalid English", vi: "Tên không hợp lệ",
  });
  await dialog.getByRole("button", { name: "Create", exact: true }).click();

  await expect.poll(() => attempts).toBe(1);
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText("English name is already used.", { exact: true })).toBeVisible();
  await expect(dialog.locator('input[data-translatable]:focus')).toHaveCount(1);
  await expect(page.getByText("Menu created.", { exact: true })).toHaveCount(0);
  await field(dialog, "Name").getByRole("button", { name: "EN", exact: true }).click();
  await expect(field(dialog, "Name").locator("input[data-translatable]")).toHaveValue("Invalid English");
});

test("server failure keeps localized form state and exposes a retryable error", async ({ page }) => {
  await setup(page, [
    { path: "**/api/v1/me/shops**", json: { data: [] } },
    {
      path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET",
      json: { data: [menu()], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } },
    },
    {
      path: `**/api/v1/hq/${BRAND}/master-menus`, method: "POST", status: 503,
      json: { message: "Menu service temporarily unavailable." },
    },
  ]);

  await page.goto(`/hq/${BRAND}/menus`);
  await page.getByRole("button", { name: "New Master" }).click();
  const dialog = page.locator('[data-slot="master-menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), {
    ja: "再試行メニュー", en: "Retry Menu", vi: "Menu thử lại",
  });
  await dialog.getByRole("button", { name: "Create", exact: true }).click();

  await expect(page.getByText("Menu service temporarily unavailable.", { exact: true })).toBeVisible();
  await expect(dialog).toBeVisible();
  await field(dialog, "Name").getByRole("button", { name: "EN", exact: true }).click();
  await expect(field(dialog, "Name").locator("input[data-translatable]")).toHaveValue("Retry Menu");
  await expect(page.getByText("Menu created.", { exact: true })).toHaveCount(0);
});

test("stale edit conflict keeps inputs and sends the optimistic concurrency token", async ({ page }) => {
  let updatePayload: Record<string, unknown> | undefined;
  await setup(page, [
    { path: "**/api/v1/me/shops**", json: { data: [] } },
    {
      path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET",
      json: { data: [menu()], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } },
    },
    {
      path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}`, method: "PUT", status: 409,
      json: (route: Route) => {
        updatePayload = route.request().postDataJSON() as Record<string, unknown>;
        return { message: "This menu was changed by another user. Reload and try again." };
      },
    },
  ]);

  await page.goto(`/hq/${BRAND}/menus`);
  const row = page.getByRole("row").filter({ hasText: "Lunch Menu" });
  await row.getByRole("button").last().click();
  await page.getByRole("menuitem", { name: "Edit" }).click();
  const dialog = page.locator('[data-slot="master-menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), {
    ja: "競合メニュー", en: "Conflicting Menu", vi: "Menu xung đột",
  });
  await dialog.getByRole("button", { name: "Save", exact: true }).click();

  await expect.poll(() => updatePayload?.updated_at).toBe("2026-07-22T00:00:00.000Z");
  await expect(page.getByText("This menu was changed by another user. Reload and try again.", { exact: true })).toBeVisible();
  await expect(dialog).toBeVisible();
  await field(dialog, "Name").getByRole("button", { name: "EN", exact: true }).click();
  await expect(field(dialog, "Name").locator("input[data-translatable]")).toHaveValue("Conflicting Menu");
  await expect(page.getByText("Menu updated.", { exact: true })).toHaveCount(0);
});

test("branch menu cannot submit without an available shop", async ({ page }) => {
  await setup(page, [
    { path: "**/api/v1/me/shops**", json: { data: [] } },
    {
      path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET",
      json: { data: [menu()], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } },
    },
  ]);

  await page.goto(`/hq/${BRAND}/menus`);
  await page.getByRole("button", { name: "New", exact: true }).click();
  const dialog = page.locator('[data-slot="menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), {
    ja: "店舗メニュー", en: "Branch Menu", vi: "Menu chi nhánh",
  });
  await expect(dialog.getByRole("button", { name: "Create", exact: true })).toBeDisabled();
  await dialog.getByRole("combobox").click();
  await expect(page.getByRole("option")).toHaveCount(1);
  await expect(page.getByRole("option")).toContainText("Select a branch");
});

test("all-empty and partially empty optional descriptions follow the documented fallback contract", async ({ page }) => {
  let payload: Record<string, unknown> | undefined;
  await setup(page, [
    { path: "**/api/v1/me/shops**", json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET", json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } } },
    {
      path: `**/api/v1/hq/${BRAND}/master-menus`, method: "POST", status: 201,
      json: (route: Route) => {
        payload = route.request().postDataJSON() as Record<string, unknown>;
        return { data: menu({ name: "No description", description: null, translations: { ja: { name: "説明なし", description: null }, en: { name: "No description", description: null }, vi: { name: "Không mô tả", description: null } } }) };
      },
    },
  ]);
  await page.goto(`/hq/${BRAND}/menus`);
  await page.getByRole("button", { name: "New Master" }).click();
  const dialog = page.locator('[data-slot="master-menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), { ja: "説明なし", en: "No description", vi: "Không mô tả" });
  await dialog.getByRole("button", { name: "Create", exact: true }).click();
  await expect.poll(() => payload).toBeTruthy();
  expect(payload).toMatchObject({ description: null });
  expect((payload?.ja as { description: unknown }).description).toBe("");
  expect((payload?.en as { description: unknown }).description).toBe("");
  expect((payload?.vi as { description: unknown }).description).toBe("");

  payload = undefined;
  await page.getByRole("button", { name: "New Master" }).click();
  await fillTranslated(field(dialog, "Name"), { ja: "一部説明", en: "Partial description", vi: "Mô tả một phần" });
  const description = field(dialog, "Description");
  await description.getByRole("button", { name: "EN", exact: true }).click();
  await description.locator("textarea[data-translatable]").fill("English source only");
  await dialog.getByRole("button", { name: "Create", exact: true }).click();
  await expect.poll(() => payload).toBeTruthy();
  expect(payload).toMatchObject({
    description: "English source only",
    ja: { description: "English source only" },
    en: { description: "English source only" },
    vi: { description: "English source only" },
  });
});

for (const failure of [
  { status: 403, message: "You do not have permission to update menus." },
  { status: 404, message: "Menu no longer exists." },
] as const) {
  test(`${failure.status} save failure retains localized input and never reports success`, async ({ page }) => {
    await setup(page, [
      { path: "**/api/v1/me/shops**", json: { data: [] } },
      { path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET", json: { data: [menu()], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } } },
      { path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}`, method: "PUT", status: failure.status, json: { message: failure.message } },
    ]);
    await page.goto(`/hq/${BRAND}/menus`);
    const row = page.getByRole("row").filter({ hasText: "Lunch Menu" });
    await row.getByRole("button").last().click();
    await page.getByRole("menuitem", { name: "Edit" }).click();
    const dialog = page.locator('[data-slot="master-menu-form-dialog"]');
    await fillTranslated(field(dialog, "Name"), { ja: "保持", en: `Retained ${failure.status}`, vi: "Được giữ" });
    await dialog.getByRole("button", { name: "Save", exact: true }).click();
    await expect(page.getByText(failure.message, { exact: true })).toBeVisible();
    await expect(dialog).toBeVisible();
    await field(dialog, "Name").getByRole("button", { name: "EN", exact: true }).click();
    await expect(field(dialog, "Name").locator("input[data-translatable]")).toHaveValue(`Retained ${failure.status}`);
    await expect(page.getByText("Menu updated.", { exact: true })).toHaveCount(0);
  });
}

test("pending create locks the button and double click emits exactly one mutation", async ({ page }) => {
  let attempts = 0;
  await setup(page, [
    { path: "**/api/v1/me/shops**", json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET", json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } } },
    {
      path: `**/api/v1/hq/${BRAND}/master-menus`, method: "POST", status: 201,
      json: async () => {
        attempts += 1;
        await new Promise((resolve) => setTimeout(resolve, 2_000));
        return { data: menu({ name: "Single Create" }) };
      },
    },
  ]);
  await page.goto(`/hq/${BRAND}/menus`);
  await page.getByRole("button", { name: "New Master" }).click();
  const dialog = page.locator('[data-slot="master-menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), { ja: "一回", en: "Single Create", vi: "Tạo một lần" });
  const create = dialog.locator('[data-slot="dialog-footer"] button').last();
  await expect(create).toHaveAccessibleName("Create");
  // DOM click returns immediately, allowing the in-flight state to be
  // observed before the delayed mocked response resolves.
  await create.evaluate((button: HTMLButtonElement) => button.click());
  await expect(create).toBeDisabled();
  await create.evaluate((button: HTMLButtonElement) => button.click());
  await expect.poll(() => attempts).toBe(1);
  await expect(dialog).toBeHidden();
});

test("menu list item and items/back route graph resolve with the same brand and menu id", async ({ page }) => {
  await setup(page, [
    { path: "**/api/v1/me/shops**", json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}`, method: "GET", json: { data: menu() } },
    { path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}/schedules**`, json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET", json: { data: [menu()], meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 } } },
    { path: `**/api/v1/hq/${BRAND}/products**`, json: { data: [], meta: { total: 0 } } },
    { path: `**/api/v1/hq/${BRAND}/categories**`, json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/product-types**`, json: { data: [] } },
  ]);
  await page.goto(`/hq/${BRAND}/menus`);
  const menuLink = page.getByRole("link", { name: "Lunch Menu", exact: true });
  await expect(menuLink).toHaveAttribute("href", `/hq/${BRAND}/menus/${MENU_ID}/items`);
  await menuLink.click();
  await expect(page).toHaveURL(new RegExp(`/hq/${BRAND}/menus/${MENU_ID}/items$`));
  await expect(page.getByText("Lunch Menu", { exact: true }).first()).toBeVisible();
  await page.goBack();
  await expect(page).toHaveURL(new RegExp(`/hq/${BRAND}/menus$`));
});

test("network abort during save retains the dialog and localized values for a safe retry", async ({ page }) => {
  await setup(page, [
    { path: "**/api/v1/me/shops**", json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/menus**`, method: "GET", json: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } } },
  ]);
  await page.route(`**/api/v1/hq/${BRAND}/master-menus`, async (route) => {
    if (route.request().method() === "POST") return route.abort("timedout");
    return route.fallback();
  });
  await page.goto(`/hq/${BRAND}/menus`);
  await page.getByRole("button", { name: "New Master" }).click();
  const dialog = page.locator('[data-slot="master-menu-form-dialog"]');
  await fillTranslated(field(dialog, "Name"), { ja: "通信失敗", en: "Timeout retained", vi: "Giữ khi timeout" });
  await dialog.getByRole("button", { name: "Create", exact: true }).click();
  await expect(dialog).toBeVisible();
  await field(dialog, "Name").getByRole("button", { name: "EN", exact: true }).click();
  await expect(field(dialog, "Name").locator("input[data-translatable]")).toHaveValue("Timeout retained");
  await expect(page.getByText("Menu created.", { exact: true })).toHaveCount(0);
});

test("partial section save failure is visible and retry converges without false success", async ({ page }) => {
  let layoutAttempts = 0;
  let sectionAttempts = 0;
  let section = {
    id: SECTION_ID,
    name: "Starters",
    translations: { ja: { name: "前菜" }, en: { name: "Starters" }, vi: { name: "Khai vị" } },
  };
  const detail = () => ({ data: menu({ menuSections: [section] }) });
  await setup(page, [
    { path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}`, method: "GET", json: detail },
    { path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}/schedules**`, json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/products**`, json: { data: [], meta: { total: 0 } } },
    { path: `**/api/v1/hq/${BRAND}/categories**`, json: { data: [] } },
    { path: `**/api/v1/hq/${BRAND}/product-types**`, json: { data: [] } },
    {
      path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}/layout`, method: "PUT",
      json: () => { layoutAttempts += 1; return { data: menu({ menuSections: [section] }) }; },
    },
    { path: `**/api/v1/hq/${BRAND}/menus/${MENU_ID}/sections`, method: "PUT", json: { data: null } },
  ]);
  await page.route(`**/api/v1/hq/${BRAND}/menu-sections/${SECTION_ID}`, async (route) => {
    if (route.request().method() !== "PUT") return route.fallback();
    sectionAttempts += 1;
    if (sectionAttempts === 1) {
      return route.fulfill({ status: 503, json: { message: "Section translation save failed." } });
    }
    const payload = route.request().postDataJSON() as Record<string, unknown>;
    section = {
      id: SECTION_ID,
      name: (payload.en as { name: string }).name,
      translations: { ja: payload.ja, en: payload.en, vi: payload.vi } as typeof section.translations,
    };
    return route.fulfill({ status: 200, json: { data: section } });
  });

  await page.goto(`/hq/${BRAND}/menus/${MENU_ID}/items`);
  const heading = page.getByText("Starters", { exact: true });
  await heading.locator("xpath=../..").getByRole("button").nth(1).click();
  const editor = page.locator("input[data-translatable]").locator("..");
  await fillTranslated(editor, { ja: "再試行", en: "Retry section", vi: "Thử lại mục" });
  await editor.locator("xpath=..").getByRole("button").filter({ has: page.locator("svg") }).first().click();
  const save = page.getByRole("button", { name: "Save", exact: true });
  await save.click();
  await expect(page.getByText("Section translation save failed.", { exact: true })).toBeVisible();
  await expect(page.getByText("Menu updated.", { exact: true })).toHaveCount(0);
  await save.click();
  await expect.poll(() => ({ layoutAttempts, sectionAttempts })).toEqual({ layoutAttempts: 2, sectionAttempts: 2 });
  await page.reload();
  await expect(page.getByText("Retry section", { exact: true })).toBeVisible();
});

test("expired HQ session cannot render or mutate localization controls and recovers at login", async ({ page }) => {
  await setup(page, [
    { path: "**/api/v1/me", status: 401, json: { message: "Unauthenticated." } },
    { path: "**/api/v1/me/shops**", status: 401, json: { message: "Unauthenticated." } },
    { path: `**/api/v1/hq/${BRAND}/menus**`, status: 401, json: { message: "Unauthenticated." } },
  ]);

  await page.goto(`/hq/${BRAND}/menus`);
  await expect(page).toHaveURL(/\/auth\/redirect\?return=%2Fhq%2Fplaywright-brand%2Fmenus$/);
  await expect(page.getByRole("button", { name: "New Master" })).toHaveCount(0);
  await expect(page.locator('[data-slot="master-menu-form-dialog"]')).toHaveCount(0);
});
