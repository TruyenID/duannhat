/**
 * plan-056 — React Query wiring for the "Tồn món" screen.
 *
 * Two properties this file has to hold, both of which fail quietly if broken:
 *
 *   1. It uses `menuAvailabilityKeys`, NEVER `shopMenuKeys`. This domain's
 *      responses include turned-off dishes; the ordering screen's must not.
 *      One shared key and a management fetch could answer an ordering render.
 *   2. Every mutation is optimistic with a real rollback. A switch that snaps
 *      back on error is how a cashier learns the write failed — a toast alone
 *      leaves the UI showing a state the server never accepted.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import {
  menuAvailabilityService,
  type AvailabilityMenuDetail,
  type SetAvailabilityInput,
} from "@/services/menu-availability-service";
import { useLocale, useTranslation } from "@/providers/app-provider";
import { useAuth } from "@/providers/use-auth";
import { useTillCurrent } from "./use-till";
import { menuAvailabilityKeys, shopMenuKeys } from "./query-keys";

/**
 * Who is standing at the terminal.
 *
 * The POS authenticates a DEVICE, not a person, so the best answer available is
 * the cashier who opened the current shift — which is also the answer the shop
 * would give if you asked out loud. Falls back to the terminal's own name when
 * no shift is open (a manager taking stock before opening), so the audit row
 * never reads "nobody did this".
 */
export function useAvailabilityActor(shopSlug: string): {
  actor_user_id: string | null;
  actor_name: string | null;
} {
  const { device } = useAuth();
  const till = useTillCurrent(shopSlug);
  const session = till.data?.open_session ?? null;

  return {
    actor_user_id: session?.opened_by_id ?? null,
    actor_name: session?.opener_name ?? device?.name ?? null,
  };
}

/** GET /pos/menu-availability/menus */
export function useAvailabilityMenus(shopSlug: string) {
  const { locale } = useLocale();

  return useQuery({
    queryKey: menuAvailabilityKeys.menus(shopSlug, locale),
    queryFn: () => menuAvailabilityService.listMenus(),
    select: (r) => r.data,
    enabled: !!shopSlug,
  });
}

/** GET /pos/menu-availability/menus/{menu} */
export function useAvailabilityMenu(shopSlug: string, menuId: string | null) {
  const { locale } = useLocale();

  return useQuery({
    queryKey: menuAvailabilityKeys.detail(shopSlug, menuId ?? "", locale),
    queryFn: () => menuAvailabilityService.getMenu(menuId as string),
    select: (r) => r.data,
    enabled: !!shopSlug && !!menuId,
    // Two tablets can be on this screen at once, and a dish going off has to
    // reach the other one without a manual refresh. 15s is well under the time
    // it takes a second cashier to walk to a table and take an order.
    refetchInterval: 15_000,
    refetchIntervalInBackground: false,
  });
}

type DetailKey = ReturnType<typeof menuAvailabilityKeys.detail>;

/** Patch one dish in the cached detail, returning the pre-patch snapshot. */
function patchProduct(
  qc: ReturnType<typeof useQueryClient>,
  key: DetailKey,
  menuProductId: string,
  patch: Partial<AvailabilityMenuDetail["products"][number]>,
) {
  const previous = qc.getQueryData<{ data: AvailabilityMenuDetail }>(key);
  if (!previous?.data) return { previous };

  qc.setQueryData<{ data: AvailabilityMenuDetail }>(key, {
    ...previous,
    data: {
      ...previous.data,
      products: previous.data.products.map((p) =>
        p.id === menuProductId ? { ...p, ...patch } : p,
      ),
    },
  });

  return { previous };
}

/** Patch one variant in the cached detail, returning the pre-patch snapshot. */
function patchSku(
  qc: ReturnType<typeof useQueryClient>,
  key: DetailKey,
  menuProductSkuId: string,
  patch: Partial<AvailabilityMenuDetail["products"][number]["skus"][number]>,
) {
  const previous = qc.getQueryData<{ data: AvailabilityMenuDetail }>(key);
  if (!previous?.data) return { previous };

  qc.setQueryData<{ data: AvailabilityMenuDetail }>(key, {
    ...previous,
    data: {
      ...previous.data,
      products: previous.data.products.map((p) => ({
        ...p,
        skus: p.skus.map((s) =>
          s.menu_product_sku_id === menuProductSkuId ? { ...s, ...patch } : s,
        ),
      })),
    },
  });

  return { previous };
}

