"use client";

import { useEffect, useState, type ReactNode } from "react";
import { useTranslation } from "@/providers/app-provider";
import {
  CHECKSUMS_FILENAME,
  WORKSTATION_LOCAL_URL,
  directoryHref,
  downloadHref,
  formatSize,
  manifestHref,
  newestRelease,
  olderReleases,
  pickDownload,
  releaseDate,
  shortCommit,
  type Catalog,
  type CatalogPlatform,
  type CatalogRelease,
} from "../catalog";

/**
 * The public workstation download page.
 *
 * Moved off a Blade template (#3088 shipped it there) because Laravel is not in
 * the business of rendering HTML for this product, and because the Blade copy
 * was bilingual IN-LINE — every line carried Japanese and Vietnamese at once
 * and English did not exist at all. Here the page speaks one language at a
 * time, through the same catalogue every other admin screen uses.
 *
 * Two decisions carried over from #3088 verbatim, both measured rather than
 * chosen for looks:
 *
 *   • WINDOWS FIRST. The live fleet is hand-installed Windows machines, so any
 *     other order makes the common case scroll past four rows it will never use.
 *   • ARCHIVED BUILDS STAY. This page is the rollback path when a release goes
 *     wrong; the old builds are ranked below the current one, never dropped.
 */

/** Which install instructions cover which platform ids. Windows leads. */
const STEP_GROUPS = [
  { key: "windows", ids: ["windows-amd64.exe"] },
  { key: "mac", ids: ["darwin-arm64", "darwin-amd64"] },
  { key: "linux", ids: ["linux-amd64", "linux-arm64"] },
] as const;

const LINUX_COMMANDS = ["tar -xzf Tempo-Workstation-….tar.gz", "./start.sh", WORKSTATION_LOCAL_URL];

interface Props {
  /** Backend origin the release files are served from. */
  origin: string;
  /** Null when the manifest could not be read — see the fallback panel. */
  catalog: Catalog | null;
}

export function WorkstationDownloads({ origin, catalog }: Props) {
  const { t } = useTranslation();
  const detected = useDetectedPlatform();

  const newest = catalog === null ? null : newestRelease(catalog);
  const older = catalog === null ? [] : olderReleases(catalog, newest);
  const archive = catalog?.archive ?? [];

  return (
    <main className="mx-auto w-full max-w-3xl px-5 pt-10 pb-20">
      <h1 className="text-2xl font-bold tracking-tight">{t("downloads.title")}</h1>
      <p className="mt-1 mb-8 text-muted-foreground">
        {t("downloads.subtitle")} <Code>{WORKSTATION_LOCAL_URL}</Code>
      </p>

      {catalog === null ? (
        <ManifestUnavailable origin={origin} />
      ) : newest === null ? (
        <p className="rounded-xl border border-dashed p-6 text-center text-muted-foreground">
          {t("downloads.empty")}
        </p>
      ) : (
        <>
          <LatestRelease origin={origin} release={newest} detected={detected} />
          <InstallSteps detected={detected} />
          {older.length > 0 && <ReleaseTable origin={origin} releases={older} kind="older" />}
          {archive.length > 0 && <ReleaseTable origin={origin} releases={archive} kind="archive" />}
        </>
      )}

      {catalog?.updatedAt && (
        <footer className="mt-12 text-xs text-muted-foreground">
          {t("downloads.updated_at")}: {catalog.updatedAt} UTC
        </footer>
      )}
    </main>
  );
}

// ─── Latest release ────────────────────────────────────────────────────────

