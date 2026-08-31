# Plan: Gỡ kẹt terminal (LP0) + Bill in lại được

**Trạng thái:** Phase 1 ✅ + Phase 2 (phần kiosk an toàn) ✅ đã implement. Phần phụ thuộc WS còn chờ.
**Ưu tiên:** Phase 1 (terminal kẹt) + Phase 2 (bill in) theo yêu cầu.
**Scope:** Chỉ kiosk-side. Phần auto-print của workstation + BE trả print-status nằm ở umbrella (xem §5).

## Tình trạng implement (kiosk) — HOÀN TẤT

WS đã fix xong (contract xác nhận ở §5). Toàn bộ phần kiosk đã implement:

- ✅ **Phase 1** — `PrintRetry` + `forceReset` gỡ kẹt terminal. Files: `src/types/terminal.ts`,
  `assets/vesca-bridge.html`, `src/providers/terminal-provider.tsx`, `app/settings.tsx`,
  `src/i18n/{vi,en,ja}.json`. UI ở Settings → card Thiết bị thanh toán → "Gỡ kẹt thiết bị".
- ✅ **Phase 2.2** — auto-retry 1 lần khi in lỗi (`src/hooks/use-receipt-print.ts`).
- ✅ **Phase 2.1 + WS-1** — kiosk subscribe socket event `print_status`
  (`src/hooks/use-auto-print-status.ts`) → khi WS báo auto-print **failed** cho order hiện tại,
  cả 4 màn success (`app/success.tsx`, `app/split/success.tsx`, `app/custom/success.tsx`,
  `app/split/items-success.tsx`) hiện banner đỏ nổi bật + nút "In lại".
  - ⚠️ **Quyết định:** KHÔNG auto-gọi `printKioskReceipt` lúc vào success — vì endpoint
    `payment-receipt` là **reprint** (trả `reprint_no:N`), gọi mù sẽ in đúp với bản auto-print
    của WS lúc confirm. Thay vào đó dựa vào `print_status`: chỉ khi WS báo failed (bản đầu CHƯA
    ra) staff/khách mới bấm In lại — reprint lúc đó là bản đầu tiên thật, không đúp.
  - ⚠️ **Giới hạn:** `print_status` là live event; nếu fire đúng lúc chuyển màn confirm→success
    thì có thể lỡ (không có API "get last print status"). Chấp nhận được; nút In lại thủ công vẫn
    là backstop.
- ✅ **Phase 3 + WS-3** — `confirm()` retry với backoff (500/1500/3000ms) trong
  `src/hooks/use-payment.ts`; confirm idempotent server-side nên retry an toàn sau khi terminal đã
  trừ tiền. Hết retry vẫn fail → surface lỗi + `auditPaymentFailed` để đối soát, KHÔNG báo succeeded giả.
- Typecheck: 0 lỗi mới (39 pre-existing codegen/test). Lint: 0 error. Test: **107/107 pass**
  (thêm 12 test mock: `use-auto-print-status`, `use-receipt-print` retry, `use-payment` confirm-retry).
- **Live test trên iOS Simulator (iPad Air, Maestro):** bắn `print_status` qua đúng
  `workstationSocket` thật → `useAutoPrintStatus` → banner đỏ "In hoá đơn thất bại" hiện đúng
  (`failed=true reason=printer_offline`); bắn `success` → banner clear. PASS. Scaffolding demo
  (route + flow + socket hook) đã gỡ sau khi verify.

---

## 1. Bối cảnh & triệu chứng

Case thực tế: **thanh toán success** (backend `succeeded`, màn `/success` hiện) **NHƯNG**:
- (A) Máy thanh toán (Vesca P400) **còn kẹt transaction** — phải reboot tay.
- (B) **Bill "ĐÃ THANH TOÁN" không in ra**.

Đây là 2 lỗi độc lập trùng thời điểm, không phải 1 nguyên nhân.

---

## 2. Root cause

### A. Terminal kẹt — failure mode LP0 (đã được document)

Tham chiếu: [terminal-payment-flow.md §LP0](../terminal-payment-flow.md) —
*"Terminal kẹt chờ reprint: giao dịch trước thành công nhưng receipt chưa xử lý. Terminal chờ POS gửi `PrintRetry`."*

Chuỗi sự kiện:
1. Terminal duyệt thẻ OK → bridge gửi `ACK` → `_onFinished` đóng socket, `cls=null`
   ([assets/vesca-bridge.html](../../assets/vesca-bridge.html) dòng minified 24-25). Happy path thì sạch.
