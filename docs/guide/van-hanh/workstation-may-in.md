---
title: "Operations — the workstation and the printers"
category: guide
tags: [workstation, printer, setup, non-technical]
summary: "Installing the workstation on the in-store machine, first-run configuration, pairing it with the system, and setting up the receipt and kitchen printers."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** The technician or manager setting up the in-store
> machine.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## 8. Installing the workstation

> 🔧 Sections 8.1-8.2 are **IT's** job. From 8.3 onwards a store manager can do it.

The application's display name is **WS App**.

### 8.1 🔧 Installing the software

| Operating system | How to install | Application name |
|---|---|---|
| **macOS** | Copy `ws-app.app` into the `Applications` folder | **WS App** |
| **Windows** | Run the installer (NSIS or MSIX) | `ws-app.exe`, shown as **WS App** |
| **Linux** | Install the `.deb`/`.rpm` package → it lands at `/usr/local/bin/ws-app` with a menu shortcut | `ws-app` |

Linux needs the `libgtk-3-0` and `libwebkit2gtk-4.1-0` libraries.

**Running without a GUI** (a server, an ARM box, Docker): use the **`ws-server`**
build — identical, but it opens no window.

**Building from source:**

```sh
cd workstation-app
make build          # build the frontend + Go → build/bin/ws-app
make build-all      # build for macOS ARM/Intel, Linux ARM/Intel, Windows
make test           # run the tests
```

> ⏱️ The first build takes **5-10 minutes** because the UI libraries are downloaded.
> To change only Go code: `go build -o build/bin/ws-app ./cmd/workstation/` (~10
> seconds).
> ⚠️ **The trailing `./cmd/workstation/` is mandatory** — omitting it produces a
> file that will not run (`exec format error`).
> `make dev` needs the wails3 tool:
> `go install github.com/wailsapp/wails/v3/cmd/wails3@latest`

### 8.2 🔧 First-run configuration

On its first run the app creates the `~/.ws-app/` directory:

