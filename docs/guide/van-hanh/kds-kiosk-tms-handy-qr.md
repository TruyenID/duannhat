---
title: "Operations — KDS · kiosk · TMS · handheld · guest QR"
category: guide
tags: [kds, kiosk, tms, handy, customer-web, non-technical]
summary: "The remaining faces of the system: the kitchen display, the self-checkout kiosk, the table-management tablet, the handheld, and the guest web reached by scanning a QR."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** A store manager setting up and running the devices
> beyond the POS.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## 17. The other devices

All four below use **the same pairing procedure**: Admin creates a device of the
right type → a 6-character code (valid 15 minutes) → type it into the device.

### 17.1 KDS — the kitchen display

**Who uses it:** the chefs. **Runs on:** a landscape tablet in the kitchen.

#### Setup

| Task | How |
|---|---|
| Open the app | A browser on the tablet → `http://kds.tempo.local` (or port 5460) |
| **Install it as an app** | The KDS is a **true PWA** — use the browser's "Add to home screen". It then runs **full screen, locked to landscape**, with no address bar. ✅ **Recommended** |
| Pair it | Create a **`kds`** device in Admin, take the code, enter it |

#### The pairing screen

- The title **デバイスをペアリング / Pair the device**, subtitle
  **6文字のコードを入力 / Enter the 6-character code**
- A large input hinting `A3BK9X`, upper-casing automatically
- The **ペアリング / Pair** button (only enabled at 6 characters)
- A **音声テスト / Sound test** button — ⚠️ **it MUST be pressed once** on an iPad,
  otherwise the new-order chime never sounds (an iOS policy)

**Errors:** a wrong code → *"Invalid code"* · an expired code → *"The code has
expired"*

#### The working screen

- **The header:** **KDS** plus the branch name · `{n} orders` · when items are late:
  🔥 `{n} late orders` (in red) · **the connection badge** · ⚙ settings
- **The body:** a grid of order tickets. Each shows **Table {code}** or
  **Takeaway**, `{n} minutes` (the wait), `{n} guests`, and the order note if there
  is one
- **The button on a ticket:** **Move everything pending → cooking**
- **The main interaction:** **tap an item line** to push it to the next step:
  ```
  Pending  →(tap)→  Cooking  →(tap)→  Ready  →(tap)→  Served
  ```
  The small button beside it is **Undo**, to step back.
- **Toppings** appear as text under the item and **cannot be tapped**
- An item that failed to print carries a red **"Printer error"** badge
- The screen **never sleeps** (it stays lit continuously)

**Item statuses:** Pending (受付済み) · Cooking (調理中) · Ready (完成) · Served
(提供済み) · Voided (取消)

**Common errors:**
| Message | Meaning |
|---|---|
| *"The order has ended"* | The order is closed and can no longer be bumped |
| *"Too soon — wait 30 seconds after ready"* | Protection against repeated mis-taps |
| *"A topping is still being prepared"* | The topping must be completed first |
| *"Not this device's store"* | The tablet is paired to a different branch |

#### KDS settings (the right-hand drawer, opened with ⚙)

| Entry | Choices |
|---|---|
| **接続モード / Connection mode** | **Automatic** (recommended) · **LAN** · **Cloud** |
| **テーマ / Theme** | Light · Dark · Follow the system |
| **通知音 / Notification sound** | On/off |
| Device information | The device name and branch name (read only) |
| **デバイスを忘れる / Forget this device** | Unpair (a red button with a confirmation) |

> ℹ️ There is no language selector in the drawer — the default language is Japanese.

#### The KDS connection badge

Top right of the screen, updated every 3 seconds:
- 🟢 **LAN: {address}** — running through the workstation
- 🔵 **クラウド / Cloud** — running over the Internet; during a retry wait it shows
  `クラウド · {n}s` counting down
- The dot inside: **green** = the realtime connection (WebSocket) is running ·
  **yellow** = it is falling back to periodic polling, with the label **(polling)**

#### What the KDS can do without a network

✅ **The KDS is the only device with a real offline cache.**

- It shows the **most recent snapshot of the orders** from the browser's storage
- A **yellow** banner: *"Showing offline data — verify before cooking"* plus
  `· {n} minutes`
- After 5 minutes the banner turns **red**: *"⚠️ This data is more than 5 minutes
  old. Confirm each order with the waiting staff before cooking."*
