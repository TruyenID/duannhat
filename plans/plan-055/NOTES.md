# Plan 055 — Notes

## 2026-08-05 — T5.1 KHÔNG cài được như đặc tả (chặn T5.1–T5.3)

Phát hiện khi bắt tay làm T5.1. Ruling *"đơn offline replay không bị từ chối vì
lý do policy"* vẫn **đúng**; chỗ sai là **T5.1 chỉ vào một đường không mang tiền**.

### Ba phép đo trên `origin/dev`

**1. `replayOffline` chỉ tạo ĐƠN, không tạo payment.**
`OrderService::replayOffline()` verify evidence rồi gọi `insertOfflineReplay` —
hết. Không có dòng `order_payments` nào sinh ra ở đó. Đặt miễn trừ tại đây là
**no-op trông như đã làm**: cổng xanh, hành vi không đổi.

**2. Tiền offline đi đường `POST /workstation/payments`** →
`Workstation\PaymentController::store()` → `OrderPaymentService::create()` với
`orchestrator_transport = 'workstation'` — **cùng endpoint với payment online**.

**3. Endpoint đó không có marker offline nào.**

```
grep -ci "offline|replay|evidence"  Workstation/PaymentController.php  →  0
```

Payload không nhận `paid_at` / `occurred_at`; server tự đóng dấu `now()`. Nên
Cloud **không phân biệt được** "bán offline hôm qua, sync hôm nay" với "bán
online vừa xong".

### Vì sao điều đó chặn T5.1–T5.3

Miễn trừ "cho replay" chỉ có hai cách cài, cả hai đều sai:

| Cách | Hỏng thế nào |
|---|---|
| Miễn trừ `replayOffline` | no-op — đường đó không tạo payment |
| Miễn trừ transport `workstation` | miễn trừ **cả payment online** của workstation ⇒ lỗ to hơn lỗ đang vá |

T5.2/T5.3 cũng không viết được: cả hai giả định "sau khi flag bật", mà flag là
**T6.1 — chưa tồn tại**. Đây là lỗi thứ tự thứ hai trong plan (lỗi thứ nhất: điều
kiện ra của G1 thiếu vế "≥1 effective option", đã sửa ở #1821).

### Ba đường đi tiếp — cần người chốt

**(a) Workstation gửi marker offline** (ví dụ `taken_offline_at`, lý tưởng là nằm
trong phần đã ký của evidence để không giả mạo được).
→ Đây là **thay đổi client**, thuộc **T3.3**, và phải land **TRƯỚC** khi bật
cưỡng chế. Nghĩa là **T5 phụ thuộc T3.3**, ngược với thứ tự plan đang ghi.
→ Đúng đắn nhất; đắt nhất.

**(b) Suy ra từ `till_session_id`** — payment trỏ vào ca đã settled gần như chắc
là sync muộn.
→ **Không nên**: đây là *heuristic*, và nó quyết định tiền có được nhận hay
không. Đoán sai theo hướng lỏng = mở lỗ; sai theo hướng chặt = từ chối tiền thật.

**(c) Bỏ miễn trừ** — cưỡng chế áp cả payment workstation sync muộn.
→ Mâu thuẫn trực tiếp với ruling của plan: tiền đã vào két, từ chối lúc replay
không lấy lại được tiền, chỉ tạo đơn mồ côi + ca thu ngân lệch.

### Trạng thái

T5.1–T5.3 **chưa làm**, và cố ý không làm. Chọn (a) thì phải sửa thứ tự plan:
`T3.3 → T5.x → T6.x`.

## 2026-08-05 — CHỐT (a), nhưng ở dạng MẠNH: server ghi marker, không phải client khai

Người chốt phương án **(a)**. Khi đi làm thì (a) tách làm hai, và bản mô tả ban
đầu là bản **yếu**:

| | Marker đến từ đâu | Giả mạo được? |
|---|---|---|
| **(a-yếu)** — như NOTES hôm trước ghi | workstation gửi `taken_offline_at` | **CÓ**. Thiết bị chỉ cần gắn trường đó vào MỌI payment là tự miễn trừ vĩnh viễn — tức chính cái lỗ đang vá, chỉ đổi tên |
| **(a-mạnh)** — chốt dùng cái này | Cloud ghi lúc `insertOfflineReplay`, từ evidence ĐÃ VERIFY | **KHÔNG** |

(a-yếu) không dùng được vì nó mâu thuẫn với nguyên tắc gốc của #1092: **thiết bị
không được tự khẳng định điều gì ảnh hưởng tới tiền**. Nó ký bằng chứng, Cloud
mới là bên phán xét.

### Vì sao (a-mạnh) khả thi

`EloquentOrderPersistence::insertOfflineReplay()` đã:
- gọi `$command->assertTrusted()` — chỉ chạy sau khi
  `OfflineOrderEvidenceVerifier` chấp nhận chữ ký,
- ghi `opened_at = $snapshot->soldAt` (dòng 176) — thời điểm bán **ràng buộc chữ
  ký**; lùi ngày là vỡ chữ ký, verifier từ chối, **không đơn nào được tạo**.

Nên tại đúng chỗ đó Cloud **đã biết chắc** đơn này đến từ offline. Chỉ cần
**ghi lại sự thật đó**, thay vì để client khai.

### Hệ quả: THỨ TỰ SỬA NGƯỢC VỚI DỰ ĐOÁN

NOTES hôm trước viết *"(a) làm T5 phụ thuộc T3.3"*. **Sai** — đó là hệ quả của
(a-yếu). Với (a-mạnh) **không có thay đổi client nào**, nên:

```
T5.x  KHÔNG phụ thuộc T3.3  →  làm được ngay, độc lập với rollout client
```

Đây là lý do phải đọc code trước khi sắp thứ tự: cùng một chữ "(a)" cho ra hai
đồ thị phụ thuộc trái ngược.

### Đã cân nhắc và LOẠI: suy từ `opened_at` vs `created_at`

Đơn offline có `opened_at` (đã ký) sớm hơn hẳn `created_at` (đồng hồ Cloud), nên
về lý có thể suy ra mà không đổi schema. **Loại**, vì nó cần một NGƯỠNG ("sớm hơn
bao nhiêu thì tính là offline"), và ngưỡng đó quyết định tiền có được nhận hay
không — đúng loại heuristic đã bị loại ở phương án (b).

### Việc còn lại của (a-mạnh)

Thêm một trường trên `CustomerOrder` (qua Omnify YAML, KHÔNG viết migration tay)
ghi dấu đơn đến từ replay đã verify; `handleMissingPolicyOption()` miễn trừ khi
đơn mang dấu đó, kèm log `payment_policy_replay_bypass`.

⚠️ Regen chạm submodule — theo bẫy #7 trong CLAUDE.md phải `tal submodule <path>`
cho mọi submodule sắp chạm **trước khi** `omnify:gen`, nếu không generator ghi
vào điểm mount rỗng và khoá luôn đường init.
