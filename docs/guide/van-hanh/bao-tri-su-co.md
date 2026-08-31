---
title: "Operations — maintenance, troubleshooting, wall checklists"
category: guide
tags: [maintenance, troubleshooting, backup, non-technical]
summary: "Daily, weekly and monthly tasks, backups, the checklist for a lost connection, and checklists to print and pin to the wall."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** Store managers and whoever is on call.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## 18. Maintenance and backups

### 18.1 What the workstation maintains by itself

You **have to do nothing** — the app runs the following on its own:

| Task | Interval | Retention |
|---|---|---|
| **Database backup** | Every **6 hours** | **The 7 most recent** (~42 hours of history) |
| Clean the write-ahead log | Every hour | — |
| Clean the sync queue | Every 6 hours | 7 days *(only rows already sent successfully are removed)* |
| Clean the audit log | Every 24 hours | 90 days |
| Clean the deduplication locks | Every hour | 24 hours |

The backups live in `~/.ws-app/backups/`.

> 💾 **Worth adding:** IT should copy the `~/.ws-app/` directory to an external drive
> or a NAS **once a week**. The automatic backups only cover 42 hours — not enough
> for an incident discovered late.

### 18.2 Routine tasks worth doing

**Daily:**
```
□ Check the POS connection badge is 🟢 LAN
□ Check the workstation has no red sync banner
□ Check the printers have paper
□ Perform the final close and keep the slip
```

**Weekly:**
```
□ Admin → レジ管理: look at the 7-day discrepancy chart for any abnormal trend
□ Admin → シフト履歴: check whether any shift is 破棄 (abandoned) or 失効 (expired)
□ POS → revenue report → the "Voids" tab: check whether the void rate has risen abnormally
□ Admin → デバイス: check the 最終接続 column of every device
□ IT: copy the ~/.ws-app/ directory to external storage
□ Restart the workstation machine (outside opening hours) to free memory
```

**Monthly:**
```
□ Test-print on every printer
□ Check whether the workstation's IP has changed
□ Admin → Settings → 金種: check the denomination list is still correct
□ Admin → Settings → 決済方法: remove payment brands no longer accepted
□ Check the size of ~/.ws-app/ws-app.db (abnormal growth = tell IT)
```

### 18.3 The order to roll out an update 🔧

> ⚠️ **Always update the server (backend) FIRST, and only then the workstation.**
>
> A newer workstation against an older server **runs in a degraded mode** (no errors,
> but missing features). The other way round can break.

The recommended order:
```
1. The server (backend)
2. Admin (admin-web)
3. The workstation (WS App)   ← restart the app
4. The POS (rebuild plus reload the page on the till)
5. KDS / kiosk / handheld / TMS
```

### 18.4 Checking the running version

| Software | Where to look |
|---|---|
| Workstation | **Settings → the About card** → the line `WS App v{version}` |
| Kiosk | **Settings → Workstation** → the line `v{version} · WS ✓/✗ · branch {code}` |
| The other apps | Ask IT |

---

## 19. Troubleshooting

### 19.1 The quick reference table

