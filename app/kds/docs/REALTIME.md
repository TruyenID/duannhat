# Realtime

godx-kds receives realtime kitchen events via one of two backends — workstation LAN (default) or cloud Reverb (fallback) — picked at runtime by `resolveBaseUrl().via`. Both backends expose the same listener API (`RealtimeBackend` contract) so consumers don't care which is active. This document is a technical reference for engineers maintaining KDS realtime architecture.

## Architecture

```
┌─────────────────────────────────────────────┐
│  Cloud (Laravel + Reverb)                   │
│  • Reverb private channel broadcasting      │
│  • /devices/reverb-config fetch             │
│  • /devices/broadcasting/auth endpoint      │
└──────────────┬──────────────────────────────┘
               │ Cloud fallback (30s timeout)
    ┌──────────┴──────────┐
    │ resolveBaseUrl()    │
    │ picks via mode      │
    └──────────┬──────────┘
               │ LAN primary (default)
    ┌──────────▼──────────┐
┌───┴──────────────────────┴────┐
│ Workstation :8080 WebSocket   │
│ • /ws first-message auth      │
│ • branch-scoped broadcasting  │
│ • reconnect with backoff      │
└───┬──────────────────────┬────┘
    │ HTTP + WS (mDNS)     │ Echo+Pusher setup
    ▼                      ▼
┌──────────────────────────────────┐
│ godx-kds (KDS tablets)           │
│ Both backends resolve to same    │
│ event vocabulary                 │
└──────────────────────────────────┘
```

The dispatcher (`createRealtimeDispatcher`) picks backend based on `resolveBaseUrl().via`:
- `"workstation"` → `LanWsClient` (WebSocket to workstation `/ws`)
- `"cloud"` → `CloudEchoClient` (Reverb private-branch channel via Echo+Pusher.js)

## RealtimeBackend Contract

Both clients implement this interface:

```typescript
export interface RealtimeBackend {
  /** Subscribe to events. Returns unsubscribe function. */
  on(listener: Listener): () => void;
  
  /** Open the connection. Idempotent. */
  connect(): void | Promise<void>;
  
  /** Close permanently. Idempotent. */
  close(): void;
}

export interface RealtimeEvent {
  type: string;
  payload: unknown;
  timestamp?: string;
}

export type Listener = (event: RealtimeEvent) => void;
```

## LAN WebSocket (`lan-ws.ts`)

**WebSocket protocol** (matches workstation Task 2.4 + 2.3):

1. **Open connection** to workstation `/ws`
2. **Send first message within 5s** with auth payload:
   ```json
   {
     "type": "auth",
     "payload": { "token": "<device_token>" }
   }
   ```
3. **Server responds** with:
   - `{"type":"auth_ok"}` → client authenticated, WS ready for events
   - `{"type":"auth_fail"}` → server rejected token, closes 4401, no auto-reconnect
   - **5s timeout with no message** → closes 4408

4. **After auth_ok**: Receive branch-scoped events (`order_created`, `order_updated`, `order_paid`, `order_item.status_changed`)

**Reconnect strategy:**
- Exponential backoff: 1s, 2s, 4s, ... 30s (max)
- Close code 4401 (bad token) prevents auto-reconnect — token is revoked
- Other closes trigger reconnect
- Backoff resets to 1s on successful auth

**Key properties:**
- `isReady` — true only when WS open AND auth_ok received
- `listeners` — Set of callback functions
- `authedOk` — tracks auth state separate from WS state

## Cloud Reverb (`cloud-echo.ts`)

**Setup:**

1. Fetch `getReverbConfig()` (cloud `/api/v1/devices/reverb-config`)
2. Ensure Pusher.js is on `window` so Laravel Echo can locate it
3. Create Echo client with broadcaster config:
   ```typescript
   new Echo({
     broadcaster: "pusher",
     key: cfg.app_key,
     cluster: cfg.cluster,
     wsHost: cfg.host,
     wsPort: cfg.port,
     wssPort: cfg.port,
     forceTLS: cfg.scheme === "https",
     enabledTransports: ["ws", "wss"],
     authEndpoint: `${CLOUD_URL}/api/v1/devices/broadcasting/auth`,
     auth: { headers: { Authorization: `Bearer ${token}` } }
   });
   ```

**Subscription:**
- Channel: `private-branch.{branchId}.kds-events`
- Listens for: `.order_item.status_changed` event
- Auth happens via POST to `/devices/broadcasting/auth` (cloud Task 0.3)

