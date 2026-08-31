# [01] Production Hardening — Phase 1

> **Epic:** [#284](https://github.com/godx-jp/godx-tempo/issues/284)
> **Scope:** 4 issues (#279, #280, #281, #282) — Critical + High priority fixes
> **Estimated:** 2-3 dev days (1 dev) or 1.5 days (2 dev parallel)
> **Status:** Draft — chưa start

**Goal:** Đưa pos-web từ MVP/beta → production-ready cho restaurant single-terminal deployment. Phase 1 chỉ tập trung 4 issues block production release; refactor + workstation integration là Phase 2+.

**Non-goals:**
- Refactor `page.tsx` 1289 lines (#283) — defer, blocked by tests
- WebSocket real-time — single-terminal không cần
- Workstation LAN-first routing — separate epic
- Multi-terminal concurrency — Phase 3
- Offline order queue — chờ workstation integration

---

## Context

Review 2026-05-23 phát hiện 4 vấn đề structural:

1. **Không có ErrorBoundary** → render error = blank screen
2. **401 redirect synchronous hang Promise** (`new Promise(() => {})`) → caller leak forever
3. **Mutations `retry: false`** → flaky network = hard fail UX kém
4. **Test coverage = 1 file** trong toàn repo financial app

Pattern fix đã có sẵn từ kiosk app (cùng team, recent work). Không cần invent mới.

---

## Architecture

Không thay đổi architecture. Chỉ harden các điểm yếu:

```
┌──────────────────────────────────────────────────┐
│  Root Layout                                      │
│  ┌────────────────────────────────────────────┐  │
│  │  ErrorBoundary (NEW — Issue #279)          │  │
│  │  ┌──────────────────────────────────────┐  │  │
│  │  │  QueryProvider                       │  │  │
│  │  │   - retry config (CHANGE — #281)     │  │  │
│  │  │  ┌────────────────────────────────┐  │  │  │
│  │  │  │  AuthProvider                  │  │  │  │
│  │  │  │   - register 401 handler       │  │  │  │
│  │  │  │   - navigate via router (#280) │  │  │  │
│  │  │  │  ┌──────────────────────────┐  │  │  │  │
│  │  │  │  │  Routes / POS Page       │  │  │  │  │
│  │  │  │  └──────────────────────────┘  │  │  │  │
│  │  │  └────────────────────────────────┘  │  │  │
│  │  └──────────────────────────────────────┘  │  │
│  └────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────┘

apiFetch:
  - 401: clear token + notify handler + throw ApiError (NO redirect)
  - Otherwise: throw ApiError(status, body)

QueryProvider:
  - queries.retry: 3, skip 4xx
  - mutations.retry: 3, exponential backoff, skip 4xx
```

---

## Tasks

### Task 1 — ErrorBoundary (Issue #279)

**Estimated:** ~3h

**Files:**
- Create: `src/components/error-boundary.tsx`
- Create: `src/components/error-fallback.tsx`
- Modify: `src/main.tsx` (or root layout)

**Steps:**

#### 1.1 Component

```typescript
// src/components/error-boundary.tsx
import { Component, type ReactNode } from "react";

interface Props {
  children: ReactNode;
  fallback: (error: Error, reset: () => void) => ReactNode;
  onError?: (error: Error) => void;
}

interface State {
  error: Error | null;
}

export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error): void {
    console.error("[ErrorBoundary]", error);
    this.props.onError?.(error);
  }

  reset = (): void => this.setState({ error: null });

  render() {
    if (this.state.error) {
      return this.props.fallback(this.state.error, this.reset);
    }
    return this.props.children;
  }
}
```

#### 1.2 Fallback UI

```typescript
// src/components/error-fallback.tsx
import { Button } from "@godxjp/ui";

interface Props {
  error: Error;
  reset: () => void;
}

export function ErrorFallback({ error, reset }: Props) {
  return (
    <div className="min-h-screen flex items-center justify-center p-6 bg-background">
      <div className="max-w-md text-center space-y-4">
        <h1 className="text-2xl font-bold">Đã xảy ra lỗi</h1>
        <p className="text-muted-foreground">
          Trang gặp sự cố. Nhấn "Tải lại" để thử lại.
        </p>
        {import.meta.env.DEV && (
          <pre className="text-left text-xs bg-muted p-3 rounded overflow-auto">
            {error.message}
          </pre>
        )}
        <div className="flex gap-2 justify-center">
          <Button onClick={reset}>Thử lại</Button>
          <Button variant="outline" onClick={() => window.location.reload()}>
            Tải lại trang
          </Button>
        </div>
      </div>
    </div>
  );
}
```

#### 1.3 Wire root

```tsx
// src/main.tsx — wrap app
<ErrorBoundary fallback={(err, reset) => <ErrorFallback error={err} reset={reset} />}>
  <App />
</ErrorBoundary>
```

#### 1.4 Inner boundaries cho critical dialogs (defer hoặc inline trong Task)

Bonus: wrap split-bill dialog + payment dialog với inner ErrorBoundary để cô lập crash, không sập root.

#### 1.5 Verify

- Trigger artificial error trong dev (throw trong component) → fallback hiển thị
- Click "Thử lại" → reset state, UI rebuild
- Click "Tải lại trang" → window.location.reload()

#### 1.6 Commit

```bash
git add web/pos/src/components/error-boundary.tsx \
        web/pos/src/components/error-fallback.tsx \
        web/pos/src/main.tsx
git commit -m "feat(pos-web): add ErrorBoundary root + fallback UI (#279)"
```

---

### Task 2 — Fix 401 redirect (Issue #280)

**Estimated:** ~2h

**Files:**
- Modify: `src/lib/api.ts`
- Modify: `src/providers/auth-provider.tsx`
- Create: `src/lib/api.test.ts` (basic — full coverage trong Task 4)

**Steps:**

#### 2.1 Refactor `api.ts`

```typescript
// Add module-level handler registry
let onUnauthorized: (() => void) | null = null;
export function setUnauthorizedHandler(cb: (() => void) | null): void {
  onUnauthorized = cb;
}

// In apiFetch, replace lines 50-56:
if (!response.ok) {
  const body = await response.json().catch(() => ({}));
  if (response.status === 401) {
    // Clear token sync — subsequent calls don't fire stale
    clearToken();
    onUnauthorized?.();
  }
  throw new ApiError(response.status, body);
}
```

Verify `clearToken()` function exists in `src/lib/auth.ts` — nếu chưa thì add.

#### 2.2 Register handler trong AuthProvider

```typescript
// src/providers/auth-provider.tsx
import { useNavigate } from "react-router";
import { useQueryClient } from "@tanstack/react-query";
import { setUnauthorizedHandler } from "@/lib/api";

useEffect(() => {
  setUnauthorizedHandler(() => {
    queryClient.clear();
    setUser(null);
    navigate("/login", { replace: true });
  });
  return () => setUnauthorizedHandler(null);
}, [navigate, queryClient]);
```

#### 2.3 Tests

```typescript
// src/lib/api.test.ts (basic — expand trong Task 4)
import { describe, it, expect, vi } from "vitest";
import { apiFetch, setUnauthorizedHandler, ApiError } from "./api";

describe("apiFetch 401 handling", () => {
  it("clears token + calls handler + throws ApiError(401)", async () => {
    const handler = vi.fn();
    setUnauthorizedHandler(handler);

    // Mock fetch returning 401
    global.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 401,
      json: async () => ({ message: "Unauthorized" }),
    });

    await expect(apiFetch("/test")).rejects.toThrow(ApiError);
    expect(handler).toHaveBeenCalled();
  });
});
```

#### 2.4 Verify

- Manual test: invalidate token (edit localStorage) → trigger API call → AuthProvider redirect to /login via router (no hard navigate visible)
- Open DevTools Network tab → no infinite hang requests
- React Query DevTools → mutation state không stuck `isPending`

#### 2.5 Commit

```bash
git add web/pos/src/lib/api.ts \
        web/pos/src/providers/auth-provider.tsx \
        web/pos/src/lib/api.test.ts
git commit -m "fix(pos-web): tách 401 handler ra callback registry, route navigate thay hard redirect (#280)"
```

---

### Task 3 — Mutation retry với backoff (Issue #281)

**Estimated:** ~2h

**Files:**
- Modify: `src/providers/query-provider.tsx`
- Create: `src/providers/query-provider.test.tsx`

**Steps:**

#### 3.1 Update retry config

```typescript
// src/providers/query-provider.tsx
import { ApiError } from "@/lib/api";

const shouldRetry = (failureCount: number, error: unknown): boolean => {
  // Skip 4xx — authoritative response, retry won't change result
  if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
    return false;
  }
  return failureCount < 3;
};

const retryDelay = (attemptIndex: number): number =>
  Math.min(500 * Math.pow(2, attemptIndex), 4000);

queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30 * 1000,
      refetchOnWindowFocus: false,
      retry: shouldRetry,
      retryDelay,
    },
    mutations: {
      retry: shouldRetry,
      retryDelay,
    },
  },
});
```

#### 3.2 Test

```typescript
// src/providers/query-provider.test.tsx
import { describe, it, expect } from "vitest";
import { ApiError } from "@/lib/api";
// import shouldRetry — export it for testability

describe("retry policy", () => {
  it("retries on 5xx up to 3 times", () => {
    const error = new ApiError(500, {});
    expect(shouldRetry(0, error)).toBe(true);
    expect(shouldRetry(2, error)).toBe(true);
    expect(shouldRetry(3, error)).toBe(false);
  });

  it("does NOT retry on 4xx", () => {
    expect(shouldRetry(0, new ApiError(422, {}))).toBe(false);
    expect(shouldRetry(0, new ApiError(401, {}))).toBe(false);
  });

  it("retries on network errors", () => {
    expect(shouldRetry(0, new TypeError("network"))).toBe(true);
  });
});
```

Refactor: extract `shouldRetry` + `retryDelay` to named exports so tests can import.

#### 3.3 Verify

- Manual: tạm tắt backend → tạo order mutation → DevTools Network thấy 4 attempts với khoảng cách 500/1000/2000ms
- Restart backend → retry tiếp theo succeeds
- Send invalid payload (422) → fail ngay, không retry

#### 3.4 Commit

```bash
git add web/pos/src/providers/query-provider.tsx \
        web/pos/src/providers/query-provider.test.tsx
git commit -m "feat(pos-web): mutation/query retry với exponential backoff, skip 4xx (#281)"
```

---

### Task 4 — Test infra + coverage Phase 1 (Issue #282)

**Estimated:** ~2 dev days

**Files:**
- Create: `src/__tests__/integration/order-payment.test.tsx`
- Create: `src/lib/api.test.ts` (expand from Task 2)
- Create: `src/services/order-service.test.ts`
- Create: `src/services/order-payment-service.test.ts`
- Modify: `package.json` (add deps), `vitest.config.ts`

**Steps:**

#### 4.1 Install deps

```bash
cd pos-web
npm install --save-dev @testing-library/react @testing-library/jest-dom jsdom msw
```

#### 4.2 Update Vitest config

```typescript
// web/pos/vitest.config.ts
export default defineConfig({
  resolve: {
    alias: { "@": path.resolve(dirname, "./src") },
  },
  test: {
    environment: "jsdom",
    include: ["src/**/*.test.{ts,tsx}"],
    setupFiles: ["./vitest.setup.ts"],
    globals: true,
  },
});
```

```typescript
// web/pos/vitest.setup.ts
import "@testing-library/jest-dom/vitest";
```

#### 4.3 MSW setup

```typescript
// src/__tests__/msw/server.ts
import { setupServer } from "msw/node";
import { http, HttpResponse } from "msw";

export const server = setupServer();

// In vitest.setup.ts:
import { server } from "./src/__tests__/msw/server";
beforeAll(() => server.listen({ onUnhandledRequest: "error" }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());
```

#### 4.4 Unit tests cho apiFetch

Cases (8 tests):
- [ ] 200 success → returns body
- [ ] 204 no content → returns null
- [ ] 400 → throws `ApiError(400)`
- [ ] 422 validation → throws `ApiError(422)` với body errors
- [ ] 500 → throws `ApiError(500)`
- [ ] Network timeout → throws (handled bởi caller)
- [ ] 401 → clears token + calls handler + throws `ApiError(401)`
- [ ] AbortSignal → fetch aborted

#### 4.5 Unit tests cho services

`orderService.test.ts` — verify URL, method, body, headers cho:
- [ ] `create(shopSlug, payload)` POST `/shops/{slug}/orders`
- [ ] `addItems(shopSlug, orderId, items)` POST `/orders/{id}/items`
- [ ] `voidItem(shopSlug, orderId, itemId, reason)` DELETE/PATCH
- [ ] `checkout(shopSlug, orderId)` POST `/orders/{id}/checkout`

`orderPaymentService.test.ts`:
- [ ] `create(...)` with `Idempotency-Key` header
- [ ] `confirm(paymentId)`
- [ ] `fail(paymentId, reason)`

#### 4.6 Integration tests

```typescript
// src/__tests__/integration/order-payment.test.tsx
describe("order → payment happy path", () => {
  it("creates order → adds items → checkout → cash payment → receipt", async () => {
    // 1. Mock all endpoints via MSW
    // 2. Render <PosPage /> with QueryClientProvider
    // 3. Simulate user actions:
    //    - Click "New order"
    //    - Click 3 product tiles
    //    - Click Checkout
    //    - Select Cash, enter tendered
    //    - Submit payment
    // 4. Assert: receipt dialog shown with correct totals
  });
});

describe("payment retry with idempotency", () => {
  it("retries 5xx with same idempotency key", async () => {
    // Mock POST /payments to fail 5xx first attempt, succeed second
    // Verify second attempt sent same Idempotency-Key
    // Verify final state: payment succeeded, no duplicate
  });
});
```

#### 4.7 Coverage gate

Add to `package.json`:

```json
{
  "scripts": {
    "test": "vitest run",
    "test:coverage": "vitest run --coverage"
  }
}
```

CI:
```yaml
- run: npm test
- run: npm run test:coverage
```

#### 4.8 Verify

- `npm test` xanh
- Coverage:
  - `src/lib/api.ts` ≥ 80%
  - `src/services/order-*.ts` ≥ 60%
  - `src/providers/auth-provider.tsx` ≥ 60%

#### 4.9 Commit

```bash
git add web/pos/{vitest.config.ts,vitest.setup.ts,package.json,package-lock.json} \
        web/pos/src/__tests__/ \
        web/pos/src/lib/api.test.ts \
        web/pos/src/services/*.test.ts
git commit -m "test(pos-web): add msw + integration tests cho order/payment flow (#282)"
```

---

## Definition of Done (Phase 1)

- [ ] 4 issues (#279, #280, #281, #282) closed
- [ ] `npm test` xanh trong CI
- [ ] Coverage ≥ 60% cho `lib/` + `services/` + `providers/auth-provider`
- [ ] Manual smoke test pass cho 3 flows:
  - Happy path: order → items → payment → receipt
  - Network blip: mutation retry succeeds
  - Auth expiry: 401 → graceful logout via router
- [ ] No regressions trong existing POS flows
- [ ] Code review pass

---

## Risks & Mitigations

| Risk | Mitigation |
|------|-----------|
| Test setup phức tạp hơn estimated | Start với 3 critical integration tests, expand unit tests sau |
| msw conflict với existing fetch mocking | Verify không có vi.mock("global fetch") trong codebase trước khi setup |
| Idempotency key behavior backend khác expected | Check backend Pest tests `OrderPaymentControllerTest` để verify dedup window |
| Retry với mutation idempotent? | Order create chưa có idempotency key — Phase 1 retry vẫn safe vì backend dedup on `payment_id`, không phải order |

---

## Out of scope (Phase 2+)

- #283 Refactor `page.tsx` 1289 lines
- Workstation LAN-first routing (separate epic)
- Multi-terminal concurrency + optimistic locking
- WebSocket real-time
- Bundle size optimization
- E2E Playwright tests

---

## Status

- **Created:** 2026-05-23
- **Shipped:** 2026-05-23
- **Status:** COMPLETE ✅ — all 4 tasks landed, issues #279-#282 closed
- **Owner:** Claude Code
- **Reviewer:** @phamduyanh1910

### Final coverage

| File | Target | Actual |
|------|--------|--------|
| `src/lib/api.ts` | ≥80% | 87% stmts / 100% lines |
| `src/services/order-service.ts` | ≥60% | 83% stmts / 100% lines |
| `src/services/order-payment-service.ts` | ≥60% | 100% |
| `src/providers/auth-provider.tsx` | ≥60% | 100% |

### Test counts

- Total: **72 tests pass** across 7 files
- New tests added: 51 (api 8 + order-service 18 + order-payment-service 5 + query-retry 12 + auth-provider 6 + integration 2)
- Pre-existing: 21 (split-by-items)