- **The bump buttons still work** on stale data (deliberately). When the network
  returns, any invalid action reports an error and the screen re-syncs itself.

> ⚠️ **A known issue:** the new-order chime file is currently a **one-second silent
> file** — there is no sound. Only the on-screen notification appears. The app icon
> is also a placeholder.
>
> ⚠️ In **Cloud** mode (no workstation), **new orders take up to 15 seconds to
> appear**, not instantly.

📄 More detailed documentation: [Setup KDS Device](../setup-kds-device.md)

---

### 17.2 Kiosk — the guest self-checkout

**Who uses it:** **guests, on their own**. **Runs on:** a landscape tablet in the
lobby or at the counter. Staff only touch it to open Settings or to unstick the
payment terminal.

#### Setup

| Task | How |
|---|---|
| Install the app | ⚠️ **It must be a custom build (dev-client/EAS); it will NOT run on Expo Go.** App name: **Tempo Kiosk** |
| The web build | It exists but is **limited**: the camera requires picking a file rather than scanning directly, and it **cannot discover the workstation** |
| Pair it | Create a **`kiosk`** device in Admin → type the 6-character code → the **接続 / Connect** button |

#### 🔑 Reaching Settings — the secret gesture

> The idle (advertising) screen **has no settings button**. To get in:
>
> **Tap the screen 5 times within 3 seconds.**
>
> (One or two taps open the table picker for guests — that is normal behaviour.)

**You then have to enter a PIN.** The very first time, the system asks you to **set
a new PIN** (*「設定を保護するパスコードを設定」*) and then **confirm it**. After that
you only enter it.

Errors: *「パスコードが違います」* (wrong PIN) · *「パスコードが一致しません」* (the
confirmation does not match)

> 📝 **Write the PIN down and keep it somewhere safe.** There is no default PIN and
> no recovery inside the app.

#### The settings entries

**1. 周辺機器 / Peripherals → 決済端末 / Payment terminal** (a Verifone P400 over
LAN)

| Field | What to enter |
|---|---|
| **IPアドレス** | Hints `192.168.1.100`. Error: *"Invalid IP format"* |
| **ポート** | Hints `8080`. Must be within **1-65535** |

The **保存 / Save** and **テスト接続 / Test connection** buttons → *「接続成功」* or
*「接続失敗」*

**The "端末の復旧 / Unstick the terminal" block** — for when the payment terminal
hangs:
> *"If the payment succeeded but the terminal hung without printing the slip, press
> 'Reprint the slip'. If the terminal reports it is busy, try 'Reset the terminal'."*
- **レシート再印刷（復旧） / Reprint the slip (recovery)**
- **端末リセット / Reset the terminal**

**2. セキュリティ / Security** → **パスコード変更 / Change the passcode** (three
steps: the old PIN → the new PIN → confirm)

**3. アイドルタイムアウト / Idle timeout** — how many seconds without interaction
before it returns to the idle screen. **60 seconds by default.**
> *"After this long with no interaction, the kiosk returns to the idle screen."*
>
> ⚠️ The **paying / splitting / success** screens have a **fixed 5-minute** timeout
> that cannot be changed — so a guest is never cut off mid-transaction.

**4. ワークステーション / Workstation** ⭐

| Element | Content |
|---|---|
| Status | 接続中 (connected) / 検索中 (searching) / 見つかりません (not found), with `LAN: {address}` or `Cloud: {address}` plus the line `v{version} · WS ✓/✗ · branch {code}` |
| **ワークステーションURL（手動）** | ⭐ **The kiosk is the ONLY device that lets you type the workstation address by hand.** Hints `http://192.168.1.10:8080` |
| Buttons | **保存 / Save** · **手動URLを削除 / Delete the manual URL** · **接続テスト / Test connection** |

**5. ログアウト / Sign out** (a red button)

#### How the kiosk finds the workstation

The kiosk **does discover** it (mDNS) — unlike the POS and the KDS. The precedence:

```
1. Inside the retry cooldown (30 seconds)     → use Cloud
2. A workstation discovered on the network    ← highest priority
3. The address typed by hand in Settings
4. The address baked in at build time
5. The Cloud server
```

