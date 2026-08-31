/**
 * plan-056 — the "Tồn món" screen.
 *
 * What these tests are for, in order of how much they matter:
 *
 *   1. A turned-off dish is VISIBLE here. If it were not, the shop could never
 *      switch it back on, and the whole feature would be a one-way door.
 *   2. Turning something off asks for a reason and NEVER blocks on it. One tap
 *      on a preset is a complete answer; there is no minimum length anywhere.
 *   3. Turning something back on is a single tap with no dialog.
 *   4. A failed write ROLLS THE SWITCH BACK. A toast alone leaves the UI showing
 *      a state the server never accepted.
 */

import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { beforeEach, describe, expect, it, vi } from "vitest";
import type { ReactNode } from "react";

import MenuAvailabilityPage from "./page";
// The provider's default locale in tests is ja, so the preset the dialog
// sends is the JAPANESE one. Reading it from the catalog rather than pinning
// a literal keeps this test about "a chip alone is a complete answer" instead
// of about which language the suite happens to boot in.
import jaMessages from "@/i18n/ja.json";
import { menuAvailabilityService } from "@/services/menu-availability-service";
import { AppProvider } from "@/providers/app-provider";

vi.mock("@/services/menu-availability-service", () => ({
  menuAvailabilityService: {
    listMenus: vi.fn(),
    getMenu: vi.fn(),
    setProductAvailability: vi.fn(),
    setSkuAvailability: vi.fn(),
    setToppingAvailability: vi.fn(),
    bulkSetSectionAvailability: vi.fn(),
    bulkSetSkuAvailability: vi.fn(),
  },
}));

// PosHeader drags in the workstation connection badge, till polling and a
// second-ticking clock — none of which this screen's behaviour depends on.
// Stubbed the same way settings/page.test.tsx does.
vi.mock("@/app/pos/components/pos-header", () => ({
  PosHeader: () => null,
}));

// The screen reads the shop name for the header and the cashier identity from
// the open shift. Neither is under test here, and both would otherwise fire
// real requests.
// NOTE the double envelope: `useShop` returns the query, whose `data` is the
// API response `{ data: ShopDetail }`. The mock mirrors that exactly — an
// over-simplified mock here is what let a real `shop.data?.name` type error
// reach the workstation build.
vi.mock("@/hooks/api/use-shop", () => ({
  useShop: () => ({ data: { data: { name: "Ningyocho Store" } } }),
}));
vi.mock("@/hooks/api/use-till", () => ({
  useTillCurrent: () => ({
    data: { open_session: { opened_by_id: "u-1", opener_name: "Ann" } },
  }),
}));
// The screen reads the paired device only as a FALLBACK actor name (used when
// no shift is open). Stubbed rather than wrapped in a real <AuthProvider>,
// which would pull in token storage and the SSO bootstrap for a value this
// suite never asserts on.
vi.mock("@/providers/use-auth", () => ({
  useAuth: () => ({ device: { name: "Terminal 1" } }),
}));

const mocked = vi.mocked(menuAvailabilityService);

const MENU = { id: "m1", name: "Menu trưa", status: "Active" };