| Path | Content |
|---|---|
| `~/.ws-app/config.json` | The configuration file (mode 0600 — readable only by the machine's owner) |
| `~/.ws-app/ws-app.db` | The database — **every order, menu and offline payment** |
| `~/.ws-app/backups/` | Automatic backups, every 6 hours, keeping 7 copies |

The contents of `config.json`:

```json
{
  "store_name": "Tempo Shinjuku",
  "store_address": "...",
  "server_port": 8080,
  "cloud_api_url": "https://tempo.godx.jp",
  "branch_id": "",
  "order_number_prefix": "",
  "database_path": ""
}
```

| Field | Meaning |
|---|---|
| `cloud_api_url` | The central server's address. **It defaults to `https://tempo.godx.jp` (the real production server)** — a freshly installed machine will pair into production. Change it if the store uses its own server. |
| `store_name` | The store's name. It is also the name shown when other machines discover the workstation on the network. |
| `server_port` | Defaults to `8080`. A value outside **1024-65535** is reset to 8080 with a warning logged. |
| `branch_id` | The branch code — used only for the mDNS advertisement |

> ⚠️ **The "Store Information" fields in the app's Settings screen DO NOT save.** You
> must edit `config.json` by hand and then **restart the app**. See
> [Appendix C.3](phu-luc.md#c3-the-store-information-fields-in-settings-do-not-save).

**Environment variables as an alternative** (if you would rather not edit the file):

| Variable | Effect |
|---|---|
| `WS_APP_CLOUD_URL` | The central server address (it takes precedence over config.json on first initialization) |
| `WS_APP_SERVER_PORT` | The workstation's port |
| `WS_APP_CONFIG_DIR` | Use a different configuration directory (instead of `~/.ws-app`) |

### 8.3 Opening the app for the first time

Open **WS App**. A window titled **"WS App - Workstation"** (1280×800) appears.

If it is not paired yet, **the entire screen is the pairing screen** — nothing else
is reachable.

👉 First press the 🌐 icon in the top right and choose your language.

### 8.4 Pairing the workstation with the system

The **デバイス登録 / Device registration** screen:

The instruction line reads
*「管理画面でデバイスを作成し、表示されたコードを入力してください」* (Create the device
in the admin screen and enter the code it shows).

1. In Admin, create a device of type **`workstation`** and take the 6-character code
   ([Section 7](admin-menu-ban-thiet-bi.md#7-creating-and-pairing-devices)).
2. Type the code into the **ペアリングコード / Pairing code** field — it hints
   `ABC123`, **upper-cases automatically**, strips stray characters, and accepts at
   most 6 characters.
3. Press **Enter** or the **ペアリング / Pair** button.
4. On success → a green ✓ plus the device name for about 1.5 seconds, then the app
   opens.

**The error table:**

| Message | Cause | What to do |
|---|---|---|
| *"This code is for a different device type ({type})"* | The wrong type was created in Admin | Go back to Admin and create a **workstation** device |
| *"Pairing failed. Check the code and try again"* | Wrong code / expired (>15 minutes) / already used | Press **コードを再発行** in Admin |
| *"cloud_api_url is not configured"* | Somebody deleted the server address from config.json | Fix `config.json` ([8.2](#82--first-run-configuration)) |
| *"cloud API unreachable"* | The workstation machine cannot reach the Internet | Check that machine's network |
| *"invalid cloud response"* | The server returned incomplete data (a missing branch id) | Report it to IT — usually a problem with the branch data in Cloud |

> 🔒 Pairing can only be attempted **5 times a minute**. Pressing too fast is
> temporarily blocked.

Immediately after a successful pairing the app downloads, in order: **the menu → the
zones → the tables → 30 days of order history**. Wait a few tens of seconds before
checking.

**If the app suddenly returns to this screen mid-shift:** the device has been
revoked, or the Cloud data was reinitialized. An orange explanatory banner appears:
*「この端末はクラウドとの接続が切れました…注文と会計が同期されていません。新しいペアリングコードを入力して、注文の受信とレシート印刷を再開してください」*
👉 Get a new code from Admin and pair again. **Unsynced data is still on the
machine.**

### 8.5 The workstation's screens

| Left-hand menu | Path | Purpose |
|---|---|---|
| **Dashboard** | `/` | Orders in progress, today's orders, today's revenue, devices online, **and the LAN address** |
| **Orders** | `/orders` | View orders and **reprint** kitchen tickets, hold slips and receipts |
| **Menu** | `/menu` | View the downloaded menu |
| **Devices** | `/devices` | **Printer setup** → [Section 9](#9-printer-setup) |
| **Reports** | `/reports` | Daily reports |
| **Sync** | `/sync` | The sync status with the server |
| **Settings** | `/settings` | Auto-printing, KDS, unpairing |
| *(hidden)* Sync Recovery | `/sync-recovery` | Handling stuck data — reachable only through the red banner or the button on the Sync page |
| *(hidden)* API docs | `/docs` | For IT |

### 8.6 Dashboard — getting the LAN address (very important!)

**Four metric tiles:** orders in progress (アクティブ注文) · today's orders
(本日の注文) · today's revenue (本日の売上) · devices online (オンラインデバイス,
shown as `online/total`).

It refreshes every **15 seconds**, and immediately whenever an order is created,
edited or paid.

**The LAN Server card** → the **URL** line, for example:

```
http://192.168.1.50:8080
```

📝 **WRITE THIS ADDRESS DOWN.** IT needs it to configure the POS, kiosk and KDS.

With the note
*「同じネットワーク上のタブレット・スマホからこのURLに接続できます」* (Tablets and
phones on the same network can connect to this URL).

It is also visible on the **Sync page → the LAN Server card**, which additionally
shows: **IPアドレス** · **ポート** · **URL** · **接続クライアント** (how many clients
are connected).

**The 操作 / Actions card** holds three shortcuts: **新規注文** · **デバイス管理** ·
**レポート表示**.

### 8.7 Auto-print settings

Go to **Settings** → the **レシート印刷 / Receipt printing** card:

| Toggle | Japanese | When ON | When OFF |
|---|---|---|---|
| Auto-print receipts | レシート自動印刷 | The receipt prints the moment payment is taken (and when a paid order syncs down from Cloud) | It prints only when the **Print Receipt** button is pressed |
| Auto-print kitchen tickets (dine-in) | 厨房伝票の自動印刷（イートイン） | Orders guests place themselves through a **table QR** **automatically print a kitchen ticket and a hold slip** on arrival and on every additional order | Staff must press print manually |

> Both are **OFF by default**.
> **Orders created on the POS ALWAYS require a manual print** (the "Send to kitchen"
> button), regardless of these two toggles.

### 8.8 KDS (kitchen display) settings

Go to **Settings** → the **KDS — Kitchen Display** card:

| State | Meaning |
|---|---|
| **ON** | The KDS **only shows items that have been "sent to the kitchen"** (a staff press on the POS or handheld) |
| **OFF** (the default) | The KDS shows items **the moment staff add them to the order** |

Toggling it shows a confirmation warning that items currently displayed may be
hidden (turning it on) or reappear (turning it off).

> 💡 **Turn it ON** if the store wants the kitchen to start only once staff have
> committed the order. **Turn it OFF** if the kitchen should see items immediately so
> it can prepare early.

### 8.9 Watching the sync

**Sync is entirely automatic — there is no "sync now" button.**

| Task | Interval |
|---|---|
| Push data up to the server (orders, payments, shifts, table statuses) | **5 seconds** (or immediately when there is new work) |
| Pull the menu, tables and zones down | **5 seconds** |
| Pull the branch settings down | **5 seconds** |
| Pull the slow data (lots, customers, promotions, staff, payment methods…) | **5 seconds** |
| Pull guest orders and their items down | **5 seconds** |
| Check the Internet connection | **10 seconds**, backing off to at most 5 minutes while offline |

**The status is visible in three places:**

1. **At the bottom of the left menu bar** — a WiFi icon plus `online`/`offline` plus
   the number of pending tasks (a yellow badge). Click it to open the Sync page.
   Updates every 5 seconds.
2. **The Sync page → the クラウド同期 / Cloud Sync card** (updates every 3 seconds):
   - **保留中の操作** — operations waiting to be sent
   - **失敗した操作** — failed operations (in red)
   - **デッドレター** — operations that are dead for good
   - **レート制限 → 一時停止** — when the server is rate-limiting
3. **The warning banner at the top of every page:**
   - 🔴 Red: *"{n} sync operations could not reach the server"* plus
     *"{m} of them involve payments and need reconciliation"* plus a **View
     recovery** button
   - 🟡 Yellow: *"Sync paused — rate-limited, resuming shortly"*

**When something has failed:** the **再試行 / Retry** button appears on the Sync page
(**only when there are errors**). Press it to requeue them → it reports
*「{n} 件の操作を再試行キューに追加しました」*.

**The sync log** (Sync page → 同期ログ): the 100 most recent events, filterable by
**すべて / クラウドへ (up) / 端末へ (down) / kds / LAN (kiosk-pos) / 接続**. Each row
shows the operation, the record count, the HTTP code, the attempt number, the
latency in ms, and a ✓ or ⚠.

### 8.10 Handling stuck data (Sync Recovery)

Go to **/sync-recovery** (through the red banner or the Sync page button). Titled
*「同期リカバリ」* — *"Operations that could not reach the server. Handle each one by
hand."*

Each row shows: a red **Payment** badge if money is involved, the operation name, why
it died, the record id, and the last error.

**Three choices:**

| Button | Japanese | What it does |
|---|---|---|
| Recreate in Cloud | クラウドに再作成 | For orders only — recreates that order in Cloud |
| Retry | 再試行 | Puts it back on the queue |
| **Discard** | 破棄 | ⚠️ **That data will NEVER reach the server** |

When nothing is stuck: *「滞留中の操作はありません — すべて同期済みです」*

> 🚨 **A row with a red `Payment` badge involves money. Do NOT press "Discard".
> Report it to IT.**

### 8.11 Viewing orders and reprinting

Go to **Orders**. Two filter buttons: **処理中 / In progress** and
**会計済み / Paid**. It refreshes every 10 seconds, and immediately on a new order.

Select an order in the left column → the panel on the right has three print buttons:

| Button | What it prints |
|---|---|
| **Print Kitchen Ticket** | The kitchen ticket. **It only prints what has not been printed** — increasing an item's quantity prints only the increase. It prints separately per group (kitchen / bar / hold). |
| **Print Hold** | The summary slip for the runner, with the order QR |
| **Print Receipt** | The guest's receipt |

> ⚠️ **Use the workstation's Orders screen only for REPRINTING.**
> The status buttons (`Start Preparing`, `Mark Ready`, `Mark Served`,
> `Record Payment`) use the old status vocabulary. Orders arriving from Cloud or the
> kiosk **do not show those buttons** at all, only the three print buttons. Do the
> day-to-day work on the POS. See
> [Appendix C.9](phu-luc.md#c9-the-workstation-orders-screen-uses-the-old-status-vocabulary).

### 8.12 Reports on the workstation

Go to **Reports**. Pick a date at the top of the page (today by default).

**Four tiles:** `Total Orders` · `Paid Orders` · `Revenue` · `Avg Order Value`.

**The `Popular Items` table** — the top 10 sellers: item / category / quantity /
revenue.

> ℹ️ **There is no print or export button on this page.** For a more detailed
> report, use the [POS reports](pos-ket-ca-bao-cao.md#15-reports-on-the-pos-itself)
> or Admin.

### 8.13 Unpairing the workstation

Only do this when: **the machine is being replaced, the store is changing, or IT
asks for it.**

Go to **Settings** → the **ペアリング済みデバイス** card → the red
**ペアリング解除 / Unpair** button.

**Case 1 — everything has synced:**

The confirmation dialog *「デバイスのペアリングを解除しますか？」* with the warning:
*「この端末に同期されたすべての注文・メニュー・設定が削除されます。クラウド側のデバイスは revoked 状態になります。再度ペアリングするには新しいコードが必要です」*

Press **解除する** → the app reloads itself after about a second.

**Case 2 — there is unsynced money ⚠️:**

The app **BLOCKS** and shows a red screen, *「未同期の売上があります」*, with three
lines:

- **未同期の金額** — the amount not yet on the server (formatted in ¥)
- **未同期の注文数** — the number of orders
- **未同期の決済数** — the number of transactions

👉 **The correct fix:** restore the network, **wait for the sync to finish** (the
"pending" count reaches 0), and only then unpair.

👉 If you must unpair immediately: **tick the confirmation box** and press
**強制解除 / Force unpair**.

**What a forced unpair does:**
- Transaction data **is kept on disk**
- The menu, tables, zones and stock are deleted
- The server address (`cloud_api_url`) **is kept**, so it need not be re-entered
- Pairing again **into the same branch** pushes the data up automatically

> ⚠️ **Pairing into a DIFFERENT branch locks the old data permanently** (so that
> store A's money cannot flow into store B's books). This is deliberate.

---

## 9. Printer setup

All printer configuration lives in **Workstation → Devices**. **It is not in Admin
and not on the POS.**

### 9.1 Preparation

**A network printer (recommended):**
1. Plug the LAN cable into the printer
2. **Print the printer's self-test page** (usually by holding Feed while powering on)
   to see its current IP
3. Ask IT to give the printer a **static IP**
4. Note it in the form `IP:port`, usually `192.168.1.100:9100`

**A USB printer:**
1. Plug the USB cable into the workstation machine
2. You need the device path:
   - Linux: `/dev/usb/lp0`
   - macOS: `/dev/cu.XXXX`
   - Windows: `COM3` or `LPT1`

### 9.2 Adding a printer

Go to **Devices** → **新規作成 / Create**:

| Field | The on-screen label | What to enter |
|---|---|---|
| Name | **名前 / Name** | It hints `e.g. Kitchen Printer`. Give it a memorable name: `Kitchen printer`, `Receipt printer` |
| Connection | **Connection** *(English)* | **Network (TCP)** for a network printer · **USB** for a cable |
| Address | **Address (IP:Port)** or **Address (Device Path)** *(English)* | Network: `192.168.1.100:9100` · USB: `/dev/usb/lp0` |
| Paper width | **用紙幅 / Paper width** | **80 mm** (usual) or **58 mm** |
| Roles | **役割 / Roles** | Pick **at least one** — see the table below |

**The four printer roles:**

| Role | Japanese | What it prints |
|---|---|---|
| Kitchen | 厨房 / Kitchen | Kitchen tickets — what needs cooking |
| Bar | バー / Bar | Bar tickets — drinks |
| Hold (runner) | ホールド（ランナー） | The summary slip for the runner |
| Receipt | レシート / Receipt | Guest receipts, **the shift-open slip**, **the shift-close slip**, debt slips and VAT invoices |

The note underneath reads
*「1台のプリンターで複数の役割を担当できます。このプリンターで印刷する業務をすべて選択してください」*
(One printer can take several roles. Select every job this printer will print.)

> 💡 **Only one printer in the store?** Give it **all four roles**. That is perfectly
> valid and the most common setup.

The **Create** button only activates once there is a **name, an address and at least
one role**. Removing every role shows: *「役割を1つ以上選択してください」*

### 9.3 Invalid addresses are rejected

This is a security measure, not a bug:

| Kind | Accepted | Rejected |
|---|---|---|
| **Network** | A private IP: `192.168.x.x`, `10.x.x.x`, `172.16-31.x.x`, or a name ending in `.local` | A public IP (the message: *"network printer must be on a private/LAN address"*), and any domain name |
| **USB** | `/dev/usb/lp*`, `/dev/usblp*`, `/dev/lp*`, `/dev/cu.*`, `/dev/tty.*`, `COM*`, `LPT*` | Any other path; anything containing `..` |

The port must be in the range **1-65535**.

### 9.4 Test print

On the row of the printer you just created, press the **test-tube** button.

The printer should produce:
```
=== TEST PRINT ===
Time: 2026-07-21 14:32:05
Printer OK!
```
and then **cut the paper**.

| Result | Message |
|---|---|
| ✅ Success | *「テスト印刷を送信しました」* / "Test print sent!" |
| ❌ Failure | *「テスト印刷に失敗しました」* plus the specific reason |

**If it fails, check in this order:**
1. Is the printer switched on?
2. Is there paper? Is a red light blinking?
3. Are the printer and the workstation machine on the **same subnet**?
4. Is the IP right? (print the self-test page again to confirm)
5. Is the port really `9100`?

### 9.5 Check that no role is left unassigned

If any role still has no printer, a warning banner appears at the top of the Devices
page:

> **未割当の役割: {list}** / *"No printer for: …"*

👉 Fix it by adding the missing role to an existing printer.

**The system falls back automatically when a role is missing:**

| To print | 1st choice | 2nd choice | 3rd choice |
|---|---|---|---|
| A receipt | `receipt_printer` | `kitchen_printer` | — |
| A hold slip | `hold_printer` | `receipt_printer` | `kitchen_printer` |
| A "kitchen" group item | `kitchen_printer` | — | — |
| A "bar" group item | `bar_printer` | — | — |
| An item with no group | `kitchen_printer` (with a warning logged) | — | — |

If no suitable printer exists at all, the POS receives a *"No printer configured"*
message rather than a hard error.

### 9.6 The printer list

The list refreshes every **5 seconds**. Each row shows:

- A **WiFi** icon (reachable) or a **crossed-out WiFi** icon (unreachable)
- The printer name
- `{connection kind} - {address}`
- The role badges
- The status badge
- A **test print** button and a **bin** button (delete — a soft delete, so no history
  is lost)

### 9.7 🔧 Technical notes on the printers

- **Character encoding: Shift_JIS.** Japanese prints well.
- **Vietnamese diacritics are stripped to plain letters** — thermal printers have no
  Vietnamese font. This is deliberate, so that characters do not turn into boxes.
  Consider naming items without diacritics if it matters.
- **Protocol: StarPRNT**, not Epson ESC/POS — for a **Star** machine, where the cut
  command is `ESC d 3`.
- **The cut command comes from the printer's capability profile (#1950)**, not from
  the template. `star_mcprint` → `ESC d 3`; `epson_tm_i` → `GS V 1` (a **partial**
  cut, so the slip stays hanging in the mechanism instead of dropping on the floor);
  `escpos_generic` → `GS V 0`; a tear-bar machine (`none`) → the paper is fed and
  **no cut command is sent at all**; `auto_cut_per_job` → nothing is sent, because
  the machine cuts itself and a second command means a blank slip every time.
  A machine that has never been through the setup wizard is treated as
  `escpos_generic`, so **run the wizard on Star hardware** — a Star ignores `GS V`
  and will not eject the slip.
- **Kanji mode (`FS &`) is deliberately NOT enabled** — it hangs the Star mC-Print3
  and swallows the cut command with it. An older device note (since deleted) said
  the opposite; if that text resurfaces from anywhere, **do not follow it**.
- **Paper width → characters per line:** 58mm = **32 characters** · 80mm =
  **48 characters**. The slip templates are designed at 42 columns and then centred.
- **The cash drawer** is opened with `ESC p 0 25 250` (if the printer has a cash
  drawer port).

### 9.8 The full list of printable slips

| Slip | Printed from | Printer role |
|---|---|---|
| Kitchen ticket | The POS ("Send to kitchen") or Workstation → Orders | kitchen / bar |
| Hold slip | Workstation → Orders | hold |
| Provisional bill (order bill) | POS → the cart | receipt |
| Payment receipt | POS → after payment | receipt |
| Debt slip | The POS (when a guest's debt is recorded) | receipt |
| VAT invoice | POS → after payment | receipt |
| Red invoice | POS → after payment | receipt |
| **Shift-open slip** (レジ開け) | The POS → printed automatically when the shift opens | receipt |
| **Shift-close slip** (精算 / 引き継ぎ) | The POS → printed automatically at shift close | receipt |
| **Chain summary slip** | The POS → printed automatically at the final close | receipt |
