---
title: Quan sát và đối soát máy thu tiền 釣銭機 (cụm #2876)
category: guide
tags: [payment, cash, glory, cash-changer, reconciliation, till, workstation, observability]
summary: >
  Ba sổ quan sát ở Cloud cho máy 釣銭機 — lượt thu (kể cả lượt HỎNG), 在高 tại
  ranh ca, và sự cố có dấu thời gian — cộng phép đối soát BA CHÂN cho tiền mặt
  (sổ ↔ MÁY ↔ người đếm). Giá trị không nằm ở "phát hiện lệch" mà ở PHÂN LOẠI
  lệch: hai chân cho một con số, ba chân cho hai con số và đọc chéo ra bốn ô.
  Máy là NHÂN CHỨNG, không phải QUAN TOÀ — không có đường ghi nào từ đây ghi đè
  sổ tiền hay số người đếm.
related:
  - guide/cash-changer-glory-adapter.md
  - guide/cashier-shift-recovery.md
  - guide/gateway-settlement.md
  - reference/workstation-cloud-api.md
status: T1–T5 shipped to production 2026-08-15 (0b71c6007). Ba bảng còn RỖNG cho tới khi fleet cài workstation 0.8.2.
---

# Quan sát và đối soát máy thu tiền 釣銭機

> Tầng này khác [`cash-changer-glory-adapter.md`](cash-changer-glory-adapter.md).
> Trang kia là **cách nói chuyện với máy** (giao thức, client Go, taxonomy lỗi).
> Trang này là **sổ ở Cloud và phép đối soát** dựng trên nó.

## Vấn đề nó giải

Trước cụm này, **Cloud không biết máy 釣銭機 tồn tại**:

```sh
grep -rn "glory" backend/app backend/routes backend/database
# → 2 dòng, cả hai là COMMENT trong seeder
```

Mọi dấu vết một lượt thu nằm trong `order_payments.metadata` — JSON không index.
Và vì `order_payments` **chỉ có hàng khi thu ĐƯỢC tiền**, bốn kết cục còn lại
của máy không để lại gì cả. Nặng nhất là `timeout`: **máy đang giữ tiền của
khách** mà không sổ nào trên Cloud biết.

Cùng lúc, chốt ca chỉ có **hai chân**:

```
order_payments  ↔  till_cash_denomination_counts
(sổ)               (NGƯỜI đếm tay)
```

Chân giữa — hỏi chính cái máy đang giữ tiền — chưa bao giờ được nối, dù adapter
đã có `GetInventory` (在高取得) từ đầu và **không ai gọi**.

## Ba sổ

| Bảng | Một hàng là gì | Khoá idempotent |
|---|---|---|
| `cash_device_transactions` | MỘT lượt thu ở máy, **kể cả lượt hỏng** | `(peripheral_device_id, glory_transaction_id)` |
| `cash_device_inventory_snapshots` | MỘT lần hỏi máy "trong mày có bao nhiêu tiền", tại một ranh ca | `(peripheral_device_id, till_session_id, count_phase)` |
| `cash_device_error_events` | MỘT LẦN XẢY RA của một sự cố | `(peripheral_device_id, error_title, occurred_at)` |

Cả ba **chỉ nhận ghi từ sync-UP của máy trạm** — xem
[`workstation-cloud-api.md`](../reference/workstation-cloud-api.md). Không màn
hình nào, không lệnh nào, không seeder nào được tạo một hàng: chúng khẳng định
rằng **một cái máy vật lý đã làm một việc**, và chỉ cái máy đó nói được điều ấy.
Ràng buộc này được cưỡng chế bằng aggregate `cash_device` trong
`config/domain-mutation-guard.php` với đúng hai biên giới ghi.

### Từ vựng MÁY ≠ từ vựng PHỤC HỒI

`cash_changer_sessions.outcome` (SQLite máy trạm) mang từ vựng **phục hồi** —
`recorded` · `returned` · `retained` · `unknown` — tức *chúng ta* đã làm gì.
`cash_device_transactions.outcome` mang từ vựng **máy** — `finish` · `cancel` ·
`abort` · `timeout` · `failure` — tức *máy* đã làm gì.

Ánh xạ giữa hai bên **lossy cả hai chiều**: `unknown` có thể là abort hoặc
failure và ta không phân biệt được; `returned` gộp cả cancel lẫn hết-tiền-thối.
Gộp chúng vào một cột là đúng bẫy #2860 — bảy cách viết cho ba khái niệm, sống
nhiều tháng, không gì đỏ.

### `peripheral_device_id` ≠ `server_id`

`cashChangerServerID()` **ưu tiên** `metadata.server_id`/`serial` — một chuỗi do
người lắp máy đặt — và chỉ fallback về `peripheral_devices.id`. Chuỗi đó đúng
cho dòng audit tại chỗ (`cash_changer:<id>`) nhưng Cloud khoá theo UUID, nên
quán nào có khai serial sẽ đẩy lên một khoá **không tra được**. Hai định danh ⇒
hai resolver: `cashChangerDeviceID()` cho sổ.

## Đối soát BA CHÂN — và vì sao nó không phải "phát hiện lệch"

Phía cổng thanh toán đã đủ ba chân từ plan-050
([gateway-settlement.md](gateway-settlement.md)):

```
order_payments  ↔  payment_settlements  ↔  gateway_payouts
(sổ)               (bên xử lý)             (ngân hàng)
```

Phía tiền mặt nay cũng đủ ba:

```
order_payments  ↔  cash_device_inventory_snapshots  ↔  till_cash_denomination_counts
(sổ)               (MÁY)                               (NGƯỜI đếm)
```

**Giá trị thật là PHÂN LOẠI lệch, không phải phát hiện lệch.** Hai chân chỉ cho
ra MỘT con số, và một con số không nói được lệch đó là gì. Ba chân cho ra HAI
con số, đọc chéo ra bốn ô:

| `machine_variance_minor` | `human_variance_minor` | `verdict` | Đọc là |
|---|---|---|---|
| ≈ 0 | ≈ 0 | `ok` | khớp |
| ≈ 0 | ≠ 0 | `human_count_error` | **người đếm sai** — tiền vẫn trong máy |
| ≠ 0 | ≈ 0 | `cash_left_machine_off_book` | **tiền ra khỏi máy NGOÀI SỔ** — nặng nhất |
| ≠ 0 | ≠ 0 | `cash_missing` | tiền thật sự thiếu |
| — | — | `undetermined` | **không kết luận** — xem dưới |

Công thức vế máy:

```
kỳ vọng_máy = 在高 đầu ca
            + Σ deposited_minor  (outcome = finish)
            − Σ dispensed_minor  (outcome = finish)
            + Σ paid_in − Σ paid_out   (till_cash_events)

lệch_máy   = 在高 cuối ca − kỳ vọng_máy
lệch_người = counted_cash_amount − expected_cash_amount   (đã có sẵn trên till_sessions)
```

Chỉ `finish` được cộng: bốn kết cục còn lại không để lại tiền trong máy —
`cancel` trả lại khách, `timeout`/`abort` thì tiền còn kẹt nhưng lượt đó chưa
thành khoản thu. Cộng chúng vào là **đếm hai lần một sự cố**.

Vế người **không tính lại** — nó đã có trên `till_sessions`. Tính lại là công
thức thứ hai cho cùng một con số, và hai công thức sẽ lệch nhau đúng vào lúc
cần chúng khớp.

### Ô `undetermined` quan trọng hơn ba ô kia

Máy **tự khai được là nó KHÔNG CHẮC**. `glory.Inventory.CashErrorStatus.Cash` là
map per-mệnh-giá đánh dấu 在高不確定. Mệnh giá nào máy nói "tôi không chắc" mà
vẫn đem tính lệch là **bịa ra một con số rồi bắt quán đi tìm tiền không mất** —
và lần thứ hai như vậy là lần cuối ai đó tin cảnh báo này.

Hai điều kiện cho `undetermined`, cả hai đều **cố ý**:

1. Thiếu ảnh chụp 在高 ở một hoặc cả hai mốc — **máy mất kết nối lúc chốt ca thì
   quán VẪN phải đóng cửa được.** Mất khả năng đối soát tốt hơn mất khả năng chốt
   ca. Vế người vẫn được trả về nguyên vẹn.
2. Có mệnh giá 在高不確定 — báo cáo **nói rõ đã loại mệnh giá nào**
   (`excluded_denominations`). Im lặng loại là giấu mất một phần sự thật.

`bill_reject_count > 0` cùng họ: tiền trong khay từ chối là tiền **có thật**
nhưng không nằm trong 在高; không trừ ra sẽ ra lệch giả.

### Máy là NHÂN CHỨNG, không phải QUAN TOÀ

`CashDrawerReconciliationService` **chỉ ĐỌC**. Nó không sửa `TillCashEvent`,
không sửa `counted_cash_amount`, không đóng ca, và **cố ý không nằm trong
`boundaries`** của aggregate `cash_device` — một service đọc nằm trong danh sách
biên giới ghi là lời mời cho người sau thêm một lệnh sửa vào đó.

Máy vẫn đếm sai được (tiền kẹt khe, tiền giả bị giữ lại). Một máy được quyền ghi
đè người sẽ biến một **sai số phần cứng** thành một **sự thật kế toán**.

## Ngưỡng lệch — theo BRAND, không hardcode

`brand_order_policies.cash_variance_tolerance_minor` (minor units; JPY: yên),
mặc định **100**.

⚠️ **Chưa có màn hình cấu hình.** Cột và đường đọc đã có; đổi giá trị hiện phải
qua DB hoặc một lệnh. Trang này là chỗ duy nhất nói nó tồn tại.

Vì sao theo brand: cùng bài học của `SettlementAlertService` — Stripe trả tiền
theo ngày, PayPay theo chu kỳ tháng, nên một ngưỡng chung sẽ **hoặc câm với cái
này hoặc la hét với cái kia**. Ở đây cũng vậy: một quán bán 50 đơn/ngày và một
quán bán 2000 đơn/ngày không chịu được cùng một con số, và **ngưỡng sai chiều
nào cũng giết cảnh báo** — quá chặt thì người ta tắt nó, quá lỏng thì nó không
bắt được gì.

**Ngưỡng 0 được TÔN TRỌNG**, không kẹp về mặc định: 0 nghĩa là "báo mọi lệch" và
đó là lựa chọn hợp lệ. Cổng `BranchCashVarianceTolerance` trả `null` cho "chưa
cấu hình" chứ **không** trả 0 — trộn hai thứ đó sẽ âm thầm biến lựa chọn của
brand thành mặc định.

## Nhiều máy trong một quán — hiện KÊU chứ không đoán

`cashChangerDeviceID()` tra **"máy CỦA QUÁN"**, không phải **"máy vừa chạy lượt
này"**. Hai máy thì nó trả về một cái tuỳ ý (`ORDER BY updated_at DESC LIMIT 1`,
mà `updated_at` chỉ có độ phân giải giây) — nên **khoá phiên theo nó vẫn là
đoán**.

