import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it } from "vitest";

import type { LocaleCode } from "@/i18n";
import { AppProvider } from "@/providers/app-provider";
import { normalizeManifest } from "../catalog";
import { WorkstationDownloads } from "./workstation-downloads";

/**
 * The download page used to be a Blade template on the Laravel side. These are
 * the gates that came with it (#3088) plus the ones the move itself needs:
 * every platform reachable without JS, Windows ranked first, three languages
 * that do not bleed into each other, and a manifest failure that still hands
 * over a working link.
 */

const ORIGIN = "https://backend.test";

const PLATFORM_IDS = [
  "linux-amd64",
  "linux-arm64",
  "darwin-amd64",
  "darwin-arm64",
  // Listed LAST on purpose: if the page stops ranking Windows first, it will
  // render in this order and the ordering assertion below fails.
  "windows-amd64.exe",
];

function fakeManifest() {
  return {
    latest: "v0.9.0",
    updated_at: "2026-08-17T00:00:00Z",
    versions: [
      {
        version: "v0.9.0",
        released_at: "2026-08-17T00:00:00Z",
        commit: "feedface1234",
        archived: false,
        platforms: PLATFORM_IDS.map((id) => ({
          id,
          filename: `ws-server-${id}`,
          size: 33_000_000,
          sha256: "b".repeat(64),
          bundle: {
            filename: `Tempo-Workstation-${id}.tar.gz`,
            size: 32_000_000,
            sha256: "c".repeat(64),
          },
        })),
      },
      {
        version: "v0.8.0",
        released_at: "2026-08-13T00:00:00Z",
        commit: "ba90775551",
        archived: false,
        platforms: [
          {
            id: "windows-amd64.exe",
            filename: "ws-server-windows-amd64.exe",
            size: 33_000_000,
            sha256: "c".repeat(64),
          },
        ],
      },
      {
        version: "v0.2.0",
        released_at: "2026-07-01T00:00:00Z",
        commit: "deadbeef0000",
        archived: true,
        platforms: [
          {
            id: "windows-amd64.exe",
            filename: "ws-server-windows-amd64.exe",
            size: 100,
            sha256: "d".repeat(64),
          },
        ],
      },
    ],
  };
}

function renderPage(locale: LocaleCode, catalog = normalizeManifest(fakeManifest())) {
  function wrapper({ children }: { children: ReactNode }) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return (
      <QueryClientProvider client={queryClient}>
        <AppProvider defaultLocale={locale}>{children}</AppProvider>
      </QueryClientProvider>
    );
  }

  return render(<WorkstationDownloads origin={ORIGIN} catalog={catalog} />, { wrapper });
}

/**
 * jsdom's own user agent contains the word "darwin", so without this every test
 * would silently measure the macOS-narrowed view instead of what the server
 * renders. A UA we cannot place is the honest default here: it is the same
 * state as a browser with JS switched off.
 */
function setUserAgent(value: string) {
  Object.defineProperty(window.navigator, "userAgent", { value, configurable: true });
}

beforeEach(() => {
  localStorage.clear();
  setUserAgent("Mozilla/5.0 (Unrecognised)");
});

