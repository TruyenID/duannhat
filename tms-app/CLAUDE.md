# TMS App — TempoFast Table Management Terminal

Expo/React Native app hiển thị danh sách khu vực và bàn của quán. Chỉ read-only dashboard, không có CRUD forms.

## Stack

- **Framework**: Expo 54 + React Native 0.81 + React 19
- **Routing**: Expo Router (file-based, `app/` dir)
- **Styling**: NativeWind (Tailwind CSS for RN)
- **Data fetching**: TanStack React Query v5
- **Auth**: Device pairing via `expo-secure-store`
- **i18n**: Custom (ja/en/vi), locale stored in AsyncStorage

## Project Structure

```
tms-app/
├── app/                    # Expo Router screens
│   ├── _layout.tsx         # Root layout (providers)
│   ├── index.tsx           # Auth guard → /login or /home
│   ├── login.tsx           # Device pairing screen
│   └── home.tsx            # Table status dashboard
├── src/
│   ├── components/         # React components
│   │   ├── error-boundary.tsx
│   │   └── ui/             # Reusable primitives (Button, Card, etc.)
│   ├── hooks/              # Custom hooks
│   │   ├── query-keys.ts   # TanStack Query key factories
│   │   └── use-zones.ts    # Zone + table data hook
│   ├── i18n/               # Translations (ja.json, en.json, vi.json)
│   ├── lib/                # Utilities
│   │   ├── api.ts          # API client (fetch + auth + locale header)
│   │   ├── constants.ts    # Platform constants
│   │   └── utils.ts        # cn() utility
│   ├── providers/          # React Context providers
│   │   ├── app-provider.tsx    # Theme + locale
│   │   ├── auth-provider.tsx   # Device auth
│   │   └── query-provider.tsx  # TanStack Query + AppState
│   └── types/              # TypeScript definitions
│       └── tms.ts          # Zone, Table, TableStatus, etc.
└── .env.example            # Environment variables template
```

## Provider Nesting Order

```
SafeAreaProvider → ErrorBoundary → AppProvider → QueryProvider → AuthProvider → Stack
```

ErrorBoundary trước tất cả (catch mọi crash). QueryProvider trước AuthProvider vì auth logout cần `queryClient.clear()`.

## API

- Base URL: `EXPO_PUBLIC_API_URL` (env var)
- Auth: Bearer token from `expo-secure-store`
- Locale: `Accept-Language` header from AsyncStorage
- Timeout: 15s (AbortController)
- Endpoints: `/api/v1/tms/me`, `/api/v1/tms/zones`, `/api/v1/tms/tables`, `/api/v1/devices/pair`

## Dev

```sh
npm install
npx expo start           # Expo dev server
npx expo start --web     # Web mode
```

Cần backend running (default `http://localhost:5400`). Xem umbrella `CLAUDE.md` cho docker setup.

## Table Display States

Bàn hiển thị theo visual state (không phải backend status):

| Display State | Background | Condition |
|---------------|------------|-----------|
| free | White | Default |
| occupied | Green | `status === "occupied"` |
| call_staff | Red + bell | `call_requested_at != null` |
| recently_paid | Light blue | `paid_at` within 1 minute |

Priority: call_staff > recently_paid > occupied > free.

## Rules — MUST follow

Các rules dưới đây đã được chốt và **bắt buộc** tuân theo. Không được thay đổi approach khác nếu không có explicit approval.

### Architecture Rules

1. **Provider order cố định**: `SafeAreaProvider → ErrorBoundary → AppProvider → QueryProvider → AuthProvider → Stack`. KHÔNG đổi thứ tự. AuthProvider phải nằm trong QueryProvider vì logout gọi `queryClient.clear()`.

2. **Data fetching chỉ dùng TanStack React Query**: KHÔNG dùng `useState` + `useEffect` + `setInterval` cho API calls. Mọi API fetch phải đi qua `useQuery` / `useMutation`. Polling dùng `refetchInterval`, foreground refetch dùng `focusManager` (đã setup global trong `query-provider.tsx`).

3. **Query keys phải khai báo trong `hooks/query-keys.ts`**: KHÔNG hardcode query key string trong hooks. Mọi key phải dùng factory function từ file này.

4. **File-based routing chỉ trong `app/`**: Screen components nằm trong `app/`. Business logic, hooks, utils nằm trong `src/`. KHÔNG đặt business logic trong `app/`.

### API Rules

5. **Mọi API call phải qua `apiFetch()`**: KHÔNG gọi `fetch()` trực tiếp (trừ `pairDevice()` vì cần gửi trước khi có token). `apiFetch` tự inject: Bearer token, `Accept-Language`, timeout.

6. **`Accept-Language` header bắt buộc**: Mọi request phải gửi locale từ AsyncStorage. Backend dùng header này để trả response đúng ngôn ngữ. Đã implement trong `apiFetch()` — KHÔNG bypass.

7. **Timeout 15s**: Mọi request có AbortController timeout. KHÔNG tắt timeout. Nếu cần timeout khác, truyền `{ timeout: ms }` vào `apiFetch()`.

8. **Error classification**: Dùng `ApiError.isAuthError`, `.isValidationError`, `.isServerError` để phân loại. KHÔNG check `status === 401` trực tiếp — dùng property.

### Type Rules

9. **TypeScript strict mode**: `tsconfig.json` có `"strict": true`. KHÔNG tắt. KHÔNG dùng `any`. KHÔNG dùng `as unknown as` double cast — nếu type không khớp, fix type hoặc validate data.

10. **Types nằm trong `src/types/`**: KHÔNG define interface inline trong component. Types chia sẻ phải export từ `src/types/`.

### Styling Rules

11. **NativeWind (Tailwind) only**: KHÔNG dùng `StyleSheet.create()` hoặc inline `style={{}}` object. Dùng `className` với Tailwind classes. Exception duy nhất: `ErrorBoundary` (vì nó render ngoài NativeWind context).

12. **Color tokens từ `tailwind.config.js`**: KHÔNG hardcode hex colors trong components. Dùng semantic tokens: `bg-primary`, `text-muted-foreground`, `bg-table-available`, etc.

### i18n Rules

13. **Mọi user-facing text phải qua `t()`**: KHÔNG hardcode text tiếng Nhật/Anh/Việt trong components. Thêm key vào cả 3 file: `ja.json`, `en.json`, `vi.json`.

14. **Default locale là `ja`**: Khi thêm translation key mới, **viết `ja` trước**, sau đó `en` và `vi`.

### Security Rules

15. **Token chỉ lưu trong SecureStore**: KHÔNG lưu device token vào AsyncStorage, localStorage, hoặc React state persist. Chỉ `expo-secure-store`.

16. **`.env` KHÔNG commit**: File `.env` đã trong `.gitignore`. Chỉ commit `.env.example` (template không có giá trị thật).

### Performance Rules

17. **`useMemo` cho derived data**: Computed values từ API response (filter, flatMap, reduce) phải wrap trong `useMemo`. KHÔNG compute inline trong render.

18. **KHÔNG tạo closure trong `map()` render**: Dùng `useCallback` cho event handlers hoặc extract thành component riêng.

### Testing Rules

19. **Mọi hook mới phải có unit test**: Test file đặt cùng folder hoặc trong `src/__tests__/`. Dùng vitest.

20. **TypeScript check trước commit**: Chạy `npx tsc --noEmit` trước mọi commit. KHÔNG commit code có TS error.
