# TempoFast Kiosk

> Self-service payment terminal — customer-facing tablet app for scanning orders and processing payments.

Built with [Expo](https://expo.dev), [React Native](https://reactnative.dev), and [TanStack Query](https://tanstack.com/query).

## Table of Contents

- [Background](#background)
- [Install](#install)
- [Usage](#usage)
- [Architecture](#architecture)
- [API](#api)
- [Internationalization](#internationalization)
- [Contributing](#contributing)

## Background

The Kiosk is a dedicated landscape-orientation terminal that customers use at the table or counter to:

1. **Scan** the order QR code printed on the table receipt
2. **Review** the order summary (items, totals, discounts)
3. **Select** a payment method (card, QR wallet, e-money, cash)
4. **Complete** payment and receive confirmation

The app pairs with the backend via a 6-digit pairing code, authenticates all API calls with a device token stored in secure storage, and talks exclusively to the `/api/v1/kiosk/*` endpoints (see `backend/routes/api/kiosk.php` in the umbrella repo).

## Install

```sh
# Clone and install
git clone https://github.com/godx-jp/godx-tempo-kiosk-app.git
cd godx-tempo-kiosk-app
npm install --legacy-peer-deps

# Configure environment
cp .env.example .env
# Edit .env with your API URL
```

### Prerequisites

- [Node.js](https://nodejs.org) >= 18
- [Expo CLI](https://docs.expo.dev/get-started/installation/) (`npx expo`)
- iOS/Android tablet (landscape) or emulator
- Backend API running (see umbrella repo `CLAUDE.md` for Docker setup)

## Usage

```sh
# Start Expo dev server
npx expo start

# Platform-specific
npx expo start --ios       # iOS Simulator
npx expo start --android   # Android Emulator
npx expo start --web       # Web browser (debug only)
```

### Device Pairing

1. Create a Kiosk device (`type = kiosk`) in the admin dashboard
2. Copy the 6-digit pairing code
3. Enter the code on the Kiosk login screen
4. Device is paired and authenticated

## Architecture

### Tech Stack

| Layer | Technology |
|-------|-----------|
| Framework | Expo 54 + React Native 0.81 |
| Language | TypeScript (strict mode) |
| Routing | Expo Router (file-based) |
| Styling | NativeWind (Tailwind CSS) |
| Data Fetching | TanStack React Query v5 |
| Auth Storage | expo-secure-store |
| Preferences | @react-native-async-storage |
| QR scanning | expo-camera |
| Printer | react-native-star-io10 (ESC/POS) |
| i18n | Custom (3 locales) |

### Screens (`app/`)

```
app/
├── _layout.tsx           # Root layout with provider stack
├── index.tsx             # Auth guard (redirect)
├── login.tsx             # Device pairing
├── advertise.tsx         # Idle loop attract screen
├── scan.tsx              # QR scan to load order
├── checkout.tsx          # Order review + method selector
├── payment/
│   ├── _layout.tsx
│   ├── card.tsx          # Card terminal flow
│   ├── qr.tsx            # QR wallet flow
│   ├── emoney.tsx        # IC / e-money flow
│   └── cash.tsx          # Cash flow
├── success.tsx           # Thank-you screen + receipt print
└── settings.tsx          # Device settings
```

### Provider Stack

```
SafeAreaProvider
  └── ErrorBoundary          # Catches unhandled JS errors
      └── AppProvider        # Theme, locale, i18n
          └── QueryProvider  # TanStack Query + AppState refetch
              └── AuthProvider  # Device auth (needs QueryClient for logout)
                  └── Stack  # Expo Router navigation
```

## API

All API calls go through `src/lib/api.ts`:

- **Authentication**: Bearer token from `expo-secure-store`
- **Locale**: `Accept-Language` header from AsyncStorage
- **Timeout**: 15-second abort via `AbortController`
- **Error classification**: `ApiError.isAuthError` (401), `.isForbidden` (403), `.isValidationError` (422), `.isServerError` (5xx)
- **401 auto-logout**: on any 401, `apiFetch` clears the stored token and notifies `AuthProvider`, which drops auth state and routes back to `/login`. Logout is *deferred* while the user is on a payment-flow screen (`/payment/*`, `/custom/*`, `/split/*`, `/success`) to avoid interrupting an in-progress transaction

### Endpoints (`/api/v1/kiosk/*`)

| Method | Path | Description |
|--------|------|-------------|
| POST   | `/api/v1/devices/pair`                      | Pair device with 6-digit code |
| GET    | `/api/v1/kiosk/me`                          | Current device + branch info |
| GET    | `/api/v1/kiosk/orders?table_id=<uuid>`      | Active order for a table |
| POST   | `/api/v1/kiosk/payments`                    | Submit payment (idempotent) |
| GET    | `/api/v1/kiosk/payments/{id}/status`        | Poll payment status |
| POST   | `/api/v1/kiosk/payments/{id}/confirm`       | Confirm manual method |
| POST   | `/api/v1/kiosk/payments/{id}/fail`          | Mark payment failed |

## Internationalization

Three locales supported: Japanese (default), English, Vietnamese.

Translation files: `src/i18n/{ja,en,vi}.json`

Locale is:
- Stored in AsyncStorage (`kiosk_locale`)
- Sent as `Accept-Language` header on every API request
- Switchable from the settings screen

## Contributing

This app lives in the TempoFast monorepo as the `godx-kiosk` submodule. See the umbrella `CLAUDE.md` for development workflow, Docker setup, and submodule conventions.

```sh
# TypeScript check
npx tsc --noEmit

# Build check (web)
npx expo export --platform web
```
