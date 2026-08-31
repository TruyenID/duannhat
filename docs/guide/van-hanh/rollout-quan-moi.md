---
title: "Operations — overview and the new-store rollout"
category: guide
tags: [setup, rollout, non-technical, overview]
summary: "Understand the system in five minutes, what to prepare beforehand, and the A-to-Z route for opening a new store."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** Whoever is opening a new store, and anyone who wants to
> understand the whole system before reading the detailed sections.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## 0. Understand the system in five minutes

The system is several pieces of software running on several kinds of machine, but
there are only **two data paths**:

```
                        ☁️  CLOUD (the Internet)
                     ┌──────────────────────┐
                     │   The central server   │
                     │   (holds all the data) │
                     └───┬──────────────┬───┘
        Path 1: direct   │              │  Path 1: direct
     ┌───────────────────┘              └──────────────┐
     ▼                                                  ▼
┌──────────┐  ┌──────────┐                     ┌──────────────┐
│ ADMIN    │  │ TMS      │                     │ CUSTOMER WEB │
│ (web)    │  │ (tablet) │                     │ Guests scan  │
│ Managers │  │ Tables   │                     │ a QR + order │
└──────────┘  └──────────┘                     └──────────────┘


                     ┌──────────────────────┐
                     │  🖥️ WORKSTATION       │  ← one computer in the
                     │  (the in-store box)   │     counter, ALWAYS ON
                     │  Bridge + printers    │
                     │  + offline data store │
                     └───────────┬──────────┘
             Path 2: via the box │ the local WiFi (LAN)
        ┌────────────────┬───────┴────────┬─────────────────┐
        ▼                ▼                ▼                 ▼
   ┌─────────┐     ┌──────────┐    ┌───────────┐     ┌──────────┐
   │ 💰 POS   │     │ 👨‍🍳 KDS   │    │ 🖨️ PRINTER│     │ 🧾 KIOSK │
   │ Cashier │     │ Kitchen  │    │ Kitchen/  │     │ Guest    │
   │         │     │ screen   │    │ bar/bill  │     │ self-serve│
   └─────────┘     └──────────┘    └───────────┘     └──────────┘
```

**Path 1 — straight to Cloud.** Admin, TMS and the guest web talk to the server
directly over the Internet. No Internet means they cannot be used.

**Path 2 — through the workstation.** The POS, the KDS, the kiosk and the printers
talk to the workstation sitting in the store. The workstation keeps a copy of the
data and syncs it up to Cloud afterwards. **Losing the Internet does not stop
sales.**

### Who does what

| Software | Runs on | Used by | Purpose |
|---|---|---|---|
| **Admin** (admin-web) | A browser on a computer | Managers, brand owners | Menus, store settings, devices, tables, reports, shift monitoring |
| **Workstation** (WS App) | One fixed computer in the store | Installed once and left running | Drives the printers, holds data during an outage, acts as the LAN bridge |
| **POS** (pos-web) | A browser on the till or a tablet | Cashiers | Open a shift, take orders, take payment, print, close the shift, revenue reports |
| **KDS** (godx-kds) | A tablet in the kitchen | Chefs | See what needs cooking, mark it done |
| **Kiosk** (godx-kiosk) | A machine in the lobby | Guests | Order and pay for themselves |
| **TMS** (tms-app) | A tablet or phone | Waiting staff | View and change table statuses |
| **Handy** (godx-handy) | A phone | Waiting staff | Take orders at the table |
| **Customer Web** | The guest's phone | Guests | Scan the QR on the table → order |

### Four golden rules

1. **The workstation must always be on.** Turning it off means the POS loses
   printing and loses the ability to work during a network outage.
2. **Every device must be paired once** with a 6-character code from Admin. **The
   code lives 15 minutes and is single-use.**
3. **A settled shift CANNOT be undone.** Check carefully before confirming.
4. **Never change the currency, the tax mode or the rounding rule while a shift is
   open.** The system will block it — and blocking is correct.

---

## 1. Before you start

### 1.1 Hardware

