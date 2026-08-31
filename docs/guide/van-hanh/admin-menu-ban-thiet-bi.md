---
title: "Operations — Admin: menus, zones/tables/QR, device pairing"
category: guide
tags: [admin-web, menu, tables, qr, pairing, non-technical]
summary: "Assigning a menu to a store, building zones, tables and QR codes, creating devices and getting the 6-character pairing code."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** A store manager building the menu, the table plan and
> the device pairings.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## 5. The store's menu

The **master** menu is built at brand level and then **pushed down** to the store.
At store level you fine-tune it for your own outlet.

### 5.1 The store's menu list

Go to **Store → メニュー**.

The status filter defaults to **Active**. The possible statuses are: `Active`
(running) · `Inactive` (off) · `Approved` · `Pending` (awaiting approval) · `Draft`
· `Rejected`.

> ⚠️ **Only a menu in the `Active` status appears on the POS and the guest web.**

There is a **re-sync from the master menu** function that pulls the latest changes
down from HQ.

### 5.2 Inside a menu — what a store may change

Click the menu name:

| Adjustable | Meaning |
|---|---|
| **Toggle each item** | Turn an out-of-stock item off and it disappears from the POS and the guest web |
| **A store-specific price** | Overrides HQ's price for this store only |
| **提供形態 / Service type** | Whether this item is served in-store, for takeaway, or both. Choose **本部（HQ）に従う** to follow the default, or **この支店で個別に指定** to set it locally. An item following HQ carries an **HQ** badge. |
| **タイムアウト / Timeout** | Overrides the cart-hold time for this menu only |
| **スケジュール / Schedule** | The times and weekdays this menu is in effect |

**The inheritance order for the cart-hold time** (the Timeout dialog spells it out):

```
① HQブランドデフォルト      (the brand default)
② HQメニュー個別            (set per menu at HQ)
③ 店舗デフォルト            (the store default)
④ Set for this menu at the store   ← highest priority
→ 有効値: {n} minutes        (the value actually in effect)
```

Warnings you may meet:
- *「スケジュールがないとタイムアウトを設定できません。先にスケジュールを追加してください。」*
  — you must create a schedule before a timeout can be set.
- *「このメニューにはまだ有効期限がありません。valid_toが設定されるまでタイムアウトは機能しません。」*
  — the menu has no end date, so the timeout has no effect yet.

### 5.3 Common menu errors on the POS

| The POS says | Cause | Where to fix it |
|---|---|---|
| *"No menu for today"* | The menu has no **schedule** covering today | HQ → Menus → that menu → Schedules |
| *"No menu has been activated today"* | There is a schedule but the menu is not **Active** | Store → メニュー → change the status |
| An empty menu on the workstation | The store **has no timezone set** | HQ → Shops → 店舗情報を編集 → タイムゾーン |
| Items show as `(unknown)` | The downloaded menu data is missing names | Wait for the workstation to re-sync (every 5 seconds) |

---

## 6. Zones, tables and QR codes

Go to **Store → テーブル / Tables**.

> This page **refreshes itself every 20 seconds**, because table statuses change
> constantly from the POS, TMS and guest web.

There are four buttons at the top:

| Button | Japanese | When it is enabled |
|---|---|---|
| Print QR codes | QR を印刷 | When at least one table exists |
| Take the standard tables from HQ | HQの標準テーブルを受け取る | Always |
| New zone | 新規エリア | Always |
| New table | 新規テーブル | Only once at least one zone exists |

### 6.1 Create a zone first

A table must belong to a zone. Press **新規エリア / New Zone**:

| Field | Japanese | What to enter | Required |
|---|---|---|---|
| Code | コード | A short code: `TER`, `T1`. Letters, digits and hyphens only, and **unique within the store** | ✅ |
| Name | 名前 | The display name: `Terrace`, `Second floor` | ✅ |
| Description | 説明 | Free-form notes | — |
| Display order | 表示順 | An integer ≥ 0, default 0 | — |

> ℹ️ This page **can only create zones, not edit them**. Editing a zone requires IT
> or the API.

### 6.2 Create a table

Press **新規テーブル / New Table**:

| Field | Japanese | What to enter | Required |
|---|---|---|---|
| Zone | エリア | Pick the zone | ✅ |
| Code | コード | `T-01`, `A5`. **Unique within the store** | ✅ |
| Name | 名前 | `Window table` (optional, up to 255 characters) | — |
| Seats | 席数 | **1 to 1000** | ✅ |

