export interface MenuAvailability {
  branch_name: string;
  menu_name: string;
  timezone: string;
  next_opens_at: string;
  next_closes_at: string | null;
}

export function parseMenuAvailability(body: Record<string, unknown>): MenuAvailability | null {
  if (body.code !== "menu_outside_service_hours") return null;

  const value = body.availability;
  if (!value || typeof value !== "object" || Array.isArray(value)) return null;

  const availability = value as Record<string, unknown>;
  if (
    typeof availability.branch_name !== "string" ||
    typeof availability.menu_name !== "string" ||
    typeof availability.timezone !== "string" ||
    typeof availability.next_opens_at !== "string" ||
    (availability.next_closes_at !== null && typeof availability.next_closes_at !== "string")
  ) {
    return null;
  }

  return availability as unknown as MenuAvailability;
}

/**
 * Why the menu didn't come back. The customer-facing copy differs per kind and
 * so does the action offered, so this must be decided from the response — not
 * collapsed into one "failed to load".
 */
export type MenuErrorKind =
  /** Menu exists but its schedule window is shut. Self-resolving; we can say when. */
  | { kind: "outside-hours"; availability: MenuAvailability }
  /**
   * Backend `code: "menu_unavailable"` — the shop has published no menu for
   * this service type (none assigned, inactive, or expired). Nothing is broken
   * and retrying cannot help, so it must not be dressed as a failure.
   */
  | { kind: "unavailable" }
  /** Network fault, 5xx, anything unrecognised. A retry is worth offering. */
  | { kind: "technical" };

/**
 * Reads `ApiError.body` structurally rather than via `instanceof`: this module
 * is covered by `node --test`, which cannot load `lib/api.ts` (path aliases +
 * constructor parameter properties). A network fault throws a plain Error and
 * lands here as `null`, which is the answer we want anyway.
 */
function responseBody(error: unknown): Record<string, unknown> | null {
  if (!error || typeof error !== "object") return null;

  const body = (error as { body?: unknown }).body;
  if (!body || typeof body !== "object" || Array.isArray(body)) return null;

  return body as Record<string, unknown>;
}

export function classifyMenuError(error: unknown): MenuErrorKind {
  const body = responseBody(error);
  if (!body) return { kind: "technical" };

  const availability = parseMenuAvailability(body);
  if (availability) return { kind: "outside-hours", availability };

  if (body.code === "menu_unavailable") return { kind: "unavailable" };

  return { kind: "technical" };
}

export function formatMenuAvailability(availability: MenuAvailability, locale: string) {
  const opensAt = new Date(availability.next_opens_at);
  const closesAt = availability.next_closes_at ? new Date(availability.next_closes_at) : null;
  const dateFormatter = new Intl.DateTimeFormat(locale, {
    timeZone: availability.timezone,
    weekday: "long",
    year: "numeric",
    month: "long",
    day: "numeric",
  });
  const timeFormatter = new Intl.DateTimeFormat(locale, {
    timeZone: availability.timezone,
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23",
  });

  return {
    date: dateFormatter.format(opensAt),
    opensAt: timeFormatter.format(opensAt),
    closesAt: closesAt ? timeFormatter.format(closesAt) : null,
  };
}
