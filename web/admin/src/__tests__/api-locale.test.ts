import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { setApiLocale } from "@/lib/api-locale";

describe("apiFetch locale synchronization", () => {
  beforeEach(() => {
    localStorage.clear();
    document.cookie = "app_locale=; max-age=0; path=/";
    setApiLocale("ja");
    vi.mocked(globalThis.fetch).mockClear();
  });

  it("stamps the current in-memory locale over stale persisted values", async () => {
    document.cookie = "app_locale=ja; path=/";
    localStorage.setItem("app_locale", "en");
    setApiLocale("vi");

    await apiFetch("/api/v1/hq/betoya/products");

    const options = vi.mocked(globalThis.fetch).mock.calls[0]?.[1];
    expect(new Headers(options?.headers).get("Accept-Language")).toBe("vi");
  });

  it("still allows an intentional caller header override", async () => {
    setApiLocale("vi");

    await apiFetch("/api/v1/hq/betoya/products", {
      headers: { "Accept-Language": "en" },
    });

    const options = vi.mocked(globalThis.fetch).mock.calls[0]?.[1];
    expect(new Headers(options?.headers).get("Accept-Language")).toBe("en");
  });
});
