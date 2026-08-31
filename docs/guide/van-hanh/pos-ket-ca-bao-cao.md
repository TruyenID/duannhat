---
title: "Operations — POS: during the shift, closing it, and reports"
category: guide
tags: [pos-web, shift, report, non-technical]
summary: "Paying cash in and out mid-shift, handing a shift over, the end-of-day final close (精算), and the reports available on the POS itself."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** Cashiers and shift leads.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## 14. During the shift and closing it

### 14.1 The shift management menu

Press the **user icon** in the top right. The **SHIFT / Shift management** section
only appears **while a shift is open**:

| Icon | Entry | Description | Where it goes |
|---|---|---|---|
| 🔒 | **Close the shift** | *Settle the shift and print the report* | The close page *(hidden while you are already on it)* |
| 💵 | **Cash in / out** | *Record money in or out mid-shift* | A dialog |
| 🚫 | **Abandon the shift** | *Discard a shift opened by mistake with no payments* | A dialog |

Below the divider (always present): **Shift close report settings** and **Sign out**.

### 14.2 Cash in / cash out

Use this when taking money out of, or putting money into, the drawer **mid-shift**.

> **Cash in / out**
> *"Record money in or out of the drawer mid-shift. It updates the expected amount
> on the close screen immediately."*

| Field | What to enter | Required |
|---|---|---|
| **Transaction kind** | **Cash in** (money added, a green down arrow) or **Cash out** (money removed, a yellow up arrow). Defaults to **Cash in**. | ✅ |
| **Amount** | Digits and a decimal point only. **Must be greater than 0** | ✅ |
| **Reason** | Up to 2000 characters. Hint: *"e.g. urgent packaging purchase, a customer refund…"* | ✅ |
| **Reference** | Up to 100 characters. Hint: *"e.g. the supplier invoice number"* | — |

**Errors:**
- *"The amount must be greater than 0."*
- *"A reason is required."*

Press **Record** → *"Cash in recorded."* or *"Cash out recorded."*

> 💡 **Record EVERY time money leaves the drawer.** Missing one means the shift
> reports a **shortage** and you have to explain it.
> The **Expected cash** figure on the close screen updates **immediately**.

The fields clear themselves each time the dialog closes.

### 14.3 Abandoning a shift

> **Abandon the shift**
> *"Only for a shift opened by mistake (with no payments taken). This cannot be
> undone."*
> *(That sentence appears twice — once in the description, once in the red box — on
> purpose.)*

| Field | What to enter |
|---|---|
| **Reason** | Three lines, up to 2000 characters. Hint *"Reason for abandoning (recommended)"*. **Not technically required**, but you should write one. |

Press the red **Abandon shift** button → *"Shift abandoned."* → you are taken to the
open-shift screen.

> ⛔ **If the shift already has payments, the system BLOCKS it:**
> *"Cannot abandon — this shift already has payments. It must be closed rather than
> abandoned."*

---

### 14.4 Handover or final close? — understand this first

| | 🔁 **Handover** | 🔒 **Final close** |
|---|---|---|
| **Use it when** | Changing shift mid-day — cashier A hands over to B | The day is over / the store is closing |
| **The effect** | This shift is settled, but **the chain stays open** | **The whole chain is settled** |
| **The next shift** | Continues the same chain (shift 2, shift 3…) | Starts a **new chain** |
| **The printed slip** | The **引き継ぎ / Handover** slip (one shift) | The **精算 / Close** slip plus the **chain summary slip** (every shift in the chain added up) |
| **Does it unlock the settings?** | ❌ No — currency / tax / rounding **stay locked** | ✅ Yes — they unlock once it is settled |

> 💡 **A store with one shift a day should always use "Final close".** A chain of one
> is that day's 精算 slip, exactly as before.
>
> 🔗 **What is a chain of shifts?** It is a run of consecutive shifts on one till
> during the day, linked by **handovers** and ended by a **final close**. The chain
> summary slip adds every shift together — one summary block per shift plus a
> **GRAND TOTAL** line.
>
> ⚠️ **Abandoning, expiring or manually settling a shift BREAKS the chain.** The
> next shift starts a new chain, and the broken shift **is not counted in the chain
> summary slip**.

### 14.5 The shift-close screen, section by section

Reached from the **user icon → Close the shift**. Path `/shop/{store}/shift/close`.

The subtitle: *"Reconcile the POS figures, the physical cash in the drawer and the
Stera 日計 to settle the shift."*
The back button: **Back to the POS**.

If the shift is part of a **chain**, a **Chain — shift {n}** badge sits next to the
title.

