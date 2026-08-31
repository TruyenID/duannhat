# Plan 055 — Design

## 1. Trạng thái hôm nay, theo từng tầng

```
client  ──gateway_option_id?──►  OrderPaymentService::create()
                                        │
                    có ──────────────────┤
                                        │  PaymentPolicySubmission::fromPaymentData()
                                        │        ├─ null  → KHÔNG kiểm gì
                                        │        └─ obj   → assertNewPaymentAllowed()
                                        ▼
                              PaymentMethod: tenant + is_active
```

`assertNewPaymentAllowed()` (`PaymentPolicySubmissionValidator`) kiểm đủ và
kiểm đúng: revision tồn tại, option có trong snapshot của revision đó, option
`effective === true`, connection khớp. **Vấn đề không nằm ở validator** — nó chỉ
không được gọi.

## 2. Trạng thái mục tiêu

```
client  ──gateway_option_id (BẮT BUỘC)──►  create()
                                              │
                        PAYMENT_POLICY_ENFORCEMENT_REQUIRED=true
                                              │
                        thiếu option id ⇒ 422 POLICY_OPTION_REQUIRED
                                              ▼
                                    assertNewPaymentAllowed()
```

Một cờ duy nhất, mặc định `false`, hạ được bằng env.

## 3. Bốn giai đoạn — thứ tự là phần quan trọng nhất

### G1 · Phủ policy revision (backfill)

Không đụng code đường tiền. Publish một revision cho **mọi branch active**.

Điều kiện ra: `policy_revision_coverage` = `N/N`, đo bằng
`payments:legacy-removal-readiness --json`.

⚠️ Backfill phải sinh revision **phản ánh đúng thứ shop đang thực sự nhận**, không
phải "bật hết cho an toàn". Bật hết = policy nói dối, và lần siết sau sẽ siết vào
một baseline sai.

### G2 · Client gửi option id (chưa cưỡng chế)

Ba client thêm `gateway_option_id` + `policy_revision` vào payload thanh toán.
Server **vẫn chấp nhận thiếu** — đây là giai đoạn không có rủi ro.

Đo bằng tỉ lệ: bao nhiêu % payment mới có `gateway_option_id`. Số này phải tự đo
được, xem §5.

Điều kiện ra: tỉ lệ ≈ 100% **và** giữ ổn định qua một chu kỳ đủ dài để build cũ
trong quán kịp cập nhật. Workstation là cái chậm nhất — nó quyết định độ dài.

### G3 · Quan sát

Bật một chế độ **cảnh báo, không chặn**: thiếu option id thì vẫn cho qua nhưng
ghi log `payment_policy_option_missing` kèm transport + device + branch. Đó là
danh sách chính xác những ai sẽ chết khi flip — không phải ước lượng.

Điều kiện ra: log rỗng qua một cửa sổ quan sát.

### G4 · Flip + xoá legacy

Bật `PAYMENT_POLICY_ENFORCEMENT_REQUIRED=true`. Sau khi ổn định, cổng
`legacy_payment_method_resolver` mới có nghĩa để về 0, và ~~`LegacyPaymentMethodResolver`~~ (**ĐÃ XOÁ ở #1887**)
xoá được thật.

## 4. Đơn offline replay — ca phải xử riêng

Workstation bán offline hôm qua, replay hôm nay, sau khi flag đã bật. Payload
mang option id của **revision cũ**, hoặc không mang gì (build cũ).

**Ruling: đường replay KHÔNG bị từ chối vì lý do policy.** Tiền đã vào két rồi;
từ chối lúc replay không lấy lại được tiền, chỉ tạo ra một đơn mồ côi và một ca
thu ngân không khớp.

**Cài thế nào — chốt (a-mạnh) 2026-08-05.** Ranh giới KHÔNG đặt ở đường
`replayOffline`: đường đó chỉ tạo ĐƠN, không tạo payment (đo ngày 2026-08-05).
Tiền offline đi `POST /workstation/payments`, cùng endpoint với payment online.

Nên marker phải nằm trên **ĐƠN**, do **Cloud ghi** tại
`insertOfflineReplay()` ngay sau `assertTrusted()` — thời điểm Cloud đã biết
chắc đơn đến từ evidence đã verify. Payment sau đó miễn trừ theo đơn nó thuộc về.

Tuyệt đối **không** để client tự khai (`taken_offline_at` do workstation gửi):
thiết bị chỉ cần gắn trường đó vào mọi payment là tự miễn trừ vĩnh viễn — đúng
cái lỗ plan này đang vá. Nguyên tắc gốc #1092: thiết bị KÝ bằng chứng, Cloud
PHÁN XÉT.

- replay ghi nhận option id nếu có, để nguyên nếu không;
- ghi log `payment_policy_replay_bypass` để đếm được;
- việc "đơn đó có đúng policy không" là câu hỏi **hậu kiểm**, không phải câu hỏi
  chặn-hay-không.

Validator vẫn không được biết đơn đến từ đâu — quyết định miễn trừ nằm ở
`handleMissingPolicyOption()`, đọc dấu trên đơn.

## 5. Số đo cần có TRƯỚC khi làm G2

Không có số thì G2/G3 không có điều kiện ra, và plan biến thành cảm tính.

| Số | Lấy từ đâu |
|---|---|
| độ phủ revision | đã có — `legacy-removal-readiness` precondition |
| % payment có `gateway_option_id` | **chưa có** — cần thêm, xem TASKS T1.1 |
| ai còn thiếu (transport/device/branch) | **chưa có** — log G3 |

Thứ tự trong TASKS đặt số đo **trước** thay đổi, cố ý: plan-047 đã trả giá vì
tuyên bố "code xong" trên một checklist chưa ai đo.

## 6. Đường lùi

| Bước | Lùi thế nào |
|---|---|
| G4 flip | `PAYMENT_POLICY_ENFORCEMENT_REQUIRED=false` — một dòng env |
| G2 client | client cũ vẫn chạy được vì server chưa cưỡng chế |
| G1 backfill | revision là append-only, không xoá; publish revision mới để sửa |

Không có bước nào cần rollback dữ liệu.
