/**
 * plan-056 — "Tồn món": the shop's stock switchboard.
 *
 * A file of its own, NOT extra methods on `shop-menu-service.ts`. That service
 * feeds the ordering screen and its docblock says out loud that it is read-only
 * on purpose; the boundary between "what the cashier can sell" and "what the
 * shop has run out of" is worth keeping visible at the file level, because the
 * failure mode when they blur is a sold-out dish reappearing in the cart picker.
 *
 * URLs are identical on the workstation and on Cloud, so `apiFetch` resolves
 * whichever is in play (LAN first, Cloud fallback) with no branching here.
 *
 * ## Writes carry a VALUE, never "flip it"
 *
 * On the LAN the workstation queues each write and replays it with at-least-once
 * delivery. A toggle delivered twice puts a dish the shop ran out of back on
 * sale, silently. `is_active: false` survives any number of replays.
 */

import { apiFetch } from "@/lib/api";

const BASE = "/api/v1/pos/menu-availability";

/** One axis a variant sits on — "Size" → "Lớn". */
export interface AvailabilityOption {
  option_id: string | null;
  option_name: string | null;
  value_id: string;
  value_label: string | null;
  position: number;
}

/** One variant of a dish, as the management screen sees it. */
export interface AvailabilitySku {
  /**
   * The `menu_product_sku` id — the address a write goes to.
   *
   * NULL when HQ added this SKU to the product after the branch cloned its
   * menu: the catalog row exists and the variant IS sellable, but there is no
   * pivot row yet, so there is nothing on Cloud to write back to. The UI
   * disables the switch for those rather than guessing an id.
   */
  menu_product_sku_id: string | null;
  product_sku_id: string;
  /**
   * What tells this variant apart from its siblings — "Lớn · Cay" — resolved
   * server-side from the option values, falling back to the SKU's own name.
   *
   * NULL is the NORMAL answer for a simple product: one SKU, no name of its
   * own, no option axis. Render nothing rather than a placeholder; a column of
   * "(variant)" rows is noise that buries the products where the axis matters.
   */
  variant_label: string | null;
  /**
   * The option AXES this variant sits on — Size: Lớn, Cay: Không.
   *
   * The screen groups a dish's variants on `value_id` to offer one switch per
   * option VALUE ("turn off Lớn"), which then writes every variant carrying it.
   * There is no shop-scoped row for an option value itself — `product_options`
   * hangs off the PRODUCT with no branch column — so the value is a name for a
   * SET of variant rows, and this is the set membership.
   *
   * Grouping keys on `value_id`, never the label: two option groups can carry
   * values spelled the same ("Không" under Cay and under Hành), and merging
   * those would build one switch that turns off variants nobody selected.
   */
  options: AvailabilityOption[];
  /** The SKU's own name. Usually null; prefer `variant_label`. */
  name: string | null;
  sku: string | null;
  /** READ-ONLY here. Price lives in admin-web and is Manager-gated. */
  selling_price: number;
  default_price: number | null;
  is_price_overridden: boolean;
  is_active: boolean;
  disabled_reason: string | null;
  disabled_at: string | null;
  disabled_by_name: string | null;
}

/** One dish. */
export interface AvailabilityProduct {
  /** `menu_product` id — the write address for the dish-level switch. */
  id: string;
  menu_id: string;
  product_id: string;
  menu_section_id: string | null;
  display_order: number;
  is_active: boolean;
  disabled_reason: string | null;
  disabled_at: string | null;
  disabled_by_name: string | null;
  skus: AvailabilitySku[];
  topping_groups?: AvailabilityToppingGroup[];
  product?: { name?: string | null; image_url?: string | null } | null;
  section?: { id: string; name: string } | null;
}

/**
 * One priced variant of a topping — "Ít bánh +0 ¥" vs "Nhiều bánh +120 ¥".
 *
 * READ-ONLY on this screen. It is here so staff can tell the variants of a
 * topping apart, which is the same job `variant_label` does for a dish. The
 * price itself belongs to admin-web: writing it means the admin topping-sync
 * endpoint, which REPLACES every override of the group.
 */
export interface AvailabilityToppingSku {
  id: string;
  product_sku_id: string | null;
  /** NULL means the row applies to every variant (a wildcard override). */
  sku_label: string | null;
  sku_code: string | null;
  /**
   * A STRING on both transports, deliberately — money crosses the wire without
   * passing through a float. Format it; don't do arithmetic on it here.
   */
  extra_price: string;
}

/** One topping option on one dish. */
export interface AvailabilityToppingItem {
  id: string;
  name: string;
  /**
   * Optional because the ORDERING payload has no reason to carry it and a
   * shape that is required-but-absent would make the sales read fail to type.
   * Absent and empty mean the same thing here: nothing to expand.
   */
  skus?: AvailabilityToppingSku[];
  /**
   * `is_active`, NOT `is_hidden`.
   *
   * The stored Cloud column is `is_hidden` — the inverse. Both servers invert
   * once, at their own boundary, so the client speaks ONE vocabulary across
   * dishes, variants and toppings. A UI that had to remember which of the three
   * levels flips is a UI that eventually flips the wrong one, and an inverted
   * topping toggle is invisible until a customer is served something the shop
   * marked gone.
   */
  is_active: boolean;
}

