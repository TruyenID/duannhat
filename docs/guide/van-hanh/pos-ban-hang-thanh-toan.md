---
title: "Operations — POS: selling and taking payment"
category: guide
tags: [pos-web, order, payment, non-technical]
summary: "Taking orders, editing and voiding items, merging and splitting tables, splitting bills, and the payment methods on the POS."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** Cashiers while selling.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## 12. Selling

### 12.1 The screen layout

**The top bar (PosHeader), left to right:**
- The title **Sell**
- The store name (with a store icon)
- **A clock ticking every second** plus the date
- **The connection badge**
  ([section 10.4](pos-cai-dat-mo-ca.md#104-check-the-connection-to-the-workstation-))
- A **History** button (a rewind-clock icon) →
  [Order history](pos-ket-ca-bao-cao.md#152-order-and-payment-history)
- A **Revenue** button (a chart icon) →
  [Revenue report](pos-ket-ca-bao-cao.md#151-the-revenue-report)
- The 🌐 language button
- **The user icon** → the shift menu, Settings and Sign out

**The tab bar:**
- The **Overview** tab (the table plan) — always present
- A **Takeaway** tab with a count
- One tab per **open order** (labelled with the order code; an order with no code
  yet shows *"Assigning a code…"*), each with a **✕**
- A **+** button at the end to create a new order

> The tabs are remembered on that machine — closing and reopening the browser keeps
> them.

**The content area:**
- **A wide screen (a landscape tablet or larger):** the menu on the left, the cart on
  the right (360-400px wide)
- **A narrow screen:** the cart collapses into a floating **"Cart"** button in the
  corner with an item count; pressing it slides it in from the right

### 12.2 The Overview tab — the table plan

*"Press a table in service to open its order tab. Press an empty table to create a
new order."*

**Four metric tiles:** In service · Open orders · Guests · Seats

**A zone filter:** starting with **All**, then each zone.

**A Takeaway card:** `{n} orders` plus a **View list** button

**A grid of table cards:** each shows the seat count, a **Has order** badge (if
applicable), a **New order** button, and a **⋮** menu with:
- **View history**
- **Change status** (Free / In use / Reserved / Being cleaned / Out of service)

The **Mark as cleaned** action reports *"Table marked as cleaned"* or *"The table
status could not be updated"*.

### 12.3 Creating a new order

Press **+** (or press an empty table on the Overview tab):

> **New order**
> *"Every field is optional. The table and guest count can be assigned later."*

| Field | Content |
|---|---|
| **Order type** | Three buttons: **Quick** (the default) · **Dine-in** · **Takeaway** |
| **Table** | **Several tables** can be chosen (a merged table). There is a **Clear all** button. Only **Free** or **Reserved** tables can be chosen. The zone used last time is remembered. |
| **Guests** | With a **Skip** button |
| **Guest phone** | Hints `0912345678`. An existing number attaches the order to the existing customer. A new one creates a new customer. Left blank means a walk-in. |
| **Notes** | *"A birthday, an allergy, a preference…"* |

The **Create order** button → the message *"Order created"*.

> ⚠️ The system **does not require a table**. Create still works with no table
> chosen.

### 12.4 Adding items

**The menu area only works while there is an open order in the "open" state.**

- A menu selector at the top (*"Choose a menu…"*)
- A search box (*"Find an item…"*)
- A grid of item cards

**An item card may carry these badges:** `Combo` · `{n} options` ·
`Tax included` / `Tax excluded` · `Happy Hour` *(with the line "Valid until
{time}")*

**An item with options (size, toppings)** opens the **Product options** dialog:

| Section | Content |
|---|---|
| **Variant** | Pick the size or kind |
| **Topping groups** | Clearly labelled: **Pick 1** / **Up to {n}** / **Optional** / **Required** / **default** |
| **Note for the kitchen** | *"less spicy, no onion, well done…"* |
| **Order summary** | The base price plus each topping = **Total** |

Common errors: *"Pick at least {n}"* / *"At most {n}"*

The **Add to cart** button. When editing an existing item the button becomes
**Save**, with the warning: *"To change the variant: void this item and add it
again."*

**Menu error messages:**
- *"No menu for today"*
- *"No menu has been activated today"*, with a fix hint: *"No branch menu has a
  schedule covering today. Add a menu schedule from HQ admin-web, or check whether
  the existing schedule includes today's weekday."*

### 12.5 The cart

**The header line:** the order code · a table badge (**Table {n}** or **No table**)
· the guest count · a **Cancel order** button

**The action buttons:**

| Button | What it does |
|---|---|
| **Assign table** | Assign a table to an order that has none |
| **Change table** | Move to a different table |
| **Merge table** | Add another table to the order (a merged table) |
| **Split table** | Remove a table from the order |
| **Look up debt** | See what this customer still owes |
| **Print provisional bill** | Print a provisional bill for the guest to look at |

**Each item row has:**
- Quantity up/down buttons
- **Edit** · **Edit toppings** · **Void item**
- **Update status** (Pending / Preparing / Ready / Served / Voided)
- Voided items are collapsed into **▸ Show {n} voided items**

**The totals table:**
```
Subtotal
Discount
Service charge     (with a sub-line, "Before tax")
Tax                (shown as "Tax 10%" or "Tax included 10%")
Rounding
──────────────────
Total
```

**Discount code:** an input hinting *"Enter a code (e.g. WELCOME10)"* plus an
**Apply** / **Remove** button.

There are around fifteen distinct coupon errors; the notable ones:
- *"The cart contains a Happy Hour item, which cannot be combined with a discount
  code."*
- A **"Remove the discount code to add this item?"** dialog with two buttons,
  **Remove code & add** / **Cancel**

### 12.6 Sending to the kitchen

The **Send to kitchen** button is a **floating, draggable round button** — place it
where your hand falls. Its position is remembered.

**It only sends what has not been sent.** Raising an item's quantity from 2 to 5
prints only the extra 3.

| Message | Meaning |
|---|---|
| *"{n} items sent to the kitchen"* | ✅ Printed successfully |
| *"{n} items sent to the KDS (no printer)"* | ✅ There is no printer but the KDS received them |
| *"Everything has already been printed"* | There is nothing new to send |
| *"No kitchen printer configured — open the workstation settings"* | ❌ The `kitchen_printer` role is missing |
| *"The workstation is syncing; try again"* | Wait a few seconds and press again |
| *"Sending to the kitchen failed. Check the printer."* | ❌ The printer has a problem |

Hover the button to see the status: `Kitchen: online/offline`, `Bar: …`, plus a
stale-sync warning.

### 12.7 Voiding an item and cancelling an order

| | **Void an item** | **Cancel the order** |
|---|---|---|
| Effect | The item stays on the bill but is **marked voided** (the kitchen and the accounts still see it) | The order is cancelled and **the table is freed** |
| Undo | No | **No** |
| Preset reasons | Guest changed their mind · Out of stock · Made wrongly · Guest unhappy | Guest changed their mind · Wrong order · System or device fault · Guest request |
| Mandatory | A reason | A reason, **meeting the minimum length** |

The note: *"Record the reason for audit purposes (at least {n} characters)."*

> ℹ️ **Closing the tab (pressing ✕) does NOT cancel and does NOT delete the
> order.** It only removes the tab from the strip — nothing is sent to the
> server, and the order stays open. Cancelling an order is only ever the
> **"Cancel order"** button, which takes a reason and leaves an audit trail.
>
> 👉 **How to reopen an order whose tab you closed:**
>
> | The order | The way back in |
> |---|---|
> | Has a table | Overview → tap the serving table |
> | Takeaway | Overview → **"Takeaway orders"** drawer |
> | **Quick (spot) or dine-in with no table** | **There is none** — see below |
>
> ⚠️ An order with **no table** that is not takeaway has no way back in once its
> tab is closed: it is on no grid and in no drawer. So the POS asks first —
> *"Order {code} has no table assigned, so once the tab is closed you will not be
> able to reopen it from the overview."* Press **Cancel**, give the order a
> table, and then close the tab if you want to be able to find it again.
> (Quick — **spot** — is the create-order dialog's default type, so this is the
> ordinary case, not a rare one.)

---

## 13. Taking payment

### 13.1 Step 1 — checkout

Press **Checkout** in the cart, then **Confirm order**.

> 🚫 **BLOCKED while any item is not yet "Served".**
>
> The message: *"{n} items have not been served ({item names}). Mark every item as
> 'Served' before checking out."*
> (It lists up to three item names and then adds `+N`.)
>
> 👉 The fix: in the cart, use **Update status** on each item → choose **Served**.
> 👉 To avoid doing this every time: Admin → Settings → Orders → card 1 → choose
> **提供済 / Served**
> ([section 4.1](admin-cai-dat.md#41-the-注文--orders-tab--card-1-the-default-item-status)).

### 13.2 Step 2 — taking the money

Press **Pay** → the **Payment** dialog:

**Choose the payment method** from the list. A method that cannot be used says
*"This method cannot be paid at the counter"*.

**The amount lines:**
```
This order          {amount}
+ Old debt ({n} orders)  {amount}     ← only when the customer owes something
──────────────────────────────
Total               {amount}
Tendered            [___]  [Exact]
Change:             {amount}
```

**If the customer has an old debt**, a panel appears:
> **This customer owes money**
> Phone · Orders · **Total old debt** · `+{n} more orders`
> ☐ **Settle this debt now**
>
> Ticked: *"The debt is added automatically to the total due"*
> Unticked: *"The customer will pay this debt on their next visit"*

**The "Allow the customer to underpay (record a debt)" toggle:**
> *"The unpaid portion is added automatically to the customer's next order"*

The underpayment warning:
> *"Remaining after this payment: {amount}. The order stays in 'Paying' and will
> reappear on the customer's next visit."*

> ⚠️ **A walk-in (with no customer details) MUST pay in full:**
> *"A walk-in order (with no customer) must be paid in full ({amount}). A debt
> cannot be held with no customer details — attach a customer first, or take the
> full amount."*

Press **Confirm payment** → *"Processing…"* → *"Payment successful"*.
On failure the button becomes **Retry**.

### 13.3 Step 3 — printing

The **Payment successful** screen shows: the customer · the phone · **Transaction
#{n}** · the tendered amount · the total paid.

| Button | What it prints |
|---|---|
| **Print receipt** | The guest's receipt. Reprints are numbered `#2`, `#3`… |
| **Issue a VAT invoice** | Enter: the **tax number** (hinting `0312345678`) · the **company name** · the **address** · an **email** (optional). Error: *"Invalid tax number"* |
| **Print a red invoice** | Enter the **customer name**. The note: *"Leave it blank to write it on the printed invoice by hand."* |
| **Print a debt slip** | Only when a debt was recorded |
| **Done** | Close the dialog |

> ⚠️ **If NO print buttons appear:** the POS cannot reach the workstation. Check the
> connection badge
> ([section 10.4](pos-cai-dat-mo-ca.md#104-check-the-connection-to-the-workstation-)).
> The print buttons hide themselves when there is no workstation.

### 13.4 Splitting the bill

Press **Split bill** — there are **three styles** on three tabs:

#### Style 1 — split evenly

| Element | Content |
|---|---|
| **Number of people** | Enter the headcount |
| Each row | The amount, a method selector and a **Take** button |
| The summary | **Sum of the shares** · **Difference from the order total** · **Remaining on the order** |
| After taking | Each taken row gains a **Refund** and a **Print receipt** button |
| Secondary button | **Pay as one** (cancels the split) |

**A drift warning:** *"The order total has changed since the split (it was {a}, it
is now {b}). Recalculate before continuing."* plus a **Recalculate the shares**
button

**A lock:** *"At least one share has been paid — the headcount can no longer be
changed."*

#### Style 2 — by item

Drag unassigned items onto each person. There is **Add person** / **Remove person**.
It shows `{remaining}/{total} left`, **All assigned**, **Take per person
({taken}/{total})** and **Edit the allocation**.

#### Style 3 — by amount

**Add person**, then enter an **amount** for each. At the bottom: **Allocated** /
**Due** / **Taken** / **Order total** / **Difference: {n}**.

The requirement: *"At least 2 rows are needed — for one person, use normal
payment."*

#### General notes on splitting

- **One order uses ONE split style.** Mixing styles reports: *"This order is already
  being paid with a different split style. Use the same style as the previous
  payment."*
- Other errors: the item does not exist · the item was voided · **two people both
  claim the same item** · the totals do not match · the total has drifted
- The split state is stored on that machine — reloading the page does not lose it

### 13.5 Refunds

> ⚠️ **At present a refund is ONLY possible from the Split bill screen** — the
> **Refund** button on each taken row.
>
> **There is no dedicated refund screen** for an ordinary payment. If an ordinary
> transaction needs refunding, ask IT or a manager to handle it in Admin.

Refunds support **partial refunds** (the full amount by default).
