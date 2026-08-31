# Workstation Integration — Manual Test Plan

**Scope:** End-to-end verification of Kiosk ↔ Workstation routing (mDNS discovery + LAN-first apiFetch + WebSocket real-time).

**Prereq:**
- Kiosk built with EAS dev client (Expo Go won't load `react-native-zeroconf`)
- Workstation app running on same WiFi LAN, paired to a Cloud branch
- A second device paired to the same branch (for `/kiosk/orders` to return data)
- Cloud API reachable from the LAN (for fallback path)

## Setup checklist

- [ ] Workstation advertising mDNS (`_ws-app._tcp.local.`) — verify with `dns-sd -B _ws-app._tcp` on macOS
- [ ] Workstation TXT records include `branch_id`, `proxy_url`, `version`
- [ ] Workstation `/api/lan/health` reachable from kiosk IP — verify with browser/curl
- [ ] Kiosk dev client installed via `npx expo run:ios --device` (or Android equivalent)
- [ ] Kiosk paired to **the same `branch_id`** as workstation

## Scenarios

### 1. Happy path — auto-discover
- Pair kiosk → open scan / advertise → wait < 5s
- **Expected:** Banner briefly shows "Connected to Workstation: {name}" then hides
- **Verify:** Settings → Workstation → status = "Connected", LAN URL shown
- **Verify:** Tap scan or trigger any API call — native debugger shows requests going to workstation IP, not Cloud

### 2. Workstation IP changed (DHCP renewal)
- Disconnect-reconnect router so workstation gets a new IP
- **Expected:** Within 30s kiosk re-discovers workstation at new IP
- **Expected:** Banner transitions: connected → unreachable → connected
- **Verify:** Settings shows new IP

### 3. Workstation tắt
- While actively using kiosk, kill workstation process
- **Expected:** Next API call times out at 3s → banner shows "unreachable, using Cloud"
- **Expected:** Subsequent calls go directly to Cloud (no extra 3s wait per request)
- Restart workstation → within 30s banner returns to "connected"

### 4. iOS Local Network permission denied
- Fresh install on iOS → deny Local Network prompt
- **Expected:** Banner shows "Workstation not found. Local Network permission may be required." + "Open Settings" button
- **Expected:** Kiosk continues working with Cloud (banner stays visible)
- Tap "Open Settings" → opens iOS Settings deep link
- After granting permission and reopening kiosk → banner resolves to "connected"

### 5. mDNS fail (enterprise router blocking Bonjour)
- Disable multicast on router or test on VLAN
- **Expected:** Banner shows "not found" after 5s grace
- Settings → Workstation → enter manual URL `http://<workstation-ip>:8080`
- Tap Save
- **Expected:** Banner now shows "Connected" using the manual URL
- **Verify:** Settings → "Clear manual URL" button visible; clearing returns to mDNS-only mode

### 6. Cloud down, workstation up
- Block Cloud URL (firewall or disconnect Cloud DNS)
- **Expected:** Kiosk continues operating — menu, order list, payments work via workstation
- Submit a payment → workstation queues it locally
- Re-enable Cloud → workstation flushes queue within ~10s
- **Verify:** Cloud has the payment recorded

### 7. Both down
- Workstation off + Cloud blocked
- **Expected:** Banner red/warning "unreachable" → API calls eventually fail with TimeoutError
- Existing cached data still displays (React Query staleTime)
- **Expected:** No app crash, error messages localized

### 8. Menu updated remotely
- On Cloud admin, edit a menu price
- Workstation pulls (within 60s) and broadcasts `menu_updated` over WS
- **Expected:** Kiosk's menu query invalidated immediately → new price visible
- **Verify:** Settings → Workstation shows `WS ✓` (WebSocket connected)

### 9. Device revoked from Cloud
- Cloud admin revokes the kiosk's device
- Workstation receives `device.revoked` event (Phase 2 — not yet implemented on workstation side)
- **Expected (current behavior):** Next API call returns 401 → `apiFetch` clears token + triggers handler → AuthProvider defers logout if in payment flow, else redirects to /login
- **Verify:** Deferred logout still works when in `/payment/*`, `/custom/*`, `/split/*`, `/success`

## Pass criteria

- All 9 scenarios behave as described above
- Banner states transition correctly on iOS and Android device builds
- Manual URL fallback works when mDNS unavailable
- No regressions in existing kiosk flows (pair, scan, order, payment, receipt print)
- TypeScript clean: `npx tsc --noEmit` shows no new errors

## Known caveats

- iOS simulator does not support Bonjour — must test on real device
- Expo Go does not load `react-native-zeroconf` (lazy-required, prints warning, falls back to manual URL only)
- WebSocket reconnect backoff: 1s → 2s → 4s → ... → 30s (max). On flaky networks may take ~minute to recover