export interface AvailabilityToppingGroup {
  id: string;
  name: string;
  items: AvailabilityToppingItem[];
}

export interface AvailabilitySection {
  id: string;
  name: string;
}

export interface AvailabilityMenu {
  id: string;
  name: string;
  status: string;
}

export interface AvailabilityMenuDetail extends AvailabilityMenu {
  sections: AvailabilitySection[];
  products: AvailabilityProduct[];
}

/** Body shared by the dish and variant writes. */
export interface SetAvailabilityInput {
  is_active: boolean;
  /**
   * Only meaningful when turning something OFF. No minimum length is enforced
   * anywhere in the stack — a cashier mid-service taps a preset chip, and
   * "reason too short" is a validation error that costs service time and
   * protects nothing.
   */
  reason?: string | null;
  /**
   * Who is at the terminal. The workstation authenticates the TERMINAL, not the
   * person, so it forwards this and Cloud vets it against the branch's
   * organisation before storing. An unrecognised id is dropped, never rejected.
   */
  actor_user_id?: string | null;
  actor_name?: string | null;
}

export const menuAvailabilityService = {
  /** Every branch menu, whatever its status — a shop preps tomorrow's too. */
  listMenus(): Promise<{ data: AvailabilityMenu[] }> {
    return apiFetch<{ data: AvailabilityMenu[] }>(`${BASE}/menus`);
  },

  /** One menu with EVERY dish and variant, including the turned-off ones. */
  getMenu(menuId: string): Promise<{ data: AvailabilityMenuDetail }> {
    return apiFetch<{ data: AvailabilityMenuDetail }>(`${BASE}/menus/${menuId}`);
  },

  setProductAvailability(
    menuProductId: string,
    input: SetAvailabilityInput,
  ): Promise<{ data: Partial<AvailabilityProduct> }> {
    return apiFetch<{ data: Partial<AvailabilityProduct> }>(
      `${BASE}/products/${menuProductId}`,
      { method: "PUT", body: JSON.stringify(input) },
    );
  },

  setSkuAvailability(
    menuProductSkuId: string,
    input: SetAvailabilityInput,
  ): Promise<{ data: Partial<AvailabilitySku> }> {
    return apiFetch<{ data: Partial<AvailabilitySku> }>(
      `${BASE}/skus/${menuProductSkuId}`,
      { method: "PUT", body: JSON.stringify(input) },
    );
  },

  /**
   * Hide or show one topping on one dish.
   *
   * A SINGLE-row write on both servers. It deliberately does not reuse the
   * admin topping endpoint, which replaces every override of the group — that
   * one would wipe the shop's topping PRICES as a side effect of hiding one
   * item, and a POS toggle has no business touching money.
   */
  setToppingAvailability(
    menuProductId: string,
    toppingItemId: string,
    input: SetAvailabilityInput,
  ): Promise<{ data: { is_active: boolean } }> {
    return apiFetch<{ data: { is_active: boolean } }>(
      `${BASE}/products/${menuProductId}/toppings/${toppingItemId}`,
      { method: "PUT", body: JSON.stringify(input) },
    );
  },

  /**
   * Turn every variant carrying one option value on or off — "hết cỡ Lớn".
   *
   * An EXPLICIT id list, resolved on screen. The alternative — sending the
   * option value and letting the server expand it — would let a replay hours
   * later (the workstation queues these) reach variants HQ added to that option
   * meanwhile, which the operator never saw.
   *
   * Returns how many rows ACTUALLY changed, same contract as the section bulk.
   */
  bulkSetSkuAvailability(
    menuId: string,
    menuProductSkuIds: string[],
    input: SetAvailabilityInput,
  ): Promise<{ updated: number }> {
    return apiFetch<{ updated: number }>(`${BASE}/menus/${menuId}/skus/bulk`, {
      method: "POST",
      body: JSON.stringify({ ...input, menu_product_sku_ids: menuProductSkuIds }),
    });
  },

  /**
   * Turn a whole section on or off.
   *
   * Returns how many rows ACTUALLY changed, which is what the toast reports.
   * The section size would be a padded number, and a number staff learn to
   * distrust is worse than no number.
   */
  bulkSetSectionAvailability(
    menuId: string,
    sectionId: string,
    input: SetAvailabilityInput,
  ): Promise<{ updated: number }> {
    return apiFetch<{ updated: number }>(
      `${BASE}/menus/${menuId}/sections/${sectionId}/bulk`,
      { method: "POST", body: JSON.stringify(input) },
    );
  },
};
