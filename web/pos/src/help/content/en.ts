/**
 * pos-web operator guide — English.
 *
 * Same topic set and same section shape as `vi.ts` / `ja.ts`. `setup` describes
 * what has to be true OUTSIDE pos-web, because most of "the POS can't do X" is
 * not a fault — X is switched off somewhere else.
 */

import type { HelpCatalogue } from "../types";

export const helpEn: HelpCatalogue = {
  // ──────────────────────────────────────────────────────────────────────────
  //  Pages
  // ──────────────────────────────────────────────────────────────────────────
  pairing: {
    title: "Pair this POS device",
    subtitle: "ペアリング",
    purpose:
      "The first screen on a terminal that is not yet bound to a shop. Enter the 6-character code generated in the admin console to exchange it for a device token — the key this POS sends with every later call.",
    setup: [
      "admin-web → Shop → Devices: create a POS device on the RIGHT branch, then generate a pairing code. The code is 6 characters and lives 15 minutes.",
      "The branch attached to the device decides which shop the POS opens into — there is no shop picker afterwards.",
      "The terminal must be able to reach the server: the cloud build calls the backend directly; a build served by the workstation (URL ending in /pos/) goes through the workstation, which relays to cloud.",
      "Every device type on the platform (POS · kiosk · KDS · TMS · workstation) shares ONE pairing endpoint. There is no POS-specific one.",
    ],
    usage: [
      "Open admin-web on another machine, go to the branch's Devices page and generate a pairing code.",
      "Type the 6 characters. The field upper-cases and strips punctuation for you, so case does not matter.",
      "Press “Pair”. On success the POS lands in that branch's sales screen (or the shift-open screen if no shift is open).",
    ],
    checks: [
      "The code expires after 15 minutes → “code expired”. Generate a new one in admin-web; there is no way to extend it.",
      "A wrong or already-used code → “invalid code”. Each code works exactly once.",
      "The token is stored in localStorage AND a cookie for one year. Clearing browser data means pairing again.",
      "Paired to the wrong branch? Revoke the device in admin-web and pair again — the POS cannot switch branch on its own.",
    ],
    glossary: [
      {
        term: "Pairing code",
        description:
          "A 6-character, single-use string valid for 15 minutes, generated per device by an administrator.",
      },
      {
        term: "Device token",
        description:
          "The bearer key minted at pairing. Different from a user login: signing out of an account does not unpair the device.",
      },
    ],
  },

  "pos-main": {
    title: "Sales screen",
    subtitle: "POS メイン",
    purpose:
      "The cashier's main workbench: a strip of open-order tabs on top, the menu on the left, the cart on the right. Each tab is an independent order, so several tables can be served at once without losing context.",
    setup: [
      "A shift must be OPEN. With no open shift the POS redirects to the shift-open screen and refuses to sell.",
      "HQ must publish a menu scheduled for today with active products. No menu for today means an empty product grid.",
      "Tables and zones are defined in admin-web; without tables you can still sell, but only as counter / takeaway orders that carry no table.",
      "Currency, service charge, tax-included display, quick-order mode and the item-void matrix all live in admin-web → Shop → Order settings. The POS re-reads them every 60 seconds and immediately when the browser tab regains focus.",
      "LAN features (kitchen tickets, bill printing, cash recycler, card terminal) appear only when this terminal points at a workstation. With no workstation those buttons are hidden — that is silence by design, not breakage.",
    ],
    usage: [
      "Press “+” on the tab strip (or tap a free table on the overview) to open a new order.",
      "Pick items from the grid. Products with variants or topping groups open an options dialog; simple products are added in one tap.",
      "Work the cart on the right: quantities, toppings, voids, table assignment, guest count.",
      "Press “Check out” to close the order — only then do the coupon field and the tax/service breakdown appear.",
      "Press “Take payment”, or “Split bill” when the order has more than one guest.",
    ],
    checks: [
      "Closing a tab (the ×) on an order that ALREADY HAS ITEMS is a hard delete with no audit trail. To keep a trace, use “Void order” with a reason instead.",
      "Every dialog resets when you switch tabs — deliberate, so you cannot act on the wrong order. The post-payment receipt screens deliberately survive.",
      "Switching language in the top bar re-loads item names on open orders in the new language.",
      "Offline: cached data still displays, but taking payment, opening a shift and closing a shift are HARD-BLOCKED, never queued. pos-web does not sell offline — that is the workstation's job.",
    ],
  },

  "tables-overview": {
    title: "Table overview",
    subtitle: "テーブル一覧",
    purpose:
      "Every table in the shop, grouped by zone, with four quick figures: tables in service, open orders, total guests, seats in use. Tap a serving table to open its order; tap a free table to start an order right there.",
    setup: [
      "Tables and zones are defined in admin-web → Shop → Tables. A table must be active to be tappable.",
      "The zone filter row appears only when the shop has two or more zones.",
      "The “Takeaway” tile is always the first cell, because takeaway orders carry no table and therefore never appear on this grid.",
    ],
    usage: [
      "Filter by zone with the pills at the top (when present).",
      "Tap an amber (serving) table to open that order's tab.",
      "Tap a free table to create an order already bound to it.",
      "Use the “⋯” button on a table card for its history, or to change the table's status.",
    ],
    checks: [
      "Status can only be changed on tables with NO live order. A serving table's status is order-driven — close the order first.",
      "Cleaning / out-of-service / reserved tables cannot be tapped to start an order; set them back to free first.",
      "A table shown as serving but without an order code means that order is outside the latest 100 open orders — reach it through the tab strip or history.",
    ],
  },

  takeaway: {
    title: "Takeaway orders",
    subtitle: "テイクアウト",
    purpose:
      "The list of active takeaway orders. Takeaway carries no table, so the table grid cannot show it — this tab is its home.",
    setup: [
      "Orders arrive here when created with type “Takeaway”, or when a customer submits one from customer-web / kiosk for counter payment.",
      "Takeaway menus are published in HQ with the Takeaway service type. An item's tax rides the menu line it was ordered from, so the wrong menu means the wrong tax.",
      "It is fed by a dedicated stream (order_type=takeaway) so a busy dine-in floor cannot crowd takeaway out of the shared open-orders page.",
    ],
    usage: [
      "Tap a card to open that order's tab and work it like any other order.",
      "A customer-submitted order in pending/confirmed state must be accepted with “Accept order” in the cart before it can be checked out.",
      "Items can still be added to a confirmed order at the counter before payment.",
    ],
    checks: [
      "The badge counts active takeaway ORDERS, not items.",
      "Takeaway orders have no table, so merge / change / unmerge table do not apply.",
    ],
  },

  "table-history": {
    title: "History of one table",
    subtitle: "テーブル履歴",
    purpose:
      "Every order this table has ever carried, grouped by day, with the full story of the selected order: when it opened, when each item was added, which items were voided (when and why) and how it was paid.",
    setup: [
      "Full history is served by the workstation (it keeps a persistent table id and the order-table pivot). Without one, cloud returns only the table's live order.",
      "Open it from the “⋯” menu on a table card in the overview, under “View history”.",
    ],
    usage: [
      "Pick an order in the left column to read its detail on the right.",
      "Read the payments section for method, cash tendered and change on each collection.",
      "Press “Close” to return to the previous screen.",
    ],
    checks: [
      "This screen is READ-ONLY — past orders cannot be edited from here.",
      "Seeing exactly one order almost always means the terminal is running in cloud mode rather than through the workstation.",
    ],
  },

  "order-history": {
    title: "Order history",
    subtitle: "注文履歴",
    purpose:
      "Shop-wide order history by day / month / year, all statuses, grouped by calendar day and tagged with where the sale happened (which table, takeaway, or at the counter). The right column tells the selected order's full story — the same renderer as the per-table view.",
    setup: [
      "Reached from the history icon in the POS top bar.",
      "Best served over LAN by the workstation — complete and fast; without one it falls back to cloud.",
    ],
    usage: [
      "Choose the granularity: Day / Month / Year.",
      "Step through periods with the arrows, or pick year / month / day directly.",
      "Press “Load more” at the end of the list — it pages in rather than dumping a whole month at once.",
      "Select an order to read its detail on the right.",
      "Print the selected order's paperwork from the button row under the header: receipt · red invoice · kitchen ticket · order slip.",
      "The two money documents come as a PAIR: “Print original” for the first sheet, “Reprint” for every one after. Exactly one of the two is always live.",
      "Split bills: every row in Payments carries its OWN pair for that payer. The buttons in the row above print the last payer's slip.",
    ],
    checks: [
      "The “total collected” figure only appears once every page of the period is loaded — while a “Load more” button remains, that number is incomplete.",
      "You cannot page into the future: the “next” arrow is disabled once the window reaches the current period.",
      "This is a read-back screen. Reversing money is done from the order's own payment / refund flow.",
      "The print buttons appear only when this terminal is paired with a workstation — the printers live on the LAN, there is no cloud print path.",
      "Receipt and red invoice show only for a completed order that has money in. A live order can still reprint its kitchen ticket and order slip.",
      "A greyed-out “Reprint” means that document has NEVER been printed for this scope — press “Print original”, which produces the same sheet. After it prints, the two swap.",
      "Only the reprint branch asks for a reason, and the reason is NOT required: leaving it blank still prints. It only goes to the print ledger.",
      "“Reprint kitchen ticket” only pushes paper — it does NOT re-fire the order or put it back on the kitchen display.",
      "From the second copy on, the receipt carries a “COPY #N” mark and every print is journalled. Paper is not free.",
    ],
  },

  revenue: {
    title: "Revenue report",
    subtitle: "売上レポート",
    purpose:
      "Three views on revenue: by TIME (day/month/year chart with weekday averages and payment-method mix), by PRODUCT (ranked by revenue or quantity, at product or variant level), and VOIDS (voided orders / items, reasons, value lost).",
    setup: [
      "The displayed currency comes from admin-web → Order settings → currency code. It is pure display — the system never converts between currencies.",
      "The void-reason breakdown only means anything once the brand has defined a void-reason master in HQ; otherwise every row falls into free-text.",
      "The category column on the Product tab comes from the product types / categories defined in HQ.",
    ],
    usage: [
      "Choose the view at the top: Time · Product · Voids.",
      "Pick Day / Month / Year, or press the calendar icon for a custom range.",
      "On the Product tab: choose product or variant (SKU) level, then sort by revenue or by quantity.",
      "Use the pager under the table to move through pages; change the page size if needed.",
    ],
    checks: [
      "The business day follows the BRANCH's timezone, not the terminal's and not the signed-in user's. A manager in Hanoi opening the Tokyo report sees Tokyo's business day.",
      "Orders with a provisional code (no cloud order number yet) are marked as such — those are LAN orders still syncing, not broken data.",
      "These are per-order figures. Cash reconciliation per SHIFT is read on the shift-close (精算) screen, not here.",
    ],
    glossary: [
      {
        term: "Revenue vs Collected",
        description:
          "Revenue counts recorded orders; collected is cash actually received. Orders left on account make the two differ.",
      },
      {
        term: "Value lost to voids",
        description:
          "The total of voided lines. An item voided after the kitchen cooked it still consumed stock — this figure does not capture that cost.",
      },
    ],
  },

  settings: {
    title: "Terminal settings",
    subtitle: "レジ設定",
    purpose:
      "Turn individual sections of the 精算 (shift-close) thermal slip on and off. These are the ONLY shop settings pos-web is allowed to write; everything else belongs to admin-web.",
    setup: [
      "The rest (currency, service charge, tax-included prices, quick order, void matrix, stock-deduction timing) lives in admin-web → Shop → Order settings.",
      "The per-rate tax section is an AUDIT CONTROL: only a signed-in user may toggle it. A device-token-only terminal is refused and shown a “manager only” message.",
      "A thermal printer attached to a workstation is what gives these sections somewhere to print. Without one the toggles still save but have nothing to show for it.",
    ],
    usage: [
      "Flip the switch for the section you want on or off. It moves immediately; saving happens in the background.",
      "The order of sections on this screen is the order they print on the slip.",
      "Press “Back” to return to the sales screen.",
    ],
    checks: [
      "This affects the THERMAL SLIP only. The Z-report PDF always includes the per-rate tax breakdown regardless.",
      "If a save fails the switch snaps back to the server value — a switch that jumps back means the save failed, not that you mis-tapped.",
      "The setting applies to the whole SHOP, not this terminal. Changing it here changes it for every POS in the shop.",
    ],
  },

  "menu-availability": {
    title: "Stock switchboard",
    subtitle: "在庫切れ設定",
    purpose:
      "Take a dish off the menu the moment it runs out, and put it back when the delivery lands. Turning it off HIDES IT FROM THE SALES SCREEN — it disappears from the picker for everyone.",
    setup: [
      "WHICH dishes are on the menu is head office's decision and cannot be changed here. This screen only switches existing ones on and off.",
      "PRICE is read-only here. To change it use admin-web → Shop → Menu, which needs Manager rights or above.",
      "Any shop staff can use this — no manager rights needed. Running out of an ingredient is a kitchen fact, not an administrative decision.",
      "With a workstation this screen WORKS OFFLINE: the change is written locally and pushed up when the link returns.",
      "Without a workstation (talking straight to the cloud) an offline terminal cannot toggle anything.",
    ],
    usage: [
      "Pick a menu top-left. Every menu the shop has is listed, including ones not currently in service.",
      "Flip the switch on a dish to turn it off or on. Turning off asks for a reason; turning on is a single tap.",
      "Open the arrow to see VARIANTS (sizes, types) and switch one on its own — e.g. out of large but small is still selling.",
      "The two buttons on a section header switch the WHOLE section. The off button asks for a reason and says how many dishes it will affect.",
      "Turn on “Only show what is off” at the end of service to catch anything nobody switched back on.",
    ],
    checks: [
      "TURNING A DISH OFF DOES NOT VOID ORDERS. An order open on another tablet keeps its lines and the kitchen keeps cooking.",
      "Turning off the DISH turns off all of its variants, even ones whose own switch still reads on.",
      "The reason has NO length rule. One tap on a preset is enough; typing more is optional.",
      "A switch snapping back means the write failed — not that you mis-tapped.",
      "If a manager edits the same dish in admin-web at the same time, CLOUD WINS once the workstation finishes syncing.",
      "Other POS tablets catch up within about 15 seconds; no reload needed.",
    ],
    glossary: [
      {
        term: "Dish / Variant",
        description:
          "The dish is “Phở bò”. The variants are “small”, “large”. Turning the dish off takes all of them; turning a variant off removes only that size.",
      },
      {
        term: "Off vs Removed",
        description:
          "Off is temporary and reversible at any time. Removing a dish from the menu is head office's job and does not happen here.",
      },
    ],
  },
  "shift-open": {
    title: "Open shift",
    subtitle: "レジ開け",
    purpose:
      "Count the opening float and open a cashier shift. Without an open shift the POS will not sell — every amount has to belong to a shift so the drawer can be reconciled at the end.",
    setup: [
      "The branch needs a configured till and a denomination set for the currency. Denominations are defined in admin-web → Shop → Settings → Denominations.",
      "The currency comes FROM the shop's order settings and is deliberately not selectable here, so shifts of the same shop cannot drift apart.",
      "The staff list in “Opened by” comes from the shop's staff. If it fails to load you can still open the shift by choosing “Someone else” and typing a name.",
      "Printing the レジ開け slip needs a workstation with a printer. Without one it is skipped silently and the shift opens normally.",
    ],
    usage: [
      "Check the shift information: shop, device, opener, timestamp.",
      "If a gap-reconciliation panel appears, tick the payments that belong to this shift and confirm the cash was held separately.",
      "Count the drawer and enter the quantity for each denomination. The running total sits under the table.",
      "Add a note if needed, then press “Open shift”.",
    ],
    checks: [
      "At least one denomination with a quantity above zero is required before the button unlocks.",
      "A till carries exactly ONE open shift at a time. If a shift is already open on another terminal, this screen sends you back to the sales screen.",
      "Offline the button is BLOCKED. A shift “opened offline” would be a ghost shift, so it is not allowed.",
      "When this open continues a handover chain the system says which position it is. You still count from scratch — the blind re-count is deliberate; the previous shift's figure is not shown.",
    ],
    glossary: [
      {
        term: "Opening float",
        description:
          "The cash counted at open. It is the baseline the closing over/short is measured against.",
      },
      {
        term: "Shift chain",
        description:
          "A run of consecutive shifts on one till, linked by handovers and ended by a final close.",
      },
    ],
  },

  "shift-close": {
    title: "Close / hand over shift",
    subtitle: "精算・引き継ぎ",
    purpose:
      "Recount the drawer, compare against what the system computed, declare the card-terminal figures, then settle the shift. This screen produces the shift's 過不足 (over/short).",
    setup: [
      "Only reachable while a shift is open or closing. A shift settled elsewhere sends you back to the shift-open screen.",
      "The currency was SNAPSHOTTED at open and is deliberately not re-read from shop settings, so an admin changing currency mid-shift cannot corrupt the reconciliation.",
      "Tender categories (card / QR / e-money / shop-defined) and registered payment terminals are configured in admin-web. Without them you get a single generic section.",
      "The over/short tolerance comes from the till configuration. Beyond tolerance a reason becomes mandatory.",
      "Printing the 精算 slip needs a workstation with a printer. Without one the shift still settles, it just produces no paper.",
    ],
    usage: [
      "Read the three summary boxes at the top: cash counted, cash the system expects, and the variance.",
      "Count the drawer by denomination. Coins smaller than the smallest denomination go in the “odd change / adjustment” field.",
      "For each payment terminal: enter gross and cancelled amounts per tender, and the machine's own batch total from its slip.",
      "Give a reason for every section out of tolerance; cash variance is explained in the closing note.",
      "Press “Hand over” if the next shift continues the same chain, or “Close shift” to end the chain.",
    ],
    checks: [
      "HAND OVER and CLOSE are different: a handover settles this shift but KEEPS the chain open and the next cashier re-counts the float; a close ENDS the chain and prints the chain-wide aggregate. The confirm button names which one you are about to do — read it.",
      "While any out-of-tolerance section still lacks a reason, both buttons stay disabled — exactly as the server would refuse it.",
      "“Save draft” keeps your count; re-opening this screen restores it. Only a shift already in the closing state has a draft to restore.",
      "UNPAID orders do not block the close — they carry naturally into the next shift. Only paid orders settle into this one.",
      "Offline both buttons are blocked: cloud has to recompute the authoritative snapshot.",
    ],
    glossary: [
      {
        term: "過不足 (over/short)",
        description: "Cash counted minus cash expected. Negative is short, positive is over.",
      },
      {
        term: "端末日計 (terminal batch total)",
        description:
          "The grand total printed on the card terminal's own daily slip. Entered here to cross-check the figures you declared.",
      },
      {
        term: "Draft",
        description:
          "A saved-but-not-settled count. The shift moves to the closing state without being finalised.",
      },
    ],
  },

  // ──────────────────────────────────────────────────────────────────────────
  //  Shop settings (they live in admin-web and decide what the POS does)
  // ──────────────────────────────────────────────────────────────────────────
  "shop-settings": {
    title: "Shop settings that drive the POS",
    subtitle: "店舗設定 → POS",
    summary: "The map: which setting lives where, who may change it, how fast the POS sees it.",
    purpose:
      "A map of everything that lives OUTSIDE pos-web yet decides what pos-web shows and permits. Almost every “the POS can't do X” ends in one of the groups below rather than in a defect.",
    setup: [
      "Order settings (quick order, default item status, currency, service charge, tax, voiding, stock timing, slip language): admin-web → Shop → Settings → “Order” tab.",
      "Cash denominations and tender types: same Settings page, “Denominations” and “Tender types” tabs.",
      "Payment policy (which methods appear at checkout): admin-web → Shop → Settings → Payments (four sections: Ownership · Connection · Options · Devices).",
      "Tables and zones: admin-web → Shop → Tables. Staff: → Staff.",
      "Menus, products, variants, toppings, combos, tax types, promotions, coupons, void reasons: HQ (brand level), not shop level.",
    ],
    usage: [
      "Work out which group the symptom belongs to, then open that group's guide (the `?` buttons just below this screen).",
      "After changing it in admin-web, come back to the POS — most order settings refresh within 60 seconds, or the moment you switch back to the POS browser tab.",
      "If nothing changed, check you edited the RIGHT BRANCH — these are per-shop settings, not per-brand.",
    ],
    checks: [
      "The POS re-reads order settings every 60 seconds and whenever its tab regains focus. Quick order is re-read at the instant “+” is pressed, so it takes effect immediately.",
      "Three changes are BLOCKED while a shift is open and the server answers 409: currency, tax-included pricing, and the tax rounding rule. Close the shift first — deliberate, because changing them mid-shift corrupts that shift's reconciliation.",
      "Several settings are tri-state: “Follow HQ” (blank) / On / Off. Blank means inherit the brand, not off.",
      "Settings belong to the SHOP, not the terminal. Changing one changes it for every POS in that shop.",
      "Conversely the workstation address and the LAN/Cloud mode are PER TERMINAL and are not in admin-web at all — they live on the POS itself, in the connection badge.",
    ],
  },

  "settings-order-flow": {
    title: "Order flow & item edits",
    subtitle: "注文フロー設定",
    summary: "Quick order, the status new items are born in, edit/void rights, stock timing.",
    purpose:
      "The settings that decide how an order is created and what the cashier may change afterwards. This is the group most often mistaken for a bug, because it makes buttons disappear without saying anything.",
    setup: ["All under admin-web → Shop → Settings → “Order” tab."],
    usage: [
      "QUICK ORDER (enable_quick_order): on, “+” creates an empty order immediately and skips the create dialog. Off, “+” opens the dialog for order type, tables, guest count and phone number.",
      "DEFAULT ITEM STATUS (default_order_item_status): the status every item is BORN in when added. Four values: pending · preparing · ready · served. Blank = pending.",
      "EDIT ITEMS IN ANY STATUS (allow_item_edit_any_status) and the VOID MATRIX (item_voidable_statuses): they decide which lines can still be voided. The matrix is the newer mechanism and wins; the old flag applies only when no matrix is defined.",
      "STOCK DEDUCTION TIMING (stock_deduction_timing): on_close (when the order closes) · on_preparing (when an item moves to preparing) · on_add (the moment it is added).",
      "PREPARE BEFORE PAYMENT (prep_before_payment) and TABLE STATUS AFTER PAYMENT (table_status_after_payment): both tri-state, blank = follow HQ.",
    ],
    checks: [
      "The big trap: set DEFAULT ITEM STATUS to anything other than pending and a freshly added item is ALREADY UNEDITABLE. Quantity / note / topping edits are pending-only, so the −/+ stepper and the “edit” link never appear. It looks exactly like a broken POS.",
      "That same setting changes stock behaviour: with on_preparing timing, only lines BORN at preparing or beyond deduct immediately — that is the shape meant for shops with no kitchen display.",
      "The void matrix always allows voiding a pending line; the other statuses are whatever you declare. Voiding a cooked item skews stock, because voided lines are excluded from ingredient deduction.",
      "Quick order removes the GUEST COUNT and PHONE NUMBER steps. Without a guest count there is no split-bill button; without a customer the order cannot be underpaid. Both can still be added later from the cart.",
      "Table status after payment accepts only “free” or “cleaning”. Choose cleaning and a just-settled table cannot be tapped for a new order until someone changes it back.",
    ],
  },

  "settings-money": {
    title: "Money, tax and rounding",
    subtitle: "金額・税設定",
    summary: "Currency, service charge, tax-included pricing, tax and split-bill rounding.",
    purpose:
      "The settings behind every amount a cashier reads out to a customer. Three of them are LOCKED while a shift is open, because changing them mid-shift corrupts that shift's own reconciliation.",
    setup: ["admin-web → Shop → Settings → “Order” tab, money & tax area."],
    usage: [
      "CURRENCY (currency_code): picked from a fixed list. It drives number formatting across the POS, the quick-tender buttons at checkout, and the denomination set on the shift screens. Display only — the system never converts between currencies.",
      "SERVICE CHARGE (service_charge_rate, 0–100%) and ITS TAX RATE (service_charge_tax_rate): set it to 0 and the line disappears from the cart.",
      "TAX-INCLUDED PRICES (prices_include_tax, 総額表示): on, menu cards and the cart show 税込; off, they show 税抜 with tax added below.",
      "DEFAULT TAX TYPE (default_tax_type_id): the rate used when an item declares none of its own.",
      "TAX ROUNDING (tax_rounding_mode + tax_rounding_decimals): round / up / down, and 0–3 decimal places. SPLIT-BILL ROUNDING (split_bill_rounding_mode) is a separate, unrelated rule.",
    ],
    checks: [
      "Three settings are BLOCKED while a shift is open and the server answers 409: currency, tax-included pricing, and tax rounding. Close the shift first.",
      "A shift's currency is SNAPSHOTTED at open. The close screen deliberately uses that snapshot instead of re-reading the setting, so even if someone did change it mid-shift the reconciliation stays right.",
      "The tax rounding rule is snapshotted onto EACH ORDER at creation. Changing it today does not rewrite yesterday's orders — which is why old reports still add up.",
      "In tax-included mode the “(of which tax …)” line under the total is a NOTE. Adding it to the total again is wrong — the total already contains it.",
      "Changing currency does NOT change the numbers. 100,000 ₫ becomes 100,000 ¥, not an equivalent value.",
      "An item's real tax rate comes from the MENU LINE it was added from, not from this setting. This is only the fallback.",
    ],
  },

  "settings-till": {
    title: "Till, denominations and tenders",
    subtitle: "レジ・金種設定",
    summary: "Denominations for counting, the tender vocabulary, the over/short tolerance.",
    purpose:
      "The settings that build the two cash-counting screens: shift open and shift close. Without them the counting table is empty and the shift cannot be opened.",
    setup: [
      "DENOMINATIONS: admin-web → Shop → Settings → “Denominations” tab. Declared per currency, split into notes and coins.",
      "TENDER TYPES and CATEGORIES: same page, “Tender types” tab. Four system categories (cash · card · QR · e-money) ship built in; custom categories appear on the close screen automatically.",
      "THE TILL and its OVER/SHORT TOLERANCE: configured in the shop's till management.",
      "REGISTERED PAYMENT TERMINALS: admin-web → Shop → Settings → Payments → Devices. Each device produces its own reconciliation block on the close screen.",
    ],
    usage: [
      "Declare a full denomination set for the shop's currency before the first shift is opened.",
      "Enable the tender types the shop genuinely accepts — the close screen lists only enabled ones.",
      "Set the tolerance to match the shop's reality. Beyond it, the close screen demands a reason and keeps the buttons disabled until there is one.",
    ],
    checks: [
      "With an empty denomination table the SHIFT OPEN screen has no working button — it requires at least one denomination with a quantity above zero.",
      "Denominations are declared PER CURRENCY. Change the shop's currency without declaring the matching set and shift open is stuck.",
      "Coins smaller than the smallest denomination cannot be typed into the table — use the “odd change / adjustment” field on the close screen.",
      "With no payment terminals declared the close screen shows exactly ONE generic reconciliation block. That is valid behaviour for a single-terminal shop, not a gap.",
      "A tolerance set too wide lets a real cash discrepancy through with nobody having to explain it.",
    ],
  },

  "settings-payments": {
    title: "Payment policy",
    subtitle: "決済ポリシー",
    summary: "Decides which method tiles appear at checkout.",
    purpose:
      "The settings that decide which tiles a cashier can press at checkout. This is the answer to “checkout says no payment methods are configured”.",
    setup: [
      "admin-web → Shop → Settings → Payments, in four sections: Ownership (whose account) · Connection (the gateway) · Options (enable each method) · Devices (card reader, cash recycler).",
      "The POS shows only options that are IN EFFECT and flagged usable at the POS. An option enabled at brand level but not in effect for the shop does not appear.",
      "Cash and a standalone card terminal come from the internal catalogue and need no gateway at all.",
    ],
    usage: [
      "Open the Options section to enable or disable each method for the shop.",
      "Read the effective-options preview on that same page — it tells you exactly what the POS will see.",
      "Register the card reader under Devices if the shop uses a physical one.",
    ],
    checks: [
      "Tell the two empty states apart: an empty grid WITH a retry button is a connection fault the cashier can fix. An empty grid WITHOUT one means the policy returned nothing — a manager's job, not a cashier's.",
      "The “charge to account” action needs an enabled on_account method. Without it the debt button reports nothing configured.",
      "Whether a tile asks for a tendered amount is declared by that option, not guessed by the POS.",
      "The card reader and the cash recycler only work when this terminal points at a workstation — they sit on the shop LAN and cloud has no route to them.",
      "The tender-brand vocabulary in the sub-choice step is for reconciliation grouping only; it changes no amount and never blocks a collection.",
    ],
  },

  "settings-printing": {
    title: "Printing and slips",
    subtitle: "印刷設定",
    summary: "Slip language, tax registration number, 精算 sections, auto-printing.",
    purpose:
      "The settings that decide what the paper looks like and which language it speaks. Most live in admin-web; the 精算 slip's sections are editable on the POS itself.",
    setup: [
      "SLIP LANGUAGE (print_label_locale): admin-web → Shop → Settings → Order. ja / en / vi, or blank to follow the default.",
      "SELLER TAX REGISTRATION NUMBER and the switch that prints it (show_seller_registration_on_receipt): declared at brand level, overridable per shop.",
      "AUTO-PRINTING: the shift-open slip (print_shift_open_report) and the table-paid slip (print_table_paid).",
      "THE 精算 SLIP'S SECTIONS: editable on the POS, on this very screen.",
      "PRINTERS: declared on the workstation. With no workstation every print button is hidden.",
    ],
    usage: [
      "Pick the slip language for the shop.",
      "Enter the seller's registration number at HQ or at the shop, then switch on printing it if needed.",
      "Turn auto-printing on or off to match how the shop works.",
      "Toggle each 精算 section on this screen.",
    ],
    checks: [
      "SLIP LANGUAGE IS NOT the POS interface language. Switching language on the POS changes only the screen; paper follows its own setting. That is deliberate: if slips followed whichever terminal fired the print, terminal A (ja) firing the kitchen and terminal B (vi) printing the receipt would produce two sheets naming the same dish differently, and staff could not match them at handover.",
      "Slip-language precedence: the workstation's own pick → this shop setting → the branch language → the default. The workstation ranks HIGHEST so one odd station can be fixed without changing the whole shop.",
      "The 精算 section switches affect the THERMAL SLIP only. The Z-report PDF always carries the full per-rate tax breakdown.",
      "The per-rate tax section is an audit control: only a signed-in user may toggle it; a device-token-only terminal is refused.",
      "With no registration number entered the slip simply has no such line, and the system does NOT warn — an unregistered business is legal.",
      "The named-buyer payment receipt prints DIRECTLY and stores no record. There is no invoice ledger to look it up in; the “printed ×N” badge is the only count there is.",
    ],
  },

  "settings-catalog": {
    title: "Menus, tables and master data",
    subtitle: "カタログ・マスタ",
    summary: "Menus + schedules, items and toppings, tax, promotions, tables, void reasons, staff.",
    purpose:
      "The master data that becomes the content the POS displays. Unlike the groups above, most of it lives at HQ (brand level) rather than at the shop.",
    setup: [
      "MENUS + SCHEDULES (weekday, time window) and SERVICE TYPE (店内 / 持ち帰り / shared): HQ → Menus.",
      "PRODUCTS, VARIANTS, TOPPING GROUPS, COMBOS: HQ → Products.",
      "TAX TYPES (10% / 8% / 0%) attached to a menu line, a section or a product: HQ → Tax.",
      "PROMOTIONS (Happy Hour) and COUPONS, with their no-stacking rules: HQ.",
      "VOID REASONS: HQ. Without them the void dialog falls back to a free-text field.",
      "TABLES and ZONES: admin-web → Shop → Tables. STAFF: → Staff (the shift-opener list).",
    ],
    usage: [
      "Publish a menu for today with the right time window — the POS loads only the current day's menus.",
      "Give each menu a service type so dine-in and takeaway see the right prices and the right tax rate.",
      "Assign a tax type to each product or menu line.",
      "Declare tables by zone so the POS table grid can group them.",
    ],
    checks: [
      "No menu scheduled for TODAY means an empty product grid. This is the single most common cause of “the POS shows no items”.",
      "An item's tax rate rides the MENU LINE it was added from and is snapshotted onto the order. Changing the order type afterwards does NOT re-price it — the wrong menu means the wrong tax on a real invoice.",
      "A product with no tax type shows no 税込/税抜 label on its card. A missing label means unassigned, not zero.",
      "The spotlight block (time-limited promotions) is computed by the WORKSTATION on the shop clock. A cloud-only terminal will not see it — normal, not a fault.",
      "A table must be active to be tappable on the POS. Out-of-service tables render dimmed.",
      "With no void reasons at HQ every void lands in the free-text field, and the void report loses its by-reason breakdown.",
    ],
  },

  // ──────────────────────────────────────────────────────────────────────────
  //  Panels inside a page
  // ──────────────────────────────────────────────────────────────────────────
  "menu-catalog": {
    title: "Menu",
    subtitle: "メニュー",
    purpose:
      "The product grid for adding items to the open order, with search, a menu picker and a jump-to-section strip.",
    setup: [
      "Menus, sections, products, variants and topping groups are defined in HQ, along with each menu's schedule (weekday + time window).",
      "The POS loads only TODAY's menus, filtered by the open order's service type: a dine-in order sees 店内 (or shared) menus, takeaway sees 持ち帰り.",
      "The active menu is auto-picked by the current time window. For a counter order the auto-pick deliberately never lands on a takeaway menu — reaching one takes a manual choice.",
      "Whether prices read 税込 or 税抜 is driven by the shop's tax-included setting (admin-web → Order settings).",
      "The spotlight block (time-limited promotions) is computed by the WORKSTATION on the shop clock. A cloud-only terminal shows nothing there — that is normal.",
    ],
    usage: [
      "Pick a menu in the selector on the right if the auto-picked one is not what you want.",
      "Type a keyword and press Enter (or the magnifier) to search.",
      "Use the section strip to jump; it also highlights the section you are currently reading.",
      "Tap a card to add. Products with variants or toppings open the options dialog first.",
    ],
    checks: [
      "With no open order tab, or an order past check-out, the grid is locked — open or select an order first.",
      "An item's tax rides the MENU LINE it was added from and is snapshotted onto the order. Changing the order type afterwards does NOT re-price it. The wrong menu means the wrong tax.",
      "The 税込 / 税抜 label under a price only appears when the product has a resolved tax rate. No label means no tax type was assigned in HQ.",
      "Promotional (Happy Hour) items show a struck-through price. If a coupon is already attached to the order, adding one is blocked and you are asked which to keep.",
    ],
  },

  "order-cart": {
    title: "Cart & check-out",
    subtitle: "カート",
    purpose:
      "The open order's contents, the table/guest actions, the tax-and-service breakdown, and the two buttons that move the order along: “Check out”, then “Take payment”.",
    setup: [
      "Service-charge rate and its tax rate come from admin-web → Order settings. Set it to 0 and the line disappears.",
      "The price presentation (税込/税抜) and the currency come from the same settings.",
      "Which item statuses may be VOIDED is decided by the shop's void matrix. The default is pending-only.",
      "The void-reason master is defined in HQ. Without it the void dialog falls back to a free-text reason.",
      "The “Print order slip” button and the round “Send to kitchen” button only appear when this terminal points at a workstation with a printer.",
      "Coupons and promotions are defined in HQ.",
    ],
    usage: [
      "Assign / merge / change / unmerge tables with the button row under the order code; edit the guest count with the pencil next to the people icon.",
      "Per line: change quantity with −/+, edit toppings with the “edit” link, void with the bin button, change kitchen status with the status pill.",
      "Press “Check out” — the tax/service breakdown opens and the coupon field appears.",
      "Enter a coupon if any, then press “Confirm”.",
      "Press “Take payment”, or “Split bill” when the order has more than one guest.",
    ],
    checks: [
      "Quantity / note / topping edits are PENDING-ONLY. Once the kitchen owns the line the only honest moves are void-with-reason and add.",
      "Changing a VARIANT is never an in-place edit: the old line is voided and a new one is added.",
      "A voided item does not disappear — it becomes “voided” and is hidden; the link under the list reveals them.",
      "Voiding an item the kitchen ALREADY COOKED skews stock, because voided lines are excluded from ingredient deduction.",
      "In tax-included mode the “(of which tax …)” line under the total is a NOTE — do not add it into the total again.",
      "A “rounding adjustment” row appears when tax carries a fraction below the smallest currency unit; it makes the column add up to the payable total.",
    ],
    glossary: [
      {
        term: "Check out vs Take payment",
        description:
          "“Check out” closes the order and locks item edits; “Take payment” is what actually collects money.",
      },
      {
        term: "Service charge (tax-included)",
        description:
          "The service line always shows its own tax inside it. Tap the label to expand the net + tax split.",
      },
    ],
  },

  "pos-tabs": {
    title: "Order tab strip",
    subtitle: "注文タブ",
    purpose:
      "Each tab is one open order. The first two are pinned: “Overview” (the table grid) and “Takeaway”. The “+” opens a new order.",
    setup: [
      "If the shop has QUICK ORDER enabled (admin-web → Order settings), “+” skips the dialog and creates an empty order immediately. Disabled, “+” opens the create-order dialog.",
      "The POS re-reads that setting at the moment you press “+”, so a manager flipping it in admin-web takes effect at once with no POS reload.",
    ],
    usage: [
      "Tap a tab to switch to that order.",
      "Tap “+” to open a new order.",
      "Tap the × on a tab to close it.",
    ],
    checks: [
      "The tab label is always the ORDER CODE, never the table name, so it does not shift when tables are merged or changed mid-order.",
      "Closing a tab on an order that already has items asks first, because it is a hard delete with no trace. For an audit trail use “Void order”.",
      "A tab with a payment or receipt screen open is PINNED, so the workstation's “paid” broadcast cannot unmount the flow one beat before it hands over.",
      "An order showing “awaiting code” was created on the LAN and has not received its cloud order number yet. Wait for sync — it is not an error.",
    ],
  },

  connection: {
    title: "LAN / Cloud connection",
    subtitle: "接続",
    purpose:
      "Shows whether the POS is routing every call through the in-store workstation (LAN) or straight to the server (Cloud), and lets you switch mid-shift.",
    setup: [
      "The default depends on the build: a page opened from the internet defaults to Cloud; a page served BY the workstation (URL ending in /pos/) defaults to LAN, because the workstation is the origin that sent the page.",
      "Each shop runs its workstation on its own operator-chosen port, so the address is NOT baked into the build — enter it here; it is stored per terminal.",
      "With no workstation, all LAN features (kitchen tickets, bill printing, cash recycler, card terminal) are hidden. That is deliberate silence, not breakage.",
      "A page loaded over HTTPS from the internet CANNOT call an HTTP device on the LAN — the browser blocks it. Multi-terminal shops should open the POS from the workstation itself.",
    ],
    usage: [
      "Press the connection badge in the top bar.",
      "Enter or correct the workstation address (e.g. http://192.168.1.50:8080) and save.",
      "Choose a mode: Auto · Workstation · Cloud.",
      "Press “Test connection”; the LAN/Cloud counters below show which way calls are actually going.",
    ],
    checks: [
      "AUTO falls back to Cloud after a workstation network error and waits 30 seconds before retrying. On a workstation-served build, falling back to Cloud means falling out to the internet the shop may not have.",
      "Cloud does NOT serve some workstation-only routes (card terminal, part of the till data). Choosing Cloud while you need those produces 404s.",
      "The workstation address is stored PER TERMINAL and does not sync to other POS devices.",
      "On a workstation-served build a stored address is ignored — the origin that served the page is the workstation.",
    ],
    glossary: [
      {
        term: "LAN",
        description:
          "Through the in-store workstation. Fast, works without internet, and the only route to printers and hardware.",
      },
      {
        term: "Cloud",
        description:
          "Straight to the server over the internet. Always there while the line is up, but cannot reach in-store hardware.",
      },
    ],
  },

  "gap-reconcile": {
    title: "Gap reconciliation between shifts",
    subtitle: "ギャップ精算",
    purpose:
      "Between the previous shift closing and this one opening, the shop can still take money. Those payments belong to no shift. This panel lists them so the cashier confirms which belong to the new one.",
    setup: [
      "It appears only when payments actually landed in that window. Nothing shown means nothing to reconcile.",
      "There is no automatic carry-over queue: a human is deliberately required, because only a human knows where the cash physically is.",
    ],
    usage: [
      "Read the list and tick the payments that belong to the shift you are opening.",
      "Ticking any CASH row also requires the “held separately” acknowledgement before the shift can open.",
      "Ticking nothing is a valid answer — those payments simply keep waiting.",
    ],
    checks: [
      "Only tick a cash row when the cash really was held aside rather than folded into the opening float. A wrong tick makes this shift over and the previous one short.",
      "Ticking creates and destroys no money — it only states which shift a payment belongs to.",
    ],
  },

  "unresolved-orders": {
    title: "Unresolved bills from the previous shift",
    subtitle: "未精算伝票",
    purpose:
      "Orders that were still paying or at checkout when the previous shift closed. Unlike gap payments, this money may never have been taken. The cashier must collect the rest, put it on account, or void with a reason — not attribute it to this shift.",
    setup: [
      "Appears only when at least one paying/checkout order was created before the previous shift's close. Empty is normal.",
      "An order whose table is already free is an orphan bill — the floor map will not show it. That is why this list starts from orders, not tables.",
    ],
    usage: [
      "Read each row: code, status, how much is still outstanding.",
      "Open the order on the floor (or search by code) and collect the remaining amount, or put it on account.",
      "Opening this shift is not blocked. The list is a warning, not a gate.",
    ],
    checks: [
      "Do not treat these rows as gap payments. Ticking them into this shift would book money that was never taken.",
      "A “table released” badge means the table is already free — look the order up by code, not by walking the floor.",
    ],
  },

  "shift-gate-error": {
    title: "Cannot open this shop",
    subtitle: "接続エラー",
    purpose:
      "The blocking screen when the POS cannot read the shop's till state. Two very different causes: a bad shop context (404/403), or the currently-selected target being unreachable (network failure).",
    setup: [
      "404 = the shop slug does not exist. 403 = this device has no access to that shop. Both are fixed in admin-web; switching LAN/Cloud will not help.",
      "No status code = a network failure: either cloud is unreachable, or the stored workstation address is wrong (the shop changed the port, for instance).",
    ],
    usage: [
      "Press “Retry” first.",
      "If it is a network failure, use the button that switches to the other side (Workstation ↔ Cloud).",
      "In workstation mode, correct the address in the field below and retry.",
    ],
    checks: [
      "This screen blocks on purpose — you cannot proceed into sales without knowing whether the shop has an open shift.",
      "“Retry” re-issues the same request to the same address. If the address is wrong, no number of retries will help.",
    ],
  },

  "shift-expired": {
    title: "Shift ended",
    subtitle: "シフト終了",
    purpose:
      "A blocking notice shown when the open shift disappeared mid-session — expired by the scheduler, settled on another terminal, or force-abandoned by a manager. It requires an explicit confirmation instead of navigating away silently.",
    setup: [
      "A scheduled job expires forgotten shifts hourly. A manager can also force-abandon a shift or manually settle an expired one.",
      "The staleness threshold and the force-abandon permission are configured server-side and cannot be changed from the POS.",
    ],
    usage: [
      "Read the notice and finish whatever you have in hand (write down a count in progress if needed).",
      "Press confirm to go to the shift-open screen.",
    ],
    checks: [
      "It cannot be dismissed with Esc or a backdrop click — deliberate, so losing context never happens quietly.",
      "Anything half-typed on the screen behind is NOT saved. Write it down before confirming.",
    ],
  },

  // ──────────────────────────────────────────────────────────────────────────
  //  Order-building dialogs
  // ──────────────────────────────────────────────────────────────────────────
  "create-order": {
    title: "New order",
    subtitle: "新規注文",
    purpose:
      "Open an order. Every field is OPTIONAL — only what you fill is sent — so an order can be anything from “dine-in, tables and guests” to completely empty.",
    setup: [
      "The table list and zones come from admin-web → Shop → Tables.",
      "Entering a phone number finds-or-creates a customer and attaches them. Only orders WITH a customer may later be underpaid (left on account).",
      "If the shop has quick order enabled, this dialog no longer opens — “+” creates an empty order directly.",
    ],
    usage: [
      "Pick the order type: Counter · Dine-in · Takeaway.",
      "Pick one or more tables (leave empty for a table-less order).",
      "Enter the guest count if known — without it the split-bill button never appears.",
      "Enter the customer's phone number if the order may go on account or be looked up later.",
      "Press “Create order”.",
    ],
    checks: [
      "The guest count gates SPLIT BILL: an order without a guest count above 1 shows no split button at payment time.",
      "No phone number means a walk-in, and walk-ins MUST pay in full — they cannot be left on account.",
      "The order type decides which menus appear in the grid, and the menu decides the tax rate. Get it right at the start.",
      "The zone of the table you picked last time is remembered and pre-opened next time.",
    ],
  },

  "product-options": {
    title: "Item options",
    subtitle: "オプション選択",
    purpose:
      "Choose a variant (size / type) and toppings before adding an item to the order. The same dialog is used to EDIT an existing cart line.",
    setup: [
      "Variants and topping groups are defined per product in HQ, including each group's minimum and maximum selections.",
      "Combo products (product type “combo”) show a dedicated badge and the number of required choice groups.",
      "A product with a single variant and no topping groups never opens this dialog — one tap adds it.",
      "Prices here follow the shop's 税込/税抜 mode.",
    ],
    usage: [
      "Pick the variant.",
      "Pick toppings per group; a required group blocks submission until it is satisfied.",
      "Add a kitchen note if needed.",
      "Check the running price in the preview panel, then add to the order.",
    ],
    checks: [
      "In EDIT mode only a pending line can be changed. Switching the VARIANT becomes void-old + add-new rather than an in-place edit.",
      "“Remove” toppings show a − and add no money; “add” toppings may.",
      "The note is free text printed on the kitchen ticket — not an instruction to the system, and it changes no price or tax.",
    ],
  },

  "assign-table": {
    title: "Assign table",
    subtitle: "テーブル割当",
    purpose:
      "Attach one or more tables to an order that currently has NONE (a floating order). Used when the order is opened first and the party is seated afterwards.",
    setup: [
      "Only free tables are selectable; serving / cleaning / out-of-service tables are dimmed.",
      "Tables are defined in admin-web → Shop → Tables.",
    ],
    usage: [
      "Pick a zone if needed, then tick one or more tables.",
      "Confirm. The order is written with exactly the table list you picked.",
    ],
    checks: [
      "For orders that have NO table yet. If the order already has one, use Merge / Change / Unmerge instead.",
      "An order past check-out can no longer be assigned a table.",
    ],
  },

  "change-table": {
    title: "Change table",
    subtitle: "テーブル移動",
    purpose:
      "Move the order to a different table. Only available while the order sits on EXACTLY ONE table.",
    setup: ["The destination table must be free."],
    usage: [
      "Pick the destination table.",
      "Confirm. The system merges the new table, then unmerges the old one, in that order.",
    ],
    checks: [
      "A change is TWO steps. If unmerging the old table fails, the order sits on both tables and the cart shows a warning banner with a retry button — use it, do not dismiss it.",
      "With two or more tables the button is disabled; unmerge first.",
    ],
  },

  "merge-table": {
    title: "Merge table",
    subtitle: "テーブル結合",
    purpose:
      "Add a table to an order that already has one — for a party that grew and needs the next table joined onto the same bill.",
    setup: ["The table you are merging in must be free."],
    usage: ["Tick the tables to add.", "Confirm. They are merged one at a time."],
    checks: [
      "Merging runs sequentially. A failure part-way leaves the already-merged tables in place — re-read the table list on the cart before retrying.",
      "Merging tables does NOT merge two orders: it only adds a table to the current one.",
    ],
  },

  "unmerge-table": {
    title: "Unmerge table",
    subtitle: "テーブル分離",
    purpose: "Remove one or more tables from an order that sits on two or more.",
    setup: ["Only offered when the order has at least two tables."],
    usage: ["Tick the tables to remove.", "Confirm."],
    checks: [
      "You cannot remove them ALL: a dine-in order must keep at least one table. The UI prevents selecting everything.",
      "Removal runs sequentially; a failure part-way leaves the already-removed tables removed.",
    ],
  },

  "guest-count": {
    title: "Guest count",
    subtitle: "人数",
    purpose:
      "Enter or correct the order's guest count. This is not only a statistic — it unlocks split bill.",
    usage: ["Enter the number of guests.", "Confirm."],
    checks: [
      "The count must be ABOVE 1 for the “Split bill” button to appear at payment time.",
      "It also seeds the number of shares on the equal-split screen.",
      "An order past check-out can no longer have its guest count changed.",
    ],
  },

  "void-item": {
    title: "Void item",
    subtitle: "商品取消",
    purpose:
      "Record a reason and void ONE cart line. The line is not deleted — it becomes “voided” and stays in the history.",
    setup: [
      "The void-reason master is defined in HQ. The reason picked drives the stock effect on the server.",
      "With no master (or an unreachable one) the dialog falls back to a free-text reason — the void still works.",
      "Which statuses may be voided is decided by the shop's VOID MATRIX (admin-web → Order settings). Pending is always voidable.",
    ],
    usage: [
      "Pick a reason from the list.",
      "Reasons that require a note demand one before you can continue.",
      "Confirm the void.",
    ],
    checks: [
      "A voided line is TERMINAL — it cannot be restored. Re-add the item as a new line if needed.",
      "Voiding an item the kitchen already cooked skews stock: voided lines are excluded from ingredient deduction while the ingredients were really used.",
      "A junk reason (typing anything to get past the field) is rejected by the server.",
    ],
  },

  "void-order": {
    title: "Void order",
    subtitle: "注文取消",
    purpose:
      "Void the whole order with a reason. This is the correct way to abandon an order that has activity, because it leaves an audit trail.",
    usage: ["Enter a reason (at least 10 characters).", "Confirm."],
    checks: [
      "It cannot be undone.",
      "Different from closing the tab: closing is a HARD DELETE with no trace; voiding keeps the order with a voided status and the reason.",
      "An order that has taken payment cannot be voided this way — use the refund path.",
    ],
  },

  "close-tab": {
    title: "Close order tab",
    subtitle: "タブを閉じる",
    purpose:
      "The ✕ on a tab only tidies the screen: it removes the tab and touches nothing on the order. The order stays open — not voided, not deleted. To finish an order, use “Void order” in the cart; that is where the reason and the audit trail are recorded.",
    usage: [
      "Press ✕ to put a tab away when you are done working on that order.",
      "To reopen an order with a table: go to the overview and tap the serving table.",
      "To reopen a takeaway order: go to the overview and open the “Takeaway orders” drawer.",
    ],
    checks: [
      "Closing a tab does NOT void and does NOT delete the order — no request is sent to the server.",
      "Spot orders and dine-in orders with NO table have no way back in, so the POS asks before closing. Assign a table first if you want to keep a route back.",
    ],
  },

  "stacking-conflict": {
    title: "Promotion and coupon conflict",
    subtitle: "併用不可",
    purpose:
      "Shown when you add a promotional (Happy Hour) item to an order that already carries a coupon. The two discounts do not stack, so the system asks which one to keep.",
    setup: ["The no-stacking rule is configured per promotion / coupon in HQ."],
    usage: [
      "To keep the coupon: cancel, and do not add that promotional item.",
      "To add the promotional item: confirm — the system releases the coupon, then adds the item.",
    ],
    checks: [
      "Confirming REMOVES the coupon from the order; re-enter the code if you want it back.",
      "In the other direction (promotional items already in the cart, then a coupon typed) the error appears at the coupon field in the cart, with a “use the coupon instead” button.",
    ],
  },

  // ──────────────────────────────────────────────────────────────────────────
  //  Money dialogs
  // ──────────────────────────────────────────────────────────────────────────
  payment: {
    title: "Take payment",
    subtitle: "会計",
    purpose:
      "Collect for the open order and, when the customer has old balance, settle it in the same handover. Each old debt is posted as its own transaction, never merged into the current order.",
    setup: [
      "The method tiles come from the shop's PAYMENT POLICY: only options that are in effect AND flagged usable at the POS appear. Configured in admin-web → Shop → Settings → Payments.",
      "An empty grid WITH a retry button means the request never landed (a connection fault) and the cashier can fix it. An empty grid WITHOUT one means the shop has configured nothing — call a manager.",
      "The card-terminal button only works when this terminal points at a workstation: the reader sits behind the shop's NAT and cloud has no route to it.",
      "The cash-recycler button appears only when the shop has a workstation and the chosen method requires a tendered amount. The machine speaks unencrypted HTTP on the LAN, so it must go through the workstation.",
      "The card/QR brand sub-choice comes from the branch's tender vocabulary; it is attribution for reconciliation only and changes no amount.",
      "The quick-tender denomination tiles follow the shop's currency.",
    ],
    usage: [
      "For a multi-guest order you want to split, press the “Split bill” block at the top.",
      "If old balance exists, decide whether to include it with the tick on the debt card.",
      "Pick a method tile.",
      "For cash: type what the customer handed over, or tick “exact amount”, or tap the denomination buttons (repeated taps ADD UP).",
      "Confirm. The receipt screen opens on success.",
    ],
    checks: [
      "WALK-INS (no phone number) MUST pay in full. Underpaying keeps the confirm button disabled with the reason stated.",
      "Registered customers may underpay — the shortfall becomes balance and resurfaces on their next visit.",
      "Multiple collections (old debt + this order) run sequentially, oldest debt first. A failure part-way is safe to retry: an idempotency key stops a double charge.",
      "Changing the method CLEARS what you typed as tendered — deliberate, so cash typed for a cash line cannot drift onto a card line.",
      "Never re-run a card charge whose outcome is unknown. The dialog stays open and says so — charging again on a hunch is how the same card gets taken twice.",
      "Offline the confirm button is BLOCKED, never queued.",
      "“Charge to account” moves the whole remaining balance onto the customer instead of collecting now; it is disabled for walk-ins.",
    ],
    glossary: [
      {
        term: "Tendered / Change",
        description:
          "Print-only figures. The books and every till report add up the TRANSACTION AMOUNT, never the tendered amount.",
      },
      {
        term: "Tender brand",
        description:
          "A label recording what the customer actually tapped on the reader (credit, PayPay, ID…). Purely for reconciliation; leaving it blank never blocks a payment.",
      },
    ],
  },

  "split-bill": {
    title: "Split bill",
    subtitle: "会計分割",
    purpose:
      "Split one order into several collections. Three ways: EQUALLY by headcount, BY ITEM (assign each dish to a person), and BY AMOUNT (type each share).",
    setup: [
      "Only available once the order is checked out (checkout or paying state).",
      "The split button appears only when the guest count is above 1.",
      "Each row's method list comes from the same payment policy as the normal payment dialog.",
      "The tendered field appears only on rows whose method requires cash to be counted.",
    ],
    usage: [
      "Pick a tab. Switching between tabs does NOT lose what you already entered.",
      "Equal: adjust the number of people. By item: pick a person, then tap dishes to assign. By amount: type each share.",
      "Per row: pick a method, type the tendered amount for cash, then press “Collect”.",
      "Once every row is collected the split-bill receipt screen opens.",
    ],
    checks: [
      "BY AMOUNT requires the rows to sum EXACTLY to the order total — no shortfall, no surplus.",
      "An empty tendered field means “exact”, and the field is pre-filled with the share. Typing less keeps the Collect button disabled, because the server would refuse it too.",
      "Changing a row's method clears that row's tendered amount.",
      "The tendered amount is capped at 99,999,999. One digit too many leaves the transaction permanently stuck on sync.",
      "Closing the dialog part-way does NOT lose collected rows — the tab stays open so you can continue; it closes only once the order is fully settled.",
      "To go back to a single collection, press cancel-split and the normal payment dialog reopens.",
    ],
  },

  "payment-receipt": {
    title: "Payment complete",
    subtitle: "会計完了",
    purpose:
      "The confirmation after the order is paid in full. It lists every transaction just recorded, the total collected, the tendered amount and the change, plus the two things you can do with paper: “Print receipt” and “Print payment receipt” (the copy that names the buyer).",
    setup: [
      "Both print buttons appear only when this terminal points at a workstation with a printer. Without one the screen is still correct, it just has no print buttons.",
      "The seller's tax registration number printed on the slip comes from the brand or branch settings. Not entered means no such line — that is legal, and the system does not warn about it.",
    ],
    usage: [
      "Read back the transaction rows and the total to the customer.",
      "Press “Print receipt” if they want one. The label changes to “Printed” and then “Reprint” as you press it again.",
      "Press “Print payment receipt” when the customer needs the copy that NAMES THE BUYER. Same content as “Print receipt” above, plus a name line.",
      "Press close — only then is the order tab closed.",
    ],
    checks: [
      "The tab DELIBERATELY waits for you to close this screen, so context is never lost mid-flow.",
      "Every reprint is written to the print history and the audit log. Paper is not free.",
      "If the order was not paid in full you get the amber “On account” screen instead of this one.",
    ],
  },

  "on-hold-receipt": {
    title: "On account",
    subtitle: "未収",
    purpose:
      "The end-of-collection screen when the shop did NOT receive the full amount — the customer still owes. It looks deliberately unlike the green “payment complete” screen, because a different state must look different.",
    setup: [
      "Only an order with a customer attached can end here; walk-ins must pay in full.",
      "The balance resurfaces in “Debt lookup” and at that customer's next payment.",
    ],
    usage: [
      "Read the reason the order is on account, stated at the top.",
      "Confirm the outstanding amount with the customer.",
      "Press close.",
    ],
    checks: [
      "This screen DELIBERATELY has no “Print receipt” and no “Print payment receipt” button — nothing is fully paid, so there is nothing to certify as paid.",
      "Do not mistake it for the green screen: amber means the shop extended credit.",
      "To collect the rest, reopen that customer's order or use “Debt lookup” when they return.",
    ],
  },

  "split-bill-receipt": {
    title: "Split-bill receipts",
    subtitle: "分割会計 完了",
    purpose:
      "The confirmation after EVERY row of a split-bill session has been collected. Print a receipt per person, or all of them in one go.",
    setup: [
      "Printing needs a workstation with a printer.",
      "Slips are printed sequentially with a pause between them so the thermal printer can cut.",
    ],
    usage: [
      "Select the rows you need printed.",
      "Press print — each row shows pending → printing → printed / failed.",
      "Close when done.",
    ],
    checks: [
      "Each person's slip is looked up by TRANSACTION ID, not by amount — an equal split gives several people the same amount, and matching by amount prints the wrong person's slip.",
      "One failed row does not block the others; reprint just that row.",
    ],
  },

  "print-result": {
    title: "Recorded — print?",
    subtitle: "印刷確認",
    purpose:
      "Shown after a balance (or document) has been RECORDED successfully, to ask the separate question “do you want paper?”. Recording and printing are two different decisions.",
    setup: ["Printing needs a workstation with a printer. Without one just close — the data is already saved."],
    usage: ["Press print if the customer wants paper.", "Press close if not."],
    checks: [
      "The write has ALREADY succeeded before this opens. Nothing here can lose money.",
      "A print failure is reported and the dialog stays open so you can retry — the record does not need redoing.",
    ],
  },

  "red-invoice": {
    title: "Payment receipt (named buyer)",
    subtitle: "領収書",
    purpose:
      "Prints the same slip as the paid receipt plus a BUYER NAME line. Used when the customer needs a document in their own name.",
    setup: [
      "Requires a workstation and a printer — this path prints DIRECTLY and writes no database record at all.",
      "The seller's tax registration number on the slip comes from brand / branch settings.",
      "During a split bill the slip targets exactly ONE payer, so it does not print the whole order.",
    ],
    usage: [
      "Enter the buyer's name if the customer gives one.",
      "Leaving it blank is fine — the printer leaves a ruled line to write on.",
      "Press print.",
    ],
    checks: [
      "No record is created. This is purely paper.",
      "This slip USED to be called a “red invoice” (赤伝 in Japanese). That name was dropped in #2062/#2070 because it claimed to be a STATUTORY document it is not: it carries no invoice number, is never stored, and enters no ledger. A customer who needs a real VAT invoice must not be handed this.",
      "The “printed ×N” badge says how many originals this scope already produced. NO badge means an older workstation cannot answer the question — do not read it as “not printed yet”.",
    ],
  },

  "debt-search": {
    title: "Debt lookup",
    subtitle: "未収検索",
    purpose:
      "Answers the shop-wide question “who owes us money, and how much”, and collects it when the conditions allow. The button lives in the top bar so the question can be asked with nothing else on screen.",
    setup: [
      "Only registered customers can carry balance; walk-ins cannot.",
      "The collection method list comes from the same payment policy as the normal payment dialog.",
    ],
    usage: [
      "Search by name or phone, or browse the list of customers with balance.",
      "Pick a customer to open their individual debt rows.",
      "Pick a row, pick a method, type the tendered amount for cash, and collect.",
    ],
    checks: [
      "To COLLECT, the shop must have a LIVE ORDER for that same customer (checked out or paying). This is a server rule, not a UI limitation.",
      "Without a live order the dialog says so and offers no button, rather than one that is guaranteed to fail.",
      "The original debt's order is always closed, so the collection lands on the current live order carrying a link back to the settled balance.",
    ],
  },

  "card-terminal": {
    title: "Card terminal",
    subtitle: "決済端末",
    purpose:
      "Drives the card reader at the counter through the workstation. The workstation is what talks to the reader and is also what RECORDS the payment on approval.",
    setup: [
      "A workstation is required. The reader sits behind the shop's NAT on the LAN; cloud has no route to it.",
      "The reader must be registered under the shop's payment devices.",
      "Without a workstation the button is disabled and a line under it says why.",
    ],
    usage: [
      "Press the card-terminal button.",
      "Hand the reader to the customer.",
      "Wait for the outcome. On approval the dialog closes itself and the order is refreshed.",
    ],
    checks: [
      "If NO outcome comes back, the dialog stays open and the order stays open. Do NOT charge again on a hunch — that is how a card gets taken twice.",
      "On approval the payment is recorded by the WORKSTATION. The POS creates no additional transaction.",
      "The “Cancel” button on the waiting screen cancels the reader session; it is not a refund.",
    ],
  },

  "cash-changer": {
    title: "Cash recycler (釣銭機)",
    subtitle: "釣銭機",
    purpose:
      "Takes cash through an automatic counting-and-change machine. The machine counts and dispenses, the workstation records the payment, and the POS only watches and refreshes the order.",
    setup: [
      "A workstation is required: the machine speaks unencrypted HTTP on the LAN behind an IP allowlist, so an HTTPS page cannot call it directly. A shop that wants a 釣銭機 installs a workstation.",
      "The start call sends only the ORDER ID — the amount is read server-side, because cash a machine physically counts must not be asserted by a client.",
    ],
    usage: [
      "Press the cash-recycler button in the payment dialog.",
      "Invite the customer to insert their cash.",
      "Watch the full-screen status until it ends.",
    ],
    checks: [
      "CANCEL and TIMEOUT are NOT the same: cancel means the machine RETURNED the cash; timeout / error / abort mean the machine KEPT it. The screen says so and deliberately does not look dismissible.",
      "On a successful finish the payment has already been recorded by the workstation. NEVER add a manual transaction — that charges the customer twice.",
      "If the outcome is unclear, check the machine's cash box before doing anything else.",
    ],
  },

  // ──────────────────────────────────────────────────────────────────────────
  //  Shift dialogs
  // ──────────────────────────────────────────────────────────────────────────
  "cash-event": {
    title: "Cash in / out during the shift",
    subtitle: "入金・出金",
    purpose:
      "Record cash moving in or out of the drawer for reasons other than sales: adding change, moving cash to the safe, petty expenses. Skip it and the close will be short or over by exactly that amount.",
    setup: ["Requires an open shift; it lives in the account menu in the top bar."],
    usage: [
      "Choose the direction: paid in or paid out.",
      "Enter the amount and the reason.",
      "Confirm. The close screen's reconciliation updates immediately.",
    ],
    checks: [
      "This is NOT revenue. It only adjusts how much cash the system expects to find in the drawer.",
      "Forgetting one withdrawal makes the shift short by exactly that amount, and you will be asked to explain the variance.",
    ],
  },

  "abandon-shift": {
    title: "Abandon a mis-opened shift",
    subtitle: "シフト破棄",
    purpose:
      "Discard a shift opened by mistake (wrong person, wrong count, wrong terminal). The shift is marked abandoned rather than settled.",
    setup: ["Only for a shift with NO payments recorded against it yet."],
    usage: ["Enter a reason.", "Confirm. The POS returns to the shift-open screen."],
    checks: [
      "A shift that already has payments is REFUSED by the server. In that case go through the normal close.",
      "It cannot be undone. To restart, open a new shift and count again.",
    ],
  },

  "shift-settle-confirm": {
    title: "Confirm settlement",
    subtitle: "精算確認",
    purpose:
      "The last confirmation before a shift is settled. It restates the three figures that matter: cash expected, cash counted, and the variance.",
    usage: [
      "Re-read the three figures in the summary.",
      "Read the NAME on the confirm button — it states whether you are handing over or closing.",
      "Confirm.",
    ],
    checks: [
      "HAND OVER keeps the chain open for the next cashier; CLOSE ends the chain and prints the chain-wide aggregate. Two different acts, so the two buttons carry different names.",
      "On success the POS goes straight to the shift-open screen; printing runs in the background so a cold printer cannot hold you there.",
      "A print failure only raises a warning — the shift is already settled and does not reopen because of it.",
    ],
  },
};