describe("workstation downloads page", () => {
  it("offers every platform of the newest build, linked at the backend origin", () => {
    const { container } = renderPage("en");

    for (const id of PLATFORM_IDS) {
      const link = container.querySelector(
        `a[href="${ORIGIN}/downloads/workstation/v0.9.0/Tempo-Workstation-${id}.tar.gz"]`
      );
      expect(link, `no download link for ${id}`).not.toBeNull();
    }

    // Five platforms, five rows — nothing hidden behind a client-side guess.
    expect(container.querySelectorAll("[data-os]")).toHaveLength(PLATFORM_IDS.length);
  });

  it("ranks Windows first — the live fleet is hand-installed Windows machines", () => {
    const { container } = renderPage("en");

    const order = Array.from(container.querySelectorAll("[data-os]")).map((row) =>
      row.getAttribute("data-os")
    );

    expect(order[0]).toBe("windows-amd64.exe");
    // Stated as an ordering, not just a first-element check: every other
    // platform must sit strictly after Windows.
    for (const id of PLATFORM_IDS.filter((p) => p !== "windows-amd64.exe")) {
      expect(order.indexOf("windows-amd64.exe")).toBeLessThan(order.indexOf(id));
    }
  });

  it("lets client-side detection NARROW the list, never gate it", () => {
    // #3088 — guessing the visitor's platform may reorder what is on screen.
    // It may never be what decides whether a download is reachable, so every
    // other platform is still in the document, one <details> away.
    setUserAgent("Mozilla/5.0 (X11; Linux x86_64)");

    const { container } = renderPage("en");
    const order = Array.from(container.querySelectorAll("[data-os]")).map((row) =>
      row.getAttribute("data-os")
    );

    expect(order[0]).toBe("linux-amd64");
    expect(order).toHaveLength(PLATFORM_IDS.length);
    for (const id of PLATFORM_IDS) {
      expect(
        container.querySelector(
          `a[href="${ORIGIN}/downloads/workstation/v0.9.0/Tempo-Workstation-${id}.tar.gz"]`
        ),
        `narrowing dropped ${id}`
      ).not.toBeNull();
    }
  });

  it("keeps the rollback paths: earlier builds and the archive", () => {
    const { container } = renderPage("en");

    expect(
      container.querySelector(
        `a[href="${ORIGIN}/downloads/workstation/v0.8.0/ws-server-windows-amd64.exe"]`
      )
    ).not.toBeNull();

    // Archived builds are served from a different prefix and must keep it.
    expect(
      container.querySelector(
        `a[href="${ORIGIN}/downloads/workstation/archive/v0.2.0/ws-server-windows-amd64.exe"]`
      )
    ).not.toBeNull();
  });

  it("keeps the technical content the installer types verbatim", () => {
    const { container } = renderPage("en");
    const text = container.textContent ?? "";

    expect(text).toContain("http://localhost:8080/");
    expect(text).toContain("start.bat");
    expect(text).toContain("start.command");
    expect(text).toContain("tar -xzf Tempo-Workstation-….tar.gz");
    expect(text).toContain("./start.sh");
    // Checksum list sits next to the button, not at the bottom of the page.
    expect(
      container.querySelector(`a[href="${ORIGIN}/downloads/workstation/v0.9.0/SHA256SUMS.txt"]`)
    ).not.toBeNull();
    // Build identity — the answer to "which build is this machine running".
    expect(text).toContain("feedface1");
  });

  describe("one language at a time", () => {
    // The Blade page carried Japanese and Vietnamese on the SAME line and had
    // no English at all. A locale must now show its own copy and none of the
    // others' — otherwise the bilingual mush is back, just in a new file.
    const CASES: Array<{ locale: LocaleCode; own: string[]; foreign: string[] }> = [
      {
        locale: "ja",
        own: ["以前のバージョン", "インストール", "詳細情報"],
        foreign: ["Earlier versions", "Bản cũ hơn", "Run anyway", "Cài đặt"],
      },
      {
        locale: "en",
        own: ["Earlier versions", "Install", "Run anyway"],
        foreign: ["以前のバージョン", "Bản cũ hơn", "詳細情報", "Cài đặt"],
      },
      {
        locale: "vi",
        own: ["Bản cũ hơn", "Cài đặt", "Run anyway"],
        foreign: ["以前のバージョン", "Earlier versions", "詳細情報"],
      },
    ];

    for (const { locale, own, foreign } of CASES) {
      it(`renders ${locale} copy only`, () => {
        const { container } = renderPage(locale);
        const text = container.textContent ?? "";

        for (const phrase of own)
          expect(text, `${locale} is missing "${phrase}"`).toContain(phrase);
        for (const phrase of foreign) {
          expect(text, `${locale} leaked "${phrase}"`).not.toContain(phrase);
        }
      });
    }
  });

  it("says so plainly when the manifest cannot be read, and still hands over links", () => {
    const { container } = renderPage("en", null);

    const alert = screen.getByRole("alert");
    expect(within(alert).getByText("Could not load the release list")).toBeInTheDocument();

    // The page a shop opens BECAUSE something is broken must not be blank.
    expect((container.textContent ?? "").trim().length).toBeGreaterThan(80);
    expect(
      container.querySelector(`a[href="${ORIGIN}/downloads/workstation/manifest.json"]`)
    ).not.toBeNull();
    expect(container.querySelector(`a[href="${ORIGIN}/downloads/workstation/"]`)).not.toBeNull();
  });

  it("renders an explicit empty state when the manifest lists no build", () => {
    const catalog = normalizeManifest({ latest: null, updated_at: null, versions: [] });

    renderPage("en", catalog);

    expect(screen.getByText("No build has been published yet.")).toBeInTheDocument();
  });
});