The table's QR code is **generated automatically**; nothing needs entering.

### 6.3 Take the standard tables from the brand (faster)

If HQ has already built a standard table plan, press **HQの標準テーブルを受け取る**.

The dialog shows a preview:
- **追加されるエリア** — how many zones will be added
- **追加されるテーブル** — how many tables will be added
- A notice line: *「{n} エリア・{m} テーブルは既に存在するためスキップされます」* — codes
  that already exist are skipped
- If there is nothing new: *「受け取れる新しい標準テーブルはありません」*

Press **受け取る** → the confirmation
*「標準テーブルを受け取りました（エリア {n}・テーブル {m} 追加）」*

> ✅ **Safe to press repeatedly.** The system matches on codes — existing codes are
> skipped, and tables the store created itself are **left untouched**.

**Tables taken from HQ carry an `HQ` badge:**
- They **cannot be deleted** at store level (only deactivated)
- They **can only be edited if HQ enables the toggle**
  *「店舗がHQ標準テーブルを編集できる」* (at HQ → Settings → Table status)

### 6.4 Print QR posters for the tables

Press **QR を印刷**:

- Select the tables to print (there is a **すべて選択 / Select all** button)
- **用紙サイズ / Paper size**: **A4 = 4 posters per sheet** · **A3 = 16 posters per
  sheet**. Each poster is **74×105mm**.
- The summary line: `{n} テーブルを選択 → {paper size} {m} 枚`
- Tables with no QR code yet are excluded, with the line
  *「QR 未発行の {n} テーブルを除外」*
- Press **プレビュー** to preview, then **印刷（{m} 枚）**

Cut them out and stick them on the tables. A guest scanning the QR opens the store's
ordering site at an address of the form:

```
{guest-web-address}/dine-in/{store-code}/table/{qr-code}
```

### 6.5 Change a table's status

Press the **coloured badge** on the table card:

| Status | Japanese | Colour | Who can change it |
|---|---|---|---|
| Free | 空席 / Free | 🟢 Green | Any staff member |
| Occupied | 使用中 / Occupied | 🟡 Yellow | Any staff member |
| Reserved | 予約済み / Reserved | 🔵 Blue | Any staff member |
| Being cleaned | 清掃中 / Cleaning | ⚪ Grey | Any staff member |
| Out of service | 利用不可 / Out of Service | 🔴 Red | Any staff member |

The **status change history** of each table can be viewed.

> ℹ️ A **deactivated** table's status cannot be changed.

### 6.6 Other actions on a table card

Press the **⋮**:

| Action | Japanese | Notes |
|---|---|---|
| Enlarge / shrink the QR | QR を拡大 / 縮小 | To test-scan it straight from the screen (56px → 128px) |
| Enable / disable | 有効化 / 無効化 | |
| **Reissue the QR code** | QR を再発行 | ⚠️ **The old poster on the table stops working — it must be reprinted.** Managers only. |
| Edit | 編集 | Hidden for HQ tables unless HQ allows it |
| Delete | 削除 | Completely hidden for HQ tables. Confirmation: *「テーブル「{code}」を削除しますか？QRコードも無効になります」* |

---

## 7. Creating and pairing devices

> **The principle:** every device (workstation, POS, KDS, kiosk, TMS, handy) uses
> **the same procedure**: Admin creates a device of the right type → receives a
> **6-character code** → that code is typed into the device.

