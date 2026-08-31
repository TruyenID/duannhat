/**
 * #1320 — spotlight ("Khung giờ ưu đãi") for the POS menu surface.
 *
 * The window is evaluated by the WORKSTATION on the shop clock, and it closes
 * on schedule every day — so unlike the menu, this list goes stale on its own
 * with nothing changing server-side. It is refetched on an interval and on
 * window focus for exactly that reason: a cashier who left the panel open
 * across 19:00 must stop being offered the happy-hour tile.
 *
 * A cached tile can still be tapped in the seconds after a window closes; the
 * workstation re-checks the window when the line is created (#1392) and prices
 * it at the menu price instead. Client freshness is a courtesy, not the guard.
 */

import { useQuery } from "@tanstack/react-query";
import { floatingSectionService } from "@/services/floating-section-service";
import { useLocale } from "@/providers/app-provider";
import { floatingSectionKeys } from "./query-keys";

/** How often to re-ask which spotlights are open. */
const REFRESH_MS = 60_000;

export function useFloatingSections(shopSlug: string) {
  const { locale } = useLocale();

  return useQuery({
    queryKey: floatingSectionKeys.open(shopSlug, locale),
    queryFn: () => floatingSectionService.listOpen(),
    enabled: !!shopSlug,
    refetchInterval: REFRESH_MS,
    refetchOnWindowFocus: true,
    // The service already turns "no workstation" into an empty list, so a
    // retry storm would only re-confirm the same 404 on a Cloud-only POS.
    retry: false,
  });
}