Routing thật (client + mutex theo máy, id đi xuyên collector) là refactor trên
đường TIỀN, và hôm nay **0 quán có hai máy**. Nên bản hiện tại chuyển
**hỏng-im thành hỏng-kêu**:

| Tình huống | Hành vi |
|---|---|
| 1 máy | như cũ, không đổi gì |
| **≥2 máy đang bật** | **không quy máy** + alert `cash_device_ambiguous` (warning, tự đóng được, sync lên HQ) — **bán hàng vẫn chạy** |
| 0 máy đăng ký (env fallback) | rỗng, và đó là câu trả lời ĐÚNG |

Đánh đổi có chủ ý: mất khả năng **quy máy** (hàng không đẩy lên Cloud được — sổ
bỏ qua hàng không có thiết bị) chứ **không mất lượt thu**, và mã giao dịch vẫn
được đóng dấu để lượt đối soát khởi động còn hỏi lại máy được.

**Quán thấy alert này thì làm gì:** tắt bớt máy thừa trong registry thiết bị
(HQ → Devices → peripheral), để lại đúng một máy `coin_changer` đang `is_active`.
Alert tự đóng ở lượt tra kế.

> Từ vựng: type thật của máy trong registry là **`coin_changer`**, không phải
> `cash_changer` — dù tên miền nghiệp vụ hay gọi là "máy thu tiền mặt".

