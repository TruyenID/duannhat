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
# Clone and install
git clone <repo-url>
cd tms-app
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

1. Create a TMS device in the admin dashboard
2. Copy the 6-digit pairing code
3. Enter the code on the TMS login screen
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
| i18n | Custom (3 locales) |

### Project Structure

```
tms-app/
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

This app is part of the TempoFast monorepo. See the umbrella `CLAUDE.md` for development workflow, Docker setup, and submodule conventions.

```sh
# TypeScript check
npx tsc --noEmit

# Build check (web)
npx expo export --platform web
```
