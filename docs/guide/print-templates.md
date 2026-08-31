---
title: Print template registry
category: guide
tags: [printing, print-template, plan-053, versioning, sync-down]
summary: "Cloud-owned three-layer print template registry (system to brand to shop), the shop_editable allow-list, immutable versions, effective_from in business time, and sync DOWN to the workstation."
related: [printing, business-time]
---

# Print template registry — three layers, versioned, synced DOWN (#1171)

> Canonical reference for the Cloud-owned print template registry introduced
> by plan-053 / issue **#1171**: the three-layer model (system → brand → shop),
> the `shop_editable` allow-list, the immutable version lifecycle,
> `effective_from` in **business time**, sync DOWN to the workstation, and
> reprint by original version. Backend slice (M1–M3); the admin UI (M4) and
> the PHP renderer + Go↔PHP golden parity (M5) build on these primitives.

Related: [business-time.md](business-time.md) (`BusinessClock`, #1091),
[offline-order-evidence.md](offline-order-evidence.md) (the immutable-revision
pattern this borrows), [tax-types.md](tax-types.md) (where the per-rate tax
block's numbers come from),
[item-edit-and-void-policy.md](item-edit-and-void-policy.md) (赤伝 / void).

---

## The problem

Thirteen slip layouts were hard-coded in Go on the workstation
(`internal/service/print_*.go`). Changing one line of a receipt meant editing
code, building, and releasing to every shop. Cloud owned only the
**parameters** — store name, paper width, currency, locale, 登録番号 — never the
**layout**.

The registry moves the layout to Cloud as **data**: HQ edits it, publishes an
immutable version, and every workstation of the brand picks it up on its next
pull. No software release.

Two rules make that safe rather than reckless:

1. **A template PRESENTS, it never COMPUTES.** Money and tax come from the
   engine (`PerRateTaxBuckets`, #1154 allocations). A definition contains no
   arithmetic and no placeholders at all — publish rejects `{{ … }}` outright
   (TR-15). A template that could compute could disagree with the books.
2. **Compliance blocks are locked.** 登録番号, the per-rate tax block, the grand
   total, the invoice number, 「再発行」, the 赤伝 marker and the issue timestamp
   can be positioned by the system default and nothing else. Not even HQ may
   reword or reorder them (TR-16/TR-18).

---

## Three layers

```
LAYER 0  SYSTEM DEFAULT   config/print_templates.php + config/print_blocks.php
                          (CODE — a machine that has never been online prints)
LAYER 1  BRAND            HQ publishes per kind; applies to every shop
LAYER 2  SHOP OVERRIDE    the shop edits ONLY what the brand delegated
```

`App\Services\Print\TemplateResolver::forBranch($kind, $branchId)` merges them
and returns one definition plus its provenance.

### Why layer 0 is code, not a seeded row

TR-05: a brand-new workstation, or one whose cache was wiped, must still be
able to print. A seeded database row cannot reach a machine that has never
talked to Cloud; a definition shipped with the software can.

### The merge is FIELD-WISE, not wholesale

This is the decision the whole model rests on (TR-02). The naive alternative —
"the highest layer that exists wins entirely" — makes central management
useless in practice: a shop that changed its footer once would be frozen on
that day's brand layout forever, and HQ pushing a new tax block would silently
never reach it.

So an overlay contributes only the fields it actually sets:

- top-level keys merge by key;
- `blocks` merge by block **id**; shared ids keep the **base's order** and take
  the overlay's props one by one; ids the base lacks are appended.

Base order being authoritative is also the first line of defence against an
overlay reordering the compliance blocks.

### `shop_editable` — the brand's allow-list

The brand's published row carries the paths a shop may override:

```json
"shop_editable": ["logo", "footer_text", "greeting", "qr_block.enabled"]
```

A path is a **block id** (any prop of that block) or `blockId.prop` (that prop
only). `[]` locks the slip completely. A brand may not delegate a `locked`
block — that would let a shop do what HQ itself cannot.

The allow-list is applied **at resolve time**, not at write time. That single
choice gives TR-04 its behaviour for free: when a brand narrows the list, the
shop's override of the removed field stops applying immediately, yet the stored
row is untouched — widen the list again and the override comes back to life.
Nothing is destroyed by an administrative decision that may be reversed
tomorrow.

Enforcement is three-deep: the UI disables the field, the publish validator
rejects an out-of-list edit (422, TR-03), and the resolver filters it anyway.

---

## The block catalog

`config/print_blocks.php` is the allow-list every definition is checked
against. Each block declares a **mutability**:

| Mutability | Meaning |
|---|---|
| `locked` | The renderer builds the content from the engine. A definition may only position it; it is always on. |
| `toggleable` | Same, but `enabled` may be flipped — subject to `require_enabled_when`. |
| `free` | The brand authors the content (i18n text, image, QR target). |

`sources` and `param_fields` are likewise allow-lists. A `source` is a name of
data the renderer already holds, **never a URL** (TR-21): an arbitrary URL in a
definition would make every workstation in the fleet fetch an attacker-chosen
address and pipe the bytes at a printer.

### 登録番号 and the #1152 ruling

`registration_number` is `toggleable`, not `locked`, with
`require_enabled_when: seller_has_registration_number`. A 免税事業者 with no
number is legal and must not be nagged — but once the brand (or the branch,
via the #1152 override) **has** a number, printing it is mandatory and turning
the block off is a 422 (TR-17). Resolution order is the same
`SellerRegistrationResolver` branch → brand chain the invoice uses.

---

### `vat_disclaimer` — khối phủ nhận, và vì sao nó `locked` chứ không `toggleable` (#2062)

Sau #1779 kind `red_invoice` **không còn là hoá đơn GTGT**: không số hoá đơn,
không ký số, không mã CQT. Nhưng nó vẫn in tiêu đề `HOA DON DO` ở `vi`/`en` —
tên một chứng từ luật định. Bản `ja` thì vốn trung thực (領収書).

#2062 sửa hai đầu cùng lúc:

- tiêu đề `vi`/`en` → **`PHIEU THANH TOAN` / `PAYMENT RECEIPT`**;
- thêm khối **`vat_disclaimer`** mang câu `KHONG THAY THE HDDT CUA CO QUAN THUE`,
  nằm trong `blocks` **và** `required` của `red_invoice`.

**Vì sao `locked` + `editable_props: []`, không phải `require_enabled_when`.**
Cặp đối chiếu là `registration_number`: khối đó *phải* tắt được, vì 免税事業者
không có số đăng ký là hợp pháp — trạng thái của quán quyết định nó có nghĩa hay
không. Câu phủ nhận này thì ngược lại: **không trạng thái nào của quán làm nó
sai**. Nên `locked` + `required` đóng cả ba lối cùng lúc — bỏ khối khỏi
definition, tắt khối, hay dời nó đi chỗ khác.

**Và nó KHÔNG rẽ theo locale** — in cả ở `ja`. `red_invoice` là chứng từ của quán
VN; `locale` chỉ là ngôn ngữ giao diện thu ngân. Một lời phủ nhận biến mất khi
thu ngân đổi ngôn ngữ là một lời phủ nhận tắt được bằng một cú chạm.

## Version lifecycle

```
draft ──(validate)──▶ published ──(explicit retire)──▶ retired
```

- **One draft** per (kind, scope, brand, branch). Editing edits that draft.
- **Published is immutable** (TR-08). Every write path refuses a non-draft row
  with `409 PRINT_TEMPLATE_IMMUTABLE`. A printed slip must stay explainable
  years later, which is only possible if the definition it came from can never
  change.
- **Rollback is a publish, never an un-publish** (TR-38). Republishing an old
  definition creates a NEW version with auto-generated notes
  (`Rollback from vN`), so "what were we printing on the 3rd" stays answerable
  after any number of mistakes.
- **Retire** takes a version out of service for NEW prints only. The row lives
  forever so a reprint of a job that used it still renders (TR-13/TR-28).

### Publishing does NOT auto-retire the previous version

DESIGN's state diagram suggests it; the implementation deliberately does not.
Auto-retiring opens a hole: publish a version with a **future**
`effective_from` and the outgoing version would be retired immediately,
leaving a window in which the resolver falls all the way back to the system
default and every shop's slip silently changes. Instead, the newest version
already in force simply wins, and `retired` stays an explicit administrative
act.

### Two conflict doors, both 409

| Code | When | Why not auto-resolve |
|---|---|---|
| `PRINT_TEMPLATE_DRAFT_STALE` | Two people edited the same draft (TR-09) | Auto-merging two JSON layouts produces a third layout nobody designed. The loser reloads and re-applies. |
| `PRINT_TEMPLATE_REBASE_REQUIRED` | The draft's parent is no longer the live version (TR-10) | Publishing anyway would silently revert whoever published in between. |

The draft lock is a **content-derived token** (`lock_token`), not `updated_at`:
MySQL stores timestamps at one-second resolution, so two saves inside the same
second would both see an unchanged `updated_at` and the second would clobber
the first — precisely the failure the lock exists to prevent. Content-derived
also means two people who happen to save the *same* layout do not fight over
it, because they are not actually in conflict.

---

## `effective_from` is BUSINESS time (#1091)

`effective_from` is a **branch-local wall clock**, not an instant. HQ schedules
"2026-08-01 00:00" and each branch flips at **its own** midnight — a Tokyo shop
two hours before a Hanoi one — exactly the way a breakfast menu window works.

The resolver therefore compares against
`BusinessClock::now($branchId)->format('Y-m-d H:i:s')`, never `now()`.
Resolving on the server clock would flip every branch on Tokyo's (or UTC's)
schedule and re-issue Hanoi's receipts nine hours early.

Consequences:

- The column is **not** cast to `datetime` on the model. Casting it would
  invite a timezone conversion that silently shifts the switch-over by the
  branch's UTC offset.
- `null` means "in force from publication".
- A value in the **past** is valid and simply means "now" (TR-11). It never
  rewrites a slip that was already printed, because a printed job carries the
  version it used.
- `Y-m-d` is accepted as shorthand for that business day's midnight.

`tests/Feature/Print/TemplateResolveTest.php` R7 pins this down: one frozen
instant at which it is already the 1st in Tokyo but still the 31st in Hanoi and
UTC, asserted across all three branches.

---

## Sync DOWN

```
GET /api/v1/workstation/print-templates?since=
GET /api/v1/workstation/print-templates/{kind}/versions/{version}
```

Device-token auth (`device.auth:workstation`), same as every other workstation
replica feed. The branch comes from the paired device; an explicit
`?branch_id=` that disagrees is a `403 BRANCH_MISMATCH`.

The payload carries definitions **already resolved for the branch** — the three
layers merged server-side, once. The workstation must not re-implement the
merge: a second implementation of TR-02/TR-04 in Go is a guaranteed source of
"the preview and the slip disagree" bugs, and the shop that suffers it is the
one that cannot read the code.

Each entry carries `version`, `effective_from`, `updated_at` and a
**`checksum`** (sha256 of the recursively key-sorted definition). The checksum
is how the workstation decides a download is complete and trustworthy before it
is allowed to replace a working cache — TR-24: never overwrite a good cache
with unverified bytes. Key order is normalised so an unrelated PHP array
reshuffle does not make the whole fleet re-download the registry.

`?since=` makes it a delta. The response also includes `branch_timezone` and
`branch_wall_clock` so a workstation whose own clock has drifted can compare
against Cloud's answer while it is online (TR-25).

Measured in test: the full 13-kind payload for a branch is well under the
100 KB budget (§8).

### Reprint uses the ORIGINAL version

`TemplateResolver::forVersion()` addresses a specific historical version and
**includes soft-deleted rows** — a brand or branch that has been removed must
not make 再発行 of last quarter's invoice impossible (TR-39). 赤伝 for an old
invoice uses the version of the ORIGINAL invoice, so the credit note lines up
with the document it reverses (TR-30).

A version that is genuinely gone returns `404 PRINT_TEMPLATE_VERSION_GONE` —
the caller's cue to print the current version **with a visible marker** and a
log, never to substitute it silently (TR-29).

---

## The workstation side (M3-Go)

The Go renderer, the local cache and the fallback chain live in
`workstation/internal/service/print_*.go`. One sentence governs all of it:

> **A template problem can never stop a sale.**

### Production rollout (#1945)

Workstation settings (local SQLite `settings` table, not Cloud `shop_settings`):

| Key | Default | Effect |
|---|---|---|
| `print_template_renderer_enabled` | **ON** (absent) | Money slips go through `renderMoneySlip` → layer 0 renderer |
| `print_template_renderer_enabled` = `false`/`0`/`no` | — | Rollback: legacy Go formatters only |
| `print_template_use_published_templates` | **OFF** (absent) | Ignore brand/shop cache; always embedded layer 0 |
| `print_template_use_published_templates` = `1`/`true`/`yes` | — | Full TR-14 resolve chain (brand publish opt-in) |

**Where the switch is** — the table above names the keys; it does not turn
anything on. A machine with the GUI: **Settings → printing → "In bằng mẫu đã
phát hành"**. A headless `ws-server`: `PUT /api/settings/…` over **that
machine's own loopback**. There is deliberately **no path from Cloud** — the
flag exists to require a human beside the printer, looking at the first sheet.
#2017 holds the open question of whether HQ should ever flip it in bulk.

Note the two keys do **not** share a truth table: this one is read with
`settingTruthy` (`1`/`true`/`yes`), while the app's other toggles are read with
a strict `== "true"` (#2022).

See `docs/guide/printing.md` §13 for the seam contract, the fail-open behaviour,
and how to verify a published template on a fake TCP printer without burning
paper.

**First real publish: 2026-08-07.** Until that day `print_templates` was empty
in every database — the whole "published" branch had never run outside tests.
The trial (one shop, brand v1 delegating `footer_text` + a shop overlay
enabling it) is written up on #1171: byte-identical output except the delegated
block, `print_jobs.template_version` stamped `shop:1`, and the resolver holding
the boundary for every other branch.

### The renderer

```go
RenderPrintTemplate(def *PrintTemplateDefinition, data *PrintRenderData,
                    profile PrintRenderProfile, locale string) (PrintRenderResult, error)
```

- `def` — the JSON IR (`tempo.print.v1`) Cloud sent, or the copy embedded in
  the binary.
- `data` — **already computed**. Every amount, tax bucket, count and timestamp
  is decided by the engine before the renderer is called; the builders that do
  that computing are `New*RenderData` in `print_render_data.go`, one per kind,
  each a transcription of the arithmetic the matching `Format*Ticket` does
  today. The renderer itself does no arithmetic beyond column geometry.
- `profile` — `Columns`, `Paper` and the native-vs-raster ruling.
  `PrintRenderProfileFor(printer.Profile, paper)` adapts plan-052's **capability
  profile** (#1166) onto it, so the hardware question has exactly one owner: the
  renderer asks `Profile.TextModeFor`, it never re-derives "kanji ROM ⇒ native".
  The adapter only READS `internal/printer`.
- Block dispatch is a per-kind table (`printKindPlans`); a `locked` block's
  content is built here from `data`, so no brand can reword 合計.

**Width precedence** is printer → job config → the template's `paper` map →
the kind's historical default. A template can never make a slip wider than the
paper it is printed on.

**i18n** resolves locale → `ja` → `en` and warns **once per (block, locale)** —
the `warnedBrands` pattern from `TaxResolver`. Warning per print would drown a
busy shop's log; not warning would hide a half-translated template (TR-19).
Authored text wraps to the paper by display width (fullwidth = 2 columns), so a
pasted paragraph never runs off the edge (TR-20).

### The migration gate (TR-40) — the reason this is safe to ship

`print_renderer_golden_test.go` renders **every kind at every locale on 32 / 42
/ 48 columns** twice — once through the hard-coded formatter, once through the
system default definition — and asserts the two byte streams are **identical**.
117 combinations, all green:

| Kind | Legacy formatter | Gate |
|---|---|---|
| receipt | `FormatPaidTicket` | ✅ byte-identical |
| kitchen | `FormatKitchenTicket` | ✅ |
| runner | `FormatRunnerTicket` | ✅ |
| delta_qr | `FormatDeltaQRTicket` | ✅ |
| remaining | `FormatRemainingTicket` | ✅ |
| vat_invoice | `FormatVatInvoice` | ✅ |
| red_invoice | `FormatRedInvoiceTicket` | ✅ |
| void_notice | `FormatVoidNotice` | ✅ |
| debt_slip | `FormatDebtSlip` | ✅ |
| shift_open | `FormatShiftOpenReport` | ✅ |
| shift_report | `FormatShiftReport` | ✅ |
| chain_report | `FormatChainReport` | ✅ |
| table_paid | `FormatTablePaid` | ✅ |

A second assertion (G1) pins the produced bytes to recorded hashes in
`internal/service/testdata/print_golden.json`, so an edit that changed BOTH
paths in the same way still trips. Regenerate deliberately with
`go test ./internal/service/ -run Golden_G1 -args -update-print-golden`.

> **⚠️ The gate is currently RED on `dev`** (observed at workstation `b0ab8df`,
> and it is not caused by anything in #1181 — G1 and the #1181 cross-repo gate
> both still pass).
>
> `d022fd4` ("#146 kiosk-scannable order QR") changed the QR payload in the
> legacy `formatBillTicket` from the bare `order.ID` to a JSON
> `{orderId, orderCode, type}` blob, so the kiosk can resolve a scanned slip by
> `orderCode`. The **definition renderer's `emitBillQR` was not changed with
> it** and still emits `order.ID`, so the two paths now disagree on `runner`,
> `delta_qr` and `remaining` — 27 of the 117 combinations.
>
> This is precisely the drift TR-40 exists to detect, and it is not cosmetic:
> the moment T3.6 cuts the LAN print handlers over to
> `PrintTemplateStore.RenderSlip`, every bill QR silently reverts to the UUID
> and kiosk scanning breaks again — the exact bug #146 was filed to fix.
>
> **Fix the renderer, not the assertion**: `emitBillQR`
> (`internal/service/print_renderer_bill.go`) should call `kioskQRPayload(order)`
> for the `order_url` source, then regenerate the golden hashes deliberately.
> Left alone here because #1181 is forbidden from touching the Go renderer.

If a kind ever fails this gate, **fix the definition, never the assertion**. A
loosened comparison converts a silent change to thousands of receipts into a
green build.

#### The cut comes from the printer profile, not the template — #1950

`escpos.Finish(profile)` handles four cases and had existed for months, but the
only caller was the setup wizard. Every real print path emitted a bare
`FullCut()` = `ESC d 3`, which reads no profile. Measured against the three
shipped presets:

| preset | declares | received | |
|---|---|---|---|
| `escpos_generic` | `gs_v_full` (`GS V 0`) | `ESC d 3` | ✗ wrong dialect |
| `epson_tm_i` | **`gs_v_partial`** (`GS V 1`) | `ESC d 3` | ✗ **full cut, slip falls on the floor** |
| `star_mcprint` | `esc_d` | `ESC d 3` | ✓ right, by coincidence |

`epson_tm_i` is the expensive one: it declares PARTIAL precisely so a tab of
paper keeps the slip hanging in the mechanism for the cashier to tear.
`feed_before_cut` was ignored outright, and the two cases `Finish` exists for —
`none` (a tear-bar machine: still fed, **never** sent a cut, P-36) and
`auto_cut_per_job` (send nothing, or every slip is followed by a blank one) —
had never executed on a print path.

**The fix is a nullable finishing spec on the render profile, on both sides:**
`PrintRenderProfile.Finishing *escpos.Finishing` (workstation) and
`PrintRenderProfile::$finishing` (Cloud). `null` means "no profile" and
reproduces `ESC d 3` byte for byte, which is what keeps the 126 golden hashes and
the 117-cell Go↔PHP parity gate unchanged — they render with an empty profile on
both sides. The DRIVER fills it in: `service.PrintRenderProfileFor` on the
workstation, `CloudPrntJobRenderer::render()` on Cloud.

That the two gates render with an empty profile is also their limit: neither ever
exercises a configured machine. So the epilogue is gated on its own, by the
`finishing_hex` group in `print_primitives_golden.json` — Go writes it, PHP reads
it, same fixture as every other primitive.

| Claim | Gated by |
|---|---|
| each declared dialect produces the right bytes (workstation) | `internal/service/print_finishing_profile_test.go` |
| each declared dialect produces the right bytes (Cloud, end to end through the driver) | `tests/Feature/Printing/CloudPrntFinishingTest.php` |
| Go and PHP agree on those bytes | `finishing_hex` in `print_primitives_golden.json` |
| a no-profile render still emits exactly yesterday's bytes | both files above |
| an UNRECOGNISED cut mode sends no cut on either side | `Profile.normalised()` (Go) · `PrinterCapabilityProfile::cutMode()` (PHP) |

The Cloud golden-hash assertions (`CloudPrntServingTest`, `CloudPrntEnqueueTest`)
now normalise the served slip's cut back to `ESC d 3` before hashing, rather than
loosening the hash: "everything up to the cut is byte-identical to the
workstation's slip" and "the cut is the one this machine declares" stay two
separate, checkable claims.

⚠️ **Deployment note.** A printer whose `model_profile` is NULL resolves to
`escpos_generic`, which declares `gs_v_full`. A **Star** mC-Print3 in StarPRNT
emulation ignores `GS V` (#438) — so a Star machine that never went through the
setup wizard will now be sent a cut it does not understand and the paper will not
eject. Before #1950 that mislabelling was invisible, because every machine got
`ESC d 3` regardless of what its profile claimed. Run the wizard (or set the
`star_mcprint` preset) on Star hardware before rolling this out.

⚠️ **Still bare `FullCut()`**: the eleven LEGACY `Format*` formatters
(`print_service.go`, `print_shift_report.go`, `print_debt.go`,
`print_vat_invoice.go`, …). They take `PrintJobConfig` and no profile, and since
#1945 they are only reached when the template renderer is explicitly switched off
or fails. Left alone deliberately — teaching them the profile means eleven
signature changes, and changing them alters the bytes of the fallback path.

### Layer 0 in the binary

`internal/service/print_templates_default.json` (embedded via `go:embed`) is
the workstation's copy of layer 0 — the same slips, expressed as templates. It
mirrors Cloud's `SystemTemplateDefaults`; the places the two still disagree are
listed under **catalog gaps** below and must be closed before a brand's first
publish, because Cloud's copy is what a brand starts editing from.

### Cache + version selection

Local table `print_templates` (migration `061_print_templates.sql`), keyed
`(kind, version)` and never updated in place — a published version is immutable
upstream (TR-08) and keeping the old rows is what makes a reprint honest
(TR-28). `SyncPuller.PullPrintTemplates` rides the existing pull tick beside the
invoices feed.

`effective_from` is stored as the branch's **wall-clock string**, not a
timestamp (#1091): casting it would drag it through a timezone conversion and
move the switchover by exactly the branch's offset. Selection is
"newest version whose `effective_from` has arrived in **branch** time", where
branch time comes from `branch_timezone` (preferred) or the last sampled
`branch_wall_clock` offset (TR-25). The same instant resolves to different
versions in Tokyo and Hanoi — asserted in the tests.

### Fallback chain (TR-14) — never blocks a print

```
cached version in force  →  next older cached version  →  system default in binary
```

`PrintTemplateStore.RenderSlip` walks it and additionally re-renders from the
system default if the resolved definition renders with an error. Every fallback
logs loudly and is flagged on the returned `PrintTemplateSource`, so the shop
finds out from the log — not from a customer waiting at the till.

**TR-24** is enforced on the way in: every pulled entry's definition is
re-hashed locally (`PrintTemplateChecksum`, byte-parity with Cloud's
`TemplateChecksum::of` — pinned by a test against a PHP-computed digest) and a
single mismatch aborts the **entire** write and leaves the cursor untouched. A
half-written registry is worse than a stale one: stale prints last week's
footer, half-written prints an unpredictable slip.

### Raster per block (TR-36)

`PrintRenderResult.Segments` is the slip split per block, each tagged `native`
or `raster`, and the tag comes from plan-052's capability profile: a machine
with no kanji ROM gets its Japanese blocks rastered and its money native, which
is the split TR-36 is actually about. QR and control sequences always stay
native — they are device commands, not text. Segments concatenate back to
exactly the slip, so a transport that does not implement raster is no worse off.

**Deviation:** the bitmap ENCODING itself is not implemented here.
`internal/printer` belongs to plan-052 and this milestone only reads from it.
What ships is the per-block segmentation and the mode decision the encoder will
consume.

### Catalog gaps — CLOSED by #1181

The Go defaults are ordered and named to match the formatters (that is what
makes the gate pass). Cloud's catalog was never held to the same standard, and
when it was finally compared, **all thirteen kinds** disagreed — not the seven
points originally listed.

That was a BLOCKER rather than a tidy-up because of where Cloud's default is
consumed: the admin UI (M4) shows a brand the Cloud default and lets it edit
from there, so publishing once would have pushed Cloud's idea of a receipt over
the shop's real one — the exact outcome TR-40 promises can never happen.

| # | Gap | Resolution |
|---|---|---|
| 1 | Block ORDER in the money footer — Cloud listed `tax_legend, subtotal, …, tax_breakdown, grand_total` | **Fixed.** The slip prints `subtotal → discounts → service_charge → grand_total → tax_breakdown → tax_legend → registration_number`. The 内税 split sits UNDER the total (#1042) because it is already inside it. |
| 2 | `remaining` missing from `receipt` | **Fixed** — added to `receipt` and `red_invoice`, after the tender rows. |
| 3 | EN `column_header` said `Item … Amount` | **Fixed** — the slip says `Item … Price` (`printLabels.Price`). |
| 4 | `reprint_marker` position on `vat_invoice` / `debt_slip` | **Fixed** — it prints near the TOP. It was also *required* on six kinds whose formatter never printed one (kitchen, void_notice, table_paid, shift_open, shift_report, chain_report), which made the honest definition unpublishable; dropped there. |
| 5 | `vat_invoice` title is NOT localised | **Fixed as-is.** The heading is a Vietnamese statutory form name in every locale, with the <42-column shorthand (`HOA DON GTGT`) now expressible via a new `i18n_narrow` prop. `debt_slip` is the same. Translating them is a product decision with a compliance question attached, not a migration side effect. |
| 6 | Shift-report section blocks — no ids for seven of the nine 精算 sections | **Fixed** — `sales_summary`, `non_cash_change`, `discount_summary`, `acct_correction`, `check_count`, `cash_movement`, `void_summary` added as **toggleable**: a settlement report is an internal operations document, so a brand may hide a section it does not run, but the content stays engine-owned. |
| 7 | `shift_open` note + device | **Fixed** — `order_note` added, `shift_meta` names the DEVICE (`device_name`, a new param field). `customer_tax_code` / `customer_address` were likewise missing for the VAT invoice's buyer block. |
| 8 | *(found while fixing)* An empty `i18n` map encoded as `[]` | **Fixed.** PHP has one array type, so `json_encode([])` picks `[]`, and Go refuses an array where it expects `map[string]string` — failing the WHOLE definition. The workstation would then fall back to its embedded default (TR-14), silently discarding every brand and shop edit while the registry looked perfectly healthy from Cloud's side. `DefinitionNormalizer` drops the key instead; absent and empty mean the same thing on both sides. |

#### How the fix is proved

Not by inspection. `php artisan print-templates:export-defaults` writes Cloud's
layer 0 as JSON into the workstation's `testdata/`, and
`print_cloud_parity_test.go` renders it with the **real Go renderer**, hashing
against the same `print_golden.json` the TR-40 gate uses:

```
renderer(cloud layer 0) == recorded golden hash == renderer(go layer 0)
```

117/117 combinations green. Because both sides are compared to one fixture, a
pass means the two definitions are behaviourally identical rather than
similar-looking JSON: harmless prop differences (Cloud omits an empty `i18n`
where Go writes `{}`) pass, anything that would move a byte on paper fails.

On the PHP side `CatalogParityTest` keeps the exported fixture from going stale
and pins each gap with its own named assertion, so a regression says *which
rule* broke rather than "a hash moved".

> If the parity test fails, fix the **Cloud catalog** and re-export. Never
> regenerate the golden hashes to make it pass — those hashes are the paper in
> the till.

### Files (workstation)

| Concern | File |
|---|---|
| Definition IR + locale fallback + checksum | `internal/service/print_template_def.go` |
| Renderer core, segments, width, authored text | `internal/service/print_renderer.go` |
| Emitters — bill family + kitchen | `internal/service/print_renderer_bill.go` |
| Emitters — invoice / void / debt / table-paid | `internal/service/print_renderer_docs.go` |
| Emitters — shift open / settlement / chain | `internal/service/print_renderer_shift.go` |
| Engine-side data builders | `internal/service/print_render_data.go` |
| Layer 0 in the binary | `internal/service/print_templates_default.{json,go}` |
| Cache, version selection, fallback, pull | `internal/service/print_template_cache.go` |
| Local schema | `internal/store/migrations/063_print_templates.sql` |
| plan-052 profile adapter | `internal/service/print_render_profile.go` |
| Print clock seam | `internal/service/print_clock.go` |
| Tests — migration gate (G1/G6) | `internal/service/print_renderer_golden_test.go` |
| Tests — cache W1–W7 + checksum parity | `internal/service/print_template_cache_test.go` |
| Tests — version-selection & pull edges | `internal/service/print_template_cache_edge_test.go` |
| Tests — raster/i18n/wrap/fallback | `internal/service/print_renderer_behaviour_test.go` |
| Tests — block types, damaged definitions | `internal/service/print_renderer_coverage_test.go` |
| Finishing: profile → bytes (#1950) | `internal/printer/escpos/finishing.go` |
| Tests — each profile gets its dialect | `internal/service/print_finishing_profile_test.go` |

### What the workstation tests pin

Beyond the 117-case migration gate:

- **Block types** — each of `text` / `params` / `line_items` / `qr` / `locked`
  rendered alone and all together, on 32 and 48 columns, in all three locales.
  `image` is asserted to be a **safe no-op**: the ESC/POS encoder here has no
  raster primitive (plan-052 owns `internal/printer`), so enabling a logo must
  change nothing rather than crash.
- **Composed header** — store name + title share one physical line; the
  store-only, title-only and both shapes each render, and a store name too wide
  for the paper falls to two lines.
- **i18n** — fallback order is asserted to be locale → **ja** → en (a template
  with ja+en serves JA to a Vietnamese cashier); exactly **one** warning per
  (template, locale) across repeated prints of a template with two untranslated
  blocks; a fully translated locale warns zero times.
- **Wrapping** — kanji-only, mixed fullwidth/halfwidth, a single token longer
  than the line (both ASCII and fullwidth), whitespace-only, embedded newlines,
  and text outside Shift_JIS. `displayWidth` itself is pinned, including that
  **※ measures as ONE column** — not obviously right, but it is the geometry
  every filed 軽減税率 receipt was laid out with.
- **Damaged definitions** — ten malformed shapes are refused by the parser, and
  eight of them sitting in the cache each still produce a slip **byte-identical
  to today's receipt**. Locked blocks reordered, removed, duplicated, or joined
  by an unknown block id all still print.
- **Version selection** — the `effective_from` comparison is **inclusive**
  (a version effective 09:00:00 is in force at 09:00:00); ties break to the
  **highest version**; a future-only cache falls back to the default; a NULL
  `effective_from` is always in force; wall-clock, ISO-`T`, `Z`, `+09:00` and
  date-only forms all select identically; one frozen instant resolves to
  different versions in Tokyo vs Hanoi vs UTC (#1091); a **stale** cached
  version still beats the binary's default (upgrading the software must not
  silently un-publish HQ's decision).
- **Pull** — one poisoned entry rejects the whole batch; a malformed payload
  writes nothing and does not advance the cursor; a checksum-less (older) Cloud
  is accepted; an empty delta refreshes the branch clock without touching the
  cache; republishing the same version upserts instead of duplicating; the
  `?since=` cursor is URL-encoded so a `+09:00` offset cannot arrive as a space.

**Not covered, deliberately:** "valid checksum over broken JSON" is
unreachable — the checksum is computed by DECODING the JSON, so a non-JSON
definition fails the pull before any checksum exists. The reachable neighbour
(valid JSON, invalid *definition*) is covered and ends in the fallback.

---

## Validation happens at publish, never at print

That asymmetry is the whole safety model (TR-14). Everything that could make a
slip wrong is caught in front of the person who wrote it; the print path stays
unconditional. A shop must never be unable to sell because a template is bad —
a broken definition at print time falls back to the system default and logs
loudly.

`App\Services\Print\TemplateValidator` runs, in DESIGN §4 order:

1. envelope + every block id in the catalog **and** in this kind;
2. locked blocks untouched — content, props **and relative order**;
3. every required block present, and enabled where the law says so;
4. a shop override stays inside `shop_editable`;
5. i18n covers ja/en/vi or declares `fallback: true` (TR-19);
6. a **render trial**;
7. images sane — oversize is **clamped**, not rejected (TR-22).

Plus the two absolutes: no expressions (TR-15), no `source` outside the
allow-list (TR-21).

### Brand definitions are whole; shop definitions are overlays

A **brand** definition is the entire document: omitting `tax_breakdown` means
"I want it gone", and the answer to that has to be a 422, not a silent restore.
A **shop** definition is a partial overlay of the delegated fields, so the
structural rules are checked against what would actually print — the overlay
merged onto the layer below.

### The render trial (M5)

`EscposRenderProbe` runs it, using the **same primitives as the renderer**
(`Renderer\Layout`, `Renderer\Escpos`), so what publish approves and what the
printer produces cannot drift apart. It replaced `StructuralRenderProbe`, which
carried its own width table — one that measured an emoji as one column where
the printer uses two. A publish gate whose ruler is a different length from the
printer's is worse than no gate: it passes layouts that overflow, and it is
believed.

Two checks, across 2 paper widths × 3 locales (and `i18n_narrow` too — the 58mm
variant is a real slip):

**1. Geometry.** Fails on a single **unbreakable** token wider than the paper,
measured in display columns. Long-but-wrappable text passes — TR-20 says the
renderer wraps. Note what is deliberately *not* done: the text is not pushed
through `wrapText` and checked for an over-wide line, because `wrapText` always
succeeds (it hard-splits). That is correct at print time and useless as a gate.
The violation names which paper width failed, because 58mm and 80mm shops in
the same brand share one definition.

**2. Encodability** — `RENDER_TRIAL_UNPRINTABLE_CHARACTER`. The text is encoded
to Shift_JIS exactly as the renderer would and checked for substitution marks.
This is the check geometry alone could never make, and it catches a whole class
of complaint that used to reach the shop: a character with no glyph in the
printer's codepage does not fail, does not warn, and looks perfect in the
browser — it simply comes out of the till as a blank or a black block, on every
slip, until somebody phones support.

> **The wave-dash trap.** Japanese opening hours are written "10:00〜22:00", and
> two characters look identical on screen: **U+301C 〜 WAVE DASH has no glyph**,
> **U+FF5E ～ FULLWIDTH TILDE prints correctly**. macOS and most Japanese IMEs
> emit U+301C, so the natural thing to type is the broken one. The probe names
> the character and its code point (`E10b` pins both directions).

Em dashes pasted from a word processor, `€`, `£` and stray Windows glyphs are
the same story. Vietnamese diacritics are **not** — they are folded to ASCII
before encoding (`phở đặc biệt` → `pho dac biet`), so they pass; and `¥`/`₫`
are mapped to printable substitutes rather than flagged.

### The PHP renderer (M5)

`App\Services\Print\Renderer\` is Cloud's half of DESIGN §7. What ships:

| Concern | File |
|---|---|
| ESC/POS encoder (StarPRNT bytes, Shift_JIS, accent folding) | `Renderer/Escpos.php` |
| Shift_JIS repertoire overrides — **generated**, 456 code points | `Renderer/ShiftJisRepertoire.php` |
| Geometry: display width, wrapping, padding, price format | `Renderer/Layout.php` |
| Definition IR: locale fallback + warn-once (TR-19) | `Renderer/Definition.php` |
| Slip composition for preview | `Renderer/SlipComposer.php` |
| SVG preview | `Renderer/SvgRenderer.php` |
| TR-33 standard sample basket | `Renderer/SampleSlipData.php` |

#### Shift_JIS repertoire — why a generated table

PHP's `SJIS` codec and Go's `japanese.ShiftJIS` disagree on **456 BMP code
points**, and neither is a superset:

- PHP encodes 9 that Go substitutes: `¢ £ ¥ ¬ ¯ ‖ ‾ −` and **〜 (U+301C)**;
- Go encodes 447 that PHP substitutes: the NEC/IBM extension rows — `① ② ③`,
  `Ⅰ–Ⅹ`, `℡`, `№`, `∑`, and the IBM extension kanji, all of which turn up in
  authored Japanese copy constantly.

`CP932` is not the fix — it disagrees on 2262, because it adds Windows
round-trip mappings Go omits. Plain `SJIS` plus the generated override table
is. PHP's default substitution character is also `?` where Go's is `0x1A`, which
would have been a silent one-byte divergence on every em dash.

The table is proved complete by a **whole-repertoire digest**: every code point
U+0020…U+FFFF is encoded on both sides and hashed to one number
(`shift_jis_repertoire_sha256`). A sample list would never have found 〜.

#### Go↔PHP parity (TR-34)

The fixture is **shared, never duplicated**: Go writes
`internal/service/testdata/print_primitives_golden.json`, PHP reads it. There
is one set of expectations, and it belongs to the side that is the source of
truth.

```sh
# regenerate (deliberately)
cd workstation-app && go test ./internal/service/ -run Primitives_Golden \
  -args -update-print-primitives
```

Covered: `displayWidth`, `runeLength`, `formatPrice`, `dashedLine`, `padRight`,
`wrapText`, `wrapNameLines`, `columnHeaderText`, `StripAccents`, Shift_JIS
bytes, and the full repertoire digest. Green.

The primitives are gated *first*, on purpose. They are where a port dies
quietly: a one-column error in `displayWidth` is wrong on every line of every
kind and surfaces only as "receipt|ja|32 hash differs". Locking them to a shared
fixture means that when the emitters are written, whatever fails is emitter
logic — not the ruler.

> **Not done yet (T5.1d).** The per-kind ESC/POS **emitters** — roughly 2 700
> lines transcribed from `print_renderer_{bill,docs,shift}.go` plus the i18n
> label catalogs, tax blocks and item printers — are still to come, so there is
> no SLIP-level Go↔PHP parity yet and the cloud transport stays fail-closed
> (plan-052 P-39 / T5.4). **ePOS XML and WebPRNT** land with them; inventing
> either now would crown it the standard with nothing to check it against.
> **Raster (TR-35) is deliberately out of scope**: Go has no bitmap encoder yet
> (`internal/printer` belongs to plan-052), and writing one in PHP would create
> a second standard with nothing to compare it to — precisely the trap TR-34
> exists to avoid.

### Preview endpoint (TR-32/TR-33)

```
GET /hq/{brandSlug}/print-templates/{kind}/preview?locale=&paper=&source=
GET /shops/{shopSlug}/print-templates/{kind}/preview?locale=&paper=&source=
```

Returns `image/svg+xml`. `locale` ∈ ja|en|vi, `paper` ∈ 58mm|80mm, `source` ∈
draft|published (default **draft** — the point is seeing what you are about to
publish). An invalid value is a 422 rather than a silent default: quietly
falling back to 80mm would show a 58mm shop a slip that fits when its own does
not. Permissions are the existing TR-37 matrix, checked before the kind is
validated so probing kinds leaks nothing.

The HQ preview renders the brand's definition (falling back to the system
default, which is the common case on that screen); the shop preview renders the
**resolved** slip — system → brand → shop already merged — because an override
shown alone is a handful of disconnected fields, while the manager's actual
question is "what comes out of my printer".

**Structure is exact, figures are illustrative.** The preview walks the
definition with the same geometry as the ESC/POS renderer, so block order,
toggles, authored text, alignment and line breaks are what the printer will do.
Engine-owned blocks show the TR-33 sample basket — two tax rates, a topping, a
discount, a service charge, a name long enough to wrap on 58mm — because a
preview of the simple case hides every layout problem the editor exists to
catch. Showing authoritative-looking money for an order that does not exist
would be worse than showing something obviously a sample.

The SVG is inert by construction: no script, no external font, no remote
reference, served with `default-src 'none'` and `nosniff`. `xml:space="preserve"`
and `white-space: pre` are both required — every column position on a receipt is
made of literal spaces, and a renderer that collapsed whitespace runs would
silently left-shift every right-aligned price.

### The editor's UNSAVED state (T4.3)

`GET` renders what is stored. An editor's whole job is the state that is not
stored yet, so the same route also answers `POST` with a `definition` in the
body and renders that. It is still a read — nothing is written.

The alternative, save-then-GET, is worse than it looks: saving bumps the draft's
optimistic-lock token, so previewing would 409 whichever other tab is open on
the same draft (TR-09), and it would write a version history the author never
asked for.

On the SHOP route the posted definition is put through the brand allow-list and
merged onto the resolved slip — the same `filterToAllowList` + `merge` publish
uses. The shop editor holds the whole resolved document rather than an overlay,
so an edit to a field the brand never delegated can reach the endpoint; showing
it would let a manager approve a slip their own publish cannot produce.

Bounded on purpose: `definition.blocks` is required and capped at 200 entries. A
definition with no `blocks` composes to nothing, and rendering that as an empty
preview would read as "your template prints nothing" — the one answer a
malformed request must never be allowed to give.

**admin-web has no slip renderer.** It had one for milestone M4
(`preview-renderer.ts`, ~440 lines of TypeScript reimplementing the layout
rules) and it is deleted. The panel now `<img>`s the server's SVG. A second
implementation of the layout rules is how "the preview and the slip disagree"
bugs are born, and the shop that suffers it can read neither.
`src/__tests__/print-template-preview.test.ts` fails if a column constant
reappears anywhere under `components/shared/print-template/`.

One client-side check survives and is allowed to: which authored blocks have no
copy in the chosen locale. It measures nothing and lays nothing out — it reads
the document the author is looking at and reports a gap the server cannot state
through an image.

---

## Permissions (TR-37)

Mapped onto the existing IAM matrix without inventing new permission slugs — a
new slug would have to be seeded into every existing organization before it
granted anything, and a permission that is silently absent fails open on the
day it ships.

| Surface | Permission | Who holds it |
|---|---|---|
| Brand read | `menu.manage` | org-admin, org-manager, shop-manager |
| Brand write (draft/publish/retire/rollback) | `catalog.approve` | org-admin, org-manager |
| Shop override (read + write) | `shop.manage` | org-admin, org-manager, shop-manager |

`catalog.approve` is the discriminator that already separates HQ authority from
shop authority in this codebase, and publishing a brand-wide compliance
document is an approval-grade act. `staff` and `shop-staff` (the cashier) hold
neither `menu.manage` nor `shop.manage`, so the surface is **invisible** to
them — not merely read-only.

---

## API

### HQ — brand layer

| Method | Path |
|---|---|
| GET | `/hq/{brandSlug}/print-templates` |
| GET | `/hq/{brandSlug}/print-templates/{kind}` |
| POST | `/hq/{brandSlug}/print-templates/{kind}/draft` |
| PATCH | `/hq/{brandSlug}/print-templates/{kind}/versions/{id}` |
| POST | `/hq/{brandSlug}/print-templates/{kind}/publish` |
| POST | `/hq/{brandSlug}/print-templates/{kind}/versions/{id}/retire` |
| POST | `/hq/{brandSlug}/print-templates/{kind}/versions/{id}/rollback` |
| GET | `/hq/{brandSlug}/print-templates/{kind}/history` |
| GET | `/hq/{brandSlug}/print-templates/{kind}/diff?from=&to=` |

`GET {kind}` returns the block catalog, the mutability map and the system
default, so the editor never hard-codes them. `diff` with no `from` compares
against the system default — the honest baseline for a brand's first publish.

The catalog payload is assembled in **one** place, `BlockCatalog::catalogFor()`,
and carries `blocks · required · sources · param_fields · mutability ·
editable_props · prop_enums`, all keyed to the kind's own blocks. `GET {kind}` on
the **shop** surface returns the identical document (see below).

There is deliberately **no delete**.

### Shop — override layer

| Method | Path |
|---|---|
| GET | `/shops/{shopSlug}/print-templates` |
| GET | `/shops/{shopSlug}/print-templates/{kind}` |
| POST | `/shops/{shopSlug}/print-templates/{kind}/draft` |
| POST | `/shops/{shopSlug}/print-templates/{kind}/publish` |

Both list endpoints report `overridden_paths` so admin can say "this shop
overrides 3 things" rather than leaving the manager to diff two JSON blobs by
eye (TR-02).

#### The catalog is on this surface too (#2043)

`GET /shops/{shopSlug}/print-templates/{kind}` returns `data.catalog`, the same
document the HQ read returns, under the `shop.manage` this endpoint already
checks. No new permission slug: a slug that is not yet seeded into a live
organization grants nothing and fails **open** on the day it ships (TR-37).

It is here because of what its absence cost. The catalog used to be an HQ-only
read (`menu.manage`), which a shop manager does not hold, so `admin-web` carried
a hand-copied mirror of `config/print_blocks.php` — mutability, editable props,
param fields, sources, item columns — purely to draw the shop editor. That mirror
drifted from this file **four times** (#1181 ×2, #2000, #2040), and every drift
was silent in a way no test could see: TypeScript compiled, the suite stayed
green, and a block simply lost its on/off switch or a param field its checkbox.

The fix deletes the copy rather than policing it. Two mirrors survive in
`admin-web`, both deliberate and both loud: `PRINT_TEMPLATE_KINDS` (it derives a
TypeScript union, which cannot come from a runtime response — a missing kind is
a compile error) and `PRINT_BLOCK_IDS` (a translation catalogue for
`print_templates.block.*` — a missing id shows the raw key on screen). Neither
can take a control away without saying so.

---

## The 13 kinds

They are exactly the 13 `Format*` entry points the workstation renders today,
because TR-40 makes the current Go formatter the migration baseline: a system
default must render byte-identical to the formatter it replaces before anyone's
slip is allowed to change.

| Kind | Go formatter |
|---|---|
| `receipt` | `FormatPaidTicket` |
| `kitchen` | `FormatKitchenTicket` |
| `runner` | `FormatRunnerTicket` |
| `delta_qr` | `FormatDeltaQRTicket` |
| `remaining` | `FormatRemainingTicket` |
| `vat_invoice` | `FormatVatInvoice` |
| `red_invoice` | `FormatRedInvoiceTicket` |
| `void_notice` | `FormatVoidNotice` |
| `debt_slip` | `FormatDebtSlip` |
| `shift_open` | `FormatShiftOpenReport` |
| `shift_report` | `FormatShiftReport` |
| `chain_report` | `FormatChainReport` |
| `table_paid` | `FormatTablePaid` |

DESIGN.md names a 14th (`diagnostic`) in a parenthetical while its own prose
says "13 loại". There is no diagnostic formatter, so it has no migration
baseline and is not a kind — add it only together with a real renderer.

---

## Files

| Concern | File |
|---|---|
| Block catalog | `backend/config/print_blocks.php` |
| System defaults (layer 0) | `backend/config/print_templates.php` + `app/Services/Print/SystemTemplateDefaults.php` |
| Cross-language shape normalisation | `app/Services/Print/DefinitionNormalizer.php` |
| Layer-0 export (parity fixture) | `app/Console/Commands/ExportPrintTemplateDefaults.php` |
| Resolve | `app/Services/Print/TemplateResolver.php` |
| Field-wise merge + allow-list filter | `app/Services/Print/DefinitionMerger.php` |
| Publish gate | `app/Services/Print/TemplateValidator.php` |
| Render trial | `app/Services/Print/EscposRenderProbe.php` (`RenderProbe`) |
| PHP renderer | `app/Services/Print/Renderer/*` |
| Lifecycle | `app/Services/Print/TemplateVersionService.php` |
| Cache identity | `app/Services/Print/TemplateChecksum.php` |
| History diff | `app/Services/Print/DefinitionDiff.php` |
| Model / migration | `app/Models/PrintTemplate.php`, `database/migrations/omnify/*_create_print_templates_table.php` |
| Permissions | `app/Policies/PrintTemplatePolicy.php` |
| Tests — registry | `tests/Feature/Print/{TemplateResolveTest,TemplateLifecycleTest,TemplateValidationTest,WorkstationTemplateSyncTest}.php` |
| Tests — #1181 catalog parity | `tests/Feature/Print/CatalogParityTest.php` |
| Tests — Go↔PHP primitives (TR-34) | `tests/Feature/Print/RendererPrimitivesParityTest.php` |
| Tests — render trial | `tests/Feature/Print/EscposRenderProbeTest.php` |
| Tests — preview + SVG | `tests/Feature/Print/RendererPreviewTest.php` |
| Tests — cross-repo gate (Go side) | `workstation/internal/service/print_cloud_parity_test.go` |
| Tests — primitives fixture (Go side) | `workstation/internal/service/print_primitives_golden_test.go` |

Plan: `plans/plan-053/` (README · DESIGN · EDGE-CASES TR-01…TR-40).

---

## Deploy order

**Backend before workstation.** A workstation that does not yet know the
endpoint keeps printing from its hard-coded formatters, which are byte-equal to
the system defaults — so shipping the backend alone changes nothing anyone can
see. A new workstation against an old Cloud degrades to the embedded system
default, which is also correct.
