---
title: "Operations — appendices: technical notes, ports/URLs, pitfalls, glossary"
category: guide
tags: [reference, it, troubleshooting, glossary]
summary: "Appendices A-D: technical information for IT, a quick reference of ports, URLs and environment variables, the known pitfalls, and a glossary."
related:
  - guide/van-hanh-cua-hang.md
---

> **Who is this part for?** IT technicians, and anyone who needs to look up a number
> or a term quickly.
> Sections marked 🔧 **FOR IT** are a technician's job.
>
> ← [Operations handbook contents](../van-hanh-cua-hang.md)

## Appendix A — technical information for IT

### A.1 Network layout

**The firewall on the workstation machine:** open **TCP 8080** (inbound) and
**UDP 5353** (mDNS).

**The workstation listens on `0.0.0.0:8080` with NO TLS.** The only protection is:
- The `lanOnly` middleware — it blocks any client outside the private IP ranges
  (returning 403)
- A CORS allowlist: `localhost:5440/5430/5460`, every **HTTPS** host under
  `*.godx.jp`, and the configured tunnel hosts

⚠️ **Never expose port 8080 to the Internet.** The router must not NAT it.

**mDNS:** the workstation advertises the `_ws-app._tcp` service on the `local.`
domain. The TXT record contains `version`, `hostname`, `proxy_url` and — if they are
in config.json — `branch_id`, `name` and `store`.
The mDNS advertisement is **unauthenticated** — anyone on the LAN can see the
workstation's IP and port. Do not put it on a guest VLAN.

**Who actually uses mDNS:** only the **kiosk**. The POS, KDS, handheld and TMS all
use an address baked in at build time.

### A.2 How the workstation picks its LAN address

The algorithm: prefer the first non-loopback IPv4 address **starting with `192` or
`10`**; failing that, take the first non-loopback IPv4; and only then `127.0.0.1`.

⚠️ **The consequences:**
- A `172.16-172.31.x.x` network **is not preferred**
- A machine with VPN, Docker, Parallels or VirtualBox may report **the wrong
  address**

👉 **Always verify** by calling `/api/lan/health` from the POS machine.

### A.3 The workstation's file paths

| Path | Content | Mode |
|---|---|---|
| `~/.ws-app/` | The root directory | 0700 |
| `~/.ws-app/config.json` | Configuration | 0600 |
| `~/.ws-app/ws-app.db` | SQLite — all the data | |
| `~/.ws-app/backups/` | `VACUUM INTO` backups, every 6 hours, keeping 7 | 0755 |

**SQLite configuration:** `journal_mode=WAL`, `busy_timeout=15000`,
`foreign_keys=ON`, `synchronous=NORMAL`, `wal_autocheckpoint=1000`. A pool of 8 open
connections / 4 idle.

The device token lives **in SQLite's `settings` table**, not in `config.json`.

### A.4 Timeouts and intervals

**The workstation:**
| Parameter | Value |
|---|---|
| Push the queue to Cloud | 5 seconds (or triggered immediately) |
| Pull data down (4 streams) | 5 seconds, staggered 0/250/500ms |
| Check the Cloud connection | 10 seconds, backing off to at most 5 minutes when offline |
| HTTP timeout while syncing | 15 seconds |
| Backoff when rate-limited | 2 seconds → 5 minutes |
| Auth token cache | 5-minute TTL, with a stale fallback |
| HTTP server read/write timeouts | Read header 5s · Read 15s · Write 15s · Idle 60s |
| WebSocket authentication | Must be sent within 5 seconds; a bad token closes with code 4401, a timeout with 4408 |
| Pairing rate limit | 5 attempts per minute per IP |

**pos-web:**
| Parameter | Value |
|---|---|
| Workstation call timeout | **3 seconds** |
| Cloud call timeout | **15 seconds** |
| Backoff when the workstation is unreachable | **30 seconds** |
| Workstation health check interval | 30 seconds (with a 3-second timeout) |
| Query retries | 3 attempts: 500ms → 1s → 2s (4xx errors are not retried) |
| Automatic sign-out threshold | **3 consecutive 401 errors** |

