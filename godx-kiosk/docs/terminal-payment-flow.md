# Luồng kết nối Kiosk App <-> Thiết bị quẹt thẻ (Vesca P400)

## Tổng quan

Kiosk app kết nối trực tiếp với thiết bị quẹt thẻ Vesca P400 qua **WebSocket trên mạng LAN**.
Không đi qua internet. Kiosk (iPad) và P400 phải cùng mạng WiFi/LAN.

```
┌──────────────┐    WebSocket (LAN)    ┌──────────────┐    Mạng viễn thông    ┌──────────────┐
│  Kiosk App   │◄─────────────────────►│  Vesca P400  │◄────────────────────►│  Trung tâm   │
│  (iPad)      │    ws://192.168.x:port│  (máy quẹt)  │                      │  thanh toán   │
└──────────────┘                       └──────────────┘                       └──────────────┘
                                            ▲
                                            │
                                       Khách chạm thẻ/
                                       quét QR tại đây
```

## Các thành phần trong app

```
┌─ React Native ──────────────────────────────────────┐
│                                                      │
│  Payment Screen (card.tsx / qr.tsx / emoney.tsx)     │
│       │                                              │
│       ▼                                              │
│  useTerminal() hook                                  │
│       │                                              │
│       ▼                                              │
│  TerminalProvider (terminal-provider.tsx)             │
│       │  postMessage() / onMessage()                 │
│       ▼                                              │
│  ┌─ WebView (invisible, 0x0 pixel) ──────────────┐  │
│  │                                                │  │
│  │  vesca-bridge.html                             │  │
│  │       │                                        │  │
│  │       ▼                                        │  │
│  │  Web Worker (VescaJS SDK)                      │  │
│  │       │                                        │  │
│  │       ▼                                        │  │
│  │  WebSocket ──────────► Vesca P400 terminal     │  │
│  │                                                │  │
│  └────────────────────────────────────────────────┘  │
│                                                      │
└──────────────────────────────────────────────────────┘
```

## Tại sao cần WebView?

VescaJS (SDK chính thức của Vesca) được thiết kế chạy trong trình duyệt:
- Dùng **Web Worker** để chạy protocol trên thread riêng
- Dùng **WebSocket** để giao tiếp với terminal
- Dùng **Blob** + **URL.createObjectURL** để tạo inline Worker

React Native không có Web Worker hay DOM API. Nên dùng **WebView ẩn** (invisible, 0x0 pixel) làm "engine" chạy VescaJS. WebView đóng vai trò như một trình duyệt mini bên trong app.

## Luồng thanh toán chi tiết

### Bước 1: Cấu hình (1 lần)

Nhân viên vào Settings → nhập IP + Port của terminal P400.
Lưu vào AsyncStorage. Ví dụ: `192.168.1.50:3647`

### Bước 2: Khởi tạo (mỗi lần mở app)

```
1. TerminalProvider mount
2. Kiểm tra config có IP + Port không
3. Nếu có → expo-asset download vesca-bridge.html → file:/// URI
4. WebView load file URI (loadFileURL — cần file:// để Worker hoạt động trên iOS)
5. VescaJS tạo Web Worker
6. Worker sẵn sàng → gửi message { type: 'READY' } về React Native
7. TerminalProvider set status = 'ready'
```

### Bước 3: Khách thanh toán

```
1. Khách chọn bàn → xem order → chọn "Thẻ tín dụng" → bấm "Thanh toán"

2. Payment screen gọi:
   terminal.requestPayment({
     AuthorizeSales: {
       SequenceNumber: 100,
       CurrentService: 'Credit',
       Amount: 3000,         // 3000 JPY
       TaxOthers: 0,
       TrainingMode: false,
       AdditionalSecurityInformation: { lang: 'ja', apStatusOption: 1 }
     }
   })

   Lưu ý: StartService chỉ dùng cho test kết nối (疎通確認).
   AuthorizeSales mới bắt đầu thanh toán thật.

3. TerminalProvider gửi message vào WebView:
   postMessage({ type: 'REQUEST', host: '192.168.1.50', port: 3647, request: {...} })

4. WebView nhận → gọi doRequestWorker() → Worker bắt đầu xử lý

5. Worker mở WebSocket tới ws://192.168.1.50:3647

6. Worker gửi lệnh A1 (yêu cầu thanh toán) qua WebSocket
   Nội dung: Base64 encoded JSON request

7. Terminal P400 nhận lệnh → hiển thị "Vui lòng chạm thẻ"
```

