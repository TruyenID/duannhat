import { beforeEach, describe, expect, it, vi } from "vitest";
import fs from "node:fs";
import path from "node:path";

/**
 * plan-053 (#1171) T4.3 — the preview comes from the SERVER's renderer.
 *
 * Admin-web used to draw the slip itself (`preview-renderer.ts`): a TypeScript
 * re-implementation of the same layout rules the printer follows in PHP and Go.
 * Two implementations of one rule set drift, and the one that drifted was the
 * one a brand approves a template from — they signed off on a slip and the shop
 * printed a different one.
 *
 * These tests pin the two halves of the fix: the request goes to the print
 * endpoint carrying the editor's CURRENT definition, and no second renderer has
 * grown back.
 */

const apiFetch = vi.fn();
vi.mock("@/lib/api", () => ({
  apiFetch: (...args: unknown[]) => apiFetch(...args),
  ApiError: class ApiError extends Error {
    constructor(
      public status: number,
      public body: unknown
    ) {
      super("api error");
    }
  },
}));

const { printTemplateBrandService, printTemplateShopService } = await import(
  "@/services/print-template-service"
);

const definition = {
  schema: "tempo.print.v1",
  blocks: [
    { id: "title", type: "text" as const, enabled: true, i18n: { ja: "領収書" } },
    { id: "footer_text", type: "text" as const, enabled: true, i18n: { ja: "UNSAVED EDIT" } },
  ],
};

beforeEach(() => {
  apiFetch.mockReset();
  apiFetch.mockResolvedValue("<svg></svg>");
});

describe("the preview request", () => {
  it("posts the editor's definition to the brand preview endpoint", async () => {
    // The definition rides in the BODY. Previewing by saving first would bump
    // the draft's optimistic-lock token and 409 the other tab (TR-09) — a
    // preview must not write.
    await printTemplateBrandService.preview("beto-coffee", "receipt", {
      definition,
      paper: "58mm",
      locale: "vi",
    });

    const [url, options] = apiFetch.mock.calls[0];
    expect(url).toBe("/api/v1/hq/beto-coffee/print-templates/receipt/preview?paper=58mm&locale=vi");
    expect(options.method).toBe("POST");
    expect(JSON.parse(options.body)).toEqual({ definition });
    // Text, not JSON — the answer is an SVG document.
    expect(options.responseType).toBe("text");
  });

  it("posts the whole resolved slip to the shop preview endpoint", async () => {
    // The shop editor holds the RESOLVED slip rather than an overlay, so it
    // sends all of it; the backend filters it through the brand allow-list
    // before rendering, exactly as publish does.
    await printTemplateShopService.preview("hanoi", "vat_invoice", {
      definition,
      paper: "80mm",
      locale: "ja",
    });

    const [url, options] = apiFetch.mock.calls[0];
    expect(url).toBe(
      "/api/v1/shops/hanoi/print-templates/vat_invoice/preview?paper=80mm&locale=ja"
    );
    expect(options.method).toBe("POST");
    expect(JSON.parse(options.body)).toEqual({ definition });
  });

  it("sends the paper the author is looking at, never a default", async () => {
    // Silently previewing 80mm for a 58mm shop shows a slip that fits when
    // theirs does not — the single most expensive way this screen can lie.
    for (const paper of ["58mm", "80mm"] as const) {
      apiFetch.mockClear();
      await printTemplateBrandService.preview("b", "receipt", { definition, paper, locale: "ja" });
      expect(apiFetch.mock.calls[0][0]).toContain(`paper=${paper}`);
    }
  });
});

describe("no second renderer", () => {
  const componentDir = path.resolve(__dirname, "../components/shared/print-template");

  it("has no client-side slip renderer left on disk", () => {
    // The deleted file, by name. A "temporary" preview renderer is exactly the
    // kind of thing that grows back under a new name, so the assertion below
    // covers the behaviour rather than the filename.
    expect(fs.existsSync(path.join(componentDir, "preview-renderer.ts"))).toBe(false);
  });

  it("does not compute printer columns anywhere in the print-template UI", () => {
    /*
     * 32 and 48 are the column counts of 58mm and 80mm paper. They are layout
     * constants: whoever holds them decides where a line breaks. They belong to
     * the renderer that also drives the printer, and to nothing else — a copy
     * here is the drift this task removed, whatever it is called.
     */
    const offenders: string[] = [];
    for (const file of fs.readdirSync(componentDir)) {
      if (!file.endsWith(".ts") && !file.endsWith(".tsx")) continue;
      const source = fs.readFileSync(path.join(componentDir, file), "utf8");
      if (/\b(?:columns_58mm|columns_80mm)\b/.test(source)) continue; // a type field, not a decision
      if (/\b(?:32|48)\b/.test(source.replace(/\/\*[\s\S]*?\*\/|\/\/.*$/gm, ""))) {
        offenders.push(file);
      }
    }
    expect(offenders).toEqual([]);
  });
});