Go to **Store → デバイス / Devices** (or to HQ to see every branch's devices).

### 7.1 Create the device

Press **作成 / Create**:

| Field | The on-screen label | What to enter |
|---|---|---|
| Name | **名前 / Name** | A recognisable name: `POS-Counter1`, `WS-Main`, `KDS-Kitchen`. ⚠️ **Names must be unique within a branch** (a duplicate produces a clear error) |
| Type | **Type** *(always English)* | See the table below. ⚠️ **It defaults to TMS — remember to change it!** |
| Branch | **Branch** *(always English, only when creating at HQ)* | Pick the branch. Creating at store level uses the current store automatically |
| Notes | **Notes** *(always English)* | Free-form |

**Six device types:**

| Choice | Japanese | English | Which machine |
|---|---|---|---|
| `workstation` | ワークステーション | Workstation | The computer at the counter |
| `pos` | POSレジ | POS terminal | The till |
| `kds` | キッチンディスプレイ | Kitchen display | The kitchen tablet |
| `kiosk` | キオスク | Self-checkout kiosk | The guest self-service machine |
| `tms` | テーブル管理端末 | Table management terminal | The tablet showing the table plan |
| `handy` | ハンディ端末 | Handheld | The PDA used to take orders at the table |

> ⚠️ **The wrong type means pairing fails.**
> The Workstation app **rejects** a code belonging to another device type and says:
> *「このペアリングコードは別の端末種別（{type}）用です」*
> The other apps accept it but then call the wrong API group and misbehave.

Press **保存 / Save**. The device is created with the status
**アクティベーション待ち / Awaiting activation**.

### 7.2 Get the pairing code

Immediately after creation the **ペアリング / Pairing** column shows a **6-character
code** (for example `A3BK9X`). Click it to open a large dialog:

- **The code shown large** (4xl type, wide letter spacing, readable from a distance)
- A **copy** button → confirms *「ペアリングコードをコピーしました」*
- A **有効期限：** line = when the code expires

> ⏱️ **The code is valid for exactly 15 minutes and can be used once.**
> Expired or already used → the **⋮** menu → **コードを再発行 / Regenerate Code** for
> a new one (also 15 minutes).
> It can only be regenerated while the device is **アクティベーション待ち** or
> **有効**.

### 7.3 Type the code into the device

Go to the device in question and enter the code:

| Device | Detailed instructions |
|---|---|
| Workstation | [Section 8.4](workstation-may-in.md#84-pairing-the-workstation-with-the-system) |
| POS | [Section 10.3](pos-cai-dat-mo-ca.md#103-pairing-the-pos) |
| KDS | [Section 17.1](kds-kiosk-tms-handy-qr.md#171-kds--the-kitchen-display) |
| Kiosk | [Section 17.2](kds-kiosk-tms-handy-qr.md#172-kiosk--the-guest-self-checkout) |
| TMS | [Section 17.3](kds-kiosk-tms-handy-qr.md#173-tms--the-table-management-tablet) |
| Handy | [Section 17.4](kds-kiosk-tms-handy-qr.md#174-handy--the-handheld-for-table-orders) |

> 🌐 **Every device needs the Internet while pairing** — even in a store that runs
> mostly over the LAN. At that moment there is no token yet, so the pairing request
> always goes straight to the central server.

### 7.4 Confirm the pairing worked

Go back to the device list and press refresh:

- The **ステータス** column changes from **アクティベーション待ち** to
  **有効 / Active**
- The **ペアリング** column changes from the code to **接続済 / Paired** (in green)
- The **最終接続 / Last Seen** column starts showing a time

### 7.5 The four device statuses

| Status | Japanese | Meaning |
|---|---|---|
| Awaiting activation | アクティベーション待ち | Created but not yet paired |
| Active | 有効 / Active | Running normally |
| Inactive | 無効 / Inactive | Temporarily disabled; the API rejects it |
| Revoked | 取消済み / Revoked | Permanently revoked; it must be paired again from scratch |

> ℹ️ **There is no online/offline light.**
> The only way to know whether a device is alive is the **最終接続 / Last Seen**
> column — updated every time the device calls the server. If Last Seen is hours old
> during opening hours, the device has a problem.
> The **ステータス** column reflects the device's lifecycle, **not** its network
> connection.

### 7.6 Revoke, delete, restore

The **⋮** menu on each row:

| Action | Japanese | When to use it | Consequence |
|---|---|---|---|
| **Revoke** | 失効 / Revoke | The machine was lost, sold, or replaced | That machine **stops working immediately**. It must be paired again with a new code. Only shown while the status is **有効**. |
| **Delete** | 削除 / Delete | The device is no longer used | A soft delete — it can still be restored. Confirmation: *「「{name}」を削除しますか？この操作は元に戻せません」* |
| **Restore** | 復元 / Restore | Deleted by mistake | Turn on the **削除済 / Deleted** switch in the filter bar to see deleted rows |

At HQ level there is also a **bulk delete**: tick several rows → **{n}件削除**.

> ⚠️ **Do not revoke a workstation device during opening hours.** The app checks its
> status every 20 seconds; once revoked, the entire workstation interface jumps back
> to the pairing screen and every open screen is lost. See
> [Appendix C.7](phu-luc.md#c7-a-token-revoked-mid-shift-throws-the-workstation-back-to-the-pairing-screen).

### 7.7 Filtering and finding devices

- A **search** box by name
- A **削除済 / Deleted** switch to show deleted devices
- At HQ there are three filters: **status**, **type** and **branch** (each with an
  **すべて / All** option)
- At store level there is only the **status** filter
- 25 rows per page by default, sorted newest-created first