### Bước 4: Khách chạm thẻ

```
8. Khách chạm thẻ/quét QR trên máy P400

9. Terminal gửi trạng thái trung gian qua WebSocket:
   - "S507" = đang chờ thẻ
   - "S508" = đang đọc thẻ
   - "S509" = đang xử lý

10. Worker nhận → gửi StatusEvent về React Native
    → Payment screen hiển thị trạng thái cho khách

11. Terminal liên lạc với trung tâm thanh toán (qua mạng viễn thông)

12. Trung tâm phê duyệt → Terminal gửi kết quả về Worker
```

### Bước 5: Nhận kết quả

```
13. Worker nhận response từ terminal, 2 trường hợp:

    THÀNH CÔNG (OutputCompleteEvent):
    {
      "OutputCompleteEvent": {
        "SettledAmount": 3000,
        "ApprovalCode": "003993",
        "CardCompanyID": "104",
        "CurrentService": "Credit",
        "CustomerReceipt": [...],   // Dữ liệu in hóa đơn khách
        "MerchantReceipt": [...],   // Dữ liệu in hóa đơn cửa hàng
        "TenantReceipt": [...]      // Dữ liệu in hóa đơn tenant
      }
    }

    THẤT BẠI (ErrorEvent):
    {
      "ErrorEvent": {
        "ErrorCode": 114,           // Bị từ chối
        "Errorcodedetail": "POS_CANCEL",
        "Message": "..."
      }
    }

14. Worker gửi kết quả về WebView → WebView gửi về React Native

15. TerminalProvider set:
    - Thành công: status = 'success', result = OutputCompleteEvent
    - Thất bại: status = 'error', error = ErrorEvent

16. Payment screen hiển thị kết quả cho khách
    - Thành công → gọi backend POST /kiosk/payments → chuyển trang "Cảm ơn"
    - Thất bại → hiển thị lỗi, cho phép thử lại
```

## Protocol giữa Worker và Terminal

VescaJS dùng protocol tùy chỉnh trên WebSocket (không phải JSON thuần):

```
POS (Worker) → Terminal:
  A1{Base64(RequestJSON)}     Yêu cầu thanh toán
  AP                          Polling (hỏi trạng thái)
  AC                          Yêu cầu hủy
  ACK                         Xác nhận đã nhận
  NAK                         Yêu cầu gửi lại

Terminal → POS (Worker):
  0000A1                      Đã nhận yêu cầu (accepted)
  0001AP                      Đang xử lý (pending)
  S507AP                      Trạng thái: chờ thẻ
  0000AP{Base64(ResultJSON)}  Kết quả thành công
  ACK                         Xác nhận đã nhận
```

Trình tự:
```
Worker          Terminal
  │── A1{req} ───►│        Gửi yêu cầu
  │◄── ACK ───────│        Terminal xác nhận
  │── AP ─────────►│        Polling trạng thái
  │◄── 0001AP ────│        Đang xử lý
  │── ACK ────────►│
  │── AP ─────────►│        Polling tiếp
  │◄── S507AP ────│        Chờ thẻ
  │── ACK ────────►│
  │── AP ─────────►│        Polling tiếp
  │◄── 0000AP{res}│        Kết quả cuối cùng
  │── ACK ────────►│        Xác nhận
  │               │         Đóng kết nối
```

## Hủy thanh toán

Nếu khách muốn hủy (hoặc timeout):
```
Worker          Terminal
  │── AC ─────────►│        Gửi lệnh hủy
  │◄── 0000AC ────│        Terminal chấp nhận hủy
  │── ACK ────────►│
  │── AP ─────────►│
  │◄── ErrorEvent ─│        Kết quả: đã hủy (ErrorCode: 114)
```

**App-level cancel flow (kiosk-side):** Sau khi nhận ErrorEvent từ terminal (hoặc 3s timeout), kiosk gọi `GET /api/v1/kiosk/payments/{id}/status` để kiểm tra:
- `paid`: terminal đã capture trước khi cancel kịp xử lý → navigate `/success`, in biên lai cho khách.
- Khác: gọi `POST /api/v1/kiosk/payments/{id}/fail` → flip backend status, navigate back.

Logic ở `src/hooks/use-terminal-cancel.ts` (shared cho card/qr/emoney screens).

## Xử lý lỗi

| ErrorCode | Ý nghĩa | Xử lý |
|-----------|---------|-------|
| 990 | Lỗi bên trong VescaJS (mất kết nối, timeout) | Hiển thị lỗi, cho thử lại |
| 900 | Lỗi response từ terminal | Hiển thị mã lỗi |
| 114 | Thanh toán bị từ chối hoặc hủy | Hiển thị lỗi, cho thử lại |

