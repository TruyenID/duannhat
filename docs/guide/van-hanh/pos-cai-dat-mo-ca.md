---
title: "Operations — POS: setup and opening a shift"
category: guide
tags: [pos-web, shift, setup, non-technical]
summary: "Setting up the till, checking the connection to the workstation, and opening the shift at the start of the day (counting the opening float, reconciling the gap)."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** Cashiers and whoever sets up the POS machine.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## 10. Setting up the POS

### 10.1 🔧 Preparation (IT's job)

The POS is a **web page**, not an installed app. But the workstation's address is
**baked in at build time** — **there is no field anywhere in the app for typing the
workstation's IP**.

Before deploying, IT builds the POS with two variables:

```
VITE_API_URL=http://api.tempo.local                  # the central server's address
VITE_WORKSTATION_API_URL=http://192.168.1.50:8080    # the workstation's LAN address (section 8.6)
```

Optional variables:

| Variable | Effect |
|---|---|
| `VITE_POS_API_MODE` | Pins the mode to `auto` / `workstation` / `cloud`. **When set, the mode selector still appears in the UI but pressing it does nothing.** |
| `VITE_SHOP_SLUG` | The default store code when the root address `/` is opened |
| `VITE_DEFAULT_LOCALE` | The default language sent to the server (the UI still defaults to Japanese) |