**The banner at the top of the screen:**
- 🟢 *「ワークステーション接続中: {name}」* — shown for 3 seconds then hidden
- 🟡 *「ワークステーション未接続、クラウド経由」*
- ⚪ *「ネットワーク内のワークステーションを検索中...」* — the first 5 seconds after
  launch
- 🟡 *「ワークステーションが見つかりません。ローカルネットワーク権限が必要な場合があります」*
  plus, on iOS, an **設定を開く / Open Settings** link

> 📱 **On an iPad the "Local Network" permission must be granted on first launch** —
> without it, the kiosk never finds the workstation.

#### The guest journey

```
The idle screen (advertising)
   "Touch to start" · [精算をはじめる →]
        ↓ touch
Step 1: which table are you at?
   ├─ [QRスキャン] scan the QR on the table or on the bill (using the camera)
   └─ [コード入力] type the last 3-6 digits of the order code (ORD-YYYY-XXXX) on a numeric keypad
        ↓
Bill: review it (guests, subtotal, service charge, tax, discount code, total)
   [お支払いへ進む] Continue to payment
        ↓
Choose how to pay: 💳 Pay everything · 👥 Split evenly · 🧾 Pay per item · ✏️ Enter an amount
        ↓
Choose a method: card · QR · e-money · cash
        ↓
Pay (tap the card / scan the QR / insert the money)
        ↓
Done → the receipt prints automatically → it returns to the idle screen after {n} seconds
```

**If the bill is already paid:** it shows *「支払い済み」* plus a
**領収書を再印刷** (reprint the receipt) button and **ホームに戻る**.

**Common payment terminal errors:**
| Message | Meaning |
|---|---|
| *「端末に接続できません」* | Check the IP and port in Settings |
| *「端末は処理中です」* | It is busy; wait a moment |
| *「決済が拒否されました」* | The card was declined; try another |
| *「決済端末が設定されていません」* | The terminal's IP has not been entered |

**If the receipt fails to print:** a red banner, *「領収書の印刷に失敗しました」*,
plus *"Press 'Reprint' or call a staff member"* and a **再印刷** button.

> ⚠️ **A trap:** the `EXPO_PUBLIC_WORKSTATION_URL` variable must be left **EMPTY**.
> Setting it to `localhost:8080` makes the tablet call itself and **loses the Cloud
> fallback entirely**.

---

### 17.3 TMS — the table-management tablet

**Who uses it:** waiting staff and the host. **Runs on:** a phone or tablet in
**portrait**.

**What it does:** only **view the table plan** and **silence a service call**. It
does not take orders and does not take payment.

#### Setup

- It runs in **Expo Go** or a simulator. **There is no prebuilt binary.**
- Pairing: create a **`tms`** device in Admin → type the code → the
  **接続 / Connect** button
- The default language is **Japanese**, with a ja/en/vi switch on the sign-in screen

> ⚠️ **TMS goes straight to Cloud and does NOT use the workstation.** No Internet
> means no TMS. There is no address field and no connection badge.

#### The main screen (there is only one)

- **The header:** **テーブル管理 / Table management** plus the branch name plus ⚙
  (Settings) plus **ログアウト**
- **A zone tab strip:** **全ゾーン / All zones** then each zone
- **A legend line** with live counts: 空席 / 使用中 / 呼出中 / 会計済 / 清掃中
- **A table grid (3 columns):** each card shows the name, the code, `席数: {n}`, and
  the status

**The colour key:**
| Status | Colour |
|---|---|
| Free (空席) | White |
| In use (使用中) | 🟢 Dark green |
| **Calling for service (呼出中)** | 🔴 **Red + FLASHING + a 🔔 icon** |
| Just paid (会計済) | 🔵 Light blue (held for **1 minute**) |
| Being cleaned (清掃中) | 🟡 Pale yellow |

**The only action:** on a table card that is calling for service, press
**✓ 対応済み / Handled** to silence it.

> 🔄 **TMS polls the server every 15 seconds.** There is no realtime update — it can
> lag by up to 15 seconds.

#### TMS settings

Press ⚙ in the header. **There is only one entry:**

