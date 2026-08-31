# TempoFast TMS

> Table Management Terminal — real-time table status dashboard for restaurant staff.

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

TMS (Table Management System) is a dedicated terminal app for restaurant floor staff. It displays a real-time grid of all tables grouped by zone, with color-coded status indicators:

- **White** — Free table
- **Green** — Occupied (guests seated, not yet paid)
- **Red + bell icon** — Guest requesting staff attention
- **Light blue** — Recently paid (within configurable timeout)

The app pairs with the backend via a 6-digit pairing code and authenticates all API calls with a device token stored in secure storage.

## Install

```sh
# Clone the monorepo and install this app's deps
git clone https://github.com/godx-jp/godx-tempo.git
cd godx-tempo/app/tms
npm install

# Configure environment
cp .env.example .env
# Edit .env with your API URL
```

### Prerequisites

- [Node.js](https://nodejs.org) >= 18
- [Expo CLI](https://docs.expo.dev/get-started/installation/) (`npx expo`)
- iOS Simulator (macOS) or Android Emulator, or physical device with [Expo Go](https://expo.dev/go)
- Backend API running (see umbrella repo `CLAUDE.md` for Docker setup)

## Usage

```sh
# Start Expo dev server
npx expo start

# Platform-specific
npx expo start --ios       # iOS Simulator
npx expo start --android   # Android Emulator
npx expo start --web       # Web browser
```

### Device Pairing

This is the canonical description of the TMS login flow; the umbrella
[`docs/device-management.md`](../../docs/device-management.md) covers the
platform-wide device model and points here.

1. Create a TMS device in the admin dashboard
2. Copy the 6-digit pairing code
3. Enter the code on the TMS login screen → `POST /api/v1/devices/pair`
4. The `device_token` is stored in `expo-secure-store`; every subsequent call
   sends `Authorization: Bearer <device_token>`
5. On 401/403 the stored token is cleared and the app returns to the login
   screen — those two statuses are expected, so they are not retried and not
   logged (`src/providers/auth-provider.tsx`, `src/providers/query-provider.tsx`)

A pairing code that resolves to a non-`tms` device is rejected client-side
(`DeviceTypeMismatchError`) and the token is never stored (#935).

## Architecture

### Tech Stack

The single source of truth for this table is `package.json` — the umbrella docs
reference it rather than restating it.

| Layer | Technology |
|-------|-----------|
| Framework | Expo 54 + React Native 0.81 |
| Language | TypeScript (strict mode) |
| Routing | Expo Router (file-based) |
| Styling | NativeWind v4 (Tailwind CSS) |
| Components | local primitives under `src/components/ui/` |
| Data Fetching | TanStack React Query v5 |
| State | React Context (`AppProvider`) |
| Auth Storage | expo-secure-store (device token) |
| Preferences | @react-native-async-storage (locale, theme) |
| i18n | Custom (ja / en / vi), same pattern as `web/admin` |

### Project Structure

```
app/tms/
├── app/                    # Expo Router screens (file-based routing)
│   ├── _layout.tsx         # Root layout with provider stack
│   ├── index.tsx           # Auth guard (redirect)
│   ├── login.tsx           # Device pairing
│   └── home.tsx            # Table status dashboard
├── src/
│   ├── components/         # React components
│   │   ├── error-boundary.tsx  # Global error catch
│   │   └── ui/             # Design system primitives
│   ├── hooks/              # Custom React hooks
│   ├── i18n/               # Translation files
│   ├── lib/                # Utilities & API client
│   ├── providers/          # React Context providers
│   └── types/              # TypeScript type definitions
├── assets/                 # Icons, splash screens
├── .env.example            # Environment template
├── CLAUDE.md               # AI assistant instructions
└── README.md               # This file
```

### Provider Stack

Providers are nested in a specific order for correct dependency resolution:

```
SafeAreaProvider
  └── ErrorBoundary          # Catches unhandled JS errors
      └── AppProvider        # Theme, locale, i18n
          └── QueryProvider  # TanStack Query + AppState refetch
              └── AuthProvider  # Device auth (needs QueryClient for logout)
                  └── Stack  # Expo Router navigation
```

### Data Flow

```
API ← apiFetch (Bearer token + Accept-Language)
  ← TanStack Query (cache, polling, retry)
    ← useZones() hook (merges zones + tables)
      ← HomeScreen (renders table grid)
```

## API

All API calls go through `src/lib/api.ts` which handles:

- **Authentication**: Bearer token from `expo-secure-store`
- **Locale**: `Accept-Language` header from AsyncStorage
- **Timeout**: 15-second abort via `AbortController`
- **Error classification**: `ApiError.isAuthError`, `.isValidationError`, `.isServerError`

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/v1/devices/pair` | Pair device with 6-digit code |
| GET | `/api/v1/tms/me` | Current device info |
| GET | `/api/v1/tms/zones` | Zones for device's branch |
| GET | `/api/v1/tms/tables` | Tables with current status |

## Internationalization

Three locales supported: Japanese (default), English, Vietnamese.

Translation files: `src/i18n/{ja,en,vi}.json`

Locale is:
- Stored in AsyncStorage (`tms_locale`)
- Sent as `Accept-Language` header on every API request
- Switchable from the login screen

## Contributing

This app is part of the TempoFast monorepo — edit and commit it straight into the
umbrella repo. See the umbrella `CLAUDE.md` for development workflow and Docker setup.

```sh
# TypeScript check
npx tsc --noEmit

# Build check (web)
npx expo export --platform web
```