## Sổ sự cố — alert và sổ là HAI câu hỏi

Alert trả lời **"bây giờ có sao không"**. Sổ trả lời **"tháng qua mất bao
nhiêu"**. Câu thứ hai **không suy ra được** từ câu thứ nhất, nên cả hai cùng
chạy chứ không thay nhau.

Bốn nhóm vào sổ, phân theo **việc người phải làm**:

| `error_group` | Title adapter | Vì sao đo |
|---|---|---|
| `change_shortage` | `empty` | **chặn bán hàng** — đo được là đo được doanh thu mất |
| `needs_operator` | `error` · `full` · `billRejectFull` · `needPullOut` · `setError` · `recovery` · `systemError` | cần người ra máy — đo thời gian phản hồi |
| `connectivity` | `ifError` · `notReady` | đứt cáp / mất điện |
| `forbidden` | `forbidden` | IP máy trạm ngoài allowlist adapter — **cấu hình sai, thường im lặng hàng tuần** |

**`IsBusy` · `IsNotFound` · `IsNotEnoughDeposit` CỐ Ý đứng ngoài.** Chúng là
nhịp bình thường của giao thức; ghi vào sẽ chôn lấp bốn nhóm thật, và một sổ
toàn rác sẽ bị tắt. Đừng "sửa" bằng cách thêm chúng vào.

**MỘT LẦN XẢY RA = MỘT HÀNG.** Collector poll theo `pollInterval`, nên một sự cố
kéo dài hai phút đi qua hàng trăm lần. `cleared_at` là nửa cho phép tính **thời
lượng** — con số quy ra tiền, và là thứ phân biệt bảng này với một dòng log.