**周辺機器 / Peripherals → レシートプリンター / Receipt printer** (a Star MC-Print3
over LAN)
- **IPアドレス** — hints `192.168.1.232`
- The **保存** and **テスト接続** buttons (which print a test page headed "TempoFast
  TMS")

> The sign-out button is **not inside Settings** — it is in the main header.

---

### 17.4 Handy — the handheld for table orders

**Who uses it:** waiting staff. **Runs on:** a 5.5″ Android PDA in **portrait**,
with a built-in thermal printer at the top (roughly 211×83×54mm).

#### How the handheld differs from the POS

| Function | POS | Handy |
|---|---|---|
| View tables and orders | ✅ | ✅ |
| Create an order, add items | ✅ | ✅ |
| Send to the kitchen (print the ticket) | ✅ | ✅ |
| **Take payment** | ✅ | ❌ **Done at the counter** |
| **Split a bill** | ✅ | ❌ |
| Cancel an order | ✅ | ✅ |

👉 **The handheld is the staff member's hand at the table. The POS is the till.**

#### Setup and pairing

- The **godx-handy** app, which needs a custom build (dev-client). It supports OTA
  updates.
- **Only two languages: Japanese and Vietnamese** (there is no English).
- Pairing: create a **`handy`** device in Admin → the
  **デバイス認証 / Device authentication** screen
- The code entry uses **six separate boxes**, auto-advancing, with backspace, and the
  whole code can be pasted at once
- The instructions appear on screen: *"An administrator creates the device on the
  web and enters the 6-digit code shown (it expires after 15 minutes)."*
- The **認証する / Authenticate** button

**Error:** *"Authentication failed. Please try again."* — the boxes clear and focus
returns to the first.

> ⚠️ **The handheld only talks to the workstation; there is NO Cloud fallback.** A
> workstation that is off means an unusable handheld. The workstation address is
> baked in at build time or stored at pairing; **there is no field in the app**.

#### The workflow

```
The main screen: the tables by zone plus the list of open orders
   ├─ Tap a table IN USE   → open that table's order
   └─ Tap a FREE table     → asks "Create an order for table {code}?" → [作成する / Create]
        ↓
Order detail: 「注文履歴 · 卓 {code}」
   Counts: awaiting the kitchen / cooking / ready / served / total
   Each item has a [提供済にする / Mark served] button
   The [オーダーをキャンセル / Cancel order] and [＋ 追加注文 / ＋ Add items] buttons
        ↓
Menu: search, category tabs, menu picker
   Tap an item → the detail dialog:
      - Pick a size
      - Pick toppings (「1つ選択」 / 「{min}〜{max}つ選択」)
      - Quick notes (preset chips): ねぎ抜き · パクチー抜き · 辛さ控えめ ·
        辛口 · 氷少なめ · 砂糖なし · テイクアウト · 別盛り
      - A free-text note for the kitchen
      - The quantity
      → [注文に追加 / Add to the order]
        ↓
The cart: 「注文確認 · テーブル {code}」
   → [厨房へ送信 / Send to the kitchen]   ← the workstation prints the ticket
```

**Pull down to refresh** the table and order lists.

**Errors when sending to the kitchen:**
- *"Sending to the kitchen failed. Please check the printer."*
- *「一部のプリンターに送信できませんでした」* — only some printers received it

**Cancelling an order:** the dialog 「オーダーをキャンセルしますか？」 plus *"This
cannot be undone"* plus a **キャンセル理由 / Cancellation reason** field (hinting
*"e.g. the guest changed their mind, the order was wrong…"*).
It is blocked while any item is cooking or has been served:
*「調理中または提供済みの商品があります」*

#### Handheld settings

Press ⚙ in the header:

| Entry | Choices |
|---|---|
| **言語 / LANGUAGE** | 日本語 · Tiếng Việt |
| **表示 / APPEARANCE** | Light · Dark · Follow the system |
| **データ / DATA** | **店舗設定を同期 / Sync the store settings** — reload the menu, tables and settings |
| **デバイス / DEVICE** | **このデバイスの認証を解除 / Unpair this device** (a red button with a confirmation) |

> ⚠️ **Changing the language in the handheld changes only the interface, NOT the
> language of the data from the server** (item names and server error messages stay
> in Japanese).

---

### 17.5 Customer Web — guests scanning a QR to order

**Who uses it:** guests, on **their own phones**. No app to install and no sign-in.

#### The "dine-in" journey (scanning a QR)