**Lifecycle:**
- `connect()` is async (fetches config, sets up Echo)
- Only subscribes to `order_item.status_changed` in Phase 5 (other events added in Phase 6+)
- `close()` calls `echo.disconnect()`

## Dispatcher (`dispatcher.ts`)

```typescript
export function createRealtimeDispatcher(args: CreateRealtimeArgs): RealtimeBackend {
  if (args.mode === "workstation") {
    return new LanWsClient(args.token);
  }
  return new CloudEchoClient(args.token, args.branchId);
}
```

**Decision criteria**: `resolveBaseUrl().via` is a runtime choice set by:
- Default: `"workstation"` (LAN mode) if workstation reachable
- Fallback: `"cloud"` if workstation unreachable after 30s
- Runtime toggle: user can manually switch modes in settings (Phase 6)

Both clients accept the same event listeners and emit the same event types — **the dispatcher is transparent to RealtimeProvider**.

## RealtimeProvider (`RealtimeProvider.tsx`)

**Lifecycle:**

```typescript
// Trigger: state=paired (AuthProvider) + device info available
// Effect: create dispatcher, attach listeners, call connect()

useEffect(() => {
  if (state !== "paired" || !device) return;
  
  const mode: RealtimeMode = isUsingWorkstation() ? "workstation" : "cloud";
  const client = createRealtimeDispatcher({ mode, token, branchId: device.branch_id });
  
  const off = client.on((event) => {
    // Event handling (see below)
  });
  
  void client.connect();
  
  return () => {
    off();  // unsubscribe
    client.close();
  };
}, [state, device, qc, playChime]);
```

**Dependencies**: `state`, `device`, `qc` (QueryClient), `playChime` (audio hook)

**Event handling**:

| Event Type | Action |
|---|---|
| `order_created` | Play audio chime; invalidate orders query |
| `order_updated` | Invalidate orders query |
| `order_paid` | Invalidate orders query |
| `order_item.status_changed` | Invalidate orders query (unless dedupped) |

Invalidating the `['kds', 'orders']` query key triggers `useOrders` to refetch, ensuring KDS UI reflects latest server state.

## Event Vocabulary

Events match workstation Hub events (Phase 4) + cloud Reverb events (Phase 5+):

```typescript
// LAN (workstation WS Hub) — all emitted by handleLocalKdsBumpItem + pull-DOWN
{
  type: "order_created",
  payload: { order_id, branch_id, created_at, ... }
}

{
  type: "order_updated",
  payload: { order_id, updated_at, ... }
}

{
  type: "order_paid",
  payload: { order_id, paid_at, ... }
}

{
  type: "order_item.status_changed",
  payload: {
    order_id,
    item_id,
    previous_status,
    status,
    served_at,
    voided_at,
    idempotency_key,    // ← critical for dedup
    occurred_at,
    source: "local" | "pull_down" | "revert"
  }
}

// Cloud (Reverb) — OrderItemStatusChanged event
{
  type: "order_item.status_changed",
  payload: {
    order_id,
    item_id,
    previous_status,
    status,
    served_at,
    voided_at,
    idempotency_key,    // ← passed from mutation request
    occurred_at
  }
}
```

## Self-echo Deduplication

**Problem:** When KDS bumps an item via a POST bump action (mark-preparing / mark-ready / mark-served / revert / bump-all), the mutation returns immediately. But the same change also arrives via WebSocket (either from workstation local broadcast or cloud Reverb). Without dedup, the UI would see the change twice and potentially show stale state or cause a flicker.

**Solution:** 30-second TTL Set of recent `idempotency_key` values:

```typescript
// RealtimeProvider
const recentBumpsRef = useRef<Map<string, number>>(new Map());

const recordBumpKey = useCallback((key: string) => {
  recentBumpsRef.current.set(key, Date.now());
  // Lazy cleanup: drop entries older than 30s
  const cutoff = Date.now() - DEDUP_TTL_MS;
  for (const [k, ts] of recentBumpsRef.current.entries()) {
    if (ts < cutoff) recentBumpsRef.current.delete(k);
  }
}, []);
```

**Usage in useBump:**

```typescript
// useBump hook — mutationFn
const idempotencyKey = crypto.randomUUID();
realtime.recordBumpKey(idempotencyKey);  // Record BEFORE the request
return bumpItem({ ...args, idempotencyKey });
```

**Usage in RealtimeProvider:**

