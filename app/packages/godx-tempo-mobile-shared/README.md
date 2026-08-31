# @godxjp/mobile-shared

Shared infrastructure for GodX React Native + Expo apps. Pairs with
[`@godxjp/ui-native`](https://github.com/godx-jp/godx-tempo-ui-native) — the
UI library — to give every mobile app the same auth, locale, printer, and
storage primitives without copy-paste.

> **Mobile only.** Web apps consume [`@godxjp/ui`](https://github.com/godx-jp/godx-tempo-ui)
> and have their own `apiFetch` in `admin-web/src/lib/api.ts`. Don't import
> from this package on web — `react-native-star-io10` and `expo-secure-store`
> have native bindings that don't run in a browser bundle.

## Install

```sh
npm install @godxjp/mobile-shared
# or
pnpm add @godxjp/mobile-shared
```

Peer dependencies (declared optional — install whichever your app needs):

| Peer | Why |
|---|---|
| `@react-native-async-storage/async-storage` | Required by `createDeviceTokenStorage` (web fallback) and `createLocaleStorage`. |
| `expo-secure-store` | Required by `createDeviceTokenStorage` for native (Keychain / Keystore). |
| `react-native-star-io10` | Required by `testPrinterConnection` / `printReceiptImage`. Skip if your app doesn't print. |

The package itself depends only on `react-native` and React.

## API

### Typed API client — `createApiFetch`

```ts
import { createApiFetch, ApiError } from '@godxjp/mobile-shared';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { tokenStorage } from './lib/storage';

export const apiFetch = createApiFetch({
  baseUrl: process.env.EXPO_PUBLIC_API_URL ?? 'http://localhost:5400',
  resolvers: {
    getToken: () => tokenStorage.get(),
    getLocale: () => AsyncStorage.getItem('locale').then((v) => v ?? 'ja'),
    onUnauthorized: () => tokenStorage.clear(),
  },
});

// Usage
const me = await apiFetch<{ data: User }>('/api/v1/me');
```

Behaviour:

- Stamps `Authorization: Bearer <token>` and `Accept-Language: <locale>` on
  every request.
- Composes the caller's `signal` with a 15-second timeout (configurable via
  `timeoutMs`).
- Throws `ApiError` on every non-2xx response. Branch on `isAuthError`,
  `isValidationError`, `isServerError` rather than parsing `body` directly —
  the shape is server-defined and may change.
- Calls `onUnauthorized` once per 401 unless the call passed `silent401: true`
  (use for fire-and-forget preference saves).

### Secure device-token storage — `createDeviceTokenStorage`

```ts
import { createDeviceTokenStorage } from '@godxjp/mobile-shared';

export const tokenStorage = createDeviceTokenStorage('tms_device_token');

await tokenStorage.set(token);
const t = await tokenStorage.get();
await tokenStorage.clear();
```

Native: Keychain / Keystore via `expo-secure-store`.
Web: AsyncStorage (Expo's SecureStore web shim is empty and throws on every
call). Both branches are awaited — the same code path works on every platform.

### Star ESC/POS printer — `testPrinterConnection`, `printReceiptImage`

```ts
import { testPrinterConnection, printReceiptImage } from '@godxjp/mobile-shared';

await testPrinterConnection('192.168.10.42', { appName: 'TempoFast TMS' });
await printReceiptImage('192.168.10.42', base64Png);
```

Wraps `react-native-star-io10` with a lazy `require` so importing this file
on web or in Expo Go doesn't crash the bundler — the functions throw with a
clear message only when actually invoked. Apps targeting Expo Go can navigate
around printer screens safely.

### Locale storage — `createLocaleStorage`

```ts
import { createLocaleStorage } from '@godxjp/mobile-shared';

export const localeStorage = createLocaleStorage({
  key: 'tms_locale',
  defaultLocale: 'ja',
});
```

Thin AsyncStorage wrapper with a default-when-empty contract so the API
client's `getLocale` resolver always returns a string.

## Versioning

Pinned via `github:godx-jp/godx-tempo-mobile-shared#main` in consuming apps,
mirroring the `@godxjp/ui` and `@godxjp/ui-native` distribution pattern.
Each `npm install` pulls the latest commit on `main`. Breaking changes
should be coordinated with consuming apps before merging to `main`.
