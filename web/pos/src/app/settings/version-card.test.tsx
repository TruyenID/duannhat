/**
 * VersionCard (#2632) — the two build numbers on the settings screen.
 *
 * What the tests hold down:
 *   1. BOTH numbers render, separately, even when they disagree — a merged
 *      single number is the thing the issue rules out.
 *   2. An unknown version renders as words. A card that quietly kept the last
 *      number it saw would look identical on a happy path and lie on the one
 *      call where it matters.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { AppProvider } from "@/providers/app-provider";
import type {
  WorkstationUpdateHint,
  WorkstationVersions,
} from "@/providers/workstation-provider";
import enMessages from "@/i18n/en.json";

const h = vi.hoisted(() => ({
  ctx: {
    versions: { workstation: null, posBundle: null } as WorkstationVersions,
    updateHint: {
      available: false,
      expectedVersion: null,
    } as WorkstationUpdateHint,
    mode: "workstation" as "auto" | "workstation" | "cloud",
    status: "lan-active" as string,
    testConnection: vi.fn(),
  },
}));

vi.mock("@/providers/workstation-provider", () => ({
  useWorkstation: () => h.ctx,
}));

import { VersionCard } from "./version-card";

const en = enMessages as Record<string, string>;

function renderCard(over: Partial<typeof h.ctx> = {}) {
  Object.assign(h.ctx, over);
  return render(
    <AppProvider>
      <VersionCard />
    </AppProvider>,
  );
}

beforeEach(() => {
  localStorage.setItem("pos_locale", "en"); // deterministic string assertions
  h.ctx.versions = { workstation: null, posBundle: null };
  h.ctx.updateHint = { available: false, expectedVersion: null };
  h.ctx.mode = "workstation";
  h.ctx.status = "lan-active";
});

afterEach(() => {
  vi.clearAllMocks();
});

describe("VersionCard", () => {
  it("shows the binary version and the bundle version as two separate values", () => {
    renderCard({
      versions: { workstation: "1.4.2", posBundle: "2026.08.12-a1b2c3" },
    });

    expect(screen.getByText(en["settings.version.workstation"])).toBeInTheDocument();
    expect(screen.getByText(en["settings.version.pos_bundle"])).toBeInTheDocument();
    expect(screen.getByText("1.4.2")).toBeInTheDocument();
    expect(screen.getByText("2026.08.12-a1b2c3")).toBeInTheDocument();
  });

  it("shows both when they DISAGREE, without merging or hiding either", () => {
    renderCard({ versions: { workstation: "1.4.2", posBundle: "1.3.9" } });

    expect(screen.getByText("1.4.2")).toBeInTheDocument();
    expect(screen.getByText("1.3.9")).toBeInTheDocument();
    expect(screen.queryByText(en["settings.version.unknown"])).toBeNull();
  });

  it("renders 'Unknown' for each missing version — no digits anywhere", () => {
    renderCard({ versions: { workstation: null, posBundle: null } });

    expect(screen.getAllByText(en["settings.version.unknown"])).toHaveLength(2);
    // Nothing on the card may read as a version when there is none.
    expect(screen.queryByText(/\d+\.\d+\.\d+/)).toBeNull();
  });

  it("marks only the missing half unknown when the bundle answered and the binary did not", () => {
    renderCard({ versions: { workstation: null, posBundle: "1.3.9" } });

    expect(screen.getAllByText(en["settings.version.unknown"])).toHaveLength(1);
    expect(screen.getByText("1.3.9")).toBeInTheDocument();
  });

  it("says 'Checking' rather than 'Unknown' while the first probe is in flight", () => {
    renderCard({
      status: "checking",
      versions: { workstation: null, posBundle: null },
    });

    expect(screen.getAllByText(en["settings.version.checking"])).toHaveLength(2);
  });

  it("re-probes on demand", () => {
    renderCard({ versions: { workstation: "1.4.2", posBundle: "1.4.2" } });

    fireEvent.click(
      screen.getByRole("button", { name: en["settings.version.refresh"] }),
    );
    expect(h.ctx.testConnection).toHaveBeenCalledTimes(1);
  });

  it("explains Cloud mode instead of pretending the probe failed", () => {
    renderCard({ mode: "cloud", status: "cloud-manual" });

    expect(screen.getByText(en["settings.version.cloud_mode"])).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: en["settings.version.refresh"] }),
    ).toBeDisabled();
  });
});

/**
 * #2633 — the read-only "there is a newer build" line.
 *
 * Two failures are pinned here, and they point in opposite directions:
 *   1. The line must appear when the workstation says an update exists —
 *      otherwise the endpoint work has no visible effect at all.
 *   2. It must NOT appear otherwise, and it must NEVER carry a control. The
 *      project owner ruled updating from the POS out; a button that "just works"
 *      would restart the shop's workstation from a tablet mid-service.
 */