| Device | Minimum requirement | Important notes |
|---|---|---|
| **The workstation machine** | A Mac mini, a Windows PC or a small Linux box | Permanently powered. **A UPS is strongly recommended** — a sudden power cut can corrupt the database. |
| **The POS machine** | A computer or tablet with a modern browser (recent Chrome / Safari / Edge) | The buttons are designed at 44-48px so they take a fingertip well. On a narrow screen the cart collapses into a floating button. |
| **Printer** | A **StarPRNT** thermal printer (e.g. the Star mC-Print3), connected by **LAN** or **USB** | **80mm** paper (the default) or **58mm**. A pure Epson printer may not cut the paper correctly. |
| **Kitchen tablet (KDS)** | iPad Safari 16.4+ or Android Chrome 90+ | An external speaker is recommended so the new-order chime is audible |
| **WiFi router** | Must allow mDNS/multicast, with every machine on one subnet | **Do not use a Guest WiFi network** for the store's devices |

### 1.2 Networking

- **Every device must be on the same LAN** (the same first three IP octets, e.g.
  everything on `192.168.1.x`). No VLAN separation.
- The workstation machine should have a **fixed IP**. Ask IT to set a "DHCP
  reservation" on the router. If the IP changes after a reboot, the POS and the
  printers lose their connection.
- 🔧 **IT:** open the workstation machine's firewall for **TCP 8080** (inbound) and
  **UDP 5353** (mDNS).
- 🔧 **IT:** on an iPad or iPhone, iOS 14+ requires the "Local Network" permission —
  the app must declare `NSLocalNetworkUsageDescription` and `NSBonjourServices`.

### 1.3 Accounts and permissions

A manager needs the organization's **SSO** account (signing in through
`id.godx.jp`).

The system has five role levels:

| Role | Level | Why it matters |
|---|---|---|
| **Org Admin** | 100 | Full organization rights |
| **Org Manager** | 80 | Manages several stores |
| **Shop Manager** | 60 | **The minimum level for viewing or operating shift management (Till)** |
| **Staff** | 30 | Cannot enter shift management |
| **Shop Staff** | 10 | Cannot enter shift management |

Details worth knowing:

- **Devices:** *anyone* who can sign in may create, pair or revoke devices in their
  own organization. **There is no role check.** Be careful who gets an account.
- **Shift management (Till):** **Shop Manager and above** only. Anyone else who
  visits the URL sees a 403 warning panel inside the page (the menu entry is still
  visible; it is not hidden).
- **Tables:** anyone can change a table's **status**. But **creating, editing and
  deleting** a table requires Shop Manager. The interface **does not hide the
  buttons** — ordinary staff still see "Create table" but pressing it returns a 403.

### 1.4 The web addresses to know

There are **three deployment styles**. Ask IT which one you are on.

**Style A — the server is in the store (a Mac mini with `.local` names):**

| Software | Address to open in a browser |
|---|---|
| Link index page | `http://tempo.local` |
| Admin | `http://admin.tempo.local` |
| POS | `http://pos.tempo.local` |
| Guest web | `http://shop.tempo.local` |
| KDS (kitchen) | `http://kds.tempo.local` |
| Kiosk | `http://kiosk.tempo.local` |
| Server API | `http://api.tempo.local` |
| Workstation | `http://<the-workstation-IP>:8080` |

**Style B — running in the Cloud (AWS Amplify, Tokyo region):**

| Software | Address |
|---|---|
| Admin | `https://main.d3cqu96a6b470f.amplifyapp.com` |
| POS | `https://main.d3nuz12zp9crpd.amplifyapp.com` |
| Production server | `https://tempo.godx.jp` |

