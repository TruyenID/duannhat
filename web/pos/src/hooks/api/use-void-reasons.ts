/**
 * useVoidReasons — plan-051 (#1149) void-reason picker list for the
 * void-item dialog.
 *
 * The list is brand-scoped master data edited at HQ, so it changes rarely —
 * a 5-minute staleTime keeps dialog-open latency at zero without hammering
 * the endpoint. Errors are NOT surfaced to the operator: an unreachable list
 * (offline LAN without a mirror for the shops-domain endpoint) simply means
 * the dialog falls back to the legacy free-text reason input.
 */

import { useQuery } from "@tanstack/react-query";
import { voidReasonService } from "@/services/void-reason-service";
import { useLocale } from "@/providers/app-provider";
import { voidReasonKeys } from "./query-keys";

export function useVoidReasons(shopSlug: string) {
  const { locale } = useLocale();

  return useQuery({
    queryKey: voidReasonKeys.list(shopSlug, locale),
    queryFn: () => voidReasonService.list(shopSlug),
    enabled: !!shopSlug,
    staleTime: 5 * 60 * 1000,
    // One retry only — when the endpoint is unreachable (LAN without the
    // shops-domain mirror) we want the dialog to settle into its free-text
    // fallback quickly, not spin through the default retry ladder.
    retry: 1,
  });
}
