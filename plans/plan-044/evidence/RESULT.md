# Evidence — order/payment attribution across shift phases (plan-044 R2)

**Câu hỏi:** khi đang **kết ca** (closing) mà có order được **thanh toán / tạo mới**, và cả trong lúc **gap** (đã settled, chưa mở ca mới) — order/payment đó thuộc **ca trước** hay **ca sau** sau khi mở ca lại?

**Ngày chạy:** 2026-07-16 · **Shop:** `sjk` (đã park các ca seed cũ để test sạch — `openSessionIdForBranch=NULL` ban đầu).

**Phương pháp (đủ 3 loại bằng chứng):**
- **curl** (HTTP thật, token Sanctum + `X-Shop-Slug: sjk`) drive shift lifecycle + gap-preview + open-với-claim.
- **Resolver thật** của handler: order dùng `openSessionIdForBranch` (open-only), payment dùng `resolveSyncedSessionId(..., inProgress)` — tạo row DB stamp y hệt handler.
- **DB** (`order_payments.till_session_id` — đúng cột `reconcile()` đọc) làm ground-truth.
- **Playwright** chụp UI pos-web.

- Ca 1 = **S1** `019f6a0a-6373-72f3-b91c-d2cb3c5e146a` (SHIFT-20260716-007)
- Ca 2 = **S2** `019f6a44-b7fe-7275-9961-59e843a0f5ec` (SHIFT-20260716-008)

---

## Kết quả theo từng phase (data thật)

| Phase | Trạng thái ca 1 | `openSessionIdForBranch` (ORDER) | `resolveSyncedSessionId` (PAYMENT) | ORDER thuộc | PAYMENT thuộc |
|---|---|---|---|---|---|
| **OPEN** | `open` | S1 | S1 | **S1** | **S1** |
| **CLOSING** (đang kết ca) | `closing` | **NULL** | **S1** | NULL (display-only) | **✅ S1 — ca đang kết** |
| **GAP** (đã settled) | `settled` | NULL | NULL | NULL | NULL → gap payment |
| **CLAIM @ mở ca 2** | — | — | — | — | **✅ S2 — ca mới (sau khi confirm)** |

### Kết luận trực tiếp cho câu hỏi
- **Thanh toán TRONG LÚC KẾT CA (`closing`) → thuộc CA 1 (ca đang kết).** Vì payment dùng `inProgress` (open **hoặc** closing) → ca đang đóng vẫn sở hữu tiền. → **PAYMENT_B (¥1000) tạo lúc closing → S1.**
- **Tạo ORDER lúc closing → order header `till_session_id = NULL`** (order dùng open-only). Nhưng đây chỉ là cột **display-only**, `reconcile()` KHÔNG đọc — không ảnh hưởng tiền. Order nợ carry tự nhiên.
- **Thanh toán TRONG GAP (đã settled) → NULL** → hiện ở panel "Đối chiếu thanh toán" khi mở ca 2 → nhân viên confirm → **gán vào CA 2.** → **PAYMENT_C (¥800) → S2.**

### Bằng chứng DB cuối cùng (sau toàn bộ chu trình)
```
PAYMENT_A  ¥1000  till_session_id → S1 (ca 1)          [tạo lúc OPEN]
PAYMENT_B  ¥1000  till_session_id → S1 (ca 1)          [tạo lúc CLOSING → vẫn về ca đang kết]
PAYMENT_C  ¥800   till_session_id → S2 (ca 2)          [gap → claim ở mở ca 2 → về ca mới]
```

### Bằng chứng HTTP (curl) — các mốc chính
```
POST /pos/till/sessions                → 201  S1 open
PATCH /pos/till/sessions/{S1}/draft     → 200  S1 status=closing
GET  /pos/till/gap-preview              → 200  previous_session=SHIFT-...007  totals={count:1, cash:800}  gap payment ¥800 is_cash=true
POST /pos/till/sessions (claim PAY_C)   → 201  S2 open, gap_payments_claimed=1
```

---

## Bằng chứng UI (Playwright, pos-web `/shop/sjk/shift/open`)

- **`ui-01-shift-open.png`** — màn Mở ca hiển thị panel **"Đối chiếu thanh toán trong lúc chuyển ca · 1 khoản"**: order `E2E-D` ¥500, badge **"Tiền mặt — giữ riêng"**. (Đây là gap payment tạo trong lúc không có ca → hiện ở màn mở ca để đối chiếu.)
- **`ui-02-gap-claimed-ack.png`** — sau khi **tick** khoản tiền mặt: hiện callout **"Xác nhận tiền mặt giữ riêng"** + checkbox bắt buộc **"Tôi xác nhận số tiền mặt trên được giữ riêng, không nằm trong tiền đầu ca."** — gate chặn mở ca cho tới khi tick.

> UI xác nhận đúng data: **chỉ payment (đã thanh toán) mới hiện ở màn mở ca** để đối chiếu — order chưa thanh toán KHÔNG hiện ở đây (chúng carry sang ca sau, chỉ đếm ở màn kết ca).

---

## Ghi chú dọn dẹp
- Test tạo các row nhãn `E2E-*` (orders + payments) trên shop `sjk` — vô hại, có thể xoá.
- Đã **park 3 ca seed cũ** của sjk (REG1/REG2/REG3 open/closing) → `abandoned` để test sạch. Có thể mở lại nếu cần.
- Auth: token Sanctum admin (đã xoá sau test được nếu muốn); UI ép Cloud bằng `localStorage pos_api_mode=cloud` vì máy này có workstation chạy ở :8080 chặn token user.