/**
 * Bust the ORDERING caches too.
 *
 * The dish just left (or rejoined) the cart picker, and the sales screen may be
 * mounted in another tab against the same QueryClient. Skipping this is how a
 * cashier turns a dish off on one tab and keeps adding it on the other.
 */
function invalidateOrderingViews(
  qc: ReturnType<typeof useQueryClient>,
  shopSlug: string,
) {
  qc.invalidateQueries({ queryKey: shopMenuKeys.all(shopSlug) });
}

export function useSetProductAvailability(shopSlug: string, menuId: string | null) {
  const qc = useQueryClient();
  const { locale } = useLocale();
  const { t } = useTranslation();
  const key = menuAvailabilityKeys.detail(shopSlug, menuId ?? "", locale);

  return useMutation({
    mutationFn: ({
      menuProductId,
      input,
    }: {
      menuProductId: string;
      input: SetAvailabilityInput;
    }) => menuAvailabilityService.setProductAvailability(menuProductId, input),

    onMutate: async ({ menuProductId, input }) => {
      await qc.cancelQueries({ queryKey: key });

      return patchProduct(qc, key, menuProductId, {
        is_active: input.is_active,
        // Mirror the server rule locally so the row does not flash a stale
        // reason for the ~200ms before the response lands: turning something
        // back on clears why it was off.
        disabled_reason: input.is_active ? null : (input.reason ?? null),
        disabled_by_name: input.is_active ? null : (input.actor_name ?? null),
        disabled_at: input.is_active ? null : new Date().toISOString(),
      });
    },

    onError: (e: Error, _vars, ctx) => {
      if (ctx?.previous) qc.setQueryData(key, ctx.previous);
      toast.error(e.message || t("menu_availability.toast.failed"));
    },

    onSuccess: (_res, { input }) => {
      toast.success(
        input.is_active
          ? t("menu_availability.toast.turned_on")
          : t("menu_availability.toast.turned_off"),
      );
    },

    onSettled: () => {
      qc.invalidateQueries({ queryKey: menuAvailabilityKeys.all(shopSlug) });
      invalidateOrderingViews(qc, shopSlug);
    },
  });
}

export function useSetSkuAvailability(shopSlug: string, menuId: string | null) {
  const qc = useQueryClient();
  const { locale } = useLocale();
  const { t } = useTranslation();
  const key = menuAvailabilityKeys.detail(shopSlug, menuId ?? "", locale);

  return useMutation({
    mutationFn: ({
      menuProductSkuId,
      input,
    }: {
      menuProductSkuId: string;
      input: SetAvailabilityInput;
    }) => menuAvailabilityService.setSkuAvailability(menuProductSkuId, input),

    onMutate: async ({ menuProductSkuId, input }) => {
      await qc.cancelQueries({ queryKey: key });

      return patchSku(qc, key, menuProductSkuId, {
        is_active: input.is_active,
        disabled_reason: input.is_active ? null : (input.reason ?? null),
        disabled_by_name: input.is_active ? null : (input.actor_name ?? null),
        disabled_at: input.is_active ? null : new Date().toISOString(),
      });
    },

    onError: (e: Error, _vars, ctx) => {
      if (ctx?.previous) qc.setQueryData(key, ctx.previous);
      toast.error(e.message || t("menu_availability.toast.failed"));
    },

    onSuccess: (_res, { input }) => {
      toast.success(
        input.is_active
          ? t("menu_availability.toast.variant_on")
          : t("menu_availability.toast.variant_off"),
      );
    },

    onSettled: () => {
      qc.invalidateQueries({ queryKey: menuAvailabilityKeys.all(shopSlug) });
      invalidateOrderingViews(qc, shopSlug);
    },
  });
}

