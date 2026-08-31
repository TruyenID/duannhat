/**
 * Shared render helpers for the vitest (jsdom) suite — #1184.
 *
 * Every admin screen sits under two providers it cannot render without:
 *
 *   • `QueryClientProvider` — TanStack Query. `AppProvider` itself calls
 *     `useQueryClient()` (locale switch invalidates translated queries), so the
 *     query client is not optional even for a component that fetches nothing.
 *   • `AppProvider` — supplies `useTranslation()` / `useLocale()` / `useTheme()`
 *     plus the `@godxjp/ui` `UIProvider` that `<Input translatable />` and
 *     friends read their locale config from.
 *
 * Tests default to the **English** dictionary so string assertions can be
 * written as readable regexes instead of Japanese literals. Pass
 * `{ locale: "ja" }` when the assertion is specifically about Japanese copy.
 */

import type { ReactElement, ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  render,
  renderHook,
  type RenderOptions,
  type RenderHookOptions,
} from "@testing-library/react";
import { AppProvider } from "@/providers/app-provider";
import type { LocaleCode } from "@/i18n";

/**
 * A query client tuned for tests: no retries (a rejected mutation must surface
 * its error on the first tick, not 3 × backoff later) and no GC churn between
 * assertions.
 */
export function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: Infinity, staleTime: 0 },
      mutations: { retry: false },
    },
  });
}

export interface ProviderOptions {
  /** UI-string locale. Defaults to `en` for readable assertions. */
  locale?: LocaleCode;
  /** Reuse a client across renders (cache-assertion tests). */
  queryClient?: QueryClient;
}

export interface TestProvidersProps extends ProviderOptions {
  children: ReactNode;
}

export function TestProviders({ children, locale = "en", queryClient }: TestProvidersProps) {
  const client = queryClient ?? createTestQueryClient();
  return (
    <QueryClientProvider client={client}>
      <AppProvider defaultLocale={locale} defaultTimezone="Asia/Tokyo" defaultTheme="light">
        {children}
      </AppProvider>
    </QueryClientProvider>
  );
}

/** `render()` with QueryClientProvider + AppProvider already mounted. */
export function renderWithProviders(
  ui: ReactElement,
  { locale, queryClient, ...options }: ProviderOptions & Omit<RenderOptions, "wrapper"> = {}
) {
  return render(ui, {
    wrapper: ({ children }) => (
      <TestProviders locale={locale} queryClient={queryClient}>
        {children}
      </TestProviders>
    ),
    ...options,
  });
}

/** `renderHook()` with the same provider stack — for hooks under `src/hooks/`. */
export function renderHookWithProviders<Result, Props>(
  hook: (initialProps: Props) => Result,
  {
    locale,
    queryClient,
    ...options
  }: ProviderOptions & Omit<RenderHookOptions<Props>, "wrapper"> = {}
) {
  return renderHook(hook, {
    wrapper: ({ children }) => (
      <TestProviders locale={locale} queryClient={queryClient}>
        {children}
      </TestProviders>
    ),
    ...options,
  });
}

// Re-export the query/event surface so a test file needs one import.
// `render` / `renderHook` are deliberately NOT re-exported — use the
// `*WithProviders` variants above so nothing renders provider-less by accident.
export { act, cleanup, fireEvent, screen, waitFor, within } from "@testing-library/react";