**The other apps:**
| App | Timeout | Cloud fallback | Realtime updates |
|---|---|---|---|
| KDS | — | ✅ (30s backoff) | LAN WebSocket + Cloud Echo; otherwise 15s polling |
| Kiosk | LAN 3s / Cloud 15s *(payment confirmation is 15s even over LAN)* | ✅ (30s backoff) | LAN WebSocket |
| Handy | ❌ **No timeout** | ❌ **None** | LAN WebSocket (3s→30s backoff) |
| TMS | 15 seconds | — (Cloud only) | ❌ 15-second polling |
| Customer Web | ❌ None | — (Cloud only) | Payment screen: adaptive polling. Dine-in table session: Echo/Reverb — **nothing arrives on production**, see [Cloud realtime state](../realtime-broadcast-state.md) |

### A.5 Backend operations commands

```sh
# Test the shift-expiry job (a dry run that writes nothing)
docker compose exec app php artisan tills:expire-stale-shifts --dry-run

# Check the scheduler is alive
docker compose exec app php artisan tills:check-scheduler-freshness
```

The tuning variables:
| Variable | Default | Meaning |
|---|---|---|
| `POS_SHIFT_STALE_TIMEOUT_HOURS` | 48 | A shift open longer than this becomes eligible for expiry |
| `POS_SHIFT_STALE_ACTIVITY_WINDOW_HOURS` | 6 | No payment within this many hours counts as inactive |

### A.6 Building and deploying

| App | How to build | Where it is deployed |
|---|---|---|
| Workstation | `make build` / `make build-all` | Installed directly on the machine |
| `web/pos` | `pnpm build` → `dist/` | AWS Amplify (app id `d3nuz12zp9crpd`) or nginx in Docker |
| `web/admin` | `next build` (standalone output) | AWS Amplify (app id `d3cqu96a6b470f`) |
| `web/customer` | `next build` (standalone output) | AWS Amplify (app id `d3bw22hyw76201`) |
| `app/kds` | `pnpm build` → `dist/` | nginx in Docker, port 5460 |
| `app/kiosk` | An EAS build (an Android APK / iOS) | Installed on the tablet. The web export uses port 5480 |
| `app/handy` | An EAS build plus OTA updates | Installed on the PDA |
| `app/tms` | Expo Go (there is no prebuilt binary) | — |

> ⚠️ **The web deploy path is currently BROKEN — nothing ships the three web
> apps.** Amplify `autoBuild` is OFF, so pushing does not build; and the four
> deploy/test workflows sit in `.github/workflows-parked/`, which GitHub does
> **not** run. `.github/workflows/` holds only `backend-tests.yml` and
> `deploy-xserver.yml` (backend, triggered by a `v*` tag). Restoring web deploy
> needs the Amplify apps repointed at this repo plus the two secrets re-entered:
> [`docs/reference/deploy-web-amplify.md`](../../reference/deploy-web-amplify.md).

> ⚠️ **Every `VITE_*` and `EXPO_PUBLIC_*` variable is baked in at BUILD time.**
> Editing the `.env` file after a build **has no effect** — it must be rebuilt.

### A.7 The nginx configuration pos-web and the KDS require

```nginx
try_files $uri $uri/ /index.html;
```
Without this line, pressing F5 on a deep path (for example `/shop/sjk/shift/open`)
returns a 404.

The current configuration **forces HTTPS except** for the hosts `*.local`,
`localhost` and `127.0.0.1`.
⚠️ A store reaching it by **raw IP** through a proxy that sets
`X-Forwarded-Proto: http` is redirected to HTTPS and breaks. Use a `.local` name.

---

## Appendix B — quick reference: ports, URLs, environment variables

### B.1 Ports

| Service | Port | Notes |
|---|---|---|
| Backend (Laravel) | 5400 | |
| admin-web | 5430 | |
| pos-web | 5440 | Fixed; it does not pick another port |
| customer-web | 5450 | |
| godx-kds | 5460 | |
| Reverb (Cloud WebSocket) | 5470 (host) / 8080 (in the container) | |
| godx-kiosk (the web build) | 5480 | 5485 in `compose.local-server.yml` |
| **Workstation LAN** | **8080** | Binds every interface, HTTP with no TLS |
| mDNS | 5353/UDP | `_ws-app._tcp.local.` |
| Network printers | 9100 | A convention, not a requirement |
| MySQL (dev) | 3307 | |

### B.2 LAN domain names (`.local`)

| Name | Points to |
|---|---|
| `tempo.local` | The link index page |
| `api.tempo.local` | The Laravel backend |
| `admin.tempo.local` | admin-web |
| `pos.tempo.local` | pos-web |
| `shop.tempo.local` | customer-web (the guest web) |
| `kds.tempo.local` | godx-kds |
| `kiosk.tempo.local` | godx-kiosk |
| `ws.tempo.local` | The Reverb WebSocket |
| `files.tempo.local` | MinIO (uploaded files) |
| `mail.tempo.local` | Mailpit (viewing test email) |

