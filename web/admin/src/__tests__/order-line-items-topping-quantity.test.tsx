/**
 * REGRESSION — a shop reported this on 2026-08-13.
 *
 * Pick a dish, add an extra that costs money, set the quantity to 3. The order
 * detail showed:
 *
 *   Pho bo            ¥1.000        ¥3.300
 *   + Them gio lua                   +¥100
 *
 * Three figures that do not reconcile: 3 × ¥1.000 is ¥3.000, and a ¥100 extra
 * does not account for the missing ¥300. `topping.quantity` counts the extra
 * WITHIN ONE UNIT of the dish, so the row has to carry the line quantity too —
 * it sits next to `item.subtotal`, which every writer stores as
 * `quantity × (unit_price + topping_subtotal)`.
 *
 * The money charged was never wrong. Only the three surfaces that display it
 * were — this screen, the POS split-bill preview and the printed slip — which
 * is the worst combination: nothing to reconcile against, so it ran unnoticed.
 */

import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { OrderLineItems } from "@/components/shared/order-line-items";
import type { CustomerOrderItem } from "@/services/order-service";

function toppedLine(overrides: Partial<CustomerOrderItem> = {}): CustomerOrderItem {
  return {
    id: "item-1",
    customer_order_id: "order-1",
    product_sku_id: "sku-1",
    quantity: 3,
    unit_price: 1000,
    topping_subtotal: 100,
    // quantity × (unit_price + topping_subtotal) — the stored contract.
    subtotal: 3300,
    status: "preparing",
    product_sku: { id: "sku-1", sku: "PHO-01", name: "Pho bo", product: { name: "Pho bo" } },
    toppings: [
      { id: "t-1", name: "Them gio lua", quantity: 1, unit_price: 100, note: null },
    ],
    ...overrides,
  } as unknown as CustomerOrderItem;
}

function renderLines(items: CustomerOrderItem[]) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <AppProvider defaultLocale="en">
        <OrderLineItems items={items} currencyCode="JPY" />
      </AppProvider>
    </QueryClientProvider>,
  );
}

/**
 * The topping row's text, with the money read as a number.
 *
 * Read off the row rather than matched against a formatted string: the amount
 * is split across text nodes ("+" and the figure) and the glyph is the shop's
 * currency, so pinning either would make the test fail for reasons that have
 * nothing to do with the arithmetic it is here to guard.
 */
function toppingRow(): { text: string; amount: number } {
  const li = screen.getByText(/Them gio lua/).closest("li");
  if (!li) throw new Error("topping row not rendered");
  // The amount is the row's LAST child; the first carries the name and the
  // "×N" count, whose digits would otherwise run into the figure.
  const amountEl = li.lastElementChild;
  const digits = (amountEl?.textContent ?? "").replace(/[^\d]/g, "");
  return { text: li.textContent ?? "", amount: digits === "" ? 0 : Number(digits) };
}

describe("OrderLineItems — topping amounts", () => {
  it("charges the extra once per UNIT, not once per line", () => {
    renderLines([toppedLine()]);

    // 3 bowls × ¥100 = ¥300. ¥100 was the bug.
    expect(toppingRow().amount).toBe(300);
  });

  it("shows the total helping count so the row can be checked by eye", () => {
    // 2 slices on each of 3 bowls = 6. Printed count × unit price must equal
    // the printed amount, or the row is unverifiable however right the total is.
    renderLines([
      toppedLine({
        subtotal: 3600,
        topping_subtotal: 200,
        toppings: [
          { id: "t-1", name: "Them gio lua", quantity: 2, unit_price: 100, note: null },
        ],
      } as Partial<CustomerOrderItem>),
    ]);

    const row = toppingRow();
    expect(row.text).toContain("×6");
    expect(row.amount).toBe(600);
  });

  it("states what a free-tier group gave away, so the block adds up", () => {
    // `free_up_to_n` waives the most expensive picks and the engine stores only
    // the RESULT in `topping_subtotal`; which picks were waived is never
    // persisted. So the rows print at list price and the block over-states by
    // exactly the amount given away — unless it is stated.
    //
    //   Pho bo   ¥1.000                    ¥3.240
    //   + Them gio lua ×3                   +¥300   ← waived
    //   + Trung chan ×3                     +¥240
    //   Free toppings                       −¥300
    renderLines([
      toppedLine({
        subtotal: 3240,
        topping_subtotal: 80, // 100 + 80 list, the ¥100 waived by a 1-free tier
        toppings: [
          { id: "t-1", name: "Them gio lua", quantity: 1, unit_price: 100, note: null },
          { id: "t-2", name: "Trung chan", quantity: 1, unit_price: 80, note: null },
        ],
      } as Partial<CustomerOrderItem>),
    ]);

    const waiver = screen.getByText("Free toppings").closest("li");
    expect(waiver).not.toBeNull();
    const digits = (waiver?.lastElementChild?.textContent ?? "").replace(/[^\d]/g, "");
    expect(Number(digits)).toBe(300);
    // …and it reads as a deduction, not another surcharge.
    expect(waiver?.textContent).toContain("−");
  });

  it("claims no waiver on a flat group", () => {
    // The default strategy and the only one admin-web can configure. Nothing
    // was given away, so nothing is claimed — a fabricated discount on an order
    // record is worse than a block that needs no explaining.
    renderLines([toppedLine()]);
    expect(screen.queryByText("Free toppings")).not.toBeInTheDocument();
  });

  it("claims no waiver when the line carries no stored topping subtotal", () => {
    // The legacy / unsynced shape: the rows ARE the only figure, so the block
    // already agrees with itself and a "discount" here would be invented.
    renderLines([
      toppedLine({ topping_subtotal: 0, subtotal: 3300 } as Partial<CustomerOrderItem>),
    ]);
    expect(screen.queryByText("Free toppings")).not.toBeInTheDocument();
  });

  it("leaves a single-unit line alone", () => {
    // The case that always looked right, kept so the fix cannot drift into
    // multiplying something that was already a line amount.
    renderLines([toppedLine({ quantity: 1, subtotal: 1100 })]);

    const row = toppingRow();
    expect(row.amount).toBe(100);
    expect(row.text).not.toContain("×");
  });
});