function LatestRelease({
  origin,
  release,
  detected,
}: {
  origin: string;
  release: CatalogRelease;
  detected: string | null;
}) {
  const { t } = useTranslation();

  const downloadable = release.platforms.filter((platform) => pickDownload(platform).name !== "");
  // Client-side detection only ever NARROWS. Until it runs — and forever, with
  // JS off — every platform is on screen and reachable (#3088).
  const match = downloadable.filter((platform) => platform.id === detected);
  const primary = match.length === 1 ? match : downloadable;
  const secondary = match.length === 1 ? downloadable.filter((p) => p !== match[0]) : [];

  const rawBinaries = downloadable
    .map((platform) => ({ platform, raw: pickDownload(platform).raw }))
    .filter((entry) => entry.raw !== "");

  return (
    <section className="mb-4 rounded-xl border bg-muted/40 p-6">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-2">
        <span className="text-3xl font-bold tracking-tight">{release.version}</span>
        <span className="rounded-full border px-2 py-0.5 text-xs font-bold tracking-wide text-emerald-700 dark:text-emerald-400">
          {t("downloads.badge.latest")}
        </span>
      </div>
      <p className="mt-1 mb-5 text-sm text-muted-foreground">
        {releaseDate(release.releasedAt)}
        {release.commit !== "" && (
          <>
            {" · commit "}
            <Code>{shortCommit(release.commit)}</Code>
          </>
        )}
      </p>

      <div>
        {primary.map((platform) => (
          <DownloadRow
            key={platform.id}
            origin={origin}
            version={release.version}
            platform={platform}
          />
        ))}
      </div>

      {secondary.length > 0 && (
        <details className="mt-2">
          <summary className="cursor-pointer py-1.5 text-sm text-primary">
            {t("downloads.other_os")}
          </summary>
          {secondary.map((platform) => (
            <DownloadRow
              key={platform.id}
              origin={origin}
              version={release.version}
              platform={platform}
            />
          ))}
        </details>
      )}

      <p className="mt-4 text-sm text-muted-foreground">
        {t("downloads.checksum.label")}:{" "}
        <a
          className="text-primary underline"
          href={downloadHref(origin, release.version, CHECKSUMS_FILENAME)}
        >
          {CHECKSUMS_FILENAME}
        </a>
      </p>

      {rawBinaries.length > 0 && (
        <details className="mt-2">
          <summary className="cursor-pointer py-1.5 text-sm text-primary">
            {t("downloads.raw.summary")}
          </summary>
          <p className="mt-1 text-sm text-muted-foreground">{t("downloads.raw.note")}</p>
          <div className="mt-2 flex flex-wrap gap-2">
            {rawBinaries.map(({ platform, raw }) => (
              <a
                key={platform.id}
                className="rounded-lg border px-3 py-1.5 text-sm text-primary"
                href={downloadHref(origin, release.version, raw)}
              >
                {platformLabel(t, platform.id)}
              </a>
            ))}
          </div>
        </details>
      )}
    </section>
  );
}

function DownloadRow({
  origin,
  version,
  platform,
}: {
  origin: string;
  version: string;
  platform: CatalogPlatform;
}) {
  const { t } = useTranslation();
  const file = pickDownload(platform);
  const size = formatSize(file.size);

  return (
    <div
      data-os={platform.id}
      className="mb-2 flex flex-wrap items-center justify-between gap-3 rounded-lg border bg-background px-4 py-3"
    >
      <div className="min-w-0">
        <div className="font-semibold">{platformLabel(t, platform.id)}</div>
        <div className="text-xs [overflow-wrap:anywhere] text-muted-foreground">
          {file.name}
          {size !== "" && ` · ${size}`}
        </div>
      </div>
      <a
        className="rounded-lg bg-primary px-5 py-2 font-semibold whitespace-nowrap text-primary-foreground"
        href={downloadHref(origin, version, file.name)}
      >
        {t("downloads.action.download")}
      </a>
    </div>
  );
}

// ─── Install steps ─────────────────────────────────────────────────────────