### B.3 Cloud addresses

| Component | Address |
|---|---|
| The production server | `https://tempo.godx.jp` |
| Admin | `https://main.d3cqu96a6b470f.amplifyapp.com` |
| POS | `https://main.d3nuz12zp9crpd.amplifyapp.com` |
| Guest web | `https://main.d3bw22hyw76201.amplifyapp.com` |
| SSO | `https://id.godx.jp` |

The AWS region: **ap-northeast-1 (Tokyo)**.

### B.4 Environment variables

**The workstation (read at runtime — editable with no rebuild):**

| Variable | Default | Effect |
|---|---|---|
| `WS_APP_CLOUD_URL` | `https://tempo.godx.jp` | The central server URL |
| `WS_APP_SERVER_PORT` | 8080 | The LAN port (1024-65535) |
| `WS_APP_CONFIG_DIR` | `~/.ws-app` | The configuration directory |

**pos-web (baked in at build time — a rebuild is required):**

| Variable | Default | Effect |
|---|---|---|
| `VITE_API_URL` | `http://localhost:5400` | The Cloud API URL |
| `VITE_WORKSTATION_API_URL` | `http://localhost:8080` ⚠️ | The workstation LAN URL. Set it to **`none`** to disable LAN |
| `VITE_POS_API_MODE` | *(empty)* | Pins `auto`/`workstation`/`cloud` |
| `VITE_SHOP_SLUG` | — | The default store |
| `VITE_DEFAULT_LOCALE` | `vi` in .env / `ja` in the code | The language sent to the server |

**godx-kds (baked in at build time):** `VITE_API_URL`, `VITE_WORKSTATION_API_URL`,
`VITE_DEFAULT_LOCALE`
⚠️ The KDS's `.env.example` names it `VITE_CLOUD_URL` — **wrong**; the code reads
`VITE_API_URL`.

**godx-kiosk (baked in at build time):** `EXPO_PUBLIC_API_URL`,
`EXPO_PUBLIC_WORKSTATION_URL` *(which must be left EMPTY)*

**godx-handy (baked in at build time):** `EXPO_PUBLIC_API_URL`, `EXPO_PUBLIC_WS_URL`

**tms-app:** `EXPO_PUBLIC_API_URL`

**admin-web / customer-web:** `NEXT_PUBLIC_API_URL`,
`NEXT_PUBLIC_CUSTOMER_WEB_URL`, `NEXT_PUBLIC_REVERB_*`,
`NEXT_PUBLIC_OIDC_CLIENT_ID`

### B.5 Where device tokens are stored

| App | Storage key | Where |
|---|---|---|
| Workstation | `device_token` | The `settings` table in SQLite |
| pos-web | `pos_device_token`, `pos_device_info` | localStorage |
| godx-kds | `kds_device_token`, `kds_device_info` | localStorage |
| godx-kiosk | `tms_device_token` | expo-secure-store |
| tms-app | `tms_device_token` | expo-secure-store |
| godx-handy | `device_token`, `device_info`, `workstation_url` | expo-secure-store |
| customer-web | `cw_auth_token` | localStorage *(a guest account, not a device)* |

---

## Appendix C — known pitfalls

> This is a list of issues **confirmed in the source code**. Read it before
> deploying, to save time.

### C.1 Mixed content — the `https://` POS cannot reach the `http://` workstation

**The problem:** a POS served over `https://` (the Amplify build, for example) has
every request to `http://192.168.x.x:8080` **blocked** by the browser.

**The consequence:** **no printing and no offline operation**, with the badge stuck
on 🟡 Cloud.

**This is a browser security mechanism, not a software bug.** It cannot be "turned
off".

**What to do:**
- ✅ Serve the POS **over HTTP on the LAN** (`http://pos.tempo.local`). This is the
  deployment style the system was designed for — the nginx configuration
  deliberately **does not force HTTPS** for the hosts `*.local`, `localhost` and
  `127.0.0.1`.
- Or issue a TLS certificate for the workstation and serve it over HTTPS (complex
  and **not supported out of the box** — the design documents explicitly place it
  out of scope).

> 🚨 **This is the single biggest deployment trap in the whole system.**

### C.2 A POS built from compose local-server has no workstation address

**The problem:** `docker/pos-web.prod.Dockerfile` only declares
`ARG VITE_API_URL`. It **does not accept** `VITE_WORKSTATION_API_URL`.

