# [02] Kiosk Integration — route Kiosk qua Workstation (mDNS + fallback Cloud)

> **Prerequisite:** [01 Workstation Local Replica](01-workstation-local-replica.md) Task 1-3 phải xong (schema + local endpoints + auth cache).
> **Owner:** Mobile dev (Expo / React Native)
> **Ước tính:** 4-5 ngày làm việc

**Goal:** Kiosk discover workstation qua mDNS, route mọi API call qua workstation (LAN-first). Workstation xử lý local (đã làm ở [01]). Fallback gọi thẳng Cloud nếu workstation unreachable.

**KHÔNG động vào:**
- Pair flow — kiosk vẫn pair Cloud trực tiếp như hiện tại (qua `POST /api/v1/devices/pair`). Token đó dùng được cho cả workstation lẫn Cloud.
- Print receipt — kiosk đã có [`react-native-star-io10`](../../../godx-kiosk/src/lib/printer.ts) in **trực tiếp** qua TCP. Không qua workstation.
- Tổng thể UX kiosk — chỉ thay đổi base URL của API client.

**Tech Stack:** Expo 54, React Native 0.81, `expo-secure-store` (đã có), `@react-native-community/netinfo` (đã có), thêm `react-native-zeroconf`.

---

## Phạm vi

| Tính năng | Vào plan này? | Lý do |
|---|---|---|
| mDNS discovery workstation | Yes | Core |
| Routing LAN-first, fallback Cloud | Yes | Core |
| Connection status UI | Yes | UX |
| WebSocket subscription qua workstation | Yes | Real-time menu + order |
| Pair flow đổi | No | Giữ pair Cloud hiện tại |
| Print receipt qua workstation | **No** | Kiosk tự in qua Star SDK |
| Local offline queue ở kiosk | No | Workstation queue đủ |

---

## Task 1 — Dependency mDNS + iOS/Android permissions

**Files:**
- Modify: `godx-kiosk/package.json`
- Modify: `godx-kiosk/app.json` (Expo config plugin)
- Auto: `godx-kiosk/ios/Info.plist`, `godx-kiosk/android/app/src/main/AndroidManifest.xml`

**Mô tả:**