> ⚠️ **THE THREE BIGGEST TRAPS — read before deploying:**
>
> **1.** If `VITE_WORKSTATION_API_URL` is **not** set, the system defaults to
> `http://localhost:8080` — that is, **the tablet running the browser itself**. The
> print button still appears but fails when pressed. To disable LAN mode entirely,
> set the value to exactly **`none`**.
>
> **2.** `docker/pos-web.prod.Dockerfile` currently **does not accept** the
> `VITE_WORKSTATION_API_URL` variable. Building the POS with
> `compose.local-server.yml` without patching the Dockerfile leaves a POS that
> **cannot print and cannot run offline**. See
> [Appendix C.2](phu-luc.md#c2-a-pos-built-from-compose-local-server-has-no-workstation-address).
>
> **3.** A POS served over `https://` **cannot reach** a workstation over `http://`.
> See [Appendix C.1](phu-luc.md#c1-mixed-content--the-https-pos-cannot-reach-the-http-workstation).
>
> ⚠️ Changing any `VITE_*` variable requires **a rebuild**, not a restart.

### 10.2 Opening the POS on the till

1. Open a browser (a recent Chrome / Safari / Edge).
2. Go to the POS address
   ([section 1.4](rollout-quan-moi.md#14-the-web-addresses-to-know)), for example
   `http://pos.tempo.local`.
3. **Bookmark it or add it to the home screen** so it opens quickly next time.
4. Press 🌐 → your language.

> 🚫 **TURN OFF the browser's page translation** (Google Translate). It freezes the
> POS or produces a blank screen. There are countermeasures but they are not
> perfect.
>
> 📷 **The POS cannot use the camera** — the pairing code must be **typed by hand**;
> it cannot be scanned. (This page blocks the camera, microphone, GPS, USB and
> Bluetooth for security reasons.)
>
> 🔤 The POS loads its fonts from Google Fonts. A machine with no Internet at all
> falls back to system fonts, and Vietnamese diacritics may render poorly on
> Windows.

### 10.3 Pairing the POS

On first opening, the POS goes straight to the **Pair POS device** screen:

| Element | Content |
|---|---|
| Title | **Pair POS device** / *POS デバイスをペアリング* |
| Subtitle | *Enter the 6-character code generated in the admin screen* |
| Input | Hints *6-character code*, upper-cases automatically, accepts letters and digits only, in very large type |
| Button | **Connect** / *Connecting…* while it works |
| Footnote | *The pairing code can be generated on the device management page* |

The **Connect** button only activates once **exactly 6 characters** are entered.

> 🌐 **The POS machine MUST have Internet while pairing** — even in a store that runs
> mostly over the LAN.

**The error table:**

| Message | Cause | What to do |
|---|---|---|
| *"Invalid or expired code"* | The code is wrong or already used | Generate a new one in Admin (**コードを再発行**) |
| *"The code has expired. Generate a new one"* | More than 15 minutes have passed | Generate a new one |
| *"Connection failed. Please try again"* | A network or other error | Check the Internet and retry |

### 10.4 Check the connection to the workstation ⭐

After pairing, look at the **top right** — there is a **round coloured badge**. This
is the most important thing on the POS screen:

| Badge | Colour | Meaning | Can it print? | Does it work offline? |
|---|---|---|---|---|
| **LAN** | 🟢 Green | Running through the workstation | ✅ | ✅ |
| **Cloud (auto)** | 🟡 Yellow | The workstation is unreachable; temporarily running over the Internet | ❌ | ❌ |
| **Cloud** | 🔵 Blue | Manually set to run over the Internet only | ❌ | ❌ |
| **Disconnected** | 🔴 Red | Nothing is reachable | ❌ | ❌ |
| **Checking…** | ⚪ Grey | Probing; wait a few seconds | — | — |

**Press the badge** to open the **API route** detail panel:

- A monospace line showing **the address in use** (`LAN: http://…` or
  `Cloud: http://…`) — **read only, not editable**
- **Last checked: {time}**
- **Three modes:**
  | Mode | Behaviour |
  |---|---|
  | **Automatic (LAN → Cloud)** ✅ | Prefers the workstation; falls back to the Internet on failure. **Recommended.** |
  | **Workstation only (LAN)** | No fallback. A broken workstation makes every action fail (deliberately, so the fault surfaces immediately) |
  | **Cloud only (over the Internet)** | Bypasses the workstation entirely. **Cannot print.** |
- **Request counts since the page loaded**: over LAN / over Cloud, with error counts
- A **Test connection** button and a **Reset counters** button

> ℹ️ Changing the mode clears the cache and reloads all the data — normal, and
> nothing is lost. (This is deliberate: without the clear, a cashier with an open
> shift would be told "no shift is open" after switching mode.)

**The automatic fallback mechanism (Automatic mode):**

```
Call the workstation → wait at most 3 seconds
   ├─ It answers        → use the result, badge 🟢 LAN
   └─ No answer         → mark it "unreachable" for 30 seconds
                        → call Cloud instead (waiting at most 15 seconds)
                        → badge 🟡 Cloud (auto)

Every 30 seconds the POS retries the workstation (waiting at most 3 seconds).
A successful retry returns it to 🟢 LAN immediately.
When the machine regains its network, it retries at once rather than waiting.
```

> 📌 Only a **network error** triggers the Cloud fallback. If the workstation answers
> with an error (say "no shift is open"), the POS does **not** ask Cloud — that
> answer is correct.

**If the badge is 🟡 Cloud (auto) while the workstation is running:** see the
[checklist in section 19.2](bao-tri-su-co.md#192-checklist-when-the-pos-loses-the-workstation).

### 10.5 POS settings (there is only one page)

Press the **user icon** in the top right → **Shift close report settings**.

That page holds just **five toggles** deciding which blocks the shift-close slip
prints (in the order they print):

| # | Block | What is printed |
|---|---|---|
| 1 | **Per-rate tax detail** 🔒 | Revenue and tax broken down by rate (8% reduced / 10% standard) |
| 2 | **Payment methods** | A breakdown per method (cash, PayPay, card, wallet…): count plus amount |
| 3 | **Service charge** | The service-charge line |
| 4 | **Cash count** | Counted cash, expected cash, over/short, and who did it |
| 5 | **Denominations** | The count of each denomination at the closing count |

- Each toggle **saves immediately**
- It applies to **every POS machine in the store**
- It takes effect **from the next print** after the workstation syncs

The footnote reads: *"Applies to every terminal in this shop. Turning a block off
means it will not print on the shift-close slip. Changes take effect from the next
print after the workstation syncs."*

> 🔒 **Only a signed-in manager can change the "Tax detail" toggle.**
> Pressing it from a POS machine reports: *"Only a signed-in user (a manager) can
> change the tax block of the shift-close report. Please change it in admin-web."*
> and the toggle reverts.
> 👉 Change it at **Admin → Settings → 注文 → 税 → 精算レポートに税率別内訳を表示**.

**Things the POS does NOT have:**

| What you are looking for | Where it is |
|---|---|
| ❌ Printer setup | **Workstation → Devices** |
| ❌ Renaming the device | **Admin → Devices** (the name comes from pairing) |
| ❌ Choosing a till | Not needed — the system determines it, **one till per branch with the code `MAIN`** |
| ❌ Entering the workstation address | Baked in at build time |
| ❌ Currency, tax, service charge | **Admin → Settings** |
| ❌ Changing the language | The 🌐 icon in the top bar (not inside Settings) |
| ❌ Changing the connection mode | Press the connection badge in the top bar |

### 10.6 Signing out / unpairing the POS

The **user icon** → **Sign out** (the red line).

The machine returns to the pairing screen and needs a **new code** to be used again.

**Automatic sign-out:**

| Situation | What the POS does |
|---|---|
| One or two consecutive auth errors | It does **not** sign out. Any successful call resets the counter. (Deliberate — a restarting workstation rejects the token for a few seconds) |
| **Three consecutive** auth errors | Sign out, back to the pairing screen |
| **Plugging store A's POS into store B's workstation** | ⚠️ **The session is cleared IMMEDIATELY**; it must be paired again |
| The device is **revoked** in Admin | The next call fails → after three failures it signs out |

---

## 11. Opening a shift

The POS **will not let you into the selling screen** until a shift is open — it
redirects to the Open shift page.

Path: `/shop/{store}/shift/open`
Breadcrumb: **Cashier › Open shift**

The yellow warning when there is no shift: *"Device **{machine name}** has no open
shift. Open a shift to enter the selling screen."*

The subtitle: *"Create the opening report before you start selling. Counting the
cash in the drawer and entering it accurately is mandatory."*

### 11.1 Card 1 — shift information

An **Automatic** badge sits in the corner of the card.

| Field | Content |
|---|---|
| **Store** | Filled from the device; not editable |
| **Device** | Filled from the device; not editable |
| **Opened by** | **Chosen from a list** — see below |
| **Opened at** | Filled automatically and **frozen when the page opens** (format `YYYY/MM/DD HH:MM:SS`) |

**The "Opened by" field has three groups of choices:**

1. **Me (signed in)** — the default
2. **Store staff** — the staff list, shown as `Name ・ email`
3. **Other…** — choosing this reveals a name field, hinting *"The name of the person
   opening on someone's behalf"*, up to 255 characters

> If the staff list fails to load, a yellow note says: *"The staff list could not be
> loaded (Me / Other still work)."*

### 11.2 Card 2 — reconciling payments taken during the handover ⭐

> **This card only appears when there IS something to reconcile.** Not seeing it
> means there is nothing to do.

**What is this?** Between the previous shift **closing** and this one **opening**
there is a window in which no shift is open. Guests can still pay while the cashier
is counting the drawer. Those payments have not been attributed to any shift.

The yellow title: *"Reconcile payments taken during the handover"* plus a
`{n} payments` badge

The description: *"Payments taken between the previous shift closing and this one
opening (currently attributed to no shift). Select the ones that belong to this
shift to attribute them."*

**Each row shows:**
- A tick box
- The order code
- The amount
- The time (`YYYY/MM/DD HH:MM`)
- A yellow **Cash — held separately** badge *(if it was cash)* or the payment method
  name

**Ticking ANY cash row** reveals a mandatory yellow acknowledgement box:

> **Confirm the cash is held separately**
> *"The selected cash must be held separately by staff — not mixed into the opening
> float — so that it is not counted twice at the cash count."*
>
> ☐ **I confirm the cash above is held separately and is not part of the opening
> float.**

**That tick box BLOCKS the open-shift button** until it is ticked.

> 🚨 **EXTREMELY IMPORTANT — the cash ticking rule:**
>
> ✅ **TICK** when the money really is **kept separately** (an envelope, a separate
> compartment, a separate box) and is **NOT** part of the amount you just counted in
> Card 3.
>
> ❌ **DO NOT TICK** when the money has already gone into the drawer and was counted
> in Card 3.
>
> Ticking wrongly = **counting the money twice** = a **fake overage** at shift close.
>
> ℹ️ An unticked payment **is not lost** — it reappears at the next shift for
> reconciliation.

### 11.3 Card 3 — counting cash by denomination

**The currency cannot be changed** — it is shown as a pill, `{CODE} ・ {name}`, with
a gear icon whose tooltip says: *"The currency is configured in the shop's Settings.
Contact an administrator to change it."*

The note: *"Count each denomination accurately. This total is the baseline used at
shift close. The currency ({currency}) comes from the shop's Settings."*

**The table has three columns:** `Denomination ({CODE})` · `Count` · `Amount`

**Two groups:** **Notes** then **Coins**.

**Each row has:**
- A **−** button (greyed out at 0)
- A **count field** (digits only, stray characters stripped, rounded to an integer
  ≥ 0)
- A **+** button

**Special states:**
- Loading: *"Loading denominations…"*
- None available: *"There are no denominations for this currency."* → ⚠️ ask a
  manager to check
  [Admin → Settings → 金種](admin-cai-dat.md#413-the-金種--denominations-tab--cash-denominations-)

**The running total bar at the bottom:**

> **Opening cash balance**
> `{n} notes and coins ・ {a} notes ・ {b} coins`
> **{LARGE AMOUNT}**

**A Notes field** (optional, two lines, up to 2000 characters) — hinting: *"e.g.
handed over from the night shift, 2,000₫ short from yesterday…"*

### 11.4 Pressing open shift

Two buttons at the bottom:

| Button | Effect |
|---|---|
| **Cancel** | Returns to the selling screen (which immediately pushes you back here) |
| **Open shift & print report** 🖨️ | Opens the shift. While it works it shows *"Opening shift…"* |

**The "Open shift" button ONLY activates when all three conditions hold:**

```
✅ At least one denomination has a count above 0
✅ A name has been entered if "Other…" was chosen for Opened by
✅ The cash acknowledgement is ticked (if any cash row was ticked in Card 2)
```

### 11.5 After the shift opens

| Result | What you see |
|---|---|
| ✅ Success | *"Shift opened."* → the opening slip prints → the selling screen |
| 🔗 Continuing a chain | Plus the message *"Continuing the chain — shift {n} (recount the float)"* |
| ⚠️ A shift is already open | *"A shift is already open on this till."* → you are taken to the selling screen |
| ❌ Another error | *"The shift could not be opened."* or a specific message from the server |

> 🖨️ **Printing the opening slip is best-effort.** If the printer fails, the shift
> **still opens successfully** and the print error is silently ignored.

**Understanding "Continuing the chain — shift N":** the previous shift was **handed
over** (not finally closed), so the chain of shifts is still open and you are shift
N within it. **You must still count the cash from scratch** — the system
deliberately does not carry the previous shift's amount over, so that each cashier
is responsible for their own discrepancy.

### 11.6 If the system closes the shift mid-way

If the shift expires or a manager force-closes it while you are selling, the POS
shows a **blocking screen that cannot be dismissed** (ESC and clicking outside do
nothing):

> ### Shift expired
> *"Your shift has been closed by the system (through inactivity, or by a manager).
> Press Confirm to open a new shift."*
>
> **[ Open a new shift ]**

Press the single button to start a new shift.

> ℹ️ **The money taken is NOT lost.** It stays in the old shift and appears in the
> reports. A manager will **manually settle** the old shift in Admin
> ([section 16.4](admin-giam-sat-ca.md#164-manually-settling-an-expired-shift)).

### 11.7 The error screen when the shift cannot be loaded

If the POS cannot retrieve shift information, it shows a **"Could not load the
shift"** screen with a **Retry** button:

| Error | Meaning |
|---|---|
| *"No store found for this device."* | The device is not attached to the right branch |
| *"This device is not permitted to access this store."* | The POS belongs to another branch |
| *"Could not load the shift information. Please try again."* | A network error |