```typescript
client.on((event) => {
  const payload = event.payload as { idempotency_key?: string } | null;
  const key = payload?.idempotency_key;
  if (key && recentBumpsRef.current.has(key)) {
    return;  // Skip — own echo, already optimistically updated
  }
  // Process event normally
});
```

**Key invariant:** The idempotency key is recorded **before** the request is sent, so any WS echo arriving strictly after (RTT > 0) will be in the dedup Map when the event arrives.

## Audio Chime

**Hook: `use-audio-chime.ts`**

```typescript
export function useAudioChime() {
  // Audio element preloaded from /sounds/new-order.mp3
  // Browser autoplay rules: gesture required before first play()
  
  const unlock = () => {
    // Call from click handler (e.g., "Test sound" button on pairing screen)
    // Invoke play() + pause() immediately to satisfy gesture requirement
    // Subsequent play() calls don't need gesture
  };
  
  const play = () => {
    // Check localStorage[STORAGE_KEY] for user preference ("0" = disabled)
    // If enabled: play audio, catch failures silently
    // Visual signal (ticket appearing in grid) is primary notification
  };
}
```

**Integration in RealtimeProvider:**

```typescript
const { play: playChime } = useAudioChime();

client.on((event) => {
  if (event.type === "order_created") {
    playChime();  // New order → audio feedback
  }
});
```

**Gesture unlock on pairing screen:**
- Pairing page has "Test sound" button
- Click handler calls `useAudioChime().unlock()`
- User can verify audio works before kitchen deployment
- Subsequent `play()` calls from realtime events don't fail (gesture requirement satisfied)

## Caveats

### Browser WebSocket Limitations
- **Authorization header not allowed on WS upgrade** (browser security) — workstation uses first-message auth handshake instead of `Authorization` header on initial upgrade
- **5-second timeout** (arbitrary, but sufficient for LAN latency) — clients waiting longer may see spurious closes (4408)

### Wake Lock Support Matrix
- **iOS Safari 16.4+** — Screen Wake Lock API supported; acquires lock on dashboard mount
- **Chrome 84+** — Supported
- **Older Safari** — `request()` call silently fails; best-effort; no banner needed
- **Implementation**: `WakeLockProvider` re-acquires on visibility change to handle tab backgrounding

### Cloud-only Endpoint Dependency
- `/devices/reverb-config` is **cloud-only** (not proxied through workstation) because:
  - Config includes Reverb cluster endpoint + port (workstation doesn't know or forward this)
  - Workstation in LAN mode has no need for Reverb config; endpoint only fetched when explicitly switching to cloud fallback
- If cloud is unreachable when fetching config, `CloudEchoClient.connect()` throws; `RealtimeProvider` silently catches and `qc.invalidateQueries` covers the data refresh via HTTP polling

### Echo Client Bundle Requirement
- Phase 6 PWA must include **both** laravel-echo + pusher-js in the final bundle
- Currently both are in `package.json` dependencies
- Vite will tree-shake unused code if Reverb is never used in LAN-only deployments
- No dynamic import strategy in place; Phase 7+ optimization opportunity

### Idempotency Key Lifecycle
- **Generated client-side** (UUID v4) in `useBump` mutationFn
- **Recorded in dedup Map** before request sent
- **Forwarded in HTTP header** (`Idempotency-Key`) to workstation/cloud
- **Echoed in WS event payload** for dedup matching
- **Workstation caches** idempotency key for 24h (SQLite `idempotency_keys` table)
- **Cloud caches** idempotency key for 24h (Redis `Cache::put`)

### Close Code 4408 (WS Auth Timeout)
- Workstation protocol: no auth message in 5s → close 4408
- Client should treat 4408 same as other transient close codes (reconnect with backoff)
- Matches IANA reserved code space; not conflicting with standard 4000-4999 range for apps

## See Also

- **Plan-027 DESIGN.md §3** — Full WS contract + HTTP bump-action flow
- **Plan-027 DESIGN.md §4.7** — Echo client config verbatim
- **workstation-app CLAUDE.md** — Hub.BroadcastEvent + first-message auth server-side (Tasks 2.3-2.4)
- **backend CLAUDE.md** — OrderItemStatusChanged event dispatch + Reverb channel auth (Phase 1 Tasks 1.3-1.4)
- **godx-kds docs/AUTH.md** — Device pairing + token lifecycle
- **godx-kds docs/HARDWARE_UX.md** — Wake Lock + audio chime user experience
- **admin-web src/hooks/notifications/use-notification-realtime.ts** — Echo client pattern reference