| Symptom | Common cause | What to do |
|---|---|---|
| **The POS prints nothing** | The connection badge is 🟡 Cloud or 🔴 red | 1. Check the workstation is on<br>2. Check they are on the same WiFi<br>3. Press the badge → **Test connection**<br>4. Still nothing → follow [19.2](#192-checklist-when-the-pos-loses-the-workstation) |
| **The POS is always 🟡 Cloud (auto)** although the workstation is on | The workstation address is misconfigured (baked in at build time) or the LAN is down | Tell IT — the POS must be rebuilt with the right `VITE_WORKSTATION_API_URL` |
| **The POS shows no print buttons at all** | No workstation address is configured → the print buttons hide themselves | Tell IT ([C.2](phu-luc.md#c2-a-pos-built-from-compose-local-server-has-no-workstation-address)) |
| **The printer produces nothing on a test print** | Powered off / out of paper / the wrong IP / a different network | Print the printer's self-test page to see its real IP and correct it in Workstation → Devices |
| **Kitchen tickets print but receipts do not** | The `receipt_printer` role is not assigned | Workstation → Devices → edit the printer → also tick **レシート / Receipt** |
| **Vietnamese prints without diacritics** | Thermal printers have no Vietnamese font | Normal and unfixable. Consider naming items without diacritics. |
| **The Star printer does not cut the paper** | Somebody enabled Kanji mode `FS &` | A configuration fault — tell IT; the source deliberately leaves it off |
| **A shift will not open — "A shift is already open"** | The previous shift was not closed | Close it, or a manager uses **強制終了** in Admin |
| **"Shift expired" appears mid-sale** | The system closed the shift, or a manager force-closed it | Press **Open a new shift**. Ask a manager to manually settle the old one. No money is lost. |
| **The "Close shift" button is greyed out** | Nothing has been counted, or a difference exceeds the threshold with no reason | Enter the cash counts; write the reason in the **Notes** field |
| **Checkout is blocked** | Some items are not yet "Served" | Update each item's status, or change the [card 1 setting](admin-cai-dat.md#41-the-注文--orders-tab--card-1-the-default-item-status) |
| **The currency cannot be changed in Admin** | A shift or a **chain of shifts** is open | The cashier must perform a **final close** (not a handover), then wait about a minute |
| **The tax or rounding settings cannot be changed** | The same | The same |
| **The POS says "No menu for today"** | The menu has no schedule covering today | Admin → HQ → Menus → Schedules |
| **Items show as `(unknown)`** | The downloaded menu data is incomplete | Wait for the workstation to sync (every 5 seconds); if it persists, tell IT |
| **Workstation: the menu is empty after pairing** | The branch **has no timezone** set in Cloud | Admin → HQ → Shops → 店舗情報を編集 → **タイムゾーン** |
| **Pairing says "This code is for a different device type"** | The wrong device type was created | Recreate the device with the right type |
| **Pairing says "Invalid or expired code"** | More than 15 minutes, or the code was already used | Admin → **⋮** → **コードを再発行** |
| **Repeated pairing attempts get blocked** | The limit of 5 per minute | Wait a minute |
| **The workstation shows a red banner, "N operations could not reach the server"** | A network interruption or a data error | Press **View recovery**. Any row with a red **Payment** badge → **tell IT immediately**; do not press "Discard" |
| **Unpairing the workstation is blocked** | There is unsynced money | Restore the network and wait for the sync. If you must proceed → tick the acknowledgement and use **強制解除** |
| **The POS suddenly returns to the pairing screen** | It was plugged into another branch's workstation, or the device was revoked in Admin | Check whether the device is still **有効/Active** |
| **The POS goes blank or behaves oddly** | The browser's page translation is on | **Turn off Google Translate** and reload |
| **A device's "最終接続" is long ago** | The machine is off, the network is down, or the token was revoked | Check that machine |
| **The KDS makes no sound** | The chime file is currently silent (a known bug) | Rely on the on-screen notification. Remember to press **音声テスト** once on an iPad. |
| **The KDS is slow to receive new orders (up to 15 seconds)** | It is in Cloud mode with no workstation | Set the KDS back to **Automatic** or **LAN** mode |
| **The kiosk cannot find the workstation** | The "Local Network" permission has not been granted on the iPad | Grant it in the iPad's settings. Or type the address by hand in the kiosk's settings |
| **The kiosk hangs after a payment** | The payment terminal is stuck | Kiosk settings → **レシート再印刷（復旧）** or **端末リセット** |
| **The handheld does not work** | The workstation is off (the handheld has no Cloud fallback) | Turn the workstation on |
| **TMS shows no tables** | No Internet (TMS talks straight to Cloud) | Check the Internet |
| **A guest reports "The menu will not load"** | A known bug in the guest web | **Tell IT** — see [C.10](phu-luc.md#c10-known-bugs-in-the-guest-web) |

### 19.2 Checklist when the POS loses the workstation

Work through it in order and stop at the first step that fails:

**Step 1 — is the workstation running?**
Go to the machine, open **WS App**, and see whether the Dashboard appears.
❌ It will not open → restart the machine.

**Step 2 — what is the LAN address?**
Workstation → **Dashboard → the LAN Server card → the URL line**. Note it, for
example `http://192.168.1.50:8080`.

**Step 3 — can the POS machine reach it?**
On the POS machine, open a **new browser tab** and enter that address **plus
`/api/lan/health`**:
```
http://192.168.1.50:8080/api/lan/health
```
- ✅ Some text containing `"status":"ok"` appears → **the network is fine** and the
  problem is the POS configuration → **tell IT** (the POS needs rebuilding)
- ❌ The page will not load → **the problem is the network or the firewall** → go to
  step 4

**Step 4 — are they on the same network?**
Compare the POS machine's IP with the workstation's. **The first three groups must
match** (for example, both on `192.168.1.x`).
❌ They differ → the two machines are on different networks (one may be on the guest
WiFi). Move them onto the same one.

**Step 5 — has the workstation's IP changed?**
If the router hands out dynamic IPs, a reboot can change it → every existing
configuration is then wrong.
👉 **Ask IT to give the workstation machine a static IP (a DHCP reservation).**

**Step 6 — is a firewall blocking it?**
🔧 IT should check that the workstation machine has **TCP 8080** open inbound.

### 19.3 When to call IT IMMEDIATELY

```
🔴 The workstation has a dead sync row carrying a "Payment" badge
🔴 Unpairing is blocked because there is unsynced revenue
🔴 An unusually large, unexplainable cash discrepancy
🔴 The figures in the Admin report DO NOT match the slip the POS printed
🔴 The same order was charged twice
🔴 A guest reports they cannot open the menu from the QR
🔴 The system moved a shift a cashier is actively working to "expired"
```

In these cases, **do not try to fix it yourself** — any "corrective" action can
destroy the audit trail.

### 19.4 What to record when reporting an incident

To let IT act quickly, provide:
```
□ Which device? (POS-Counter1 / WS-Main / KDS-Kitchen…)
□ The exact time it happened
□ The order code or shift code involved
□ A screenshot of the error message (verbatim)
□ What colour is the connection badge?
□ Was anything printed? (photograph it)
□ What have you already tried?
```

---

## 20. Checklists to print and pin to the wall

### ☀️ START OF SHIFT (opening)

```
□ 1. Check the workstation machine is ON
      → the icon at the bottom of the left menu reads "online"
      → there is no red banner at the top of the page

□ 2. Check the printers: paper in, a green light, no red blinking

□ 3. Open the POS; the badge in the top right MUST BE 🟢 LAN
      → if it is 🟡 Cloud or 🔴 red: TELL THE MANAGER NOW, do not start selling

□ 4. Count the cash in the drawer

□ 5. POS → Open shift:
      □ Choose who is opening
      □ If the "Reconcile handover payments" panel appears:
          → Tick only the payments that BELONG to this shift
          → Cash: ONLY tick it if the money is KEPT SEPARATE and not in the drawer
          → Tick the "held separately" acknowledgement
      □ Enter the count of each denomination
      □ COMPARE the on-screen total with what you counted by hand
      □ Add a note if anything is unusual
      □ Press "Open shift & print report"

□ 6. Keep the opening slip

□ 7. Check the kitchen KDS is on and showing the right branch
```

### 🌙 END OF SHIFT (closing)

```
□ 1. Make sure every order is paid or accounted for

□ 2. Take the daily summary (日計) from EVERY card and QR payment device

□ 3. Count ALL the cash in the drawer

□ 4. POS → the user icon → Close the shift:
      □ Enter the count of each denomination
      □ Enter "Loose change / adjustment" if there is odd-denomination cash
      □ Enter the revenue and voids for EACH payment brand
      □ Look at the "Difference" box:
            🟢 green  → fine
            🔴 red    → SHORT → write the reason in the Notes field
            🟡 yellow → OVER  → write the reason in the Notes field
      □ PRESS THE RIGHT BUTTON:
            Handing over to another shift?  → "Hand over the shift"
            Closing for the day?            → "Final close"
      □ READ the confirmation dialog's title carefully
      □ Re-check the three figures: expected / counted / difference
      □ Press confirm

□ 5. Take the close slip from the printer and clip it to the device summaries

□ 6. If nothing printed → ask a manager to reprint the Z report from Admin

□ 7. DO NOT switch the workstation machine off
      DO NOT unplug the workstation machine
```

### 🔁 HANDOVER MID-DAY

```
THE OUTGOING CASHIER:
□ Count the cash
□ Close using the "Hand over the shift" button (NOT "Final close")
□ Give the handover slip and the drawer to the incoming cashier

THE INCOMING CASHIER:
□ Count the cash again from scratch — do NOT look at the previous shift's figure (a blind count)
□ Open a new shift and enter what you just counted
□ Seeing "Continuing the chain — shift N" is CORRECT
□ Sign the handover slip
```

### 🚨 WHEN THE NETWORK GOES DOWN

```
□ Look at the POS badge:

   🟢 LAN  → NORMAL, keep selling
              (the Internet is down but the workstation is running)

   🟡 Cloud (auto) → the workstation has a problem
              → you can sell but CANNOT PRINT
              → tell the manager; write a receipt by hand if the guest needs one

   🔴 Red   → YOU CANNOT SELL
              → WRITE THE ORDERS ON PAPER
              → ⛔ NEVER RELOAD THE PAGE (F5)
              → wait for the network and re-enter them

□ When the network returns:
      → check the badge is back to 🟢 LAN
      → Workstation → Sync: wait for the "pending" count to reach 0
```