Hai nhóm `forbidden` và `connectivity` xảy ra khi **không có lượt thu nào đang
chạy**, nên không có hàng giao dịch để bám vào — đó là lý do bảng sự cố tồn tại
riêng thay vì chỉ dùng cột `error_title` trên bảng lượt thu.

## Tra cứu giao dịch (không riêng tiền mặt)

`GET /api/v1/hq/{brand}/transactions` — **chỉ đọc**, màn hình HQ → **取引照会**.

Đây là **nghĩa vụ pháp lý**: 電子帳簿保存法 検索要件 bắt tra được theo
**取引年月日 · 取引金額 · 取引先** và **kết hợp** từ hai trục trở lên.

**Ô `reference` tra được sáu loại mã trong MỘT ô** — người vận hành cầm đúng một
cái, thường là cái nhà cung cấp đưa, và không phải biết nó thuộc cột nào:

`reference_no` · `idempotency_key` · `idempotency_key` dạng `glory:<mã>` **nhận
cả mã TRẦN** · `payment_code` · `payment_attempts.provider_object_id` ·
`payment_attempts.provider_request_key`

Ngày lọc đi qua `BusinessClock::utcRangeForBusinessDates` — cận trên
**exclusive**. Xem [business-time.md](business-time.md).

**Phạm vi là ORGANIZATION + BRAND, KHÔNG có phân quyền theo chi nhánh** — và đó
là một khẳng định, không phải thiếu sót (#2911). `branch_id` là **bộ lọc**, giống
`CustomerOrderController`. Muốn per-branch ở HQ thì đó là quyết định cắt ngang
mọi màn HQ đọc tiền, và phải cẩn thận: `branch_id IS NULL` nghĩa là **MỌI** chi
nhánh (`all_branches_access` — xem
[branch-isolation.md](../explanation/branch-isolation.md)).

## Vận hành

**Ba bảng sẽ RỖNG cho tới khi fleet cài workstation `0.8.2`.** Máy trạm chỉ đẩy
khi đã cập nhật binary; hai máy Windows **không tự cập nhật**. Backend đã sẵn
sàng nhận — đúng thứ tự **backend TRƯỚC workstation**.

Nếu một sổ trống bất thường sau khi fleet đã cập nhật, kiểm theo thứ tự:

1. Máy có trong registry và `is_active` chưa? Không có ⇒ `peripheral_device_id`
   rỗng ⇒ đường đẩy **cố ý bỏ qua** hàng đó.
2. Có nhiều hơn một máy `coin_changer` đang bật? ⇒ alert `cash_device_ambiguous`,
   và cũng không quy được máy.
3. `machine_outcome` còn rỗng? ⇒ phiên bị đóng vì hết giờ mà chưa hỏi được máy.
   Đường đẩy **cố ý không gửi** hàng như vậy — đẩy nó lên là đẩy một khẳng định
   bịa về tiền.

## Rào đang canh

| Rào | Canh gì |
|---|---|
| `CashDeviceTransactionUplinkTest` (8 ca) | idempotency, seq-no arbitration, ranh giới chi nhánh |
| `CashDeviceObservationTest` (17 ca) | tổng 在高 chắc chắn, bốn ô phán đoán, một-sự-cố-một-hàng |
| `TransactionLookupApiTest` (8 ca) | 検索要件, ô `reference` sáu loại mã, không rò chéo brand, `branch_id` là bộ lọc |
| `cash_device_sync_up_test.go` (7) · `cash_device_observation_sync_up_test.go` (5) | fail-open, không đẩy hàng chưa ngã ngũ, không đẩy lại |
| `cash_changer_multi_device_test.go` (4) | mập mờ nhiều máy phải KÊU |
| `OwnershipLedgersAgreeTest` · `DomainGuardFkReachabilityTest` | ba bảng phải có chủ aggregate |

Mỗi bộ chia **hai nửa cố ý**: nửa PHẢI KÊU và nửa PHẢI IM. Nửa thứ hai nặng hơn
— một rào tiền kêu oan sẽ bị tắt, và lúc đó nó không còn canh gì nữa.

## Còn mở

- **Routing thật cho nhiều máy** (client + mutex theo máy). Alert
  `cash_device_ambiguous` là chỗ bắt đầu.
- **Màn hình cấu hình ngưỡng** `cash_variance_tolerance_minor`.
- **Nối `CashDrawerReconciliationService` vào cảnh báo/báo cáo** — service trả
  verdict, hiện chưa có ai gọi nó theo lịch. Đây là mảnh còn thiếu để #2848
  (ba cảnh báo lệch tiền treo ở 本郷店 + 人形町店) thật sự được phân loại.
