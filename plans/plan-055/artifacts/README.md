# plan-055 — chạy sáu task production

Sáu task cuối (`T1.3` `T2.2` `T2.3` `T4.2` `T4.3` `T6.2`) không code nào làm thay
được. File này biến chúng thành các bước chạy được, và **giữ lại bốn điều kiện ra
được rút ra TRONG LÚC thực hiện** — chúng không có trong plan gốc, và người chạy
production không có mặt lúc chúng được phát hiện.

> **Đọc mục "Bốn cái bẫy" trước khi chạy bất cứ lệnh nào.** Cả bốn cùng một hình
> dạng: **một con số xanh không có nghĩa là an toàn.** Ba trong bốn đã thực sự
> báo xanh trong khi đường tiền vẫn hỏng.

Artifact lưu ngay tại thư mục này, đặt tên `<task>-<YYYY-MM-DD>.json`.

---

## T1.3 — baseline, TRƯỚC khi đổi gì

```sh
php artisan payments:legacy-removal-readiness --json \
  > plans/plan-055/artifacts/t1-3-readiness-$(date +%F).json
php artisan payments:backfill-policy-revisions \
  > plans/plan-055/artifacts/t1-3-backfill-report-$(date +%F).txt
```

Lệnh backfill **mặc định chỉ báo cáo**, không ghi. Không kèm `--apply` ở bước này.

Trong file JSON, đọc `gates[] → key = "legacy_payment_method_resolver" → preconditions[]`:

| Trường | Nghĩa |
|---|---|
| `policy_revision_coverage.met` | mọi branch active **sẵn sàng** (xem bẫy 1) |
| `.organizations_incomplete[]` | org nào còn thiếu — **xấu nhất đứng đầu** |
| `.branches_without_effective_option[]` | branch có revision nhưng **không nhận được tiền** |
| `.payments_with_unresolvable_channel` | khoảng trống bằng chứng (xem bẫy 4) |
| `client_sends_gateway_option_id.met` | client đã gửi định danh chưa |
| `policy_enforcement_is_mandatory.met` | cờ đã bật chưa |

---

## T2.2 — NGƯỜI đọc và duyệt

```sh
php artisan payments:backfill-policy-revisions
```

Đọc cột **`Effective options`**. **Hàng `0` là hàng phải xử lý, không phải hàng bỏ
qua** — publish revision cho nó làm con số coverage xanh lên mà branch **vẫn** từ
chối mọi checkout.

Đây là bước duyệt của **con người**. Không tự động hoá.

---

## T2.3 — apply, rồi xác nhận

```sh
php artisan payments:backfill-policy-revisions --apply
php artisan payments:legacy-removal-readiness --json \
  > plans/plan-055/artifacts/t2-3-after-apply-$(date +%F).json
```

**Điều kiện ra KHÔNG phải `N/N`** — xem bẫy 1.

---

## T4.2 / T4.3 — quan sát

Bật chế độ cảnh báo-không-chặn ở staging trước, rồi production.

### Bước 0 — chứng minh phép đo CÓ THỂ thấy sự kiện (#1871)

**Chạy trước, mỗi lần.** Hai lệnh đếm bên dưới chỉ có nghĩa khi cả hai điều này
đúng; nếu không, `0` là **im lặng**, không phải **sạch**.

```sh
# 1. Channel phải nhận mức `warning` — cả hai dòng đếm ở dưới đều là ->warning()
php artisan config:show logging.channels.payment_orchestration.level

# 2. File log hôm nay phải tồn tại — `grep -c` trên file không có cũng in 0
test -f storage/logs/payment-orchestration-$(date +%F).log \
  || echo "CHƯA CÓ LOG HÔM NAY — 0 ở dưới không có nghĩa gì"
```

Mức của channel là `env('LOG_LEVEL', 'debug')`, và `deploy-xserver.yml` **không
ghi `LOG_LEVEL`** vào `.env` trên server — nên mức thật là thứ đang nằm trong
`.env` của máy đó, **không đọc được từ repo**. Ai đó siết `LOG_LEVEL=error` để
giảm nhiễu là hai dòng `warning` không bao giờ được ghi nữa, hai lệnh dưới trả
`0 / 0`, điều kiện ra thoả, và cú flip từ chối tiền ở mọi quầy chạy client cũ.

Đây là **bẫy thứ năm**, cùng hình dạng bốn cái ở cuối file: một con số xanh không
có nghĩa là an toàn.