| Errorcodedetail | Message | Ý nghĩa |
|-----------------|---------|---------|
| 0129 | ConnectionError | Không kết nối được terminal (sai IP/port, terminal tắt) |
| 0129 | NetworkDown | Mất kết nối giữa chừng |
| 0110 | POSForceCancelled | POS (app) đã hủy giao dịch |
| 0001 | DeviceBusy | Terminal đang xử lý giao dịch khác |

## Loại thanh toán hỗ trợ

| CurrentService | Loại | Mô tả |
|---------------|------|-------|
| `Credit` | Thẻ tín dụng | Visa, Mastercard, JCB, AMEX |
| `UnionPay` | Thẻ ngân hàng TQ | China UnionPay |
| `Edy` | E-money | Thẻ điện tử Rakuten Edy |
| `Alipay` | QR Pay | Quét mã Alipay |
| `WeChatPay` | QR Pay | Quét mã WeChat |

## Terminal Simulator (cho dev)

File `terminal-simurator.js` (Node.js) giả lập terminal P400:

```bash
node terminal-simurator.js
# WebSocket server chạy trên port 3647
```

Trong Settings, nhập IP máy dev + port 3647. Gửi thanh toán → simulator trả response mẫu.

## Lỗi thường gặp

### "DOMException doesn't exist"

**Nguyên nhân:** WebView load HTML bằng `loadHTMLString` với `baseURL = about:blank`. iOS sandbox chặn `Blob`/`Worker` dưới origin `about:blank`.

**Fix:** Dùng `expo-asset` resolve HTML file thành `file:///` URI → WebView dùng `loadFileURL` → Worker hoạt động.

### "RNCWebViewModule could not be found"

**Nguyên nhân:** `react-native-webview` native module chưa được link.

**Fix:** `npx pod-install && npx expo run:ios --device`

### Terminal connection timeout

**Nguyên nhân:** iPad và terminal không cùng mạng, hoặc IP/port sai.

**Fix:** Kiểm tra cùng WiFi, ping IP terminal, kiểm tra port trong settings terminal.

### Kết nối thất bại sau rebuild app

**Nguyên nhân:** iOS App Transport Security (ATS) chặn `ws://` plaintext tới IP LAN. `NSAllowsLocalNetworking = true` chỉ cho phép localhost/127.0.0.1/.local — không bao gồm IP private như 192.168.x.x. Cần `NSAllowsArbitraryLoads = true` trong `Info.plist`.

**Fix:** Set `NSAllowsArbitraryLoads = true` trong `ios/Kiosk/Info.plist`.

**Lưu ý cho App Store:** Nếu sau này publish lên App Store:
- Apple có thể yêu cầu justification cho `NSAllowsArbitraryLoads = true`
- Lý do chính đáng: kết nối thiết bị thanh toán Vesca P400 trên LAN qua WebSocket, terminal không hỗ trợ TLS (`wss://`)
- Thay thế an toàn hơn: dùng `NSExceptionDomains` cho IP cụ thể — nhưng IP terminal thay đổi theo nhà hàng nên không thực tế
- Kiosk app dùng enterprise distribution (không qua App Store) thì không bị ảnh hưởng

### LP0 — Terminal kẹt chờ reprint

**Nguyên nhân:** Giao dịch trước thành công nhưng receipt chưa được xử lý. Terminal chờ POS gửi `PrintRetry`.

**Fix:** Gửi `PrintRetry` qua Vesca sample page hoặc khởi động lại terminal. Để tránh: luôn gửi `printOption: 0` trong `AdditionalSecurityInformation` (kiosk app đã set).

## Files liên quan

| File | Vai trò |
|------|---------|
| `src/providers/terminal-provider.tsx` | Bridge giữa React Native và WebView |
| `assets/vesca-bridge.html` | HTML chứa VescaJS SDK + Web Worker |
| `src/hooks/use-terminal-config.ts` | Đọc/ghi IP + port từ AsyncStorage |
| `src/types/terminal.ts` | TypeScript types cho messages |
| `src/lib/terminal-utils.ts` | Helper format receipt data |
| `app/settings.tsx` | UI cấu hình IP + port |
| `app/payment/card.tsx` | UI thanh toán thẻ tín dụng |
| `app/payment/qr.tsx` | UI thanh toán QR |
| `app/payment/emoney.tsx` | UI thanh toán e-money |