function InstallSteps({ detected }: { detected: string | null }) {
  const { t } = useTranslation();

  const owning = STEP_GROUPS.find((group) =>
    detected === null ? false : (group.ids as readonly string[]).includes(detected)
  );
  const rest = owning ? STEP_GROUPS.filter((group) => group !== owning) : [];
  const shown = owning ? [owning] : STEP_GROUPS;

  return (
    <>
      <h2 className="mt-9 mb-2.5 text-base font-semibold">{t("downloads.install.title")}</h2>
      {shown.map((group) => (
        <StepCard key={group.key} group={group.key} />
      ))}
      {rest.length > 0 && (
        <details className="mt-3">
          <summary className="cursor-pointer py-1.5 text-sm text-primary">
            {t("downloads.install.other_os")}
          </summary>
          {rest.map((group) => (
            <StepCard key={group.key} group={group.key} />
          ))}
        </details>
      )}
    </>
  );
}

function StepCard({ group }: { group: (typeof STEP_GROUPS)[number]["key"] }) {
  const { t } = useTranslation();

  return (
    <div className="mt-3 rounded-xl border bg-muted/40 px-5 py-4">
      <h3 className="mb-2.5 text-sm font-semibold">
        {group === "windows"
          ? t("downloads.os.windows")
          : group === "mac"
            ? t("downloads.os.mac")
            : t("downloads.os.linux")}
      </h3>
      <ol className="list-decimal space-y-1 pl-5">
        {group === "windows" && (
          <>
            <li>{t("downloads.windows.step.download")}</li>
            <li>{t("downloads.windows.step.unzip")}</li>
            <li>{withCode(t("downloads.windows.step.start"), "start.bat")}</li>
            <li>{withCode(t("downloads.windows.step.open"), WORKSTATION_LOCAL_URL)}</li>
          </>
        )}
        {group === "mac" && (
          <>
            <li>{t("downloads.mac.step.download")}</li>
            <li>{withCode(t("downloads.mac.step.start"), "start.command")}</li>
            <li>{withCode(t("downloads.mac.step.open"), WORKSTATION_LOCAL_URL)}</li>
          </>
        )}
        {group === "linux" &&
          LINUX_COMMANDS.map((command) => (
            <li key={command}>
              <Code>{command}</Code>
            </li>
          ))}
      </ol>
      {group === "windows" && <Hint>{t("downloads.windows.hint")}</Hint>}
      {group === "mac" && <Hint>{t("downloads.mac.hint")}</Hint>}
    </div>
  );
}

// ─── Older builds + archive ────────────────────────────────────────────────