export function useSetToppingAvailability(shopSlug: string, menuId: string | null) {
  const qc = useQueryClient();
  const { locale } = useLocale();
  const { t } = useTranslation();
  const key = menuAvailabilityKeys.detail(shopSlug, menuId ?? "", locale);

  return useMutation({
    mutationFn: ({
      menuProductId,
      toppingItemId,
      input,
    }: {
      menuProductId: string;
      toppingItemId: string;
      input: SetAvailabilityInput;
    }) =>
      menuAvailabilityService.setToppingAvailability(
        menuProductId,
        toppingItemId,
        input,
      ),

    onMutate: async ({ menuProductId, toppingItemId, input }) => {
      await qc.cancelQueries({ queryKey: key });
      const previous = qc.getQueryData<{ data: AvailabilityMenuDetail }>(key);
      if (!previous?.data) return { previous };

      qc.setQueryData<{ data: AvailabilityMenuDetail }>(key, {
        ...previous,
        data: {
          ...previous.data,
          products: previous.data.products.map((p) =>
            p.id !== menuProductId
              ? p
              : {
                  ...p,
                  topping_groups: (p.topping_groups ?? []).map((g) => ({
                    ...g,
                    items: g.items.map((i) =>
                      i.id === toppingItemId ? { ...i, is_active: input.is_active } : i,
                    ),
                  })),
                },
          ),
        },
      });

      return { previous };
    },

    onError: (e: Error, _vars, ctx) => {
      if (ctx?.previous) qc.setQueryData(key, ctx.previous);
      toast.error(e.message || t("menu_availability.toast.failed"));
    },

    onSuccess: (_res, { input }) => {
      toast.success(
        input.is_active
          ? t("menu_availability.toast.topping_on")
          : t("menu_availability.toast.topping_off"),
      );
    },

    onSettled: () => {
      qc.invalidateQueries({ queryKey: menuAvailabilityKeys.all(shopSlug) });
      invalidateOrderingViews(qc, shopSlug);
    },
  });
}

/**
 * "Hết cỡ Lớn" — turn every variant carrying one option value on or off.
 *
 * No optimistic patch, same reason as the section bulk: the server reports how
 * many rows ACTUALLY moved, and guessing that number here would put a figure in
 * front of staff that the next refetch contradicts.
 */
export function useBulkSkuAvailability(shopSlug: string, menuId: string | null) {
  const qc = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({
      menuProductSkuIds,
      input,
    }: {
      menuProductSkuIds: string[];
      input: SetAvailabilityInput;
    }) =>
      menuAvailabilityService.bulkSetSkuAvailability(
        menuId as string,
        menuProductSkuIds,
        input,
      ),

    onSuccess: (result, { input }) => {
      toast.success(
        t(
          input.is_active
            ? "menu_availability.toast.option_on"
            : "menu_availability.toast.option_off",
          { count: result.updated },
        ),
      );
    },

    onError: (e: Error) => toast.error(e.message || t("menu_availability.toast.failed")),

    onSettled: () => {
      qc.invalidateQueries({ queryKey: menuAvailabilityKeys.all(shopSlug) });
      invalidateOrderingViews(qc, shopSlug);
    },
  });
}

export function useBulkSectionAvailability(shopSlug: string, menuId: string | null) {
  const qc = useQueryClient();
  const { t } = useTranslation();

  return useMutation({
    mutationFn: ({
      sectionId,
      input,
    }: {
      sectionId: string;
      input: SetAvailabilityInput;
    }) =>
      menuAvailabilityService.bulkSetSectionAvailability(
        menuId as string,
        sectionId,
        input,
      ),

    // No optimistic patch here on purpose. A bulk toggle can touch forty rows,
    // and the server reports how many ACTUALLY changed — a number the optimistic
    // path cannot know without duplicating the comparison. Guessing it would put
    // a number in front of staff that the next refetch contradicts.
    onSuccess: (result, { input }) => {
      toast.success(
        t(
          input.is_active
            ? "menu_availability.toast.section_on"
            : "menu_availability.toast.section_off",
          { count: result.updated },
        ),
      );
    },

    onError: (e: Error) => toast.error(e.message || t("menu_availability.toast.failed")),

    onSettled: () => {
      qc.invalidateQueries({ queryKey: menuAvailabilityKeys.all(shopSlug) });
      invalidateOrderingViews(qc, shopSlug);
    },
  });
}
