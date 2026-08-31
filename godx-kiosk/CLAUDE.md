# Kiosk App — TempoFast Self-Service Payment Terminal

Expo/React Native app chạy landscape trên tablet, khách tự scan QR order rồi thanh toán (card / QR wallet / e-money / cash). Pair với backend qua 6-digit code; toàn bộ API đi qua `/api/v1/kiosk/*`.

## Stack

- **Framework**: Expo 54 + React Native 0.81 + React 19
- **Routing**: Expo Router (file-based, `app/` dir)
- **Styling**: NativeWind (Tailwind CSS for RN) + `@godxjp/ui` (from `github:godx-jp/godx-tempo-ui#main`)
- **Data fetching**: TanStack React Query v5
- **Auth**: Device pairing via `expo-secure-store`
- **QR scan**: `expo-camera`
- **Printer**: `react-native-star-io10` (ESC/POS)
- **i18n**: Custom (ja/en/vi), locale stored in AsyncStorage

## Project Structure

```
godx-tempo-kiosk-app/
├── app/                        # Expo Router screens
│   ├── _layout.tsx             # Root layout (providers)
│   ├── index.tsx               # Auth guard → /login or /advertise
│   ├── login.tsx               # Device pairing screen
│   ├── advertise.tsx           # Idle loop
│   ├── scan.tsx                # Camera QR scanner
│   ├── checkout.tsx            # Order review + method picker
│   ├── payment/
│   │   ├── _layout.tsx
│   │   ├── card.tsx
│   │   ├── qr.tsx
│   │   ├── emoney.tsx
│   │   └── cash.tsx
│   ├── success.tsx             # Receipt + print
│   └── settings.tsx
├── src/
│   ├── components/             # React components
│   │   ├── error-boundary.tsx
│   │   └── ui/                 # Reusable primitives
│   ├── hooks/                  # Custom hooks
│   │   └── query-keys.ts       # TanStack Query key factories
│   ├── i18n/                   # Translations (ja.json, en.json, vi.json)
│   ├── lib/                    # Utilities
│   │   ├── api.ts              # API client (fetch + auth + locale header)
│   │   ├── constants.ts        # Platform constants
│   │   └── utils.ts            # cn() utility
│   ├── providers/              # React Context providers
│   │   ├── app-provider.tsx    # Theme + locale
│   │   ├── auth-provider.tsx   # Device auth
│   │   └── query-provider.tsx  # TanStack Query + AppState
│   ├── services/               # API service layer
│   └── types/                  # TypeScript definitions
│       └── models/
└── .env.example                # Environment variables template
```

## Provider Nesting Order

```
SafeAreaProvider → ErrorBoundary → AppProvider → QueryProvider → AuthProvider → Stack
```

ErrorBoundary trước tất cả (catch mọi crash). QueryProvider trước AuthProvider vì auth logout cần `queryClient.clear()`.

## Auth lifecycle

- Token verify on mount qua `/api/v1/kiosk/me`. Lỗi 401/403 lúc boot → clear token, render login screen.
- **Runtime 401**: `apiFetch` tự `clearDeviceToken()` + bắn callback đã đăng ký từ `AuthProvider` (`setUnauthorizedHandler`). AuthProvider set flag `pendingLogout`, một effect riêng theo dõi pathname để **defer logout** khi user đang ở `/payment/*`, `/custom/*`, `/split/*`, `/success` (tránh ngắt giao dịch tiền mặt / terminal đang chạy). Khi user rời khỏi flow, drain effect chạy: `queryClient.clear()` + reset `device` → root layout tự redirect `/login`.
- 403 KHÔNG trigger logout (scope sai chứ token vẫn hợp lệ); `ApiError.isForbidden` tách riêng cho UI tự handle.

## API

- Base URL: `EXPO_PUBLIC_API_URL` (env var)
- Auth: Bearer token from `expo-secure-store`
- Locale: `Accept-Language` header from AsyncStorage
- Timeout: 15s (AbortController)
- Endpoints:
  - `POST /api/v1/devices/pair` — public pairing
  - `GET  /api/v1/kiosk/me`
  - `GET  /api/v1/kiosk/orders?table_id=<uuid>`
  - `POST /api/v1/kiosk/payments` (idempotent via `Idempotency-Key` header, throttle 10/min)
  - `GET  /api/v1/kiosk/payments/{id}/status` (throttle 30/min)
  - `POST /api/v1/kiosk/payments/{id}/confirm`
  - `POST /api/v1/kiosk/payments/{id}/fail`

