import { beforeEach, describe, expect, it, vi } from "vitest";
import { apiFetch } from "@/lib/api";
import { resolveHqBrandSlug } from "./hq-entry-service";

vi.mock("@/lib/api", () => ({ apiFetch: vi.fn() }));

const mockedApiFetch = vi.mocked(apiFetch);
const brandPage = (...slugs: string[]) =>
  ({ data: slugs.map((slug) => ({ slug })) }) as never;

describe("resolveHqBrandSlug", () => {
  beforeEach(() => mockedApiFetch.mockReset());

  it("returns the last brand when it is still accessible", async () => {
    mockedApiFetch.mockResolvedValueOnce(brandPage("betoya"));

    await expect(resolveHqBrandSlug("betoya")).resolves.toBe("betoya");
    expect(mockedApiFetch).toHaveBeenCalledOnce();
  });

  it("falls back to the first accessible brand when the preference is stale", async () => {
    mockedApiFetch
      .mockResolvedValueOnce(brandPage())
      .mockResolvedValueOnce(brandPage("another-brand"));

    await expect(resolveHqBrandSlug("removed-brand")).resolves.toBe("another-brand");
  });

  it("returns null when the user has no accessible brands", async () => {
    mockedApiFetch.mockResolvedValueOnce(brandPage());

    await expect(resolveHqBrandSlug(null)).resolves.toBeNull();
  });
});