### Đếm

```sh
grep -c payment_policy_option_missing      storage/logs/payment-orchestration-$(date +%F).log
grep -c payment_policy_alias_would_refuse  storage/logs/payment-orchestration-$(date +%F).log
```

**Điều kiện ra: CẢ HAI về 0**, qua một cửa sổ đủ dài để phủ chu kỳ phát hành chậm
nhất (workstation) — **và bước 0 đã qua**. Xem bẫy 2.

Mỗi dòng log mang `transport · device · branch · org`, nên tập log **chính là danh
sách đích danh** client sẽ chết khi flip — không phải ước lượng.

---

## T6.2 — flip

Chỉ flip khi **cả bốn** bẫy dưới đã xử lý.

```sh
# .env production
PAYMENT_POLICY_ENFORCEMENT_REQUIRED=true
```

Bật staging trước, quan sát, rồi production — theo từng org nếu cần.

**Đường lùi:** đặt lại `false`. Cờ đọc lúc chạy, không cần deploy lại; nếu config
đã cache thì `php artisan config:clear`.

---

## Bốn cái bẫy — đọc trước khi flip

### 1. `N/N` coverage KHÔNG đủ

Điều kiện đúng: **mọi branch active có revision VÀ ≥1 effective option**, đo trên
**mọi channel branch đó đã thực sự thu tiền** (`order_payments.channel`).

Đo được trên dev: cả 8 branch chưa phủ đều sẽ có **0 effective option**. Publish
revision đưa coverage lên `9/9` và precondition chuyển `met` — nhưng chúng **vẫn**
ăn `No effective payment options are available for checkout` khi flip. Con số xanh,
quầy vẫn từ chối tiền.

Đọc `branches_without_effective_option[]`, không đọc mỗi `met`.

### 2. Phải CẢ HAI log rỗng

Hạm đội legacy **không** xuất hiện trong `payment_policy_option_missing` — chúng
nằm ở `payment_policy_alias_would_refuse`. Chỉ đọc vế đầu là đọc một con số **đã
xanh sẵn** trong khi mọi workstation và kiosk vẫn trượt policy.

### 3. Tiền mặt phải ĐI QUA trong cùng lượt flip

Internal tender (tiền mặt · máy thẻ rời · 掛売) **không thể** mang gateway identity —
resolver fail-closed không bao giờ surface được option không có connection. Chúng
được miễn theo trạng thái server sở hữu.

Chứng minh **trong cùng một lượt bật cờ**, cả hai vế:
- một payment **tiền mặt** ĐI QUA
- một payment **gateway thiếu option id** BỊ CHẶN

Chỉ có vế thứ hai thì không phân biệt được *"cưỡng chế đúng"* với *"cưỡng chế chặn
tất"*. Bộ test đã từng mã hoá "tiền mặt bị từ chối" và **xanh** suốt, không ai đọc
nó như một cảnh báo.

### 4. Không option gateway nào được ánh xạ sang `card_terminal`

`payment_gateway_options` **không có phạm vi theo org** — một hàng tác động mọi
shop ở mọi org.

`cash` và `debt` đã rào cứng (`NEVER_GATEWAY_ROUTABLE`). `card_terminal` thì **cố ý**
vẫn chịu phép trừ, vì `card_present` là hàng thật (Stripe Terminal đang chạy). Trước
khi flip, xác nhận không option **ACTIVE** nào của provider khác `internal` ánh xạ
sang `card_terminal`.

Nếu `payments_with_unresolvable_channel` khác rỗng: đó là payment không xác định
được channel, tức gate **có thể đang bỏ sót** một channel. Cảnh báo này **chỉ tính
branch đã có revision** — trong suốt Gate 2, danh sách rỗng nghĩa là *"chưa nhìn
tới"*, **không** phải *"không có gì"*.

---

## Nợ còn treo, nên chốt trước khi flip

- **#1863 / #1859** — đã ship, nằm trong `dev`.
- **T7.1 / T7.2** — gỡ ~~`LegacyPaymentMethodResolver`~~ (**ĐÃ XOÁ ở #1887**). **Chặn bởi hạm đội**, không
  phải bởi cờ: hai controller nhận **mã legacy** (`payment_method` dạng chuỗi), nên
  gỡ được nghĩa là mọi kiosk và workstation đang chạy phải gửi thứ khác.