function ReleaseTable({
  origin,
  releases,
  kind,
}: {
  origin: string;
  releases: CatalogRelease[];
  kind: "older" | "archive";
}) {
  const { t } = useTranslation();

  return (
    <>
      <h2 className="mt-9 mb-2.5 text-base font-semibold">
        {kind === "older" ? t("downloads.older.title") : t("downloads.archive.title")}
      </h2>
      {kind === "older" && (
        <p className="text-sm text-muted-foreground">{t("downloads.older.note")}</p>
      )}
      <details className="mt-2">
        <summary className="cursor-pointer py-1.5 text-sm text-primary">
          {t("downloads.show_count", { count: releases.length })}
        </summary>
        <div className="overflow-x-auto">
          <table className="w-full border-collapse text-sm">
            <thead>
              <tr className="text-xs text-muted-foreground">
                <th className="border-b px-2.5 py-2 text-left font-semibold">
                  {t("downloads.col.version")}
                </th>
                <th className="border-b px-2.5 py-2 text-left font-semibold">
                  {t("downloads.col.date")}
                </th>
                <th className="border-b px-2.5 py-2 text-left font-semibold">
                  {t("downloads.col.commit")}
                </th>
                <th className="border-b px-2.5 py-2 text-right font-semibold">
                  {t("downloads.col.download")}
                </th>
              </tr>
            </thead>
            <tbody>
              {releases.map((release) => (
                <tr key={release.version}>
                  <td className="border-b px-2.5 py-2 font-semibold">{release.version}</td>
                  <td className="border-b px-2.5 py-2 text-muted-foreground">
                    {releaseDate(release.releasedAt)}
                  </td>
                  <td className="border-b px-2.5 py-2 text-muted-foreground">
                    <Code>{shortCommit(release.commit)}</Code>
                  </td>
                  <td className="space-x-2 border-b px-2.5 py-2 text-right">
                    {release.platforms.map((platform) => {
                      const file = pickDownload(platform);
                      if (file.name === "") return null;
                      return (
                        <a
                          key={platform.id}
                          className="inline-block rounded-lg border px-3 py-1.5 text-primary"
                          href={downloadHref(origin, release.version, file.name, release.archived)}
                        >
                          {platformLabel(t, platform.id)}
                        </a>
                      );
                    })}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </>
  );
}

// ─── Manifest unreachable ──────────────────────────────────────────────────

/**
 * A shop reaches this page BECAUSE something is already broken. Rendering
 * nothing — or a stack trace — leaves a technician with no way to get the file
 * they came for, so the direct paths are printed even when the index is gone.
 */
function ManifestUnavailable({ origin }: { origin: string }) {
  const { t } = useTranslation();

  return (
    <section role="alert" className="rounded-xl border border-amber-500/50 bg-amber-500/10 p-6">
      <h2 className="font-semibold">{t("downloads.error.title")}</h2>
      <p className="mt-1 text-sm">{t("downloads.error.body")}</p>
      <div className="mt-3 flex flex-wrap gap-2">
        <a
          className="rounded-lg border px-3 py-1.5 text-sm text-primary"
          href={manifestHref(origin)}
        >
          {t("downloads.error.manifest_link")}
        </a>
        <a
          className="rounded-lg border px-3 py-1.5 text-sm text-primary"
          href={directoryHref(origin)}
        >
          {t("downloads.error.directory_link")}
        </a>
      </div>
    </section>
  );
}

// ─── Small pieces ──────────────────────────────────────────────────────────

function Code({ children }: { children: ReactNode }) {
  return (
    <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-[0.85em] [overflow-wrap:anywhere]">
      {children}
    </code>
  );
}

function Hint({ children }: { children: ReactNode }) {
  return (
    <p className="mt-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2.5 text-sm">
      {children}
    </p>
  );
}

/**
 * Splice a literal into a translated sentence. Word order differs per language
 * — Japanese puts the filename first, English and Vietnamese put it last — so
 * the placeholder travels inside the translation instead of being concatenated
 * around it.
 */
function withCode(sentence: string, literal: string): ReactNode {
  const [before, after = ""] = sentence.split("{code}");
  return (
    <>
      {before}
      <Code>{literal}</Code>
      {after}
    </>
  );
}

/**
 * Platform names read the same in every locale — they are other vendors'
 * product names, not our copy — but they still come from the catalogue so no
 * screen ever prints a raw id like `darwin-arm64` at a shop, and so a missing
 * entry is caught by the same parity gate as every other string.
 */
function platformLabel(t: (key: string) => string, id: string): string {
  switch (id) {
    case "windows-amd64.exe":
      return t("downloads.platform.windows_amd64");
    case "linux-amd64":
      return t("downloads.platform.linux_amd64");
    case "linux-arm64":
      return t("downloads.platform.linux_arm64");
    case "darwin-amd64":
      return t("downloads.platform.darwin_amd64");
    case "darwin-arm64":
      return t("downloads.platform.darwin_arm64");
    default:
      return id;
  }
}

/**
 * Guess the platform the visitor is standing on, AFTER mount.
 *
 * #3088 again: this only reorders what is already rendered. A wrong guess, a
 * browser with JS off, or a platform we cannot name all land on the same
 * behaviour — the full list, exactly as the server sent it.
 */
function useDetectedPlatform(): string | null {
  const [detected, setDetected] = useState<string | null>(null);

  useEffect(() => {
    const ua = navigator.userAgent || "";
    if (/Windows|Win64|Win32/i.test(ua)) {
      setDetected("windows-amd64.exe");
    } else if (/Mac|Darwin/i.test(ua)) {
      setDetected(/ARM|Apple/i.test(navigator.platform || "") ? "darwin-arm64" : "darwin-amd64");
    } else if (/Linux|X11/i.test(ua)) {
      setDetected("linux-amd64");
    }
  }, []);

  return detected;
}