describe("VersionCard update hint (#2633)", () => {
  const hintOf = (c: HTMLElement) =>
    c.querySelector('[data-slot="workstation-update-hint"]');

  it("shows the line, naming the expected version, when an update is available", () => {
    const { container } = renderCard({
      versions: { workstation: "1.4.2", posBundle: "1.4.2" },
      updateHint: { available: true, expectedVersion: "1.5.0" },
    });

    const hint = hintOf(container);
    expect(hint).not.toBeNull();
    expect(hint).toHaveTextContent(
      en["settings.version.update_available"].replace("{version}", "1.5.0"),
    );
  });

  it("shows nothing at all when no update is available", () => {
    const { container } = renderCard({
      versions: { workstation: "1.4.2", posBundle: "1.4.2" },
      updateHint: { available: false, expectedVersion: null },
    });

    expect(hintOf(container)).toBeNull();
  });

  // An unconfigured update feed also reports "no update", so an "up to date"
  // badge would be a lie in the most common configuration. Silence is the only
  // honest rendering of false.
  it("does not claim the terminal is up to date when there is no update", () => {
    const { container } = renderCard({
      versions: { workstation: "1.4.2", posBundle: "1.4.2" },
      updateHint: { available: false, expectedVersion: "1.4.2" },
    });

    expect(hintOf(container)).toBeNull();
  });

  it("stays silent when a stale version leaks through with available=false", () => {
    const { container } = renderCard({
      updateHint: { available: false, expectedVersion: "9.9.9" },
    });

    expect(hintOf(container)).toBeNull();
    expect(screen.queryByText(/9\.9\.9/)).toBeNull();
  });

  it("still states the case when the expected version is unusable", () => {
    const { container } = renderCard({
      updateHint: { available: true, expectedVersion: null },
    });

    expect(hintOf(container)).toHaveTextContent(
      en["settings.version.update_available_unknown_version"],
    );
  });

  it("offers no control — the only button on the card stays the re-probe", () => {
    renderCard({
      versions: { workstation: "1.4.2", posBundle: "1.4.2" },
      updateHint: { available: true, expectedVersion: "1.5.0" },
    });

    const buttons = screen.getAllByRole("button");
    expect(buttons).toHaveLength(1);
    expect(buttons[0]).toHaveAccessibleName(en["settings.version.refresh"]);
    expect(screen.queryByRole("link")).toBeNull();
  });
});

describe("version-card i18n completeness", () => {
  it.each(["vi", "ja", "en"])("%s defines every version key", async (loc) => {
    const msgs = (
      await import(`@/i18n/${loc}.json`)
    ).default as Record<string, string>;
    for (const key of [
      "settings.version.title",
      "settings.version.description",
      "settings.version.workstation",
      "settings.version.workstation_desc",
      "settings.version.pos_bundle",
      "settings.version.pos_bundle_desc",
      "settings.version.unknown",
      "settings.version.checking",
      "settings.version.refresh",
      "settings.version.cloud_mode",
      "settings.version.update_available",
      "settings.version.update_available_unknown_version",
    ]) {
      expect(msgs[key], `${loc}: ${key}`).toBeTruthy();
    }
  });
});