2. Khi terminal **in slip nội bộ bị lỗi** (kẹt giấy / hết giấy / lỗi máy in P400),
   terminal rơi vào trạng thái **chờ POS gửi `PrintRetry`** và giữ giao dịch mở.
3. Bridge hiện **chỉ hỗ trợ** `StartService` / `AuthorizeSales` / `SubtractValue` + `CANCEL`
   ([src/types/terminal.ts](../../src/types/terminal.ts#L13-L52)). **Không có `PrintRetry`**
   → app không có đường gỡ → buộc reboot terminal tay.

Lỗi phụ — `DeviceBusy` mồ côi:
- Worker dùng singleton `cls`; `isAcceptable()` chỉ true khi `cls == null`.
- Nếu giao dịch trước **không tới `FINISHED`** (app background, WebView reload, crash giữa
  chừng), `cls` còn non-null trong JS → request kế tiếp bị `DeviceBusy`
  ([vesca-bridge.html](../../assets/vesca-bridge.html) worker `self.onmessage`) mà không có
  message nào để force-clear → khách kế tiếp kẹt.

### B. Bill không in — auto-print "mù"

- Workstation là "single print authority", **auto-in lúc confirm**; kiosk cố tình KHÔNG auto-in
  ([app/success.tsx:61-63](../../app/success.tsx#L61-L63)).
- Hệ quả: nếu auto-print của workstation fail (máy in offline/kẹt/LAN rớt), **màn `/success`
  vẫn báo "hoàn tất" mà không có cảnh báo nào**. Banner lỗi in chỉ xuất hiện khi user **bấm nút
  reprint thủ công** ([success.tsx:144-151](../../app/success.tsx#L144-L151)). Khách rời đi không
  có bill, không ai biết để in lại.
- `useReceiptPrint` không có retry, không pre-check ([src/hooks/use-receipt-print.ts](../../src/hooks/use-receipt-print.ts#L25-L40)).

### C. (Liên quan, secondary) confirm() fail sau khi terminal đã trừ tiền

[use-payment.ts:134-150](../../src/hooks/use-payment.ts#L134-L150): nếu `confirm()` ném lỗi
(workstation/mạng) **sau khi** terminal đã duyệt → tiền đã trừ nhưng backend còn `pending`,
khách kẹt màn "processing", không có retry confirm. Biến thể ghost-payment chưa che (chỉ path
cancel mới có ghost-guard ở [use-terminal-cancel.ts](../../src/hooks/use-terminal-cancel.ts)).

---

## 3. Manh mối kỹ thuật Vesca (đã tra trong SDK + Vesca sample page)

Nguồn: `http://vescad.s3-ap-northeast-1.amazonaws.com/VescaJS/FullFeatured-WS/payment-sample.html`
(+ `payment-sample.js?v=0.5-20230622`) — đây là trang staff dùng để "mock print lại" gỡ kẹt.

- ✅ **Schema `PrintRetry` chính xác** (verbatim từ `payment-sample.js`):
  ```json
  { "PrintRetry": { "SequenceNumber": 113, "CurrentService": "All", "TrainingMode": false } }
  ```
- ✅ `vescajs` minified bundle trong [assets/vesca-bridge.html](../../assets/vesca-bridge.html)
  **đúng y hệt** module trong sample page (cùng version 0.5 2023/06/22). Nên `PrintRetry` chạy
  **đúng cùng đường** như `AuthorizeSales`: `request` object → `JSON.stringify` → Base64 →
  `"A1"+base64` → trả `OutputCompleteEvent` (success) hoặc `ErrorEvent`.
- ➜ **Hệ quả lớn:** bridge là passthrough; gửi `{type:'REQUEST', host, port, request:{PrintRetry:…}}`
  **không cần sửa `vesca-bridge.html`** — chỉ cần thêm type + 1 hàm provider tái dùng đường REQUEST sẵn có.
- Các lệnh khác sample page hỗ trợ (tham khảo): `AuthorizeSales` (Credit/UnionPay),
  `SubtractValue` (EMoney/QRPayment), `AccessDailyLog` (DailyReport), `StartService` (`{"StartService":""}`),
  `Cancel`.
- `Cancel` đi đường riêng (`cls._requestCancel()`), chỉ chạy khi `cls != null`. PrintRetry trong
  case LP0 (giao dịch trước đã `FINISHED` nên `cls == null`) đi đường **request mới**
  (`isAcceptable → new cls → doRequest`) → chạy bình thường.
- ⚠️ Lưu ý `DeviceBusy` mồ côi: nếu `cls != null` (worker còn nghĩ đang bận), PrintRetry cũng bị
  `DeviceBusy`. Đây là case riêng cần FORCE_RESET (xem 1.2).
- [settings.tsx:107](../../app/settings.tsx#L107) đã expose `testConnection` + `resetTerminal` →
  chỗ gắn UI gỡ kẹt.

---

## 4. Plan thay đổi

### Phase 1 — Gỡ kẹt terminal

**1.1 Mở rộng protocol type** — [src/types/terminal.ts](../../src/types/terminal.ts)
- Thêm `VescaPrintRetryRequest` vào union `VescaRequest` (schema thật, đã verify từ sample page):
  ```ts
  /** PrintRetry — yêu cầu terminal in lại slip của giao dịch vừa duyệt (gỡ LP0). */
  export interface VescaPrintRetryRequest {
    PrintRetry: {
      SequenceNumber: number;     // sample dùng 113
      CurrentService: 'All';
      TrainingMode: boolean;      // false
    };
  }
  ```
- Việc này đủ để `requestPayment(req)` (đang post `{type:'REQUEST', …}`) type-check với PrintRetry.
- (Cho FORCE_RESET) thêm message out `{ type: 'FORCE_RESET' }`.

**1.2 Bridge** — [assets/vesca-bridge.html](../../assets/vesca-bridge.html)
- **PrintRetry: KHÔNG cần sửa bridge** — đi đúng đường `REQUEST` sẵn có (đã verify ở §3).
- **FORCE_RESET (cho `DeviceBusy` mồ côi):** thay vì sửa code vendor minified, xử lý ở lớp wrapper:
  `FullFeaturedWorker.terminate(); FullFeaturedWorker = createWorker(vescajs);` → worker mới,
  `cls=null` sạch, rồi gửi lại `READY`. Thêm nhánh `else if (msg.type === 'FORCE_RESET')` trong
  cả 2 listener (`document` + `window`).
- Lưu ý: FORCE_RESET chỉ xoá state JS phía app, **không** đảm bảo terminal vật lý nhả giao dịch →
  PrintRetry mới là fix chính; FORCE_RESET là fallback khi worker kẹt `DeviceBusy`.

**1.3 Provider** — [src/providers/terminal-provider.tsx](../../src/providers/terminal-provider.tsx)
- Expose `printRetry()`: tái dùng đúng logic `requestPayment` (guard `processing`, set status,
  post REQUEST) nhưng với payload
  `{ PrintRetry: { SequenceNumber: 113, CurrentService: 'All', TrainingMode: false } }`.
  Kết quả về qua `RESULT` (OutputCompleteEvent) → status `success`, hoặc `ERROR`.
- Expose `forceReset()`: post `{ type: 'FORCE_RESET' }`, set `workerReady=false` → chờ `READY`,
  `updateStatus('initializing')`, clear `pendingMessage`.
- Thêm cả hai vào `TerminalContextValue` + `useMemo` value.

**1.4 UI gỡ kẹt** — [app/settings.tsx](../../app/settings.tsx) (mục Terminal card)
- Nút **"Gỡ kẹt máy thanh toán / In lại slip"** (staff thao tác sau passcode) → gọi `printRetry()`,
  hiển thị status/result/error qua state terminal sẵn có.
- Nút **"Reset kết nối terminal"** → `forceReset()` (khi gặp `DeviceBusy`).
- (Optional) Banner tự đề xuất trong [card.tsx](../../app/payment/card.tsx)/[emoney.tsx](../../app/payment/emoney.tsx)
  khi `terminalError` có code `DeviceBusy (0100/Processing)` → nút "Reset & thử lại".

**1.5 i18n** — [src/i18n/{vi,en,ja}.json](../../src/i18n/)
- `terminal.recover_print` / `terminal.force_reset` / `terminal.recover_hint`.

### Phase 2 — Bill in được & quan sát được

**2.1 Chủ động xác nhận in ở success** — [app/success.tsx](../../app/success.tsx)
- Đổi posture: thay vì im lặng tin auto-print của workstation, **gọi `printer.print(orderId)`
  một lần khi vào màn** (hoặc đọc print-status từ confirm response **nếu** BE expose — xem Ngoài scope).
- Giữ guard tránh in đúp: nếu BE đã auto-in thành công thì `printKioskReceipt` phải **idempotent**
  phía workstation (cần xác nhận BE — ghi ở Rủi ro). Nếu BE KHÔNG idempotent → KHÔNG auto-gọi,
  chỉ làm 2.2/2.3 (surface lỗi rõ + retry thủ công nổi bật).
- Áp dụng cho cả 4 success screen: [success.tsx](../../app/success.tsx),
  [app/split/success.tsx](../../app/split/success.tsx),
  [app/custom/success.tsx](../../app/custom/success.tsx),
  [app/split/items-success.tsx](../../app/split/items-success.tsx).

**2.2 Retry trong hook** — [src/hooks/use-receipt-print.ts](../../src/hooks/use-receipt-print.ts)
- Auto-retry 1 lần sau ~2s khi lỗi (khớp đề xuất cũ ở
  [receipt-printing-integration.md](receipt-printing-integration.md): không retry vô hạn).
- Giữ nút "In lại" rõ ràng.

**2.3 Surface lỗi nổi bật** — [app/success.tsx](../../app/success.tsx)
- Khi `printer.status === 'error'`: banner đỏ to + nút "In lại" prominent (không chỉ dòng nhỏ),
  để nhân viên thấy ngay là bill chưa ra.

### Phase 3 — Hardening confirm (secondary, làm sau nếu duyệt)

- [use-payment.ts](../../src/hooks/use-payment.ts) `confirm()`: retry có backoff (terminal đã
  capture → **không** được `fail` backend). Nếu vẫn fail sau N lần → set trạng thái
  "cần đối soát" + `auditPaymentFailed`/cảnh báo, không quăng khách về `pending` im lặng.

### Phase 4 — QA

- Kịch bản thêm vào [docs/qa-test-scenarios.md](../qa-test-scenarios.md):
  1. Terminal hết giấy → duyệt OK → gỡ kẹt bằng PrintRetry từ settings.
  2. `DeviceBusy` sau app background → ForceReset → giao dịch mới chạy.
  3. Workstation máy in offline → success screen báo lỗi + retry thành công.
  4. confirm() timeout sau capture → retry → vào success (Phase 3).

---

## 5. Phần fix ở Workstation / Backend (cho @bạn làm bên WS)

> Bối cảnh: kiosk coi **workstation là single print authority** — WS auto-in bill "ĐÃ THANH TOÁN"
> lúc payment confirm. Kiosk chỉ gọi reprint qua `POST /api/lan/print/payment-receipt`. Hiện kiosk
> **không có cách nào biết** auto-print của WS thành công hay fail → đó là vì sao "thanh toán
> success nhưng bill không ra" mà màn hình vẫn báo hoàn tất. 4 việc dưới đây fix gốc ở WS.

**WS-1. Báo kết quả auto-print về kiosk (QUAN TRỌNG NHẤT)**
- Sau khi WS auto-in lúc confirm, **broadcast 1 socket event** mới tới kiosk, ví dụ:
  ```json
  { "type": "print_status",
    "payload": { "order_id": "...", "status": "success" | "failed",
                 "reason": "printer_offline" | "paper_out" | "...", "kind": "payment_receipt" } }
  ```
- Kênh: cùng WebSocket mà kiosk đang nghe (hiện có `menu_updated` / `order_created` /
  `order_updated` / `order_paid` — xem [workstation-provider.tsx:80-97](../../src/providers/workstation-provider.tsx#L80-L97)).
  Kiosk **đã sẵn** `useWorkstationSubscribe(type, handler)` để tiêu thụ, gần như không tốn công phía kiosk.
- Khi `status: "failed"` → màn `/success` của kiosk sẽ hiện banner đỏ "bill chưa in" + nút "In lại".

**WS-2. `POST /api/lan/print/payment-receipt` phải idempotent + báo lỗi đúng**
- **Idempotent / reprint-safe:** gọi 2 lần cho cùng `order_id` KHÔNG được ra 2 bill ngoài ý muốn
  (hoặc định nghĩa rõ semantics reprint). Cần guard "đã in" hoặc đánh dấu bản reprint.
  → Quyết định này mở khoá việc kiosk có được **tự gọi in lại lúc vào success** hay không (Phase 2.1).
- **Response contract rõ ràng:** trả body có ý nghĩa — `success` / `queued` / `printer_error` +
  `reason`. **KHÔNG** trả `200` khi máy in đang offline (chỉ mới enqueue) — nếu trả 200 giả,
  kiosk sẽ báo "đã in" sai. Khi in fail thật → trả HTTP 4xx/5xx để `printKioskReceipt` (kiosk)
  ném lỗi và hiện banner ([api.ts:393-398](../../src/lib/api.ts#L393-L398),
  [use-receipt-print.ts](../../src/hooks/use-receipt-print.ts)).

**WS-3. `POST /api/v1/kiosk/payments/{id}/confirm` phải idempotent**
- Để kiosk có thể **retry confirm** an toàn khi mạng chập chờn sau khi terminal đã trừ tiền
  (Phase 3). Hiện flow validate "payment phải đang `pending`"
  ([kiosk-backend-flow.md:199](../kiosk-backend-flow.md)) → nếu confirm lần 2 sau khi đã
  `succeeded` sẽ bị 4xx. Sửa: confirm một payment **đã `succeeded`** → trả `{ status: "succeeded" }`
  (idempotent), KHÔNG báo lỗi. Đây là chốt chặn ghost-payment phía server.

**WS-4. (Optional) Queue + retry in phía WS**
- Khi máy in tạm offline → WS enqueue và tự retry vài lần, rồi mới phát `print_status` cuối cùng.
  Giảm tải cho retry phía kiosk.

> Lưu ý phân biệt 2 máy in (đừng nhầm khi fix WS): **slip nội bộ P400** (LP0, gỡ bằng `PrintRetry`
> — thuộc kiosk) ≠ **bill "ĐÃ THANH TOÁN"** (đi qua WS `payment-receipt`). WS chỉ lo cái sau.

### Bảng tóm tắt WS-side

| ID | Việc | Endpoint / kênh | Vì sao |
|---|---|---|---|
| WS-1 | Broadcast `print_status` (success/failed) sau auto-print | WebSocket event mới | Kiosk biết bill fail để hiện nút in lại |
| WS-2 | Print endpoint idempotent + trả lỗi đúng (không 200 giả) | `POST /api/lan/print/payment-receipt` | Cho phép kiosk auto in lại, không in đúp, không báo "đã in" sai |
| WS-3 | `confirm` idempotent (đã succeeded → trả succeeded) | `POST /api/v1/kiosk/payments/{id}/confirm` | Cho kiosk retry confirm an toàn, chống ghost-payment |
| WS-4 | (Optional) Queue + retry in phía WS | nội bộ WS | Giảm phụ thuộc retry phía kiosk |

---

## 6. Rủi ro & câu hỏi mở

1. ~~Schema `PrintRetry` thực tế~~ — ✅ **ĐÃ GIẢI QUYẾT**: lấy verbatim từ Vesca sample page
   (`{ "PrintRetry": { "SequenceNumber": 113, "CurrentService": "All", "TrainingMode": false } }`),
   và bundle `vescajs` trong kiosk trùng khớp module sample. Không còn là ẩn số.
2. **Idempotency của print endpoint** — quyết định 2.1 auto-gọi hay chỉ manual phụ thuộc câu này.
   Vẫn cần xác nhận với BE.
3. **Phân biệt 2 máy in**: slip nội bộ của terminal P400 (LP0) ≠ bill "ĐÃ THANH TOÁN" của
   workstation. PrintRetry chỉ gỡ cái (A); cái (B) đi đường `printKioskReceipt`.
4. **`SequenceNumber`**: sample hard-code 113; nên kiểm tra terminal có yêu cầu sequence tăng dần
   / khớp giao dịch trước không (đa số AP chấp nhận giá trị bất kỳ cho PrintRetry). Thử thực địa.

---

## 7. File đụng tới (tóm tắt)

| File | Phase | Thay đổi |
|---|---|---|
| `src/types/terminal.ts` | 1 | + PrintRetry request type, + PRINT_RETRY/FORCE_RESET messages |
| `assets/vesca-bridge.html` | 1 | handle PRINT_RETRY + FORCE_RESET (force `cls=null`) |
| `src/providers/terminal-provider.tsx` | 1 | expose `printRetry()` + `forceReset()` |
| `app/settings.tsx` | 1 | nút gỡ kẹt + reset |
| `app/payment/{card,emoney}.tsx` | 1 | (optional) banner DeviceBusy → reset & retry |
| `src/i18n/{vi,en,ja}.json` | 1,2 | keys mới |
| `app/success.tsx` (+ 3 success khác) | 2 | chủ động xác nhận in + banner lỗi nổi bật |
| `src/hooks/use-receipt-print.ts` | 2 | auto-retry 1 lần |
| `src/hooks/use-payment.ts` | 3 | retry confirm + trạng thái cần-đối-soát |
| `docs/qa-test-scenarios.md` | 4 | 4 kịch bản mới |
