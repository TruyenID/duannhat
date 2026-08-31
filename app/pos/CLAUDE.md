# POS Shell — TempoFast native wrapper for pos-web

Expo/React Native **thin shell** for shop tablets. It does **not** embed or
rebuild `web/pos`. It discovers the LAN workstation and opens a full-screen
WebView at `http://<ws-ip>:<port>/pos`.

Pairing, orders, print, Glory, and P400 stay inside pos-web (same as a browser
opening `/pos`).

## Stack

- **Expo SDK 57** + React Native 0.86 + React 19
- **Routing**: Expo Router (`app/`)
- **WebView**: `react-native-webview`
- **mDNS**: `react-native-zeroconf` (`_ws-app._tcp`) — needs a **dev client /
  custom build** (not Expo Go)
- **Persistence**: `expo-secure-store` (AsyncStorage on web)

## Screens

| Route | Role |
|---|---|
| `/` | Boot: stored URL + `/api/lan/health` → `/pos` or `/setup` |
| `/setup` | mDNS list + manual IP:port |
| `/pos` | Full-screen WebView → `{baseUrl}/pos` |
| `/settings` | 5-tap top-right corner from `/pos`; change WS / reload |

## Dev

```sh
cd app/pos
npm install
cp .env.example .env   # optional EXPO_PUBLIC_WORKSTATION_URL=http://host:port
npx expo run:ios       # or run:android — first time builds a dev client
npm run typecheck
npm run lint
```

Workstation must already serve pos-web (`make posweb` / running ws-app). See
`docs/guide/workstation-serves-pos-web.md`.

## Notes

- Cleartext HTTP is intentional (shop LAN). iOS ATS allows local networking;
  Android `usesCleartextTraffic` via `expo-build-properties`.
- `react-native-zeroconf` is not an Expo-first-class module; if peer warnings
  appear on SDK bumps, keep scanning the same `_ws-app._tcp` service type.
- Do not add device pairing in this shell — pos-web already pairs type `pos`.