## Dev

```sh
npm install --legacy-peer-deps   # peer-dep conflict from @testing-library/react-hooks, safe to override
npm run start                    # expo start --dev-client
npm run typecheck                # tsc --noEmit
npm run lint                     # eslint src/ app/
npm run test                     # vitest run (78 tests)
```

## Lint + test infrastructure (Sprint D)

- **ESLint** wired via `eslint.config.js` (flat config wrapping
  `eslint-config-expo`). 0 errors / 93 warnings on the current tree.
  The warnings cover React 19 strict rules (`react-hooks/set-state-in-effect`,
  `react-hooks/refs`, `react-hooks/immutability`) that the existing
  payment-flow effects intentionally trigger — demoted to warn so the
  linter is a productivity gate without forcing a 24-site cleanup pass.
- **Vitest** runs 78 hook + lib tests. The `test` script was missing
  pre-Sprint-D so `npm test` did nothing — fixed now.
- **TypeScript** stays at `tsc --noEmit` (no project references
  needed; single tsconfig). Sprint B noted that the omnify codegen
  layer (`src/types/models/base/*.ts`) accounts for ~60 errors when
  `noUncheckedIndexedAccess` is enabled — kept off for now, tracked.

## Observability (Sprint C)

### Sentry SDK
- `src/lib/sentry.ts::initSentry()` reads `EXPO_PUBLIC_SENTRY_DSN`
  at build time. Silent no-op when unset (dev / tests / unwired
  deploys). Init is called from `app/_layout.tsx` BEFORE the React
  tree mounts so a crash during the first provider's setup is
  captured.
- Privacy posture: `sendDefaultPii: false`, Sentry Replay
  deliberately disabled (would record the cash-amount keypad).
  `tracesSampleRate: 0.05` because the tablet runs 24/7 and full
  sampling burns the Sentry quota.
- `beforeBreadcrumb` AND `beforeSend` scrub `device_token` (both
  quoted + unquoted forms) and `Bearer ...` tokens from breadcrumb
  messages, exception values, and componentStack.

### Error reporting
- `src/lib/error-reporter.ts::reportError(scope, err)` is the
  canonical entrypoint for runtime errors. Forwards to
  `captureException` with the scope tag + a console.error fallback.
  DO NOT use raw `console.error` in payment flows — that defeats
  the rollout.
- `ErrorBoundary.componentDidCatch` routes through
  `reportErrorWithContext("error-boundary", error, { componentStack })`
  AND fires `auditCrash()` so reconciliation knows the kiosk crashed
  mid-payment.

### Cloud audit-log (PCI Req 10.2)
- `src/lib/audit-log.ts` posts to `/api/v1/kiosk/audit-logs`.
  Helpers: `auditPaymentInitiated` / `Submitted` / `Confirmed` /
  `Failed` / `Crash`. ALL fire-and-forget — never blocks the
  payment flow even if the cloud audit endpoint is unreachable.
- Wired call sites:
  - `use-payment.ts::submit()` fires Initiated + Submitted
  - `use-payment.ts::confirm()` fires Confirmed (success) +
    Failed (error path)
  - `use-payment.ts::fail()` fires Failed
  - `error-boundary.tsx::componentDidCatch` fires Crash

See `docs/explanation/observability.md` in the umbrella for the
fleet-wide deployment guide (DSN strategy, CSP wiring, PCI
follow-ups).

## Test mocking pattern (Flow-in-bundle gotcha)

`@sentry/react-native` ships Flow-annotated `.js` that vitest's
rolldown can't parse. Any test that transitively imports
`src/lib/sentry.ts` (which means: anything importing
`error-reporter.ts` or `audit-log.ts` or `error-boundary.tsx`)
MUST add:

```ts
vi.mock('./sentry', () => ({
  captureException: vi.fn(),
  captureMessage: vi.fn(),
  addBreadcrumb: vi.fn(),
}));
```

or — preferably — `vi.mock('../lib/audit-log', () => ({ ... }))` to
mock the higher-level helpers directly. See `src/hooks/use-payment.test.ts`
+ `src/lib/error-reporter.test.ts` for working examples.

## Umbrella integration

App này live trong monorepo `godx-jp/godx-tempo` như submodule `godx-kiosk`. Path trong umbrella: `godx-kiosk/`. Backend routes + migrations ship cùng PR umbrella (không tách độc lập).

PR target branch: `dev`. Never push to `main` (umbrella convention).
