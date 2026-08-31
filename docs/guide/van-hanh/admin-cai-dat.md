---
title: "Operations — Admin: signing in, the menu map, store settings"
category: guide
tags: [admin-web, settings, non-technical]
summary: "Signing in to Admin, the full menu map, and the six store-settings tabs (card by card, with the traps)."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** A store manager configuring the shop in a browser.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## 3. Signing in and the full menu map

### 3.1 Signing in

1. Open a browser and go to the Admin address
   ([section 1.4](rollout-quan-moi.md#14-the-web-addresses-to-know)).
2. The screen shows a **DXS Product** card with the subtitle
   *「組織アカウントでサインイン」* (Sign in with your organization account).
3. Press the only button: **SSO でサインイン** / *Sign in with SSO*.
4. You are taken to the organization's sign-in page (`id.godx.jp`) → enter your
   email and password.
5. After signing in you return to Admin automatically, see *「サインイン中...」*, and
   then land inside.

**If it fails:** a *「ログインに失敗しました」* (Sign-in failed) card plus a
**ログイン画面に戻る** (Back to sign-in) button. Try again; if it still fails,
contact IT — the account may not have been granted access to Tempo.

> A session lasts **7 days**, after which you must sign in again.

### 3.2 Choosing a workspace

The **ワークスペースを選択** (Select workspace) screen:

- **ブランド / Brands** = the **brand (HQ)** level — managing menus, products, taxes
  and devices for **all** stores.
- **店舗 / Shops** = **one specific store** — its own settings, tables and shifts.

There is a search box, *「ブランド・店舗を検索…」*. It shows 8 entries by default;
typing shows up to 10 results.

👉 **Choose your store** (the Shops section) for most of the work in this handbook.

**If you see "利用可能なワークスペースがありません"** (No workspaces available): the
account has not been assigned to any store → contact an administrator.

### 3.3 Switching between stores and brands

Press the **store name at the top of the left menu bar** → a list of Brands then
Shops appears → pick where you want to go. The one currently open is marked with a
**●**. The last line takes you back to the full workspace picker.

### 3.4 The STORE-level menu map

This is everything under `/shop/{store}/…`:

| Group | Entry | Japanese | Purpose | Covered here? |
|---|---|---|---|---|
| **概要 / Overview** | Dashboard | ダッシュボード | Today's revenue, today's orders, table occupancy, low stock | [3.5](#35-the-store-dashboard-screen) |
| **販売 / Sales** | Menu | メニュー | Toggle items, set store-specific prices | [Section 5](admin-menu-ban-thiet-bi.md#5-the-stores-menu) |
| | Customers | 顧客 | The customer list and outstanding debts | — |
| | Orders | 注文 | Every order in the store | — |
| | Promotions | プロモーション | Discount campaigns | — |
| **在庫管理 / Inventory** | Stock | 在庫 | How much material is left | — |
| | Material lots | 材料ロット | Lots and expiry dates | — |
| | Stock documents | 入出庫 | Stock in and out | — |
| | Transfers | 移動 | Moving stock between warehouses | — |
| | Stocktake | 棚卸 | Periodic counting | — |
| | Disposal | 廃棄 | Recording spoiled goods | — |
| | Alerts | アラート | Low-stock warnings | — |
| **フロア / Floor** | Tables | テーブル | Zones, tables, QR codes | [Section 6](admin-menu-ban-thiet-bi.md#6-zones-tables-and-qr-codes) |
| | **Devices** | デバイス | **Creating and pairing every device** | [Section 7](admin-menu-ban-thiet-bi.md#7-creating-and-pairing-devices) |
| | **Shift management** 🔒 | レジ管理 | The real-time shift board | [16.1](admin-giam-sat-ca.md#161-the-レジ管理-cashier-tills-monitoring-board) |
| | **Shift history** 🔒 | シフト履歴 | Looking up old shifts, CSV export | [16.2](admin-giam-sat-ca.md#162-shift-history-シフト履歴) |
| | **Stale shifts** 🔒 | ぶら下がりシフト | Dealing with stuck shifts | [16.3](admin-giam-sat-ca.md#163-force-closing-a-shift-強制終了) |
| **製造 / Production** | Batches / production orders / calculator | バッチ・製造指示・計算機 | In-house production (bread, sauces…) | — |
| **ワークフロー / Workflow** | Notifications plus four sub-entries | 通知 | Configuring automatic notifications | — |
| **設定 / Settings** | Warehouses | 倉庫 | Declaring physical warehouses | — |
| | **Settings** | 設定 | **Store settings — 6 tabs** | [Section 4](#4-store-settings--6-tabs) |

🔒 = requires **Shop Manager** or above.

### 3.5 The store dashboard screen

Under **概要 → ダッシュボード**. This is the first screen you see after choosing a
store.

**Four metric tiles:**

| Tile | Japanese | Content |
|---|---|---|
| Today's revenue | 本日の売上 | With a "versus yesterday" line |
| Today's orders | 本日の注文 | The count plus a comparison with yesterday |
| Table occupancy | テーブル稼働率 | The percentage of tables in use |
| Low stock | 在庫僅少 | Items below their minimum level |

**A revenue chart for the last 7 days.**

**A "Table status" card** — updated in **real time**, counting tables across five
statuses: Free / In use / Reserved / Being cleaned / Out of service.

**A "Production queue" card** and a **"Customer reviews" card** (recent comments and
star ratings).

### 3.6 The BRAND-level (HQ) menu map

The HQ entries a store manager usually needs:

| Path | Japanese | Purpose |
|---|---|---|
| `/hq/{brand}/product-types` | 商品タイプ | Product types (step 1 when building a menu) |
| `/hq/{brand}/categories` | カテゴリー | Item categories |
| `/hq/{brand}/tax-types` | 税区分 | **Tax types** — see [4.8](#48-tax-types-税区分--brand-level) |
| `/hq/{brand}/topping-groups` | トッピンググループ | Topping groups |
| `/hq/{brand}/products` | 商品 | Products and SKUs |
| `/hq/{brand}/menus` | メニュー | Master menus and their schedules |
| `/hq/{brand}/shops` | 店舗 | **Store information and opening hours** — see [4.9](#49-store-information-and-opening-hours-brand-level) |
| `/hq/{brand}/settings/payment-methods` | 支払方法 | **Adding and removing payment methods** |
| `/hq/{brand}/tables` | 標準テーブル | The shared standard table layout |
| `/hq/{brand}/devices` | デバイス | Devices across every branch |
| `/hq/{brand}/iam` | メンバー管理 | Members and permissions |

---

## 4. Store settings — 6 tabs

Go to **Store → 設定 (Settings)**.

| Tab | Name | Content |
|---|---|---|
| 1 | **注文 / Orders** | Orders, currency, tax, service charge — **9 cards** |
| 2 | **決済 / Payments** | The list of payment methods (read only) |
| 3 | **カートタイムアウト / Cart Timeout** | How long a guest's cart is held |
| 4 | **テイクアウト支払い / Takeaway Payment** | The takeaway payment window |
| 5 | **金種 / Denominations** | The denominations used for counting cash ⭐ |
| 6 | **決済方法 / Payment methods** | The payment brands used in shift-close reconciliation ⭐ |

> 💾 **Only the Orders tab does not save automatically.** After editing, press
> **変更を保存 / Save changes** at the bottom of the page. Unsaved changes show
> *「保存されていない変更があります」*. The other tabs save as soon as you press.

---

### 4.1 The 注文 / Orders tab — card 1: the default item status

*「注文アイテムのデフォルトステータス」* — decides which stage of the kitchen
workflow a newly added item starts in.

| Choice | Japanese | Meaning | When to use it |
|---|---|---|---|
| Pending | 保留中 / Pending | The full four steps: pending → preparing → ready → served | A full table-service restaurant |
| Preparing | 準備中 / Preparing | Skips "pending" and goes straight to the kitchen | When the kitchen should start as soon as an order arrives |
| Ready | 準備完了 / Ready | Jumps straight to "done" | A quick-service counter |
| Served | 提供済 / Served | Skips everything | Self-service or takeaway |

> 💡 This directly affects the POS: an item at **Served** can be checked out
> immediately. Any item not yet "Served" **blocks** the Pay button
> ([see 13.1](pos-ban-hang-thanh-toan.md#131-step-1--checkout)).

### 4.2 The Orders tab — card 2: allow editing items in any status

The toggle *「ステータスに関わらず商品を編集・取消可能にする」*

- **ON**: quantity, notes and toppings can be edited, and items cancelled, **even
  while the kitchen is cooking or after they have been served**.
- **OFF** (the default): only items still "pending" can be edited.

### 4.3 The Orders tab — card 3: quick order

The toggle *「クイック注文」* — when **ON**, the POS skips the table/guest/headcount
dialog and creates the order immediately. Suited to fast-service outlets and coffee
counters.

### 4.4 The Orders tab — card 4: display currency 🔒

*「表示通貨」*

**Eight choices:** VND ₫ · JPY ¥ · USD $ · EUR € · KRW ₩ · CNY ¥ · THB ฿ · IDR Rp

After saving, the POS picks it up within **60 seconds**, or on a page reload.

> 🔒 **It cannot be changed while a shift is open.**
>
> This page **re-checks every 60 seconds** (and every time you return to the browser
> tab). If a shift is open, the selector is **locked** and a yellow warning appears:
>
> **通貨ロック中 — レジシフトが開いています** (Currency locked — a cashier shift is open)
> *「シフト中の通貨変更は金種照合を壊すため不可です。レジ担当者の締めをお待ちください」*
> plus the line `シフト {shift id} · 担当 {who opened it} · {time}` and a
> **詳細を見る →** link straight to the stale-shift list.
>
> **What to do:** ask the cashier to **close the shift**, wait about a minute, and
> try again.
>
> If you try to save anyway, the server returns
> `CURRENCY_CHANGE_BLOCKED_OPEN_SHIFT` and the selector reverts to the old value.

> ⚠️ **The lock also lasts for the whole "chain of shifts".** If the cashier
> **hands over** the shift rather than performing a final close, the chain stays
> open → the currency still cannot be changed. Only a **final close** releases it.

### 4.5 The Orders tab — card 5: the service charge

*「料金 / Charges」* → **サービス料 (%)**

- Enter a number from **0 to 100**, with up to two decimal places
- Computed on **(subtotal − discounts)**
- Enter **0** to turn the service charge off

### 4.6 The Orders tab — card 6: tax 🔒

*「税 / Tax」* — with a blue note reminding you that **tax settings should be
changed outside the hours when a shift is open**.

| Field | Japanese | Meaning | Locked? |
|---|---|---|---|
| Default tax type | デフォルト税区分 | Chosen from the **brand's** 税区分 list. Each row shows `{name} · 店内 x% / 持ち帰り y%`. Choose **なし（未設定）** to leave it blank. | No |
| Prices include tax | 税込価格（内税） | ON = the listed price already contains the tax (内税). OFF = tax is added on top (外税). | 🔒 **Yes** |
| Service-charge tax | サービス料の税率 | The tax rate applied to the service charge. 0 = not taxed. | No |
| Print the tax breakdown on the close slip | 精算レポートに税率別内訳を表示 | ON = the close slip is broken down by tax rate | No |
| Tax rounding | 税額の端数処理 | **四捨五入** (round) / **切り上げ** (up) / **切り捨て** (down) | 🔒 **Yes** |
| Decimal places | 端数の桁数 | 0 / 1 / 2 / 3. **VND and JPY must use 0.** | 🔒 **Yes** |

While a shift is open, the hint lines change to:
- *「レジシフトが開いているため、税込／税別モードは変更できません」*
- *「レジシフト中は端数処理を変更できません。締め後に変更してください」*

The matching server error codes: `TAX_MODE_CHANGE_BLOCKED_OPEN_SHIFT`,
`TAX_ROUNDING_LOCKED_OPEN_SHIFT`.

> 📌 **The tax settings are snapshotted onto each order when it is created.**
> Changing the tax later does **not** alter existing orders — this is deliberate, so
> that past books are never rewritten.

### 4.7 The Orders tab — cards 7, 8 and 9

**Card 7 — rounding when splitting a bill** *「割り勘の端数処理」*

| Choice | Japanese | Meaning |
|---|---|---|
| Automatic, per currency | 通貨に合わせて自動 | ✅ Recommended |
| Always round up to a whole number | 常に整数に切り上げ | |
| Round up to 2 decimal places | 小数第2位までに切り上げ | |
| No rounding | 端数処理なし | An exact split |

Below it is an **example preview** for the currently selected currency.

**Card 8 — the cook-before/after-payment policy** *「調理ポリシー」* — applies to
**takeaway orders paid at the counter**:

- **HQの設定に従う** — follow the brand default
- **支払い完了後に調理を開始** — only cook after the guest has paid
- **支払い前でも調理を開始する** — cook immediately, do not wait

> An order prepaid through Stripe **always** enters the open state immediately,
> regardless of this setting.

The same card also holds:
- **顧客メールアドレスを必須にする** — when ON, the takeaway form requires an email
  address (for sending the order QR and the receipt)
- **ご注文確認の有効時間 (分)** — how long the guest has to confirm the order: choose
  *HQ既定値を使用* or enter **1-30 minutes**

**Card 9 — the table status after payment** *「会計後のテーブル状態」*

- **デフォルト（本部を継承）** — follow the brand (the effective value is shown in
  brackets)
- **空席 / Free** — the table is free immediately and can take new guests
- **清掃中 / Cleaning** — the table enters "being cleaned" and staff must mark it
  done

---

### 4.10 The 決済 / Payments tab — read only

The list of available payment methods. **They cannot be edited here** — a note says
*「決済方法は組織レベルで管理されています」*.

Each row shows: an icon for the kind (cash → a banknote, card → a card, transfer →
an arrow, wallet → a wallet), the name, the code, the **scope** (**組織全体** = the
whole organization / **店舗固有** = store-specific), the sort order, and the status
**有効** (enabled) / **無効** (disabled). Disabled rows are greyed out.

👉 To add or remove: go to **HQ → `/hq/{brand}/settings/payment-methods`**, where the
fields are: コード (code) / 名前 (name) / 表示順 (order) / **自動確認** (auto-confirm
— used for cash) / **受取金額必須** (require the tendered amount so change can be
computed). There is a **並び替え** button for drag-and-drop ordering.

### 4.11 The カートタイムアウト / Cart Timeout tab

How long a guest's cart is held before it is discarded.

- **HQブランドデフォルトを使用** — use the brand default (the description line shows
  HQ's current number)
- **この店舗専用に設定** — enter a store-specific number of minutes, **at least 1**

> At brand level the limit is **1-1440 minutes** (up to 24 hours).

### 4.12 The テイクアウト支払い / Takeaway Payment tab

How long a guest has to pay for a takeaway order.

- **HQブランドデフォルトを使用** or **この店舗専用のタイムアウトを設定**
- Valid values: **5 to 120 minutes**

---

### 4.13 The 金種 / Denominations tab — cash denominations ⭐

> 🚨 **The most important tab to check before the first day of trading.** Cashiers
> count cash against exactly this list when opening and closing a shift. A missing
> denomination means the count cannot be correct, which creates a fake discrepancy.

- Pick the currency at the top of the page: **JPY / VND / USD / EUR**. The store's
  own currency is marked **(店舗の既定)**.
- Two groups: **紙幣 / Notes** and **硬貨 / Coins**.
- The **ソース / Source** column:
  - **システム / System** = a built-in standard denomination that **cannot be edited
    or deleted**. To change one, add a store-level denomination of the same value to
    override it.
  - **店舗 / Shop** = added by the store; editable and deletable.

**To add a denomination:** press **金種を追加**:

| Field | What to enter |
|---|---|
| **額面 / Value** | JPY → `10000` · VND → `500000` · USD → `0.25` |
| **種類 / Kind** | **紙幣** (note) or **硬貨** (coin) |
| **メモ / Label** | Optional — "gold voucher", "opening-day voucher"… |

Deleting asks for confirmation: *「この金種を店舗から削除しますか？」*

### 4.14 The 決済方法 / Payment methods tab — used at shift close ⭐

This is different from the *Payments* tab. It is the list of **payment brands** that
appears under *"Payment device reconciliation"* on the cashier's shift-close screen.

- **Four fixed groups**: **現金 / Cash** · **カード / Card** · **QR** ·
  **電子マネー / E-money**
- Within each group, add or remove specific brands (in the QR group: PayPay, MoMo,
  VNPay…). Press **追加 / Add** on each group.
- New groups can be created (the group management button is at the top of the page).
  A group in use cannot be deleted.

> 👉 **List only the brands the store REALLY accepts.** The cashier must enter
> revenue and void counts for **every row** at shift close — every superfluous row
> costs time every evening.

---

### 4.8 Tax types 税区分 — brand level

Path: `/hq/{brand}/tax-types`. **There is no store-level screen for this** — a store
can only pick a default from the brand's list.

**The list shows:** the code · the name (click to edit; carries a **デフォルト**
badge if it is the default) · **税率** shown as `店内 10% / 持ち帰り 8%` ·
**商品数** (how many products use it) · the status · the update date.

**Creating or editing a tax type:**

| Field | Japanese | Rule |
|---|---|---|
| Code | コード | Required, up to 50 characters, **automatically upper-cased**. ⚠️ **It cannot be changed after creation.** |
| Name | 名前 | Required, up to 100 characters, **enterable in three languages** (ja/en/vi) |
| Rate | 税率 | 0-100%, **exactly one number** (#1099): Standard 10 · Reduced 8 · Exempt 0 |
| Set as default | デフォルトの税区分にする | Turning it on when another default exists shows a yellow warning, *「現在のデフォルトを置き換えます」* |
| Active | 有効 | ON by default |

**A tax type in use cannot be deleted.** The system returns an error and shows a
usage breakdown: how many **商品** (products), **メニュー商品** (menu items),
**店舗デフォルト** (stores using it as their default), and **ブランド既定税率**. It
comes with a **代わりに無効化** (deactivate instead of deleting) button.

**Tax is decided by this priority order (highest first):**

```
1. The tax set on the item WITHIN THE MENU     (highest priority)
2. The tax set on the PRODUCT
3. The STORE's default tax    (Settings → 税 → デフォルト税区分)
4. The BRAND's default tax    (the type carrying the デフォルト badge)
5. Nothing at all → 0% is charged and a warning is written to the log  ⚠️
```

The applied rate is **the single number on the resolved tax type** — the order type
cannot change it. To sell an item at 8% for takeaway, override that item with the
軽減 type inside the **takeaway menu** (step 1 of the priority chain).

> 🍺 **Alcoholic drinks:** assign the standard tax type just like any other
> product (on the product, or as an override inside the menu). Assigning the
> reduced type by mistake under-collects tax and the system will not warn you.

### 4.9 Store information and opening hours (brand level)

Go to `/hq/{brand}/shops` → click the store → the **店舗情報を編集** button:

| Field | Japanese | Notes |
|---|---|---|
| Timezone | タイムゾーン | ⚠️ **MANDATORY.** Pick `Asia/Ho_Chi_Minh` or `Asia/Tokyo`. There are 11 choices. **Leaving it blank makes the workstation pull an EMPTY menu.** |
| Address | 住所 | For example `〒100-0001 東京都千代田区千代田1-1` |
| Phone | 電話 | |
| Logo | ロゴ | A square image, JPG/PNG/WebP, up to 5MB |
| Desktop banner | バナー（デスクトップ） | ≥1024px, 1920×480 recommended |
| Tablet banner | バナー（タブレット） | 768-1023px, 1024×384 recommended |
| Mobile banner | バナー（モバイル） | <768px, 750×500 recommended |
| Seats | 席数 | An integer ≥ 0 |
| Opening hours | 営業時間 | **Free text**, e.g. `11:00 - 22:00` — for display to guests only |
| Hours per weekday | 曜日ごとの営業時間 | Opening and closing times **for each day of the week**, plus a **定休日 / Closed** tick box for rest days. Defaults to 11:00-22:00. |

Closing the form with unsaved changes asks you to confirm discarding them.