**The consequence:** the resulting POS defaults to `http://localhost:8080` — the
tablet running the browser itself. Every LAN call hangs for 3 seconds and then falls
back to Cloud. **No printing. No offline operation.**

Worse: the "is there a workstation" check returns **`true`** for the value
`http://localhost:8080`, so **the print buttons STILL APPEAR** — the cashier presses
them and gets an error.

**What to do:** add this to the Dockerfile's `builder` stage:
```dockerfile
ARG VITE_WORKSTATION_API_URL=http://localhost:8080
ENV VITE_WORKSTATION_API_URL=${VITE_WORKSTATION_API_URL}
```
and pass the real value through `build.args` in `compose.local-server.yml`.

**To disable LAN entirely:** set it to exactly **`none`** — leaving it blank does
NOT work.

### C.3 The "Store Information" fields in Settings do not save

**The problem:** in Workstation → Settings, the three fields **Name / Store Address
/ Local Server Port** write into SQLite's `settings` table (and the Port field
**writes nowhere at all**), but on reload the page reads back from `config.json` —
and **nothing ever writes to `config.json`**.

**The consequence:** you type it in, reload, and it is gone.

**What to do:** edit `~/.ws-app/config.json` directly and **restart the app**.

**A knock-on effect:** the mDNS advertisement shows the generic name `ws-app` and
carries **no `branch_id`** until `config.json` is edited by hand.

**An important note:** **the store name printed on receipts does NOT come from this
field** — it comes from the branch name pulled down from Cloud. To change the name
on receipts, change it in Admin → HQ → Shops.

### C.4 Most devices have no discovery mechanism

| App | Discovers the workstation? |
|---|---|
| **Kiosk** | ✅ Yes (mDNS) — **and it has a manual entry field in Settings** |
| **POS** | ❌ No — baked in at build time |
| **KDS** | ❌ No — baked in at build time |
| **Handy** | ❌ No — baked in at build time or stored at pairing |
| **TMS** | ❌ It does not use the workstation |

The old POS and KDS documents say "mDNS-discovered" — **that is wrong**; it was an
intention, never an implementation.

The workstation **does** advertise over mDNS, but only the kiosk consumes it.

### C.5 The LAN address can be detected wrongly