```
The guest scans the QR stuck on the table
   → their phone opens: {guest-web}/dine-in/{store}/table/{qr-code}
        ↓
The system checks the table:
   ├─ The table does not exist  → "Table not found" + [Home]
   ├─ It is being cleaned       → "This table is being cleaned" + [Choose another table]
   ├─ Somebody else is there    → "This table is in use" + [Choose another table]
   └─ It is free                → asks "Confirm you are at table {code}?" [Yes] [Cancel]
        ↓  (the table becomes "In use" AS SOON AS the guest confirms)
MENU
   - The menu name plus its valid hours
   - A search box and category tabs
   - The item grid (tap for options and toppings)
   - A round 🔔 "Call staff" button, bottom right  ⚠️ CURRENTLY DISABLED
   - The bottom bar: 🛍 {n} items · {amount}  →  [Confirm the order]
        ↓
CONFIRM THE ORDER
   - Change quantities, remove items, add notes
   - A countdown if the menu is about to expire
   - [Confirm]
        ↓
   "Thank you — your order has gone to the kitchen"
   [Order more] · it returns to the menu automatically after {n} seconds
        ↓  (once the guests have eaten)
ORDER HISTORY
   - Each item with its status: Awaiting confirmation / Preparing / Ready / Served
   - Totals: subtotal / total / paid / outstanding
   - [Pay · {amount}]
        ↓
PAYMENT — the guest picks one of two:
   ├─ 💳 Pay online (by card through Stripe)
   │     + choose: pay everything · split evenly · split by item · enter an amount
   │     + enter a discount code
   └─ 🏪 Pay at the counter
         → it shows a QR CODE to hand to staff or scan at the kiosk
        ↓
   "Payment successful" plus the order code, the amount, and an invitation to review
```

> 🔗 **The QR in the "Pay at the counter" step is exactly what the kiosk scans.**
> The guest just hands their phone to the kiosk or to a staff member.

**The table status changes automatically:** the guest confirms they are seated → the
table becomes **In use**. After payment → the table becomes **Free** or **Being
cleaned**, depending on
[the card 9 setting](admin-cai-dat.md#47-the-orders-tab--cards-7-8-and-9).

#### The "takeaway" journey

```
The guest opens the site  →  picks a store  →  chooses "Takeaway"
        ↓
MENU  →  the cart  →  [Pay]
        ↓
THE PAYMENT PAGE
   - Store information (address, opening hours)
   - The order contents
   - COLLECTION TIME: "Now" or a specific time
   - GUEST DETAILS: name* · phone* · email
        (Email is MANDATORY when "顧客メールアドレスを必須にする" is on — see section 4.7)
   - METHOD: prepay (card/PayPay)  or  pay at the store
   - A discount code · notes
        ↓
   [Place the order]
        ↓
THE COMPLETION PAGE
   - The order code plus a QR CODE (the guest shows this QR at the counter)
   - The [Download the QR] · [Track the order] · [Order this again] · [Order more] buttons
   - A countdown to the PAYMENT DEADLINE
     ⚠️ Not paying in time CANCELS the order automatically
```

If the store enables *"only cook after payment"*
([card 8](admin-cai-dat.md#47-the-orders-tab--cards-7-8-and-9)), the completion page
states clearly: *"The restaurant only starts preparing your order once payment is
confirmed."*

#### Notes for managers

| Issue | Note |
|---|---|
| **The "Call staff" button is disabled** | It exists in the code but is switched off by a flag. Ask IT to enable it if needed. When enabled, the call flashes red on the TMS. |
| **A "takeaway" cart is held for 1 hour** | The guest is not signed in, so the cart clears itself after an hour |
| **A "dine-in" cart is NOT held** | Each table is a new session, deliberately |
| **Language** | Defaults to **Japanese**, with a 🇯🇵/🇻🇳/🇬🇧 switch. The choice is remembered for a year. |
| ⚠️ **The guest menu does not appear (a known bug)** | There is a recorded bug that makes the guest menu return "not found". If a guest reports *"The menu will not load"* → **tell IT immediately**, see [Appendix C.10](phu-luc.md#c10-known-bugs-in-the-guest-web) |
| ⚠️ **A branch with no timezone** | Breaks the time-based menu. Check [section 4.9](admin-cai-dat.md#49-store-information-and-opening-hours-brand-level) |
| **No offline notice** | The guest web shows no offline indicator; errors appear only as small toasts |