function makeDetail(overrides: Record<string, unknown> = {}) {
  return {
    data: {
      ...MENU,
      sections: [{ id: "sec1", name: "Món chính" }],
      products: [
        {
          id: "mp1",
          menu_id: "m1",
          product_id: "p1",
          menu_section_id: "sec1",
          display_order: 1,
          is_active: true,
          disabled_reason: null,
          disabled_at: null,
          disabled_by_name: null,
          product: { name: "Phở bò", image_url: null },
          topping_groups: [
            {
              id: "tg1",
              name: "Topping phở",
              items: [
                {
                  id: "ti1",
                  name: "Trứng chần",
                  is_active: true,
                  // A topping with its own priced variants — the layer the
                  // expand reveals. Mixed on purpose: one wildcard row (no
                  // `product_sku_id`, applies to every variant) and one keyed
                  // to a SKU, which is exactly what the 3-tier override
                  // resolution produces.
                  skus: [
                    {
                      id: "tsk1",
                      product_sku_id: null,
                      sku_label: null,
                      sku_code: null,
                      extra_price: "0",
                    },
                    {
                      id: "tsk2",
                      product_sku_id: "psk-l",
                      sku_label: "Lớn",
                      sku_code: "TOP-TC-L",
                      extra_price: "120",
                    },
                  ],
                },
                { id: "ti2", name: "Thịt thêm", is_active: false, skus: [] },
              ],
            },
          ],
          skus: [
            {
              menu_product_sku_id: "mps1",
              product_sku_id: "sk1",
              variant_label: "Nhỏ",
              options: [
                { option_id: "op-size", option_name: "Size", value_id: "v-nho", value_label: "Nhỏ", position: 1 },
              ],
              name: null,
              sku: "PHO-S",
              selling_price: 1000,
              default_price: 1000,
              is_price_overridden: false,
              is_active: true,
              disabled_reason: null,
              disabled_at: null,
              disabled_by_name: null,
            },
            {
              menu_product_sku_id: "mps1b",
              product_sku_id: "sk1b",
              options: [
                { option_id: "op-size", option_name: "Size", value_id: "v-lon", value_label: "Lớn", position: 1 },
              ],
              variant_label: "Lớn",
              name: null,
              sku: "PHO-L",
              selling_price: 1400,
              default_price: 1400,
              is_price_overridden: false,
              is_active: true,
              disabled_reason: null,
              disabled_at: null,
              disabled_by_name: null,
            },
          ],
        },
        {
          id: "mp2",
          menu_id: "m1",
          product_id: "p2",
          menu_section_id: "sec1",
          display_order: 2,
          is_active: false,
          disabled_reason: "Hết hàng",
          disabled_at: "2026-08-13T00:00:00Z",
          disabled_by_name: "Ann",
          product: { name: "Cơm gà", image_url: null },
          skus: [],
        },
      ],
      ...overrides,
    },
  };
}

function renderPage() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={qc}>
      <AppProvider>
        <MemoryRouter initialEntries={["/shop/ningyocho/menu-availability"]}>
          <Routes>
            <Route path="/shop/:shopSlug/menu-availability" element={children} />
            {/* The sales screen, as a marker. Real route rather than a spy on
                `useNavigate`: what matters is WHERE Back lands, and a spy
                would keep passing if the path were built wrong. */}
            <Route path="/shop/:shopSlug" element={<div>SALES SCREEN</div>} />
          </Routes>
        </MemoryRouter>
      </AppProvider>
    </QueryClientProvider>
  );

  return render(<MenuAvailabilityPage />, { wrapper });
}

beforeEach(() => {
  vi.clearAllMocks();
  mocked.listMenus.mockResolvedValue({ data: [MENU] });
  mocked.getMenu.mockResolvedValue(makeDetail());
  mocked.bulkSetSkuAvailability.mockResolvedValue({ updated: 1 });
  mocked.setProductAvailability.mockResolvedValue({ data: {} });
  mocked.setSkuAvailability.mockResolvedValue({ data: {} });
  mocked.setToppingAvailability.mockResolvedValue({ data: { is_active: false } });
  mocked.bulkSetSectionAvailability.mockResolvedValue({ updated: 1 });
});

