/**
 * Workstation download catalogue — the shape published by the backend at
 * `<backend>/downloads/workstation/manifest.json`, plus the pure helpers the
 * page needs to render it.
 *
 * No I/O lives here on purpose: `catalog.server.ts` owns the fetch and the
 * environment read, so this module stays importable from the client component
 * without dragging a server-only env var into the browser bundle.
 *
 * The binaries themselves did NOT move — they are still served straight out of
 * Laravel's `public/downloads/workstation/`. Only the PAGE moved to Next, so
 * every href built here is absolute against the backend origin. Proxying the
 * files through Next would put a Node process in front of a 33 MB static
 * download for no gain.
 */

/** Path prefix the backend serves the release tree from. */
export const DOWNLOADS_BASE_PATH = "/downloads/workstation";

/** The release index. Written by `.github/workflows/workstation-release.yml`. */
export const MANIFEST_PATH = `${DOWNLOADS_BASE_PATH}/manifest.json`;

/** Checksum list published next to every release. */
export const CHECKSUMS_FILENAME = "SHA256SUMS.txt";

/** Where the workstation answers once it is running. Never localised. */
export const WORKSTATION_LOCAL_URL = "http://localhost:8080/";

export interface CatalogBundle {
  filename: string;
  size: number;
  sha256: string;
}

export interface CatalogPlatform {
  id: string;
  filename: string;
  size: number;
  sha256: string;
  /** Shop-facing zip/tar carrying start.bat / start.command. Null on old manifests. */
  bundle: CatalogBundle | null;
}

export interface CatalogRelease {
  version: string;
  releasedAt: string;
  commit: string;
  archived: boolean;
  platforms: CatalogPlatform[];
}

export interface Catalog {
  latest: string | null;
  updatedAt: string | null;
  /** Live releases, newest first as published. */
  versions: CatalogRelease[];
  /** Releases the publisher marked `archived` — kept as a rollback path. */
  archive: CatalogRelease[];
}

function asString(value: unknown): string {
  return typeof value === "string" ? value : "";
}

function asInt(value: unknown): number {
  return typeof value === "number" && Number.isFinite(value) ? Math.trunc(value) : 0;
}

function normalizePlatform(raw: unknown): CatalogPlatform | null {
  if (typeof raw !== "object" || raw === null) return null;
  const entry = raw as Record<string, unknown>;

  const id = asString(entry.id);
  if (id === "") return null;

  let bundle: CatalogBundle | null = null;
  const rawBundle = entry.bundle;
  if (typeof rawBundle === "object" && rawBundle !== null) {
    const b = rawBundle as Record<string, unknown>;
    const filename = asString(b.filename);
    if (filename !== "") {
      bundle = { filename, size: asInt(b.size), sha256: asString(b.sha256) };
    }
  }

  return {
    id,
    filename: asString(entry.filename),
    size: asInt(entry.size),
    sha256: asString(entry.sha256),
    bundle,
  };
}

function normalizeRelease(raw: unknown): CatalogRelease | null {
  if (typeof raw !== "object" || raw === null) return null;
  const entry = raw as Record<string, unknown>;

  const version = asString(entry.version);
  if (version === "") return null;

  const platforms = Array.isArray(entry.platforms)
    ? entry.platforms.map(normalizePlatform).filter((p): p is CatalogPlatform => p !== null)
    : [];

  return {
    version,
    releasedAt: asString(entry.released_at),
    commit: asString(entry.commit),
    archived: entry.archived === true,
    platforms: sortWindowsFirst(platforms),
  };
}

/**
 * Parse the published manifest. Returns null when the payload is not a JSON
 * object at all — the caller then renders the fallback panel rather than an
 * empty page, because this is the page people open while a workstation is
 * already broken.
 */
export function normalizeManifest(raw: unknown): Catalog | null {
  if (typeof raw !== "object" || raw === null) return null;
  const manifest = raw as Record<string, unknown>;

  const entries = Array.isArray(manifest.versions) ? manifest.versions : [];
  const releases = entries
    .map(normalizeRelease)
    .filter((release): release is CatalogRelease => release !== null);

  return {
    latest: typeof manifest.latest === "string" ? manifest.latest : null,
    updatedAt: typeof manifest.updated_at === "string" ? manifest.updated_at : null,
    versions: releases.filter((release) => !release.archived),
    archive: releases.filter((release) => release.archived),
  };
}

/**
 * #3088 — the real fleet is hand-installed Windows machines, so Windows is the
 * overwhelming case and has to be the FIRST row in the server-rendered HTML.
 * Client-side platform detection only ever NARROWS this list; it is never what
 * makes a download reachable, so a browser with JS off must already be looking
 * at the right row.
 *
 * Stable within each group: everything else keeps the manifest's own order.
 */
export function sortWindowsFirst(platforms: CatalogPlatform[]): CatalogPlatform[] {
  const rank = (platform: CatalogPlatform) => (platform.id.startsWith("windows") ? 0 : 1);
  return [...platforms].sort((a, b) => rank(a) - rank(b));
}

/** The release the page leads with: `latest`, else the first published entry. */
export function newestRelease(catalog: Catalog): CatalogRelease | null {
  const named = catalog.versions.find((release) => release.version === catalog.latest);
  return named ?? catalog.versions[0] ?? null;
}

/** Every live release except the one shown in the hero card. */
export function olderReleases(catalog: Catalog, newest: CatalogRelease | null): CatalogRelease[] {
  if (newest === null) return catalog.versions;
  return catalog.versions.filter((release) => release.version !== newest.version);
}

export interface PickedFile {
  /** What a shop should actually download. */
  name: string;
  size: number;
  /** The bare binary, when a bundle exists — a technician-only fallback. */
  raw: string;
}

/**
 * The bundle (start.bat / start.command inside) is what a shop needs; the bare
 * binary ships no start script and is only useful to a technician.
 */
export function pickDownload(platform: CatalogPlatform): PickedFile {
  if (platform.bundle !== null) {
    return { name: platform.bundle.filename, size: platform.bundle.size, raw: platform.filename };
  }
  return { name: platform.filename, size: platform.size, raw: "" };
}

/** Absolute href on the backend origin — the files never moved. */
export function downloadHref(
  origin: string,
  version: string,
  filename: string,
  archived = false
): string {
  const segment = archived ? `${DOWNLOADS_BASE_PATH}/archive` : DOWNLOADS_BASE_PATH;
  return `${origin}${segment}/${encodeURIComponent(version)}/${encodeURIComponent(filename)}`;
}

export function manifestHref(origin: string): string {
  return `${origin}${MANIFEST_PATH}`;
}

export function directoryHref(origin: string): string {
  return `${origin}${DOWNLOADS_BASE_PATH}/`;
}

export function formatSize(bytes: number): string {
  if (bytes <= 0) return "";
  if (bytes >= 1_000_000) return `${(bytes / 1_000_000).toFixed(1)} MB`;
  return `${Math.round(bytes / 1000)} KB`;
}

/** `2026-08-17T00:00:00Z` → `2026-08-17`. Empty stays empty. */
export function releaseDate(value: string): string {
  return value.slice(0, 10);
}

/** Enough of the commit to identify a build, short enough to read out loud. */
export function shortCommit(value: string): string {
  return value.slice(0, 9);
}
