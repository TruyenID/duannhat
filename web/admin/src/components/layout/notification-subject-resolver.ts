/**
 * Map a notification's morph subject to an admin-web href. Used by the
 * bell dropdown + /inbox page when a row is clicked.
 *
 * Phase A coverage — extend when new subject types come online:
 *   Recipe         → /hq/{brandSlug}/recipes/{id}      (requires brand context)
 *   Product        → /hq/{brandSlug}/products/{id}
 *   CustomerOrder  → /shop/{shopSlug}/orders/{id}     (requires shop context)
 *   StockAlert     → /shop/{shopSlug}/inventory/alerts
 *
 * Returns null when we can't build a URL (unknown subject type, missing
 * brand/shop context). The caller renders the row without a click target.
 *
 * NOTE: brand/shop slugs are NOT in the notification payload. Until we
 * thread them through, only subject types that reach a legacy non-scoped
 * page resolve. A proper resolution requires joining the notification's
 * organization_id back to a brand slug — deferred to follow-up (flagged
 * in plan-008 NOTES.md as FE-resolver refinement).
 */

import type { NotificationRow } from "@/services/notification-service";

export function resolveNotificationSubjectHref(row: NotificationRow): string | null {
  const subject = row.subject;
  if (!subject) return null;

  // Unknown — caller renders without a link.
  return null;
}