describe("menu availability screen", () => {
  it("gets back to the sales screen in one tap", async () => {
    // A cashier who came here to take one dish off has an order waiting. The
    // way out has to be on screen, not behind the avatar menu they arrived
    // through — and it must land on the SALES screen specifically, which is
    // why the destination is absolute rather than `navigate(-1)`: on a tablet
    // that was just reloaded or deep-linked there is no history to step back
    // through, and `-1` would either sit still or leave pos-web entirely.
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-back"));

    expect(await screen.findByText("SALES SCREEN")).toBeInTheDocument();
  });

  it("lists a turned-off dish, with its reason and who turned it off", async () => {
    // THE reason this screen exists. A dish the shop cannot see is a dish the
    // shop can never switch back on.
    renderPage();

    expect(await screen.findByText("Cơm gà")).toBeInTheDocument();
    expect(screen.getByText(/Hết hàng/)).toBeInTheDocument();
    expect(screen.getByText(/Ann/)).toBeInTheDocument();
  });

  it("turns a dish back on with ONE tap and no dialog", async () => {
    // The delivery arrived. Asking a cashier to explain why food is available
    // would double the taps on the action they take most.
    renderPage();

    await screen.findByText("Cơm gà");
    fireEvent.click(screen.getByTestId("availability-product-switch-mp2"));

    await waitFor(() => {
      expect(mocked.setProductAvailability).toHaveBeenCalledWith("mp2", {
        is_active: true,
        actor_user_id: "u-1",
        actor_name: "Ann",
      });
    });
    expect(screen.queryByTestId("reason-confirm")).not.toBeInTheDocument();
  });

  it("asks for a reason before turning a dish off, and a single chip is enough", async () => {
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-switch-mp1"));

    // Nothing is written until the dialog is confirmed.
    expect(mocked.setProductAvailability).not.toHaveBeenCalled();

    const confirm = await screen.findByTestId("reason-confirm");
    // NEVER disabled on "the reason is not good enough" — the only thing that
    // disables it is a write already in flight.
    expect(confirm).not.toBeDisabled();

    fireEvent.click(confirm);

    await waitFor(() => {
      expect(mocked.setProductAvailability).toHaveBeenCalledWith("mp1", {
        is_active: false,
        // The default preset, sent with ZERO typing.
        reason: jaMessages["menu_availability.reason.out_of_stock"],
        actor_user_id: "u-1",
        actor_name: "Ann",
      });
    });
  });

  it("never blocks on a short or empty note", async () => {
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-switch-mp1"));

    // Pick "Khác" and leave the note blank — the worst case for a validator.
    fireEvent.click(await screen.findByTestId("reason-chip-menu_availability.reason.other"));
    const confirm = screen.getByTestId("reason-confirm");
    expect(confirm).not.toBeDisabled();

    // …and a one-character note is fine too.
    fireEvent.change(screen.getByTestId("reason-note"), { target: { value: "X" } });
    expect(confirm).not.toBeDisabled();

    fireEvent.click(confirm);
    await waitFor(() => {
      expect(mocked.setProductAvailability).toHaveBeenCalledWith(
        "mp1",
        expect.objectContaining({ is_active: false, reason: "X" }),
      );
    });
  });

  it("marks exactly one reason chosen, and resets it per opening", async () => {
    // Two chips reading as chosen is a reason nobody can predict; a chip left
    // chosen from the LAST dish is worse — it stamps "hết nguyên liệu" on
    // something that simply stopped selling, and that string is what a report
    // reads back months later.
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-switch-mp1"));

    const dialog = await screen.findByTestId("disable-reason-dialog");
    const pressed = () =>
      within(dialog)
        .getAllByRole("button", { pressed: true })
        .map((el) => el.textContent);

    expect(pressed()).toEqual([jaMessages["menu_availability.reason.out_of_stock"]]);

    fireEvent.click(screen.getByTestId("reason-chip-menu_availability.reason.other"));
    expect(pressed()).toEqual([jaMessages["menu_availability.reason.other"]]);

    // Close without confirming, then open again. The dialog is never
    // unmounted — `open` just toggles — so nothing resets it but the explicit
    // during-render adjustment this asserts.
    fireEvent.click(screen.getByText(jaMessages["common.cancel"]));
    await waitFor(() => {
      expect(screen.queryByTestId("disable-reason-dialog")).not.toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId("availability-product-switch-mp1"));

    const reopened = await screen.findByTestId("disable-reason-dialog");
    expect(
      within(reopened)
        .getAllByRole("button", { pressed: true })
        .map((el) => el.textContent),
    ).toEqual([jaMessages["menu_availability.reason.out_of_stock"]]);
  });

  it("saves the amber warning for the section-wide switch-off", async () => {
    // Turning ONE dish off is routine, reversible and done dozens of times a
    // shift. Dressing it in warning colours would spend the colour, so that by
    // the time a whole course is about to go off — the genuinely damaging
    // mis-tap — the amber says nothing.
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-switch-mp1"));

    await screen.findByTestId("disable-reason-dialog");
    expect(screen.queryByTestId("disable-reason-section-warning")).toBeNull();

    fireEvent.click(screen.getByText(jaMessages["common.cancel"]));
    await waitFor(() => {
      expect(screen.queryByTestId("disable-reason-dialog")).not.toBeInTheDocument();
    });
    fireEvent.click(screen.getByTestId("availability-bulk-off-sec1"));

    expect(
      await screen.findByTestId("disable-reason-section-warning"),
    ).toBeInTheDocument();
  });

  it("rolls the switch back when the write fails", async () => {
    // A toast alone would leave the UI claiming a state the server refused —
    // the cashier walks away believing the dish is off.
    mocked.setProductAvailability.mockRejectedValue(new Error("nope"));
    renderPage();

    await screen.findByText("Phở bò");
    const toggle = screen.getByTestId("availability-product-switch-mp1");
    expect(toggle).toBeChecked();

    fireEvent.click(toggle);
    fireEvent.click(await screen.findByTestId("reason-confirm"));

    await waitFor(() => {
      expect(screen.getByTestId("availability-product-switch-mp1")).toBeChecked();
    });
  });

  it("turns one variant off without touching the dish", async () => {
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-expand-mp1"));
    fireEvent.click(await screen.findByTestId("availability-sku-switch-sk1"));
    fireEvent.click(await screen.findByTestId("reason-confirm"));

    await waitFor(() => {
      expect(mocked.setSkuAvailability).toHaveBeenCalledWith(
        "mps1",
        expect.objectContaining({ is_active: false }),
      );
    });
    expect(mocked.setProductAvailability).not.toHaveBeenCalled();
  });

  it("keeps the variant control on a SINGLE-variant dish", async () => {
    // REGRESSION GUARD. An earlier revision hid the variant table here, on the
    // theory that the dish switch was the whole answer for a simple product.
    // It was not: a variant turned off from admin-web then left the dish
    // reading ON, selling nothing, with NO control on the POS to undo it —
    // the one-way door this screen exists to avoid.
    const detail = makeDetail();
    detail.data.products[0].skus = [
      { ...detail.data.products[0].skus[0], is_active: false },
    ];
    mocked.getMenu.mockResolvedValue(detail);
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-expand-mp1"));

    const sw = await screen.findByTestId("availability-sku-switch-sk1");
    expect(sw).not.toBeDisabled();
    expect(sw).not.toBeChecked();
  });

  it("warns when a dish is on but nothing under it can be sold", async () => {
    // The silent-no-sale state: switch reads on, cart picker offers nothing,
    // and without this line the only clue is a "0/1" in the summary.
    const detail = makeDetail();
    detail.data.products[0].skus = detail.data.products[0].skus.map((s) => ({
      ...s,
      is_active: false,
    }));
    mocked.getMenu.mockResolvedValue(detail);
    renderPage();

    expect(
      await screen.findByText(jaMessages["menu_availability.no_sellable_variant"]),
    ).toBeInTheDocument();
  });

  it("labels a variant by its option axis, never by a placeholder", async () => {
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-expand-mp1"));

    // `variant_label` is resolved server-side from the option values so LAN and
    // Cloud agree. Reading `name` alone (usually null) is what produced the
    // column of identical placeholders.
    //
    // Scoped to the variant TABLE: the option-value strip above it carries the
    // same words ("Nhỏ" / "Lớn") by design, so an unscoped query would pass on
    // the wrong control and stop testing the label resolution at all.
    const table = within(
      (await screen.findByTestId("availability-sku-sk1")).closest("table") as HTMLElement,
    );
    expect(table.getByText("Nhỏ")).toBeInTheDocument();
    expect(table.getByText("Lớn")).toBeInTheDocument();
  });

  it("disables the switch on a variant that has no write address yet", async () => {
    // HQ added the SKU after the branch cloned its menu: it IS on sale, but
    // there is no pivot row on Cloud to write a toggle back to. Disabled with a
    // tooltip beats hidden — a missing row looks like a bug.
    const detail = makeDetail();
    detail.data.products[0].skus[0].menu_product_sku_id = null;
    mocked.getMenu.mockResolvedValue(detail);
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-expand-mp1"));

    expect(await screen.findByTestId("availability-sku-switch-sk1")).toBeDisabled();
  });

  it("warns how many dishes a section-wide switch-off will touch", async () => {
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-bulk-off-sec1"));

    // Only ONE of the two dishes is currently on, so that is the number shown —
    // the section size would be a padded figure staff learn to distrust.
    const dialog = await screen.findByTestId("disable-reason-dialog");
    expect(within(dialog).getByText(/1/)).toBeInTheDocument();

    fireEvent.click(screen.getByTestId("reason-confirm"));
    await waitFor(() => {
      expect(mocked.bulkSetSectionAvailability).toHaveBeenCalledWith(
        "m1",
        "sec1",
        expect.objectContaining({ is_active: false }),
      );
    });
  });

  it("filters to what is off", async () => {
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-only-off"));

    await waitFor(() => {
      expect(screen.queryByText("Phở bò")).not.toBeInTheDocument();
    });
    expect(screen.getByText("Cơm gà")).toBeInTheDocument();
  });

  it("finds a dish by its SKU code, not just its name", async () => {
    // A cashier holding a package types the barcode. Mirrors what the ordering
    // search does, so the two screens find the same things.
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.change(screen.getByTestId("availability-search"), { target: { value: "PHO-S" } });

    await waitFor(() => {
      expect(screen.queryByText("Cơm gà")).not.toBeInTheDocument();
    });
    expect(screen.getByText("Phở bò")).toBeInTheDocument();
  });
});

/**
 * Dish → Topping → group. Three taps, exactly what staff do.
 *
 * The toppings section is collapsed on open (see `topping-list.tsx`): a dish
 * with six groups of six is 36 rows that would bury the dish under it. Tests
 * walk the same path rather than reaching past it, so a change that breaks the
 * path breaks them.
 */
async function openToppings(productId = "mp1", groupId = "tg1") {
  fireEvent.click(screen.getByTestId(`availability-product-expand-${productId}`));
  fireEvent.click(await screen.findByTestId(`availability-toppings-toggle-${productId}`));
  fireEvent.click(
    await screen.findByTestId(`availability-topping-group-${productId}-${groupId}`),
  );
}

describe("toppings", () => {
  it("lists toppings under a dish, including the hidden ones", async () => {
    // Same one-way-door rule as dishes and variants: a topping the shop cannot
    // see is one it can never put back on the menu.
    renderPage();

    await screen.findByText("Phở bò");
    await openToppings();

    expect(await screen.findByText("Trứng chần")).toBeInTheDocument();
    expect(screen.getByText("Thịt thêm")).toBeInTheDocument();
    expect(screen.getByTestId("availability-topping-switch-mp1-ti2")).not.toBeChecked();
  });

  it("makes the whole dish row expand, without swallowing its switch", async () => {
    // The row-wide tap target is a full-bleed button BEHIND the row content.
    // Two properties have to hold, and only one of them is a click:
    //
    //   1. The switch must NOT be a descendant of it. If it were, a tap meant
    //      for the switch would also expand the row — and one interactive
    //      control nested inside another is invalid HTML that breaks keyboard
    //      order and screen-reader semantics besides.
    //   2. The button must cover the row rather than a 16px chevron.
    //
    // (2) is a hit-testing fact, and jsdom does no layout or hit-testing — it
    // dispatches events straight at the node you name. So it is asserted the
    // only way available: on the class that produces it. Naming the mechanism
    // beats asserting nothing and calling the row covered.
    renderPage();

    await screen.findByText("Phở bò");
    const expander = screen.getByTestId("availability-product-expand-mp1");
    const dishSwitch = screen.getByTestId("availability-product-switch-mp1");

    expect(expander).not.toContainElement(dishSwitch);
    expect(expander.className).toContain("absolute");
    expect(expander.className).toContain("inset-0");

    // The row still opens, and says so to a screen reader.
    expect(expander).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(expander);
    expect(await screen.findByTestId("availability-sku-sk1")).toBeInTheDocument();
    expect(expander).toHaveAttribute("aria-expanded", "true");
    expect(expander).toHaveAttribute("aria-controls", "availability-detail-mp1");
    expect(document.getElementById("availability-detail-mp1")).toBeInTheDocument();

    // …and toggling the dish never collapses what the cashier just opened.
    fireEvent.click(dishSwitch);
    expect(expander).toHaveAttribute("aria-expanded", "true");
  });

  it("gives a dish with nothing to show no row-wide tap target", async () => {
    // A click surface that opens nothing teaches staff the row is not worth
    // pressing — and they stop pressing the ones that are.
    renderPage();

    await screen.findByText("Cơm gà"); // seeded with no skus and no toppings
    expect(screen.queryByTestId("availability-product-expand-mp2")).toBeNull();
    expect(screen.getByTestId("availability-product-switch-mp2")).toBeInTheDocument();
  });

  it("keeps the topping section shut until it is asked for", async () => {
    // The whole point of the disclosure. If a future edit defaults it open,
    // every dish on the screen pays for its toppings' DOM the moment it is
    // expanded — and the 36-row dish buries the one under it again.
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-expand-mp1"));

    expect(await screen.findByTestId("availability-toppings-toggle-mp1")).toHaveAttribute(
      "aria-expanded",
      "false",
    );
    expect(screen.queryByText("Trứng chần")).not.toBeInTheDocument();

    // …and variants are the opposite: open on arrival, because that layer can
    // sell nothing while the dish switch still reads ON.
    expect(screen.getByTestId("availability-variants-toggle-mp1")).toHaveAttribute(
      "aria-expanded",
      "true",
    );
    expect(screen.getByTestId("availability-sku-sk1")).toBeInTheDocument();
  });

  it("expands ONE topping to its read-only add-on prices", async () => {
    renderPage();

    await screen.findByText("Phở bò");
    await openToppings();

    // Shut until asked, like every other level.
    expect(screen.queryByText("TOP-TC-L")).not.toBeInTheDocument();
    fireEvent.click(await screen.findByTestId("availability-topping-expand-mp1-ti1"));

    expect(await screen.findByText("TOP-TC-L")).toBeInTheDocument();
    // Scoped to the topping row: the DISH also has a "Lớn" variant, and an
    // unscoped query would pass on the wrong table.
    const row = within(screen.getByTestId("availability-topping-mp1-ti1"));
    expect(row.getByText("Lớn")).toBeInTheDocument();
    // `extra_price` crosses the wire as a STRING on both transports, and the
    // cell must go through the shared money formatter — not print the raw
    // string. Asserted as "contains 120 but is not exactly 120" rather than
    // against a pinned glyph, because the symbol is the shop's currency and
    // this screen has no business pinning one.
    expect(row.getByText(/120/).textContent).not.toBe("120");
    // A wildcard row (no product_sku_id) applies to every variant and must say
    // so rather than showing an empty cell.
    expect(
      row.getByText(jaMessages["menu_availability.topping_all_variants"]),
    ).toBeInTheDocument();
  });

  it("gives a topping with no variants no chevron to press", async () => {
    // A disclosure that opens onto an empty box teaches staff the chevrons are
    // not worth pressing — and they stop pressing the ones that matter.
    renderPage();

    await screen.findByText("Phở bò");
    await openToppings();

    await screen.findByText("Thịt thêm");
    expect(screen.queryByTestId("availability-topping-expand-mp1-ti2")).toBeNull();
    expect(screen.getByTestId("availability-topping-switch-mp1-ti2")).toBeInTheDocument();
  });

  it("turns a hidden topping back on with ONE tap and no dialog", async () => {
    renderPage();

    await screen.findByText("Phở bò");
    await openToppings();
    fireEvent.click(await screen.findByTestId("availability-topping-switch-mp1-ti2"));

    await waitFor(() => {
      expect(mocked.setToppingAvailability).toHaveBeenCalledWith("mp1", "ti2", {
        is_active: true,
        actor_user_id: "u-1",
        actor_name: "Ann",
      });
    });
    expect(screen.queryByTestId("reason-confirm")).not.toBeInTheDocument();
  });

  it("asks for a reason before hiding a topping", async () => {
    renderPage();

    await screen.findByText("Phở bò");
    await openToppings();
    fireEvent.click(await screen.findByTestId("availability-topping-switch-mp1-ti1"));

    expect(mocked.setToppingAvailability).not.toHaveBeenCalled();
    fireEvent.click(await screen.findByTestId("reason-confirm"));

    await waitFor(() => {
      expect(mocked.setToppingAvailability).toHaveBeenCalledWith(
        "mp1",
        "ti1",
        expect.objectContaining({ is_active: false }),
      );
    });
  });

  it("finds a dish by a TOPPING name", async () => {
    // "hết trứng chần" is a sentence about a topping; staff type that word, not
    // the dish it hangs off.
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.change(screen.getByTestId("availability-search"), {
      target: { value: "Trứng" },
    });

    await waitFor(() => {
      expect(screen.queryByText("Cơm gà")).not.toBeInTheDocument();
    });
    expect(screen.getByText("Phở bò")).toBeInTheDocument();
  });
});

describe("LAN payload shape", () => {
  it("renders toppings from the WORKSTATION shape, not just the Cloud one", async () => {
    // REGRESSION GUARD for a bug that shipped.
    //
    // The two servers disagreed twice about the same list:
    //   · POSITION — the workstation nested it at `product.topping_groups`
    //     (the ordering contract), Cloud put it at `mp.topping_groups`.
    //   · SPELLING — the workstation said `is_hidden`, Cloud said `is_active`.
    //
    // The client reads Cloud's position and Cloud's spelling, so on the LAN —
    // the transport most shops actually run — the topping section did not
    // render at all. Every test passed: the Go tests asserted the Go shape,
    // this fixture was written from the Cloud shape, and the route-parity test
    // only compares URLs.
    //
    // The workstation now emits BOTH spellings and both positions. This asserts
    // the client copes with the LAN payload verbatim, extra fields and all.
    const detail = makeDetail();
    detail.data.products[0].topping_groups = [
      {
        id: "tg1",
        name: "Topping phở",
        // The workstation's richer item shape, verbatim.
        items: [
          {
            id: "ti1",
            topping_group_id: "tg1",
            product_id: "tp1",
            name: "Trứng chần",
            image_url: null,
            is_default: false,
            is_hidden: true,
            is_active: false,
            sort_order: 1,
            skus: [],
          },
        ],
      },
    ] as never;
    mocked.getMenu.mockResolvedValue(detail);
    renderPage();

    await screen.findByText("Phở bò");
    await openToppings();

    expect(await screen.findByText("Trứng chần")).toBeInTheDocument();
    // `is_active: false` must drive the switch — reading `is_hidden` by mistake
    // would invert it, and an inverted topping toggle is invisible until a
    // customer is served something the shop marked gone.
    expect(screen.getByTestId("availability-topping-switch-mp1-ti1")).not.toBeChecked();
  });
});


describe("option values — \"hết cỡ Lớn\"", () => {
  it("offers ONE switch per option value, over the variants that carry it", async () => {
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-expand-mp1"));

    // Two values on one axis ⇒ two switches, plus the axis name once.
    expect(await screen.findByTestId("availability-option-switch-v-nho")).toBeInTheDocument();
    expect(screen.getByTestId("availability-option-switch-v-lon")).toBeInTheDocument();
    expect(screen.getByText("Size")).toBeInTheDocument();
  });

  it("turns off EVERY variant carrying the value, in one write", async () => {
    // The whole point: the value has no row of its own, so the switch moves
    // the variant rows that carry it — and only those.
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-expand-mp1"));
    fireEvent.click(await screen.findByTestId("availability-option-switch-v-lon"));

    // Turning something OFF always asks for a reason first.
    fireEvent.click(await screen.findByTestId("reason-confirm"));

    await waitFor(() => {
      expect(mocked.bulkSetSkuAvailability).toHaveBeenCalledWith(
        "m1",
        ["mps1b"],
        expect.objectContaining({ is_active: false }),
      );
    });
  });

  it("turns a value back on with ONE tap and no dialog", async () => {
    const detail = makeDetail();
    // Both Lớn variants off — the state a shop is in the morning after.
    detail.data.products[0].skus[1].is_active = false;
    mocked.getMenu.mockResolvedValue(detail);
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-expand-mp1"));
    fireEvent.click(await screen.findByTestId("availability-option-switch-v-lon"));

    await waitFor(() => {
      expect(mocked.bulkSetSkuAvailability).toHaveBeenCalledWith(
        "m1",
        ["mps1b"],
        expect.objectContaining({ is_active: true }),
      );
    });
    expect(screen.queryByTestId("reason-confirm")).not.toBeInTheDocument();
  });

  it("says a value is ON while ANY of its variants is still sellable", async () => {
    // "Lớn" spans two spice variants; one still on means the size is still
    // sellable in some form, and a switch reading OFF would be a lie.
    const detail = makeDetail();
    const skus = detail.data.products[0].skus;
    skus.push({ ...skus[1], menu_product_sku_id: "mps1c", product_sku_id: "sk1c", is_active: false });
    mocked.getMenu.mockResolvedValue(detail);
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-expand-mp1"));

    expect(await screen.findByTestId("availability-option-switch-v-lon")).toBeChecked();
  });

  it("offers no strip when the axis has a single value", async () => {
    // One value equals the whole dish, which the dish switch already does — a
    // second control for the same effect is one staff have to think about.
    const detail = makeDetail();
    detail.data.products[0].skus = [detail.data.products[0].skus[0]];
    mocked.getMenu.mockResolvedValue(detail);
    renderPage();

    await screen.findByText("Phở bò");
    fireEvent.click(screen.getByTestId("availability-product-expand-mp1"));

    await screen.findByText("PHO-S");
    expect(screen.queryByTestId("availability-option-switch-v-nho")).toBeNull();
  });
});