See [Appendix A.2](#a2-how-the-workstation-picks-its-lan-address). A machine with
VPN, Docker or Parallels, or a `172.16-31.x.x` network, can report the wrong one.

👉 **Always verify** with `/api/lan/health` from the POS machine.

### C.6 The sync queue can grow without bound

The automatic cleanup **only removes rows that were sent successfully**. A long
outage plus a high order volume makes the queue table grow without limit.

👉 Watch the size of `~/.ws-app/ws-app.db`. Abnormal growth = tell IT.

### C.7 A token revoked mid-shift throws the workstation back to the pairing screen

The app checks the device's status **every 20 seconds**. If the device is
**失効 / revoked** in Admin (or the Cloud data is reinitialized), **the entire
workstation interface jumps back to the pairing screen** and every open screen is
lost.

There is an orange banner explaining it and asking for a new code. **Unsynced data
is still on the machine.**

👉 **Do not revoke a workstation device during opening hours.**

### C.8 Some interface labels are not translated

Whichever language is chosen, the following still show **hard-coded English**:

**Admin → Devices:**
- Column headings: `Branch` · `Type` · `Pairing` · `Last Seen`
- Form labels: `Type` · `Branch` · `Notes`
- Hints: `-- Select --` · `Optional notes...`
- The description line: `"{n} devices"`
- **Every device toast** ("Device created.", "Pairing code regenerated."…)

**Workstation → Settings:** `Store Information` · `Store Address` ·
`Local Server Port` · the `About` card · the `Saved!` button

**Workstation → Devices:** `Connection` · `Address (IP:Port)` ·
`Address (Device Path)`

**Workstation → Orders:** the whole create-order form (`Order Type`,
`Table ID (optional)`, `Guests`, `Menu Items`, `Selected (n)`) and the buttons
`Start Preparing` / `Mark Ready` / `Mark Served` / `Record Payment` /
`Print Kitchen Ticket` / `Print Hold` / `Print Receipt`

**Workstation → Menu:** `Load Demo Menu` · `Price` · `Category` · `Printer Group`

**Workstation → Reports:** `Total Orders` · `Paid Orders` · `Revenue` ·
`Avg Order Value` · `Popular Items`

**Workstation → Settings → the KDS card:** ⚠️ its content is **hard-coded in
Vietnamese** whichever language is selected

**Kiosk / TMS → the sign-in screen:** the card subtitle always reads
`"TempoFast TMS"`, even on the kiosk (a copy-paste bug)

### C.9 The workstation Orders screen uses the old status vocabulary

The buttons `Start Preparing` / `Mark Ready` / `Mark Served` / `Record Payment` are
based on the previous status set. The real status set today is `pending, open,
dining, checkout, paying, closed, voided`.

**The consequence:** orders arriving from Cloud or the kiosk show **a raw,
uncoloured status** and **only the three print buttons**, with no status buttons.

👉 **Do the day-to-day work on the POS. The workstation's Orders screen is only for
REPRINTING.**

### C.10 Known bugs in the guest web

| Id | Severity | Problem |
|---|---|---|
| **BUG-CUST-001** | 🔴 P0 | The guest menu service compares the status with the wrong case (`active` vs `Active`) → **every guest menu request returns 404**, a 100% failure rate. Guests see *"The menu could not be loaded"*. |
| **BUG-CUST-002** | 🔴 P1 | When a branch **has no timezone**, the system uses UTC → a menu schedule in Tokyo or Vietnam time never matches during real opening hours → **time-dependent 404s**. |

👉 Both belong to IT. If guests complain that the menu will not open →
**tell IT and quote these ids**.

The old third entry — issue #386, "the payment-success screen never appears
because the realtime connection dies silently" — is **fixed and gone**. The
screen no longer waits on a websocket: plan-050 replaced that subscription with
an adaptive poll (`web/customer/hooks/use-order-settlement.ts`), which is why it
works even though Cloud broadcasting is off on production
([Cloud realtime state](../realtime-broadcast-state.md)).

### C.11 The guest web's "Call staff" button is disabled

The feature is complete (a yellow round button, a call sent to the TMS, and a
flashing red table card there) but it is currently **switched off by a flag in the
source**.

👉 To enable it, tell IT.

### C.12 Out-of-date documents — DO NOT TRUST THEM

| File | What is wrong |
|---|---|
| `workstation/README.md` | An unedited Wails template describing `main.go`/`app.go`/`wails.json` — which **do not exist** |
| `workstation/docs/DEVICES.md` | Says to enable Kanji mode `FS &` — the source **deliberately does not**, because it hangs the Star mC-Print3 |
| `workstation/docs/DATABASE.md` | Already carries a "STALE" warning at the top |
| `workstation/build/config.yml` | Still the Wails template (`My Company` / `My Product`). ⚠️ Running the build-assets update command **overwrites** the correct values in `Info.plist` / `info.json` |
| `web/pos/CLAUDE.md` | Says auth is SSO plus cookies — in reality there is **only device pairing**, with the token in `localStorage` |
| `web/pos/.env.example` | Says Cloud does not serve `/pos/*` for a device token and advises pinning `workstation` — **out of date**, and it destroys the Cloud fallback |
| `app/kds/.env.example` | Names the variable `VITE_CLOUD_URL` — the code reads **`VITE_API_URL`** |
| `app/handy/README.md` | Still the `create-expo-app` template |
| `app/tms/docs/websocket-migration-plan.md` | Describes WebSockets — **not implemented**; it really polls every 15 seconds |

### C.13 Limitations worth knowing in advance

| Limitation | Detail |
|---|---|
| **The POS stores no offline data in the browser** | No service worker, no IndexedDB, no queue. All the offline capability lives in the **workstation**. Reloading the page with both connections down loses everything. |
| **Only the KDS has a real offline cache** | The KDS stores an order snapshot in browser storage |
| **The handheld has no Cloud fallback and no timeout** | A workstation that is off means a frozen handheld |
| **TMS talks straight to Cloud** | No Internet means no TMS |
| **There is no refund screen for ordinary transactions** | Refunds are only possible through Split bill |
| **There is no screen for creating a till** | It is created automatically, one per branch with the code `MAIN` |
| **There is no chain summary screen in Admin** | It is only printed from the POS |
| **The POS cannot use the camera** | The pairing code must be typed |
| **Split bill: 29 money-related components have no automated tests** | This is a money surface — test it manually and thoroughly |
| **Per-field server validation errors are dropped** | The POS only shows a generic message, without naming the offending field |
| **There are two migration files numbered 040** | 🔧 IT should check this before deploying |

### C.14 Unbreakable business rules

```
✅ A settled shift CANNOT be undone.
   → There is no "reopen". Open a new shift and treat it as a new day.

✅ A force close, an expiry or a manual settle all BREAK the chain of shifts.
   → That shift is not counted in the chain summary slip.

✅ The cash discrepancy is computed from the shift id stamped on each PAYMENT,
   not from the orders.
   → Which is why unpaid orders carrying over to the next shift do not affect the money.

✅ The tax settings are snapshotted onto the order when it is created.
   → Changing the tax does not alter existing orders.

✅ An unticked gap payment is NOT lost — it reappears at the next shift.

✅ The currency, tax mode and rounding rule are locked for the whole CHAIN of shifts,
   not just one shift.
```

---

## Appendix D — glossary

| Term | Japanese | Meaning |
|---|---|---|
| **Pairing** | ペアリング | Connecting a device to the system with a 6-character code |
| **Pairing code** | ペアリングコード | A 6-character code (upper case plus digits), living **15 minutes**, usable **once** |
| **Device token** | — | The 64-character key a device receives after pairing. **It never expires** — it can only be revoked |
| **Workstation** | ワークステーション | The in-store computer acting as the bridge, driving the printers and holding the offline data |
| **LAN** | — | The store's local network |
| **mDNS** | — | How devices find each other on the local network (only the kiosk uses it) |
| **Till** | レジ | The cash drawer. **One per branch, coded `MAIN`, created automatically** |
| **Shift** | シフト | One cashier's working session, with an opening and a closing cash count |
| **Chain** | — | A run of shifts linked by handovers, ended by one final close |
| **Handover** | 引き継ぎ | Settling the current shift while **keeping the chain open** |
| **Final close** | 精算 | Settling the whole chain and printing the summary report |
| **Opening float** | 釣銭準備 | The cash already in the drawer when the shift opens |
| **Expected cash** | 予定金額 | The opening float + cash revenue + cash in − cash out |
| **Counted cash** | 実査金額 | What you actually counted in the drawer |
| **Variance** | 過不足 | Counted cash − expected cash. **Negative = short, positive = over** |
| **Blind count** | — | Recounting from scratch without looking at the previous shift's figure — so each cashier owns their own discrepancy |
| **Gap payment** | — | A payment taken between two shifts, attributed to neither |
| **Cash in / out** | 入金 / 出金 | Money put into or taken out of the drawer mid-shift |
| **Z report** | Zレポート | The shift summary as a PDF, downloaded from Admin |
| **Expired** | 失効 | A shift the system closed automatically after too long with no activity |
| **Force-abandon** | 強制終了 | A manager closing a stuck shift by hand |
| **Manual settle** | 手動精算 | A manager settling the books for an expired shift |
| **Abandon** | シフト取消 | Discarding a shift opened by mistake, **only before any payment** |
| **Denomination** | 金種 | The notes and coins used to count the drawer |
| **Tender type** | — | A payment brand (PayPay, Visa…) used in the shift-close reconciliation |
| **Tax type** | 税区分 | The brand's tax group; each one carries its own rate |
| **Tax-inclusive** | 税込 | The listed price already contains the tax |
| **Tax-exclusive** | 税別 | Tax is added at the till |
| **Fire to kitchen** | — | Sending items to the kitchen — printing the ticket and/or showing them on the KDS |
| **Hold ticket** | ホールド | The summary slip for the runner |
| **Kitchen ticket** | 厨房伝票 | The slip that tells the kitchen what to cook |
| **Bump** | — | Tapping to push an item to the next step on the KDS |
| **Split bill** | 割り勘 | Dividing a bill among several payers |
| **Void** | 取消 | Voiding an item or an order (keeping the audit trail) |
| **On-account** | — | The guest underpays and the rest is carried to their next visit |
| **KDS** | キッチンディスプレイ | The kitchen display |
| **Kiosk** | キオスク | The guest self-checkout machine |
| **TMS** | テーブル管理端末 | The tablet showing the table plan |
| **Handy** | ハンディ端末 | The handheld staff use to take orders at the table |
| **HQ** | 本部 | The brand level (managing several stores) |
| **Branch** | 支店 | One specific store |
| **Slug** | — | The store's or brand's short code in the URL, e.g. `sjk` |
| **Zone** | エリア | A group of tables (First floor, Terrace…) |
| **PWA** | — | A web app installable on the home screen like a real app (the KDS uses this) |
| **Idempotency key** | — | The deduplication key — it guarantees pressing pay twice does not charge twice |