> 🛡️ **A safeguard:** this page only opens while the shift is `open` or `closing`.
> If the shift has been settled elsewhere, force-closed or expired, the POS says
> *"There is no open shift to close."* and returns you to the open-shift page —
> **before you have counted anything**.
>
> 🔒 **The currency is taken from when the shift opened**, not re-read from the
> settings. If a manager changes the currency mid-shift, the discrepancy is still
> computed correctly.

---

#### Section 1 — shift information (read only)

| Line | Meaning |
|---|---|
| **Shift code** | This shift's code |
| **Started at** | `YYYY-MM-DD HH:MM` |
| **Opening float** | The cash you counted when you opened |
| **Cash revenue** | The cash taken during the shift |
| **Cash in** | The total of all cash-in entries |
| **Cash out** | The total of all cash-out entries |
| **Expected cash** | ⭐ **What should be in the drawer** *(in bold)* |

The formula:
`Expected cash = opening float + cash revenue + cash in − cash out`

---

#### Section 2 — the closing cash count

**Two live summary boxes at the top of the card:**

**Box A — cash reconciliation**
*"The cash you count below, compared with the expected cash in the drawer."*
| Counted | Expected | **Difference** |

**Box B — payment-device revenue reconciliation** *(only shown when there are
non-cash payments)*
| Your entered total | Device revenue (the system's figure) | **Device difference** |

**What the difference colours mean:**

| Colour | Marker | Meaning |
|---|---|---|
| 🟢 Green | `±0 {currency}` plus a ✓ | It matches (within the allowed threshold) |
| 🔴 Red | A negative number plus ⚠️ | **SHORT** |
| 🟡 Yellow | A positive number plus ⚠️ | **OVER** |

> The allowed threshold is set on the till (**0** by default, so even a one-unit
> difference must be explained).

**If the device difference exceeds the threshold**, an input appears inside box B:
> *"Reason for the discrepancy (required)"*

That one reason covers every payment device.

**Then comes the denomination count table** (identical to the one at shift open).

**Finally the "Loose change / adjustment" field:**
> *"Cash that does not fit the denomination table (loose change). It is added to the
> counted total so the difference matches the real drawer."*

The formula: `Counted cash = the denomination total + loose change/adjustment`

---

#### Section 3 — payment device reconciliation

> *"Enter the revenue and void figures from each payment device's daily summary
> (日計)."*

The revenue the system recorded is shown **once at the top of the card** for
comparison.

It is grouped (**Card** / **QR** / **E-money** / any custom groups — **cash is
excluded, since it was counted in section 2**). Each group shows a **Σ net**.

Each payment brand is a row:
```
{Brand name}    [Revenue ___]  [Voids ___]   net {computed automatically}
```

---

#### Section 4 — order summary

| Card | Content |
|---|---|
| ⚪ Grey | **Paid orders (counted in this shift)** — the count plus the total |
| 🟡 Yellow | **Unpaid orders (carried to the next shift)** — the count, with the note *"Carried over automatically."* |

> ✅ **Unpaid orders need NO action from you.** They carry over automatically.
> Closing the shift only counts paid orders.

---

#### Section 5 — notes

An input of up to 2000 characters.

> ⚠️ **This field doubles as the "cash discrepancy reason" field.**
> If the cash difference exceeds the threshold, **you must write here** before the
> close button will work.

---

### 14.6 The three buttons at the bottom

The bottom bar always shows: **counted versus expected** · the counted total · **a
large coloured difference badge**.

| Button | Use it when |
|---|---|
| **Save draft** | The count is unfinished and you want to continue later. **The shift is NOT settled.** Reports *"Draft saved."* |
| **Hand over the shift** | Handing over to the next shift, keeping the chain open |
| **Final close** *(the primary button)* | Settling completely and printing the summary report |

**The Handover and Close buttons are LOCKED when:**
```
❌ No denomination count has been entered (everything is zero)
❌ A difference exceeds the threshold with no reason written
```

When the reason is missing, a warning banner appears:
> *"There is a discrepancy above the threshold — please enter a reason before
> confirming."*

Submitting with nothing counted: *"The closing cash count is mandatory."*

### 14.7 The final confirmation dialog

It shows a padlock icon, then:

| | Handover | Final close |
|---|---|---|
| Title | *"Hand over the shift?"* | *"Settle the chain?"* |
| Body | *"Settle the current shift, print the handover slip, and open the next shift in the same chain."* | *"Settle the chain of {n} shifts and print the summary slip. The next shift opened will start a new chain."* |
| Confirm button | **Confirm & hand over** | **Confirm & final close** |

The yellow warning:
> ⚠️ *"This action cannot be undone. Check the figures below before confirming."*

**A summary table for one last check:**
```
Expected cash    {n}
Counted cash     {n}
Cash difference  {n}
```

> 💡 **The two buttons are deliberately worded differently.** They used to be
> identical, which caused serious confusion. Read the dialog title carefully before
> pressing.

### 14.8 After the shift is closed

1. The success message:
   - Handover: *"Shift handed over. The chain is still open."*
   - Final close: *"Final close complete. The chain is closed."*
2. **You are taken straight to the open-shift screen** — it does not wait for the
   printer
3. The printer runs **in the background**

> 📌 Switching screens before printing is **deliberate** — waiting for the print
> would let a slow printer freeze the cashier's screen.

**If printing fails you still see a message, but THE SHIFT WAS SETTLED
SUCCESSFULLY:**

| Message | Meaning |
|---|---|
| *"The shift was settled, but no printer is configured — the report was not printed."* | The `receipt_printer` role is missing |
| *"The shift was settled, but the workstation is offline — …"* | The workstation connection was lost |
| *"The shift was settled, but printing the report failed."* | The printer has a problem |

👉 **The fix: reprint from Admin** using **Zレポートを印刷**
([section 16.5](admin-giam-sat-ca.md#165-reprinting-the-z-report)).

**Errors while settling:**

| Error | Meaning |
|---|---|
| *"A discrepancy reason is required — check the marked rows."* | A reason is still missing somewhere |
| *"The shift is already closed (conflict) — reloading."* | Somebody just settled this shift on another machine |
| *"Closing the shift failed."* | A general error |

---

## 15. Reports on the POS itself

Cashiers and shift leads can see reports without going into Admin.

### 15.1 The revenue report

Press the **chart icon** in the top bar. Path `/shop/{store}/reports/revenue`.

There are **three views** on three tabs:

#### The "By time" tab

**Choose a range:** **By day** / **By month** / **By year**, with **◀ / ▶** buttons
to step back and forward, plus a **custom range** picker (with quick buttons: the
last 7 days, the last 30 days…).

**Four KPI tiles:**
| Tile | Content |
|---|---|
| **Total revenue** | With a "versus the previous period" line |
| **Orders** | |
| **Guests** | |
| **Revenue per guest** | |

**The revenue chart** — you can:
- **Scroll to zoom in and out**
- **Drag to pan**
- Use the **← → arrow keys**
- Toggle **Show guests** and **Trend line**
- Use the **Zoom in / Zoom out / Reset** buttons

**Side cards on the right:**
- **Average revenue by weekday** — which day of the week is busiest
- **Payment methods** — the share of each

**A detail table** with the columns: Day/Month/Year · Weekday · Revenue · Orders ·
Guests · Revenue per guest · **Total**. With full pagination (first / previous /
next / last, plus a rows-per-page selector).

#### The "By product" tab

**Filters:** **Category** (with *All categories*) · the date range · **By product**
or **By variant (SKU)** · **Sort** by *Revenue* or *Quantity sold*

**Three metric tiles:** total revenue · total quantity · number of products

**The table:** product name · category · quantity · revenue · **Share**
(the percentage contributed)

#### The "Voids" tab ⭐

> 💡 **This tab is very useful for spotting loss and fraud.**

**Four metric tiles:**
| Tile | Content |
|---|---|
| **Cancelled orders** | How many orders were cancelled |
| **Voided items** | How many items were voided |
| **Order cancellation rate** | As a percentage of settled orders |
| **Total loss** | The value voided |

**A "Voids over time" chart** — with a **Trend line** and a **Value line** toggle.

**Three analysis tables:**
- **Most-voided items** — item / count / value
- **Item void reasons** — a breakdown by reason
- **Order cancellation reasons** — a breakdown by reason

**The void log** — a detailed table of each void: time · **kind** (item void / order
cancellation) · order code · content · reason · value. Filterable by kind. An order
with no code yet shows *"code not yet assigned"*; a whole-order cancellation shows
*"Whole order · {n} items"*. A missing reason shows *"No reason recorded"*.

### 15.2 Order and payment history

Press the **rewind-clock icon** in the top bar. Path `/shop/{store}/reports/history`.

- The title carries `{n} orders` and `· {amount} taken`
- Choose a period: **Day** / **Month** / **Year**, with **Previous** / **Next**
  buttons
- A **Refresh** button
- The order list on the left; click one to see its details on the right (*"Select an
  order to see its details"*)
- A **Show more ({remaining})** button to load further orders
- Order type labels: **Dine-in** / **Takeaway**
- A **Location:** line showing which table
- When empty: *"There are no orders in this period"*
