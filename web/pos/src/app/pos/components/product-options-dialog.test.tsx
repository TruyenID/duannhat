import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { AppProvider } from "@/providers/app-provider";
import type {
  ShopMenuProduct,
  ShopMenuProductSku,
  ShopMenuToppingGroup,
} from "../types";
import { ProductOptionsDialog } from "./product-options-dialog";

const skus = [
  {
    id: "menu-sku-regular",
    product_sku_id: "product-sku-regular",
    is_active: true,
    selling_price: "1000",
    product_sku: {
      id: "product-sku-regular",
      name: "Regular",
      sku: "PHO-R",
    },
  },
  {
    id: "menu-sku-large",
    product_sku_id: "product-sku-large",
    is_active: true,
    selling_price: "1500",
    product_sku: {
      id: "product-sku-large",
      name: "Large",
      sku: "PHO-L",
    },
  },
] as unknown as ShopMenuProductSku[];

const product = {
  id: "menu-product-pho",
  is_active: true,
  product: {
    id: "product-pho",
    name: "Phở Bò",
    topping_groups: [],
  },
  skus,
} as unknown as ShopMenuProduct;

describe("ProductOptionsDialog edit mode", () => {
  it("mounts as a controlled modal and keeps every variant selectable", () => {
    render(
      <AppProvider>
        <ProductOptionsDialog
          product={product}
          skus={skus}
          toppingGroups={[]}
          mode="edit"
          initialSelectedSkuId="menu-sku-regular"
          initialSelections={[]}
          onSubmit={vi.fn()}
          open={true}
          onOpenChange={vi.fn()}
        />
      </AppProvider>,
    );

    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(screen.getAllByRole("radio")).toHaveLength(2);
    expect(screen.getAllByText("Regular").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Large").length).toBeGreaterThan(0);
  });
});

// #1708 — a topping whose sku row has a null product_sku_id can't be ordered,
// so the picker must show it visibly disabled rather than as a silent dead
// click. Uses a single-SKU product so no variant radios are rendered.
const singleSku = [
  {
    id: "menu-sku-banhmi",
    product_sku_id: "product-sku-banhmi",
    is_active: true,
    selling_price: "900",
    product_sku: { id: "product-sku-banhmi", name: "Default", sku: "BM" },
  },
] as unknown as ShopMenuProductSku[];

const banhMi = {
  id: "menu-product-banhmi",
  is_active: true,
  product: { id: "product-banhmi", name: "Bánh mì", topping_groups: [] },
  skus: singleSku,
} as unknown as ShopMenuProduct;

const juiceGroup = {
  id: "tg-juice",
  name: "Nước ép",
  selection_type: "single",
  modifier_type: "add",
  price_strategy: "flat",
  free_quantity: null,
  min_select: 1,
  max_select: 1,
  max_qty_per_item: 1,
  effective_min_select: 1,
  effective_max_select: 1,
  available_from: null,
  available_to: null,
  available_days: null,
  is_active: true,
  items: [
    {
      id: "item-coconut",
      topping_group_id: "tg-juice",
      product_id: "prod-coconut",
      name: "Nước dừa",
      is_default: false,
      sort_order: 0,
      // The #1708 defect: no product_sku_id → unorderable.
      skus: [
        {
          id: "row-coconut",
          topping_group_item_id: "item-coconut",
          product_sku_id: null,
          extra_price: "300",
        },
      ],
    },
    {
      id: "item-mango",
      topping_group_id: "tg-juice",
      product_id: "prod-mango",
      name: "Nước xoài",
      is_default: false,
      sort_order: 1,
      // Correctly bound → selectable.
      skus: [
        {
          id: "row-mango",
          topping_group_item_id: "item-mango",
          product_sku_id: "product-sku-mango",
          extra_price: "250",
        },
      ],
    },
  ],
} as unknown as ShopMenuToppingGroup;

describe("ProductOptionsDialog unselectable topping (#1708)", () => {
  it("marks a null-product_sku_id topping visibly disabled but leaves a bound one selectable", () => {
    render(
      <AppProvider>
        <ProductOptionsDialog
          product={banhMi}
          skus={singleSku}
          toppingGroups={[juiceGroup]}
          onSubmit={vi.fn()}
          open={true}
          onOpenChange={vi.fn()}
        />
      </AppProvider>,
    );

    // The unorderable option is aria-disabled; the bound one is not.
    const coconutLabel = screen.getByText("Nước dừa").closest("label");
    const mangoLabel = screen.getByText("Nước xoài").closest("label");
    expect(coconutLabel).toHaveAttribute("aria-disabled", "true");
    expect(mangoLabel).toHaveAttribute("aria-disabled", "false");

    // Its radio is disabled so a tap can't silently no-op.
    const coconutRadio = coconutLabel?.querySelector('[role="radio"]');
    expect(coconutRadio).toBeDisabled();
  });
});

/**
 * Every 30 seconds the workstation health probe and the kitchen print-status
 * probe each re-render the POS page. `MenuCatalog` rebuilds the dialog's array
 * props inline on every render — `(mp.skus ?? []).filter(...)` and
 * `mp.product?.topping_groups ?? []` — so those props arrive with fresh
 * identities even when the menu has not changed at all.
 *
 * The hydrate-on-open effect listed them as dependencies, so each of those
 * clock-driven re-renders re-ran it and called `setSelections(initial)`: the
 * cashier's toppings, variant and kitchen note vanished mid-order with nothing
 * on screen to explain it. It read like the page had reloaded itself.
 *
 * `churningTree` reproduces the parent exactly — same inline `.filter()`, same
 * `?? []` fallback — so every rerender() hands the dialog brand-new arrays
 * carrying identical data.
 */
const multiSkuBanhMi = {
  id: "menu-product-banhmi-multi",
  is_active: true,
  // null (not []) so the `?? []`-style fallback in the parent allocates a new
  // array every render, exactly like a product with no topping groups of its own.
  product: { id: "product-banhmi", name: "Bánh mì", topping_groups: null },
  skus,
} as unknown as ShopMenuProduct;

function churningTree() {
  return (
    <AppProvider>
      <ProductOptionsDialog
        product={multiSkuBanhMi}
        skus={(multiSkuBanhMi.skus ?? []).filter((s) => s.is_active)}
        toppingGroups={multiSkuBanhMi.product?.topping_groups ?? [juiceGroup]}
        onSubmit={vi.fn()}
        open={true}
        onOpenChange={vi.fn()}
      />
    </AppProvider>
  );
}

/**
 * The name also shows up in the order-summary column once picked, so match on
 * the one occurrence that actually sits inside a radio row.
 */
function radioFor(label: string): HTMLElement {
  const radio = screen
    .getAllByText(label)
    .map((el) => el.closest("label")?.querySelector('[role="radio"]'))
    .find(Boolean);
  if (!radio) throw new Error(`no radio row found for ${label}`);

  return radio as HTMLElement;
}

describe("ProductOptionsDialog — a parent re-render must not wipe the cashier's picks", () => {
  it("keeps the selected topping across re-renders that change nothing but array identity", () => {
    const { rerender } = render(churningTree());

    fireEvent.click(radioFor("Nước xoài"));
    expect(radioFor("Nước xoài")).toHaveAttribute("aria-checked", "true");

    // The 30s health probe lands. No menu data changed — only identities.
    rerender(churningTree());
    expect(radioFor("Nước xoài")).toHaveAttribute("aria-checked", "true");

    // …and it keeps happening, every half minute, for as long as the sheet is open.
    rerender(churningTree());
    rerender(churningTree());
    expect(radioFor("Nước xoài")).toHaveAttribute("aria-checked", "true");
  });

  it("keeps the chosen variant and the kitchen note too", () => {
    const { rerender } = render(churningTree());

    // Variant: pick the one that is NOT the open-time default (cheapest).
    fireEvent.click(radioFor("Large"));
    expect(radioFor("Large")).toHaveAttribute("aria-checked", "true");

    const note = screen.getByRole("textbox");
    fireEvent.change(note, { target: { value: "ít cay, không hành" } });
    expect(note).toHaveValue("ít cay, không hành");

    rerender(churningTree());

    expect(radioFor("Large")).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("textbox")).toHaveValue("ít cay, không hành");
  });
});