Lib: **`react-native-zeroconf`** (https://github.com/balthazar/react-native-zeroconf).

**iOS permissions (bắt buộc iOS 14+):**

```json
{
  "ios": {
    "infoPlist": {
      "NSLocalNetworkUsageDescription": "Tìm Workstation trong mạng nhà hàng để xử lý order nhanh hơn",
      "NSBonjourServices": ["_ws-app._tcp"]
    }
  }
}
```

**Android (qua `app.json`):**

```json
{
  "android": {
    "permissions": [
      "android.permission.INTERNET",
      "android.permission.ACCESS_NETWORK_STATE",
      "android.permission.CHANGE_WIFI_MULTICAST_STATE"
    ]
  }
}
```

**Checklist:**
- [ ] `npx expo install react-native-zeroconf`
- [ ] Cấu hình Expo config plugin
- [ ] Build EAS development build (Expo Go không support native module)
- [ ] Test mDNS trên iOS device THẬT (simulator không có Bonjour)
- [ ] Test Android device thật
- [ ] Commit

---

## Task 2 — Discovery service + base URL resolver

**Files:**
- Create: `godx-kiosk/src/services/workstation/discovery.ts`
- Create: `godx-kiosk/src/services/workstation/base-url-resolver.ts`
- Create: `godx-kiosk/src/services/workstation/types.ts`

**Mô tả:**

### Discovery service

```typescript
interface WorkstationInfo {
  name: string;        // TXT.name
  branchId: string;    // TXT.branch_id
  proxyUrl: string;    // TXT.proxy_url, vd http://192.168.1.10:8080
  version: string;
}

interface WorkstationDiscoveryService {
  start(): void;
  stop(): void;
  onChange(cb: (ws: WorkstationInfo | null) => void): Unsubscribe;
  current(): WorkstationInfo | null;
}
```

**Logic:**
- Browse service `_ws-app._tcp.local.` khi app foreground
- Stop khi background (battery)
- Filter: chỉ giữ entries có TXT `branch_id` khớp với device branch (lấy từ `/me` response sau pair Cloud)
- Nếu nhiều workstation cùng branch → chọn version cao nhất

### Base URL resolver

```typescript
function resolveBaseUrl(): string {
  const ws = discovery.current();
  if (ws && !workstationUnreachable) {
    return ws.proxyUrl;            // → http://192.168.1.10:8080
  }
  return Constants.expoConfig.extra.EXPO_PUBLIC_API_URL;  // → Cloud
}
```

**Fallback per-request:**
- Workstation timeout < 3s → mark `unreachable`, retry Cloud
- Cache `unreachable` 30s, sau đó retry workstation
- Network change (4G ↔ WiFi) → reset state

### Fallback nhập IP tay

- Settings có input "Workstation URL" (nếu mDNS fail vì router enterprise)
- Lưu `expo-secure-store` key `workstation_manual_url`
- Resolver order: current() → manual_url → Cloud

**Checklist:**
- [ ] Discovery service với event emitter
- [ ] Base URL resolver + fallback
- [ ] AppState listener
- [ ] Unit test với mock zeroconf
- [ ] Manual test: bật workstation → thấy < 5s
- [ ] Manual test: tắt workstation → cleared < 10s
- [ ] Manual test: 2 workstation khác branch → chỉ thấy đúng cái
- [ ] Commit

---

## Task 3 — API client routing + WebSocket

**Files:**
- Modify: `godx-kiosk/src/lib/api.ts` (có `apiFetch`)
- Create: `godx-kiosk/src/services/workstation/socket.ts`
- Create: `godx-kiosk/src/providers/workstation-provider.tsx`
- Modify: `godx-kiosk/src/app/_layout.tsx`

**Mô tả:**

### Modify `apiFetch`

Hiện dùng cố định `process.env.EXPO_PUBLIC_API_URL` (không phải `Constants.expoConfig.extra`). Thay bằng:

```typescript
const CLOUD_URL = process.env.EXPO_PUBLIC_API_URL ?? "http://localhost:5400";

async function apiFetch<T>(path: string, options: RequestInit): Promise<T> {
  const baseUrl = resolveBaseUrl();  // workstation LAN-first, Cloud fallback
  const token = await getDeviceToken();
  
  try {
    return await fetchWithTimeout(baseUrl + path, {
      ...options,
      headers: { Authorization: `Bearer ${token}`, ...options.headers }
    }, baseUrl !== CLOUD_URL ? 3000 : 15000);
  } catch (e) {
    if (baseUrl !== CLOUD_URL && isNetworkError(e)) {
      markWorkstationUnreachable();
      return await fetchWithTimeout(CLOUD_URL + path, {
        ...options,
        headers: { Authorization: `Bearer ${token}`, ...options.headers }
      }, 15000);
    }
    throw e;
  }
}
```

**QUAN TRỌNG — Preserve deferred logout pattern:**
Kiosk hiện có cơ chế deferred logout (pendingLogout flag) để bảo vệ payment flow khỏi bị interrupt khi 401.
Khi refactor `apiFetch`, PHẢI giữ nguyên:
- `setUnauthorizedHandler()` callback registry
- 401 → `clearDeviceToken()` + trigger handler (KHÔNG redirect trực tiếp)
- AuthProvider kiểm tra `isInPaymentFlow(pathname)` trước khi logout
- Fallback Cloud call cũng phải đi qua cùng 401 handler

Một số response từ workstation có thể có header `X-Auth-Stale: true` (workstation cache stale vì Cloud down). Kiosk treat như success bình thường, có thể log để debug.

### WebSocket

- Connect khi workstation discovered: `ws://<workstation.proxyUrl>/ws?token=<device_token>`
- Reconnect exponential backoff 1s → 30s
- Heartbeat ping 30s
- Subscribe topic `order:status`, `menu` sau khi open

**Event handlers:**

| Event từ workstation | Kiosk làm gì |
|---|---|
| `menu.updated` | Invalidate React Query key `['menu']` → kiosk refetch (sẽ gọi qua workstation, đã có local copy) |
| `order.status_changed` | Invalidate `['orders']` |
| `device.revoked` | Trigger logout flow (xóa token, về login screen) |

### Provider

```typescript
const ctx = {
  workstation: WorkstationInfo | null,
  connected: boolean,
  unreachable: boolean,
  socket: SocketHelper,
  subscribe: (topic, handler) => unsubscribe,
}
```

**Provider mount order** — Kiosk root layout hiện có:
```
SafeAreaProvider → ErrorBoundary → AppProvider → QueryProvider → AuthProvider → TerminalProvider → PaymentFlowProvider → IdleTimer → ScaledRoot → Stack
```

WorkstationProvider đặt **sau AuthProvider, trước TerminalProvider**:
```
... → AuthProvider → WorkstationProvider → TerminalProvider → ...
```

Lý do: WorkstationProvider cần `device.branch_id` (từ `useAuth()`) để filter mDNS entries (Task 2: "chỉ giữ entries có TXT `branch_id` khớp với device branch"). Nếu đặt làm parent của AuthProvider → circular dependency.

Trade-off chấp nhận được: initial `/kiosk/me` verify lúc app mount đi Cloud trực tiếp (chưa qua workstation). Sau verify xong, `branch_id` available → WorkstationProvider start mDNS discovery với filter đúng → mọi call subsequent route qua workstation. Net cost = 0 vì workstation auth cache lúc đó empty, nếu route qua workstation cũng forward Cloud anyway.

Hook `useWorkstation()`. WorkstationProvider cũng cần QueryProvider làm ancestor (để invalidate cache on WS events) — điều kiện này đã thoả mãn vì QueryProvider là ancestor của AuthProvider.

### Order status real-time

Screen "đang chuẩn bị" (sau khi khách order xong):
- Subscribe `order:status`
- Nhận event cho order_id của mình → update UI
- Fallback polling 5s nếu WS không connected

**Checklist:**
- [ ] Refactor `apiFetch`
- [ ] WebSocket helper
- [ ] Provider + hook
- [ ] AppState listener: WS reconnect foreground
- [ ] Order status screen subscribe
- [ ] Test: api call đi tới workstation IP (native debugger)
- [ ] Test: tắt workstation → fallback Cloud, banner
- [ ] Test: bật lại → re-discover < 30s
- [ ] Test: revoke device trên Cloud → kiosk auto-logout
- [ ] Commit

---

## Task 4 — Connection status UI + manual config

**Files:**
- Modify: `godx-kiosk/src/app/settings.tsx`
- Create: `godx-kiosk/src/components/workstation-status-banner.tsx`
- Modify: `godx-kiosk/src/app/_layout.tsx`

**Mô tả:**

### Settings (admin access)

Section "Workstation":
- Status: Connected to `<name>` / Searching... / Not found
- IP/URL hiện tại + version
- Input "Workstation URL (nhập tay)"
- Button "Test connection" gọi `/api/lan/health`
- Button "Clear manual URL"

### Banner

Mảnh top, hiển thị khi:
- Searching → "Đang tìm workstation..." (5 giây đầu mở app)
- Found → ẩn 3s
- Unreachable đang dùng → orange "Mất kết nối workstation, đang dùng Cloud"
- Cloud cũng fail → red "Mất kết nối hoàn toàn"
- iOS Local Network denied → "Cần cấp quyền Local Network" + deep link Settings

**Checklist:**
- [ ] Banner 4 states
- [ ] Settings section
- [ ] iOS permission detection + deep link
- [ ] Test 4 states
- [ ] Test manual URL flow
- [ ] Commit

---

## Task 5 — End-to-end manual test plan

**Files:**
- Create: `godx-kiosk/docs/workstation-test-plan.md`

**Scenarios (device thật iOS + Android):**

1. **Happy path:** Pair Cloud → mở scan QR → discover workstation < 5s → mọi request đi qua workstation (verify native debugger Network)

2. **Workstation đổi IP (DHCP):** Restart router → IP mới → kiosk re-discover < 30s

3. **Workstation tắt:** Đang dùng → tắt → banner unreachable → fallback Cloud → bật lại → reconnect

4. **iOS Local Network denied:** từ chối permission → banner + deep link

5. **mDNS fail (router enterprise):** Bonjour disabled → nhập IP tay → vẫn dùng được

6. **Cloud down (workstation up):** Tắt Cloud → menu đọc qua workstation cache → tạo payment → workstation queue → bật Cloud → flush 10s

7. **Both down:** Cloud + workstation đều tắt → red banner → chỉ cache cũ hiển thị → submit fail message rõ

8. **Menu updated remote:** HQ sửa giá menu → kiosk nhận update qua WS workstation → giá đổi 1-2s

9. **Device revoked:** HQ revoke device → workstation WS event → kiosk auto-logout

**Checklist:**
- [ ] Test plan markdown
- [ ] Chạy 9 scenarios trên iOS + Android
- [ ] Document bugs, fix
- [ ] Commit

---

## Definition of Done

- [ ] 5 task có commit riêng
- [ ] Build EAS thành công iOS + Android
- [ ] 9 test scenarios pass trên device thật
- [ ] iOS App Store metadata cập nhật permission descriptions
- [ ] Demo: discover → order → payment → status real-time → in receipt trực tiếp qua Star
- [ ] Code review pass

## Rủi ro

| Rủi ro | Giảm thiểu |
|---|---|
| Apple từ chối Local Network permission | Description rõ + video demo + screenshot UX |
| Bonjour không hoạt động router enterprise | Manual IP (Task 4) |
| Battery drain mDNS browse | Stop khi background (Task 2) |
| Kiosk + workstation khác subnet (VLAN) | Document yêu cầu network: cùng subnet, multicast enabled |
| Workstation auth cache stale → kiosk dùng token đã revoke | WS event device.revoked invalidate, fallback timeout 5 phút |

## Out of scope (Phase 2)

- Multi-workstation failover 1 nhà hàng
- Encrypted comms (TLS LAN)
- Token auto-rotation
- Offline order CREATE từ kiosk (Phase 1 kiosk chỉ list, không create order)

---

## Status

- **Workstation side:** DONE — mDNS advertiser, local endpoints, auth cache, WebSocket hub, `/api/lan/health` all implemented
- **Kiosk side:** NOT STARTED — 0/5 tasks implemented
- **Plan reviewed:** 2026-05-22 — updated Task 3 (preserve deferred logout, provider mount order, env var syntax fix). Task 1/2/4/5 valid as-is.
- **Dependencies available:** `@react-native-community/netinfo` already installed (plan originally missed this)
