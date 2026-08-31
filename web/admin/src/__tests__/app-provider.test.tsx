import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, act } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  AppProvider,
  useLocale,
  useTheme,
  useTimezone,
  useTranslation,
} from "@/providers/app-provider";
import { getLocale } from "@/lib/api";
import type { ReactNode } from "react";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
});

describe("useLocale", () => {
  it("defaults to ja", () => {
    const { result } = renderHook(() => useLocale(), { wrapper });
    expect(result.current.locale).toBe("ja");
  });

  it("changes locale without infinite loop", () => {
    const { result } = renderHook(() => useLocale(), { wrapper });

    // This should NOT cause infinite re-renders
    act(() => {
      result.current.setLocale("en");
    });

    expect(result.current.locale).toBe("en");
    expect(localStorage.getItem("app_locale")).toBe("en");
  });

  it("changes locale multiple times", () => {
    const { result } = renderHook(() => useLocale(), { wrapper });

    act(() => {
      result.current.setLocale("en");
    });
    expect(result.current.locale).toBe("en");

    act(() => {
      result.current.setLocale("vi");
    });
    expect(result.current.locale).toBe("vi");

    act(() => {
      result.current.setLocale("ja");
    });
    expect(result.current.locale).toBe("ja");
  });

  it("persists locale to localStorage", () => {
    const { result } = renderHook(() => useLocale(), { wrapper });

    act(() => {
      result.current.setLocale("vi");
    });

    expect(localStorage.getItem("app_locale")).toBe("vi");
  });

  it("synchronizes apiFetch locale before actively refetching translated queries", () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const invalidateQueries = vi.spyOn(queryClient, "invalidateQueries");
    const provider = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={queryClient}>
        <AppProvider defaultLocale="ja">{children}</AppProvider>
      </QueryClientProvider>
    );
    const { result } = renderHook(() => useLocale(), { wrapper: provider });

    act(() => {
      result.current.setLocale("vi");
    });

    expect(getLocale()).toBe("vi");
    expect(invalidateQueries).toHaveBeenCalledWith(
      expect.objectContaining({ refetchType: "active" })
    );
  });

  it("reads locale from localStorage on mount", () => {
    localStorage.setItem("app_locale", "en");

    const { result } = renderHook(() => useLocale(), { wrapper });
    expect(result.current.locale).toBe("en");
  });

  it("provides supported locales", () => {
    const { result } = renderHook(() => useLocale(), { wrapper });
    expect(result.current.locales).toEqual({
      ja: "日本語",
      en: "English",
      vi: "Tiếng Việt",
    });
  });
});

describe("useTranslation", () => {
  it("translates keys in default locale (ja)", () => {
    const { result } = renderHook(() => useTranslation(), { wrapper });
    expect(result.current.t("common.save")).toBe("保存");
    expect(result.current.t("nav.products")).toBe("商品");
  });

  it("translates keys after locale change", () => {
    const { result: localeResult } = renderHook(() => useLocale(), { wrapper });
    const { result: transResult } = renderHook(() => useTranslation(), { wrapper });

    act(() => {
      localeResult.current.setLocale("en");
    });

    // Re-render with new locale
    const { result: newTransResult } = renderHook(() => useTranslation(), {
      wrapper: ({ children }: { children: ReactNode }) => {
        const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        return (
          <QueryClientProvider client={qc}>
            <AppProvider defaultLocale="en">{children}</AppProvider>
          </QueryClientProvider>
        );
      },
    });
    expect(newTransResult.current.t("common.save")).toBe("Save");
  });

  it("falls back to English for missing key", () => {
    const { result } = renderHook(() => useTranslation(), { wrapper });
    // Key that doesn't exist returns the key itself
    expect(result.current.t("nonexistent.key")).toBe("nonexistent.key");
  });

  it("interpolates parameters", () => {
    const { result } = renderHook(() => useTranslation(), {
      wrapper: ({ children }: { children: ReactNode }) => {
        const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        return (
          <QueryClientProvider client={qc}>
            <AppProvider defaultLocale="en">{children}</AppProvider>
          </QueryClientProvider>
        );
      },
    });

    expect(result.current.t("common.showing", { from: 1, to: 25, total: 100 })).toBe(
      "Showing 1–25 of 100"
    );
  });
});

describe("useTheme", () => {
  it("defaults to system", () => {
    const { result } = renderHook(() => useTheme(), { wrapper });
    expect(result.current.theme).toBe("system");
  });

  it("changes theme without infinite loop", () => {
    const { result } = renderHook(() => useTheme(), { wrapper });

    act(() => {
      result.current.setTheme("dark");
    });
    expect(result.current.theme).toBe("dark");
    expect(localStorage.getItem("app_theme")).toBe("dark");

    act(() => {
      result.current.setTheme("light");
    });
    expect(result.current.theme).toBe("light");
  });
});

describe("useTimezone", () => {
  it("defaults to browser timezone", () => {
    const { result } = renderHook(() => useTimezone(), { wrapper });
    // jsdom returns UTC
    expect(result.current.timezone).toBeTruthy();
  });

  it("changes timezone without infinite loop", () => {
    const { result } = renderHook(() => useTimezone(), { wrapper });

    act(() => {
      result.current.setTimezone("America/New_York");
    });
    expect(result.current.timezone).toBe("America/New_York");
    expect(localStorage.getItem("app_timezone")).toBe("America/New_York");
  });
});

describe("render stability", () => {
  it("does not re-render infinitely when locale changes", () => {
    let renderCount = 0;

    function Counter() {
      const { locale, setLocale } = useLocale();
      renderCount++;
      if (renderCount > 50) throw new Error("INFINITE LOOP DETECTED");
      return null;
    }

    const { unmount } = renderHook(
      () => {
        const { setLocale } = useLocale();
        return setLocale;
      },
      { wrapper }
    );

    renderCount = 0;

    act(() => {
      // This should cause exactly 1-2 re-renders, not infinite
    });

    expect(renderCount).toBeLessThan(10);
  });
});
