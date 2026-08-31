---
title: "Operations — Admin: shift monitoring"
category: guide
tags: [admin-web, shift, monitoring, non-technical]
summary: "The manager's shift monitoring screens: open shifts, cash discrepancies, stale shifts and the three exit doors (force-abandon / expire / manual settle)."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** Store managers and head office.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## 16. Admin — shift monitoring

> 🔒 Requires **Shop Manager** or above. Anyone else who opens it sees a 403 warning
> panel inside the page (the menu entry is still visible; it is not hidden).

### 16.1 The レジ管理 (Cashier tills) monitoring board

Go to **Store → レジ管理**. The page **refreshes itself every 5 seconds** (and stops
while you are on another browser tab).

The subtitle reads *「5秒ごとに更新されます」* plus `更新: {time}`. While loading it
shows *「更新中…」*.

**Four metric tiles:**

| Tile | Japanese | Content |
|---|---|---|
| Open tills | オープン中のレジ | `{open}/{total}` |
| Shifts closed today | 本日の締めシフト | The count plus total revenue |
| Today's discrepancy | 本日の差異(累計) | 🟢 over / 🔴 short / ⚪ exactly zero |
| **Unhandled shifts** | 未対応のシフト | Stale plus expired shifts, with the line `未締: {a} / 失効: {b}`. **Click it to open the list.** |

**Per-till status** *(レジ別ステータス)* — one card per till:
- An **OPEN** (オープン) or **IDLE** (アイドル) badge
- The shift code · who opened it · `開店から {n}時間` (how many hours it has been
  open)
- 🟡 **24時間以上経過** — open more than 24 hours
- 🔴 **40時間以上経過** — open more than 40 hours
- When empty: *「レジが登録されていません」*

**The discrepancy chart** *(差異トレンド)* — the average over/short per day, with a
zero baseline. Choose a range of **7 / 14 / 30 / 90 days** (14 by default).

**The five most recent closed shifts** *(直近の締めシフト)* — click for details;
the counted cash and the discrepancy are colour-coded.

