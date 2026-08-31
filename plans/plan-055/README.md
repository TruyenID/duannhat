---
plan: 055
title: Cưỡng chế effective payment option — từ opt-in sang bắt buộc
slug: mandatory-effective-payment-options
issue: 1813
status: shipped
branch: issue-1813
created: 2026-08-05
updated: 2026-08-05
approved: 2026-08-05
parent: plan-047
---

# Plan 055 — Cưỡng chế effective payment option

**Một câu**: hôm nay shop tắt một phương thức trong policy nhưng client cũ vẫn
thanh toán được bằng nó, vì server chỉ kiểm policy **khi client tự nguyện gửi**
`gateway_option_id`; plan này làm cho việc kiểm là **bắt buộc**, theo thứ tự
không làm mất tiền.

## Lỗ hổng, viết bằng code chứ không bằng lời

```php
// PaymentPolicySubmission::fromPaymentData():35
$optionId = $data['gateway_option_id'] ?? null;

if (! is_string($optionId) || $optionId === '') {
    return null;                       // ⇒ bỏ qua toàn bộ kiểm policy
}
```

```php
// OrderPaymentService::create():225
$policySubmission = PaymentPolicySubmission::fromPaymentData($data, (string) $order->branch_id);
if ($policySubmission !== null) {       // ← null thì không ai kiểm gì
    $this->policySubmissionValidator->assertNewPaymentAllowed($policySubmission);
}
```

Còn lại chỉ là kiểm **tenancy** (`organization_id`, `branch_id`, `is_active`) —
tức "phương thức này có tồn tại và đang bật ở cấp danh mục không", **không phải**
"policy của shop/device có cho dùng nó không".

**Áp dụng cho cả ba transport như nhau.** Đây không phải lỗ của riêng kiosk hay
workstation — POS cũng vậy. Ai đọc plan-047 T4.10 (*"a caller cannot bypass a
disabled method by submitting its UUID directly"*) nên hiểu là: điều đó đúng
**chỉ với client có gửi option id**.

## Vì sao không bật ngay được — hai con số

| Đo | Giá trị (DB dev, 2026-08-05) | Nghĩa là |
|---|---|---|
| branch active **có** revision | **1 / 9** | **8 branch không có revision nào** |

⚠️ **Bản đầu của bảng này ghi "4 / 9" — sai, và sai theo kiểu dễ tin.** `4` là số
**DÒNG revision**, không phải số branch được phủ: cả bốn dòng nằm trên **cùng một
branch**. Độ phủ thật là **1/9**. Lệnh `payments:legacy-removal-readiness` luôn
báo đúng (`distinct()->count('branch_id')`); chỉ có bảng này đọc sai — đúng kiểu
tổng-che-thực-tế mà T1.2 sinh ra để chống, và nó lọt vào chính plan mô tả nó.

Bật cưỡng chế ngay thì đúng ≥5 branch đó ăn:

```
throwDisabled('No effective payment options are available for checkout.')
```

— tức **thu ngân bấm thanh toán và bị từ chối**, giữa ca, với khách đang đứng đó.

Số thật trên production đo bằng:

```sh
php artisan payments:legacy-removal-readiness --json
# → gates[].preconditions[key=policy_revision_coverage]
```

## Vì sao là plan chứ không phải một PR

Bắt buộc `gateway_option_id` là **đổi hợp đồng API** với ba client phát hành
độc lập:

| Client | Repo | Đặc thù làm nó khó |
|---|---|---|
| pos-web | `godx-tempo-pos-web` | deploy nhanh, nhưng **được nhúng vào workstation** (`/pos`, #1169) nên bản trong máy quán đi theo nhịp workstation |
| godx-kiosk | `godx-kiosk` (Expo) | build store/OTA, không ép cập nhật được |
| workstation-app | `workstation-app` (Go) | **offline-first**, có hàng đợi sync; máy trong quán chạy build cũ rất lâu, và đơn offline có thể replay **sau** khi flag đã bật |

Build cũ không gửi `gateway_option_id`. Flag bật trước khi chúng cập nhật xong =
mọi giao dịch của chúng bị từ chối.

⚠️ **Ca độc nhất của workstation**: đơn bán **offline hôm qua** replay lên **hôm
nay**, sau khi flag đã bật. Payload replay mang option id của **revision cũ**.
Nên đường replay phải được xử riêng, xem DESIGN §4.

## Nguyên tắc bất di bất dịch

1. **Không bao giờ bật cưỡng chế trước khi độ phủ revision = 100% VÀ mọi branch
   có ≥1 effective option.** Vế thứ hai được thêm sau khi chạy T2.1: trên dev cả
   8 branch chưa phủ đều có **0 option effective**, nên chỉ publish revision sẽ
   đưa coverage lên `9/9` mà vẫn từ chối mọi checkout — con số xanh, tiền vẫn
   mất. Cả hai vế đều đo được, không phải cảm nhận.
2. **Fail-closed là mục tiêu, nhưng fail-closed SỚM là mất tiền.** Thứ tự
   backfill → rollout → quan sát → flip không được đảo.
3. **Một cờ, tắt được ngay.** Cưỡng chế đi sau `PAYMENT_POLICY_ENFORCEMENT_REQUIRED`
   (mặc định `false`), hạ được trong một dòng env như `PAYMENT_ORCHESTRATOR_RUNTIME`.
4. **Đơn offline replay không bị từ chối vì lý do policy.** Tiền đã thu rồi;
   từ chối lúc replay chỉ tạo ra đơn mồ côi, không lấy lại được tiền.
5. **Áp cho cả ba transport cùng lúc**, không riêng kiosk/workstation — nửa vời
   thì hệ thống tự mâu thuẫn và không ai xoá được legacy.

## Mở khoá cái gì

Đây là tiền đề của **plan-047 T7.6**, cụ thể cổng `legacy_payment_method_resolver`
trong `payments:legacy-removal-readiness`. Chừng nào enforcement còn opt-in thì
xoá ~~`LegacyPaymentMethodResolver`~~ (**ĐÃ XOÁ ở #1887** → `PosEffectivePaymentOptionEnricher::resolveMethodByCode()`) chỉ là **đổi tên**: class đó kiểm đúng những gì
`EloquentPaymentAuthorityVerificationPort::resolvePaymentMethod()` đã kiểm
(tenant · branch · is_active), chỉ khác tra theo `code` thay vì `id`.

## Không thuộc phạm vi

- Sửa mô hình policy / effective options (đã có, đang chạy).
- Xoá `LegacyGlobalStripeConnection` — điều kiện khác hẳn, xem readiness gate.
- Xoá route `payment-methods` deprecated — chờ `Sunset: 2027-01-01`.
