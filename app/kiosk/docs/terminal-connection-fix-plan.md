# Fix triệt để kết nối P400 — Phương án

## Root causes

1. **TerminalProvider mount/unmount** mỗi lần vào/rời payment → WebView destroy → P400 giữ session cũ
2. **Settings dùng raw WebSocket** riêng biệt → chiếm session P400
3. **READY gửi trước Worker sẵn sàng** → REQUEST gửi sớm, Worker chưa xử lý
4. **Cancel không chờ P400 phản hồi** → WebView destroy trước khi CANCEL tới P400

## Phương án: 4 thay đổi

### Thay đổi 1: Chuyển TerminalProvider lên root layout

**Hiện tại:**
```
app/_layout.tsx (root)
  ├── advertise.tsx
  ├── scan.tsx
  ├── settings.tsx          ← raw WebSocket riêng
  ├── payment/
  │   ├── _layout.tsx       ← TerminalProvider ở đây → mount/unmount
  │   └── card.tsx
```

**Sau fix:**
```
app/_layout.tsx (root)
  ├── TerminalProvider      ← mount 1 lần, sống suốt app lifecycle
  │   ├── advertise.tsx
  │   ├── scan.tsx
  │   ├── settings.tsx      ← dùng useTerminal(), bỏ raw WebSocket
  │   ├── payment/
  │   │   ├── _layout.tsx   ← bỏ TerminalProvider
  │   │   └── card.tsx
```

**Kết quả:**
- WebView + Worker chỉ mount 1 lần
- Navigate giữa screens không destroy WebSocket
- P400 không bị session cũ treo

**Files thay đổi:**
- `app/_layout.tsx` — wrap TerminalProvider
- `app/payment/_layout.tsx` — bỏ TerminalProvider

---

### Thay đổi 2: Settings dùng useTerminal() thay vì raw WebSocket

**Hiện tại:** Settings tạo `new WebSocket()` riêng → chiếm session P400

**Sau fix:** Settings gọi `useTerminal().testConnection()` → gửi `StartService` qua VescaJS Worker (cùng WebView với payment)

**Kết quả:**
- Chỉ có 1 kênh kết nối tới P400
- Test connection gửi StartService thật → biết P400 sẵn sàng hay không
- Không chiếm session rồi đóng không sạch

**Files thay đổi:**
- `app/settings.tsx` — bỏ raw WebSocket, dùng useTerminal()

---

### Thay đổi 3: READY chỉ gửi sau khi Worker confirm sẵn sàng

**Hiện tại:** `vesca-bridge.html` gửi READY ngay sau `createWorker()` — Worker chưa chắc đã init xong

**Sau fix:** Worker gửi message "WORKER_INIT" về main thread khi code bên trong đã chạy. Main thread nhận → gửi READY về React Native.

Trong `vesca-bridge.html`:
```js
// Worker code (bên trong vescajs function) — thêm ở cuối:
self.postMessage({ type: 'WORKER_INIT' });

// Main thread:
FullFeaturedWorker.onmessage = function(e) {
  if (e.data && e.data.type === 'WORKER_INIT') {
    sendToRN({ type: 'READY' });  // chỉ READY khi Worker thật sự chạy
    return;
  }
  // ... existing handlers
};
```

**Kết quả:**
- `workerReady = true` chỉ khi Worker **thật sự** chạy code
- Bỏ delay 500ms workaround
- REQUEST luôn gửi đúng lúc

**Files thay đổi:**
- `assets/vesca-bridge.html`
- `src/providers/terminal-provider.tsx` — bỏ setTimeout 500ms

---

### Thay đổi 4: Cancel chờ phản hồi trước khi navigate

**Hiện tại:**
```tsx
cancel();           // gửi CANCEL
await fail(...);    // gọi backend
router.back();      // rời ngay → WebView có thể bị destroy
```

**Sau fix:**
```tsx
cancel();           // gửi CANCEL
// Chờ terminal phản hồi (ErrorEvent) hoặc timeout 3s
await waitForTerminalResponse();
await fail(...);    // gọi backend
router.back();      // rời sau khi P400 đã xác nhận cancel
```

Vì TerminalProvider ở root (thay đổi 1), WebView không bị destroy khi `router.back()`. Nhưng vẫn nên chờ phản hồi để UX rõ ràng — khách thấy "Đã hủy" trước khi quay lại.

**Implementation:** Thêm Promise trong card.tsx — resolve khi `terminalStatus` chuyển sang `error` (cancel response) hoặc timeout 3s.

**Files thay đổi:**
- `app/payment/card.tsx`
- `app/payment/qr.tsx`
- `app/payment/emoney.tsx`

---

## Thứ tự implement

1. **Thay đổi 3** (READY sau Worker init) — fix nhỏ nhất, không thay đổi architecture
2. **Thay đổi 1** (Provider lên root) — thay đổi lớn nhất, fix root cause chính
3. **Thay đổi 2** (Settings dùng useTerminal) — phụ thuộc thay đổi 1
4. **Thay đổi 4** (Cancel chờ phản hồi) — polish UX

## Rủi ro

| Thay đổi | Rủi ro | Mức |
|---|---|---|
| 1 - Provider root | WebView ẩn luôn chạy ~30-50MB RAM | Thấp — iPad đủ RAM |
| 1 - Provider root | Re-render toàn app khi terminal status đổi | Thấp — useMemo đã có |
| 2 - Settings useTerminal | Settings cần TerminalProvider wrap → OK vì đã ở root | Không có |
| 3 - READY timing | Cần sửa VescaJS minified code (thêm postMessage) | Trung bình — cẩn thận regex |
| 4 - Cancel chờ | Timeout 3s nếu P400 không phản hồi | Thấp — fallback navigate |

## Kết quả mong đợi

- **Không còn "lúc được lúc không"** — 1 WebView, 1 Worker, 1 connection
- **Settings test connection tin cậy** — dùng cùng kênh với payment
- **REQUEST luôn gửi đúng** — READY chỉ khi Worker thật sự sẵn sàng
- **Cancel sạch** — P400 nhận cancel trước khi app chuyển screen