> ⚠️ If the POS is served over `https://`, it **cannot print through the
> workstation** (the browser blocks it). See
> [Appendix C.1](phu-luc.md#c1-mixed-content--the-https-pos-cannot-reach-the-http-workstation).

**Style C — a development machine:** Admin `:5430` · POS `:5440` · Guest web
`:5450` · KDS `:5460` · Kiosk `:5480` · API `:5400` · Workstation `:8080`.

### 1.5 Change the language BEFORE doing anything else

All three main applications **display Japanese by default**. Switch language right
away:

| Software | How to change it | Stored as |
|---|---|---|
| **Admin** | Top right → the 🌐 icon → your language | The `app_locale` cookie |
| **Workstation** | The top bar → 🌐 → your language | `app_locale` on that machine |
| **POS** | The top bar → 🌐 → your language | `pos_locale` on that machine |

Choose once and that machine remembers.

> 💡 **The POS's language also decides the language printed on slips.** Choose
> Vietnamese and the shift-close slip prints in Vietnamese; choose Japanese and it
> prints in Japanese (精算 / 引き継ぎ).

> Throughout this handbook, button names are written as **English** *(日本語 /
> Tiếng Việt)* so you can find them whichever language the screen is in.

> ⚠️ **A few places show hard-coded English no matter which language you choose** —
> the full list is in
> [Appendix C.8](phu-luc.md#c8-some-interface-labels-are-not-translated).

---

## 2. New-store rollout, A to Z

Follow this order so you never have to go back and redo something.

### Stage 1 — prepare the data (in Admin, at HQ brand level)

```
□ 1.1  商品タイプ / Product types        → /hq/{brand}/product-types
□ 1.2  カテゴリー / Categories            → /hq/{brand}/categories
□ 1.3  税区分 / Tax types                 → /hq/{brand}/tax-types
          ⚠️ One type MUST be set as the default (デフォルト)
          A brand with no tax type at all computes 0% tax WITHOUT any error
□ 1.4  トッピンググループ / Topping groups → /hq/{brand}/topping-groups   (if needed)
□ 1.5  商品 / Products                    → /hq/{brand}/products
          Assign: the product type, categories, tax type
          Alcoholic drinks: assign the standard tax type like any other product
□ 1.6  SKUs (variants)                    → /hq/{brand}/products/{id}/skus
          ⚠️ Each product needs AT LEAST ONE SKU before it can be sold
□ 1.7  メニュー / Menus                    → /hq/{brand}/menus
          Add items to the menu and set an APPLICABLE SCHEDULE
          ⚠️ A menu with no schedule covering today makes the POS say "No menu for today"
□ 1.8  支払方法 / Payment methods          → /hq/{brand}/settings/payment-methods
          Enable at least one method
□ 1.9  店舗情報 / Store information        → /hq/{brand}/shops → pick the shop → 店舗情報を編集
          ⚠️ The タイムゾーン (timezone) is MANDATORY — leaving it blank makes the
             workstation pull an EMPTY menu
□ 1.10 Push the menu down to the store    → inside the HQ menu, use clone-to-branch
```

### Stage 2 — configure the store (Admin, store level)

```
□ 2.1  設定 → 注文 / Settings → Orders
          Default item status · Quick orders · Currency
          Service charge · Tax · Rounding · Cooking policy · Table status after payment
□ 2.2  設定 → 金種 / Denominations
          ⚠️ CHECK THIS CAREFULLY — cashiers count cash against exactly this list
□ 2.3  設定 → 決済方法 / Payment methods (cross-check)
          List only the brands the store REALLY accepts — the fewer, the faster the shift close
□ 2.4  テーブル / Tables
          Create zones → create tables (or take the sample tables from HQ)
□ 2.5  メニュー / Menu (store level)
          Confirm the menu is Active · toggle items · set store-specific prices
□ 2.6  Print the QR posters for the tables
```

### Stage 3 — devices (Admin plus on-site work)

```
□ 3.1  Create a `workstation` device in Admin → take the code
□ 3.2  Install the WS App on the workstation machine → edit config.json → pair it
□ 3.3  Add the printers in the WS App → assign the 4 roles → TEST PRINT each one
□ 3.4  Note the workstation's LAN address (Dashboard → LAN Server → URL)
□ 3.5  🔧 IT builds/configures the POS with that LAN address
□ 3.6  Create a `pos` device → pair the POS → check for the 🟢 LAN badge
□ 3.7  Create a `kds` device → pair the kitchen tablet
□ 3.8  Create `kiosk` / `tms` / `handy` devices if they are used
```

### Stage 4 — a trial run before opening

```
□ 4.1  POS: open a trial shift with a small float
□ 4.2  Create one table order, adding an item with toppings
□ 4.3  Press "Send to kitchen" → check the kitchen ticket prints and the item appears on the KDS
□ 4.4  Move every item to "Served" → Checkout → Pay
□ 4.5  Print the bill → check the store name, tax and total are right
□ 4.6  Try splitting a bill two ways
□ 4.7  Final close → check the printed close slip contains all 5 enabled blocks
□ 4.8  Admin → レジ管理: check the shift you just ran shows the right numbers
□ 4.9  Unplug the store's Internet cable → try creating an order, paying and printing
          → everything must still work (the POS badge must stay 🟢 LAN)
□ 4.10 Plug the network back in → Workstation → Sync: wait for the "pending" count to reach 0
□ 4.11 Scan a table QR with a phone → check the guest web opens the right menu
```

> 🚨 **Do not skip step 4.9.** It is the single most important test — it proves the
> store can still sell when the Internet is cut. If the POS switches to 🟡 Cloud
> when you unplug, the LAN configuration is **wrong**.
