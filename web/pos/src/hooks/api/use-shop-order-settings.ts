import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  shopOrderSettingsService,
  type CloseReportToggles,
  type ShopOrderSettings,
} from "@/services/shop-order-settings-service";
import { shopOrderSettingsKeys } from "./query-keys";

/**
 * GET /api/v1/pos/settings/order
 *
 * Shop order settings (enable_quick_order, default_order_item_status) are
 * flipped from admin-web — a different app with a different React Query
 * client, so pos-web can't be invalidated directly. Global defaults in
 * query-provider.tsx set `refetchOnWindowFocus: false`, which would leave
 * this query stuck on its initial value until manual reload. To close that
 * gap without reloading, override focus-refetch to true AND poll every 60 s
 * so a manager flipping quick order in admin-web takes effect in pos-web
 * within a minute at worst, immediately on tab refocus at best. Payload is
 * 3 fields — polling cost is negligible.
 */
export function useShopOrderSettings(shopSlug: string) {
  return useQuery({
    queryKey: shopOrderSettingsKeys.get(shopSlug),
    queryFn: () => shopOrderSettingsService.get(shopSlug),
    enabled: !!shopSlug,
    staleTime: 30 * 1000,
    refetchOnWindowFocus: true,
    refetchInterval: 60 * 1000,
  });
}

/**
 * PATCH /api/v1/pos/settings/order — scoped to the 精算 close-report section
 * toggles (the only fields pos-web may write; admin-web owns the full PATCH).
 *
 * On success the PATCH response carries the server-authoritative toggles, so we
 * merge THAT straight into the cached settings rather than invalidating +
 * refetching. A refetch here would race the 60s poll and could momentarily
 * revert the value; writing the confirmed response is deterministic and keeps
 * every reader (settings screen + open/pos pages) in sync. The settings screen
 * additionally drives its Switch from local state for instant feedback, so the
 * cashier never waits on the round-trip.
 */
export function useUpdateCloseReportToggles(shopSlug: string) {
  const queryClient = useQueryClient();
  const key = shopOrderSettingsKeys.get(shopSlug);

  return useMutation({
    mutationFn: (body: Partial<CloseReportToggles>) =>
      shopOrderSettingsService.updateCloseReportToggles(shopSlug, body),
    onSuccess: (response) => {
      queryClient.setQueryData<{ data: ShopOrderSettings }>(key, (old) =>
        old ? { ...old, data: { ...old.data, ...response.data } } : old,
      );
    },
  });
}