**The force-close activity card** at the bottom of the page — see
[16.6](#166-the-force-close-activity-card).

### 16.2 Shift history (シフト履歴)

Go to **Store → シフト履歴**. There are two tabs: **すべて / All** and
**未対応 / Stale shifts**.

#### The "All" tab

**Date filters:** **開始日 / From** and **終了日 / To**, with quick buttons
**本日** (today) · **7日間** · **30日間** · **90日間** · **日付クリア** (clear the
dates).

**Validation:**
| Error | Message |
|---|---|
| The start date is after the end date | *「開始日は終了日以前である必要があります」* |
| A range longer than 365 days | *「日付範囲は365日以内にしてください」* |
| A date in the future | *「未来の日付は選択できません」* |

While the range is invalid the system **does not query** and the CSV export button
is disabled.
With no dates chosen it defaults to **the last 7 days**.

**Status filter** — five chips:

| Chip | Japanese | Meaning |
|---|---|---|
| Open | オープン | The shift is running |
| Closing | クロージング | In the middle of the close process |
| Settled | 締済 | Complete |
| Abandoned | 破棄 | Discarded (opened by mistake) |
| Expired | 失効 | Closed automatically by the system |

**The columns:** コード (code) · レジ (till) · 担当 (who) · 開始日時 (opened at) ·
ステータス · 決済数 (transactions) · 売上 (revenue) · 過不足 (discrepancy)

The **CSVエクスポート** button downloads an Excel file. On failure:
*「CSVのダウンロードに失敗しました」*

25 rows per page by default, paginated as `{from}–{to} / {total}`.

#### The "Stale shifts" tab (未対応)

Titled *「ぶら下がりシフト」*, with the **期限切れ閾値** (expiry threshold) `{n}h` and
`{n}件` (the total).

**Three sub-filters:**

| Filter | Japanese | Meaning |
|---|---|---|
| Overdue but still open | 期限超過（営業中） | Still `open` but for far too long |
| Expired | 期限切れ | The system has moved it to `expired` |
| All ended | 終了済み全件 | Every closed shift |

**The columns:** セッション (shift code) · ステータス · 開始日時 · 通貨 (currency) ·
アクション

A force-closed shift carries its own red badge.

**The ⋮ menu on each row:**
- **詳細を見る** (view details) — always available
- **強制終了** (force close) — only while the status is `open` or `closing`
- **手動精算** (manual settle) — only while the status is `expired`

With no stale shifts: *「ぶら下がりシフトはありません。良好です！」* plus
*「すべてのシフトが正常に運用されています」*

### 16.3 Force-closing a shift (強制終了)

Use this when a shift is stuck and the cashier cannot close it (they left, the
machine broke, they forgot…). It **frees the till immediately** so the next shift
can open.

Title: *「セッション {code} を強制終了」*

Description: *「営業中のシフトを強制終了し、レジを解放します。決済はすべてセッションIDを保持して売上集計に残ります。マネージャーIDは監査ログに記録されます」*
(Force-close the running shift and free the till. Every payment keeps its shift id
and stays in the revenue reports. The manager's id is written to the audit log.)

**The "理由 / Reason" field — MANDATORY, one of six:**

| Reason code | Japanese | Meaning |
|---|---|---|
| `cashier_forgot_to_close` | レジ係が締め忘れ | The cashier forgot to close |
| `cashier_unavailable_today` | レジ係不在 | The cashier is absent |
| `pos_device_failure` | POS機器障害 | The POS machine failed |
| `network_outage` | ネットワーク障害 | A network incident |
| `end_of_employment` | 退職・解雇 | Resignation or dismissal |
| `other` | その他 | Other |

**The "詳細 / Details" field** — four lines, up to 2000 characters. Placeholder:
*「状況を記入してください（20文字以上）…」*

> ⚠️ **Choosing "その他 / Other" REQUIRES at least 20 characters.** A `{n}/20`
> counter shows in red until it is satisfied.

Press **強制終了を確定** → *「シフト {code} を強制終了しました」*

While it is being submitted the dialog cannot be closed (ESC and clicking outside
are blocked).

> ℹ️ **NO MONEY IS LOST.** Every payment keeps its shift id and stays in the revenue
> reports.
>
> ⚠️ **This action is tracked as a fraud signal.** A manager doing it more than
> **5 times in 7 days** triggers a system alert.
>
> 🔗 **A force close BREAKS the chain of shifts.** The next shift starts a new
> chain, and this shift **is not included in the chain summary slip**.

### 16.4 Manually settling an expired shift

This applies only to a shift in the **失効 / expired** status. For any other status
the system says: *「手動精算は期限切れステータスのセッションのみ可能です」*

Title: *「{code} を手動精算」*
Description: *「期限切れシフトを実査現金に基づいて精算します。通常締めと同じ差額計算ロジックです」*

| Part | What to enter |
|---|---|
| **実査現金 / Counted cash** | A count field for **each denomination** of that shift's currency. Coins show 🪙, notes show 💴. The currency is shown as a badge at the top of the table. |
| **手動精算の理由** | ⚠️ **MANDATORY, 20-2000 characters.** With a `{n}/20` counter. Placeholder *「状況を記入してください（20文字以上）…」* |
| **メモ（任意）** | Optional extra notes |

**The "詳細オプション / Advanced options" section** (a dashed frame):

- ☐ **開始カウントを上書き / Override the opening count**
  Only for when the recorded opening float was wrong. Turning it on reveals:
  - A warning: *「記録された開始金を上書きします。変更前と変更後は別の監査ログに残ります」*
  - A **second** denomination count table (for the correct opening float)
  - **Another confirmation tick box** that must be ticked before it can be submitted

Press **精算 / Settle** → *「シフト {code} を精算しました」*

The discrepancy calculation is **identical to a normal shift close**.

> ⚠️ **Do NOT manually settle over a shift a cashier is still working.** Use it only
> for a shift that is genuinely dead. If the system moves an active shift to
> `expired`, that is a bug — report it to IT rather than papering over it with a
> manual settle.
>
> ⚠️ **A settled shift CANNOT be reopened.** There is no way to "reopen" a settled
> shift. If something must be corrected, open a new shift and treat the following
> trading period as a new day.

### 16.5 Reprinting the Z report

Open the shift detail (`/shop/{store}/till/sessions/{id}`) → the
**Zレポートを印刷** button at the top → a PDF is downloaded.

While the file is being generated it shows *「生成中…」*.

> ⚠️ The button is greyed out while the shift **has not ended**, with the note
> *「シフトが終了するとZレポートを出力できます」*

### 16.6 The force-close activity card

It appears on both the monitoring board and the Stale shifts tab.

Title: *「強制終了アクティビティ（30日）」*

| Metric | Japanese |
|---|---|
| Shifts force-closed | 強制終了 |
| Shifts the system expired | システム期限切れ |
| A breakdown per manager | マネージャー別 |

With no data: *「過去30日のデータはありません」*

### 16.7 Reading the shift detail page

| Section | Japanese | Content |
|---|---|---|
| The tax mode at opening | 開始時の税モード | **税込** (prices include tax) or **税別** (they do not) |
| The opening float | 釣銭準備 | Per denomination plus the total |
| The counted cash | 実査 | Per denomination plus the total |
| Cash reconciliation | 現金照合 | 予定金額 (expected) / 実査金額 (counted) / **過不足** (over/short) |
| Paid in and out | 入出金記録 | The list of pay-ins and pay-outs. Kinds: 入金 (in) / 出金 (out) / 金庫から両替 (change from the safe) / 金庫へ集金 (collected to the safe) |
| Reconciliation by method | 支払方法別精算 | 支払方法 / 予定 / 申告 / 差異 / 理由 |
| The action log | 操作履歴 | Who did what and when. When empty: *「履歴がありません」* |

**The actions in the log:** シフト開始 (shift opened) · シフト締め (shift closed) ·
下書き保存 (draft saved) · シフト破棄 (shift abandoned) ·
**マネージャー強制終了** (manager force close) · **システム失効** (system expiry) ·
**マネージャー手動精算** (manager manual settle) ·
釣銭準備の上書き (opening float overridden) · 入出金記録

**Expiry reasons:** 無活動 (inactivity) · 管理者による手動失効 (expired manually by
an administrator) · デバイス通信切断 (the device lost communication)

If the shift was force-closed, a red banner appears at the top of the page:
*「このシフトはマネージャーによって強制終了されました」* plus
`理由コード: {code} / 操作者: {who}`

### 16.8 How does a shift expire on its own?

An hourly job moves a shift to `expired` when **all three** conditions hold:

```
① The shift is `open` or `closing`
② It has been open for more than 48 hours            (configurable)
③ There has been no payment transaction in the last 6 hours  (configurable)
```

This is exactly why a cashier sees the *"Shift expired"* screen on the POS
([section 11.6](pos-cai-dat-mo-ca.md#116-if-the-system-closes-the-shift-mid-way)).

### 16.9 What Admin does NOT have

- ❌ **No screen for creating a till.** Tills are created **automatically** — one
  till with the code `MAIN` per branch, the first time a cashier opens a shift.
  Managers do not have to do anything.
- ❌ **No chain-summary screen.** The chain summary report is only **printed from
  the POS** at the final close. (The chain's state still affects Admin: it keeps the
  currency / tax / rounding locks in place until the final close.)
- ❌ **No refund screen for ordinary transactions.**
