import { beforeEach, describe, expect, it, vi } from "vitest";
import { menuSectionService } from "@/services/menu-section-service";

/**
 * #1187 — the featured carousel is driven by MenuSection.is_featured, a flag
 * the shop controls, not by scanning the section's display name for a handful
 * of hard-coded words and star/fire glyphs.
 *
 * The HQ menu-items screen persists the toggle on the SAME PUT that saves the
 * section's name, so these tests pin the wire contract: the flag must reach the
 * API, and `false` must be sent as `false` rather than dropped — an omitted key
 * leaves `is_featured` untouched server-side (`sometimes|boolean`), which would
 * make un-featuring a section silently impossible.
 */
describe("menuSectionService — is_featured on the wire (#1187)", () => {
  beforeEach(() => {
    vi.mocked(globalThis.fetch).mockClear();
  });

  function sentBody(): Record<string, unknown> {
    const options = vi.mocked(globalThis.fetch).mock.calls[0]?.[1];
    return JSON.parse(String(options?.body)) as Record<string, unknown>;
  }

  it("sends is_featured=true when a section is marked featured", async () => {
    await menuSectionService.update("betoya", "sec-1", {
      updated_at: "2026-07-29T00:00:00Z",
      name: "おすすめ",
      is_featured: true,
    });

    expect(sentBody()).toMatchObject({ name: "おすすめ", is_featured: true });
  });

  it("sends is_featured=false rather than omitting it — un-featuring must stick", async () => {
    await menuSectionService.update("betoya", "sec-1", {
      updated_at: "2026-07-29T00:00:00Z",
      name: "おすすめ",
      is_featured: false,
    });

    const body = sentBody();
    expect("is_featured" in body).toBe(true);
    expect(body.is_featured).toBe(false);
  });

  it("PUTs to the brand-scoped menu-sections endpoint", async () => {
    await menuSectionService.update("betoya", "sec-1", { is_featured: true });

    const [url, options] = vi.mocked(globalThis.fetch).mock.calls[0] ?? [];
    expect(String(url)).toContain("/api/v1/hq/betoya/menu-sections/sec-1");
    expect(options?.method).toBe("PUT");
  });
});
