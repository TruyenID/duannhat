/**
 * plan-056 — "Tồn món": turn dishes and variants on/off at this shop.
 *
 * ## What this screen is, and what it is NOT
 *
 * It is the shop's stock switchboard: something ran out, take it off the menu
 * now, put it back when the delivery lands. It is NOT a menu editor — HQ owns
 * which dishes exist and what they cost, and there is no control here that
 * writes either. Prices are rendered read-only so a cashier can tell one
 * variant from another.
 *
 * ## The ordering screen is untouched
 *
 * This is a separate route, hitting a separate API namespace, keyed by a
 * separate React Query root. The sales screen keeps calling `/pos/menus/*` and
 * keeps receiving exactly what it received before this feature existed. The
 * ONE thing that crosses over is intended: turning a dish off here removes it
 * from the cart picker, because that is the entire point.
 *
 * ## Works with the internet down
 *
 * On a workstation-backed shop every read and write here is LAN-local; the
 * workstation queues the change and replays it to Cloud when the link returns.
 * A shop that loses its internet mid-service can still take the sold-out dish
 * off the menu — which is exactly when it most needs to.
 */

import { useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { ArrowLeft, Search, UtensilsCrossed, X } from "lucide-react";
import {
  Button,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
} from "@godxjp/ui";
import { PosHeader } from "@/app/pos/components/pos-header";
import { useTranslation } from "@/providers/app-provider";
import { useShop } from "@/hooks/api/use-shop";
import {
  useAvailabilityActor,
  useAvailabilityMenu,
  useAvailabilityMenus,
  useBulkSectionAvailability,
  useSetProductAvailability,
  useSetSkuAvailability,
  useSetToppingAvailability,
  useBulkSkuAvailability,
} from "@/hooks/api/use-menu-availability";
import type {
  AvailabilityProduct,
  AvailabilitySku,
  AvailabilityToppingItem,
} from "@/services/menu-availability-service";
import {
  AvailabilitySectionPanel,
  type AvailabilitySectionGroup,
} from "./components/section-panel";
import { DisableReasonDialog } from "./components/disable-reason-dialog";
import type { OptionValueGroup } from "./components/option-value-switches";

/** What the reason dialog is about to turn off. */
type DisableTarget =
  | { kind: "product"; product: AvailabilityProduct }
  | { kind: "sku"; product: AvailabilityProduct; sku: AvailabilitySku }
  | { kind: "topping"; product: AvailabilityProduct; item: AvailabilityToppingItem }
  // "Hết cỡ Lớn" — the value names a SET of variant rows, so the target
  // carries the ids resolved on screen rather than the value itself. A replay
  // hours later must land on exactly the variants the operator was looking at.
  | {
      kind: "option";
      product: AvailabilityProduct;
      valueId: string;
      label: string;
      skuIds: string[];
    }
  | { kind: "section"; section: AvailabilitySectionGroup };

const UNGROUPED_SECTION_ID = "__ungrouped__";

export default function MenuAvailabilityPage() {
  const { shopSlug = "" } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();
  const navigate = useNavigate();

  // `useShop` hands back the raw envelope (`{ data: ShopDetail }`), so the
  // shop itself is one level in — same unwrap the revenue screen does.
  const { data: shopResponse } = useShop(shopSlug);
  const shop = shopResponse?.data;
  const menusQuery = useAvailabilityMenus(shopSlug);
  const menus = menusQuery.data ?? [];

  const [selectedMenuId, setSelectedMenuId] = useState<string | null>(null);
  // Auto-pick the first menu once the list lands, so the screen is never a
  // dropdown over an empty page. An explicit choice always wins.
  const activeMenuId = selectedMenuId ?? menus[0]?.id ?? null;

  const menuQuery = useAvailabilityMenu(shopSlug, activeMenuId);
  const detail = menuQuery.data ?? null;

  const [search, setSearch] = useState("");
  const [onlyOff, setOnlyOff] = useState(false);

  const actor = useAvailabilityActor(shopSlug);
  const setProduct = useSetProductAvailability(shopSlug, activeMenuId);
  const setSku = useSetSkuAvailability(shopSlug, activeMenuId);
  const setTopping = useSetToppingAvailability(shopSlug, activeMenuId);
  const bulkSkus = useBulkSkuAvailability(shopSlug, activeMenuId);
  const bulk = useBulkSectionAvailability(shopSlug, activeMenuId);

  const [disableTarget, setDisableTarget] = useState<DisableTarget | null>(null);

  // Which row is mid-write, so only ITS switch locks. Disabling the whole list
  // during one write would make a shop turning off five dishes wait five times.
  const pendingProductId =
    setProduct.isPending && setProduct.variables
      ? setProduct.variables.menuProductId
      : null;
  const pendingSkuId =
    setSku.isPending && setSku.variables ? setSku.variables.menuProductSkuId : null;
  const pendingToppingId =
    setTopping.isPending && setTopping.variables
      ? setTopping.variables.toppingItemId
      : null;
  const pendingSectionId =
    bulk.isPending && bulk.variables ? bulk.variables.sectionId : null;

  const sections = useMemo<AvailabilitySectionGroup[]>(() => {
    if (!detail) return [];

    const needle = search.trim().toLowerCase();
    const matches = (p: AvailabilityProduct) => {
      if (onlyOff && p.is_active) return false;
      if (!needle) return true;
      const name = (p.product?.name ?? "").toLowerCase();
      if (name.includes(needle)) return true;

      // Search variant labels and SKU codes too: a cashier holding a package
      // types the barcode, not the dish name. Mirrors what the ordering
      // search does, so the two screens find the same things.
      if (
        p.skus.some(
          (s) =>
            (s.sku ?? "").toLowerCase().includes(needle) ||
            (s.variant_label ?? "").toLowerCase().includes(needle) ||
            (s.name ?? "").toLowerCase().includes(needle),
        )
      ) {
        return true;
      }

      // …and topping names. "hết trứng chần" is a thing a cashier says about a
      // TOPPING, and they will type that word, not the dish it hangs off.
      return (p.topping_groups ?? []).some((g) =>
        g.items.some((i) => i.name.toLowerCase().includes(needle)),
      );
    };

    const byId = new Map<string, AvailabilitySectionGroup>();
    for (const s of detail.sections) {
      byId.set(s.id, { id: s.id, name: s.name, products: [] });
    }

    const ungrouped: AvailabilityProduct[] = [];
    for (const product of detail.products) {
      if (!matches(product)) continue;

      const sectionId = product.menu_section_id;
      if (!sectionId) {
        ungrouped.push(product);
        continue;
      }
      let group = byId.get(sectionId);
      if (!group) {
        // A section the menu payload did not list (HQ renamed or removed it
        // between our two reads). Show the dishes under the name the row
        // itself carries rather than dropping them — an invisible dish is one
        // the shop cannot turn off.
        group = {
          id: sectionId,
          name: product.section?.name ?? t("menu_availability.unknown_section"),
          products: [],
        };
        byId.set(sectionId, group);
      }
      group.products.push(product);
    }

    const ordered: AvailabilitySectionGroup[] = [];
    if (ungrouped.length > 0) {
      ordered.push({
        id: UNGROUPED_SECTION_ID,
        name: t("menu_availability.ungrouped_section"),
        products: ungrouped,
      });
    }
    for (const group of byId.values()) {
      if (group.products.length > 0) ordered.push(group);
    }

    return ordered;
  }, [detail, search, onlyOff, t]);

  const offCount = useMemo(
    () => (detail?.products ?? []).filter((p) => !p.is_active).length,
    [detail],
  );

  // ---- write handlers -----------------------------------------------------
  //
  // Turning something ON is one tap — there is nothing to explain, and asking
  // would double the taps on the action staff take most (the delivery arrived).
  // Turning something OFF always goes through the reason dialog.

  const handleToggleProduct = (product: AvailabilityProduct, next: boolean) => {
    if (next) {
      setProduct.mutate({
        menuProductId: product.id,
        input: { is_active: true, ...actor },
      });

      return;
    }
    setDisableTarget({ kind: "product", product });
  };

  const handleToggleSku = (
    product: AvailabilityProduct,
    sku: AvailabilitySku,
    next: boolean,
  ) => {
    if (sku.menu_product_sku_id == null) return;
    if (next) {
      setSku.mutate({
        menuProductSkuId: sku.menu_product_sku_id,
        input: { is_active: true, ...actor },
      });

      return;
    }
    setDisableTarget({ kind: "sku", product, sku });
  };

  const handleToggleTopping = (
    product: AvailabilityProduct,
    item: AvailabilityToppingItem,
    next: boolean,
  ) => {
    if (next) {
      setTopping.mutate({
        menuProductId: product.id,
        toppingItemId: item.id,
        input: { is_active: true, ...actor },
      });

      return;
    }
    setDisableTarget({ kind: "topping", product, item });
  };

  const handleToggleOptionValue = (
    product: AvailabilityProduct,
    group: OptionValueGroup,
    next: boolean,
  ) => {
    // Only the variants that HAVE a write address travel. One HQ-added variant
    // with no pivot row must not fail the batch for the others.
    if (group.writableIds.length === 0) return;
    if (next) {
      bulkSkus.mutate({
        menuProductSkuIds: group.writableIds,
        input: { is_active: true, ...actor },
      });

      return;
    }
    setDisableTarget({
      kind: "option",
      product,
      valueId: group.valueId,
      label: group.valueLabel,
      skuIds: group.writableIds,
    });
  };

  const handleBulk = (section: AvailabilitySectionGroup, next: boolean) => {
    if (section.id === UNGROUPED_SECTION_ID) return;
    if (next) {
      bulk.mutate({ sectionId: section.id, input: { is_active: true, ...actor } });

      return;
    }
    setDisableTarget({ kind: "section", section });
  };

  const confirmDisable = (reason: string) => {
    const target = disableTarget;
    if (!target) return;
    const input = { is_active: false, reason, ...actor };

    if (target.kind === "product") {
      setProduct.mutate({ menuProductId: target.product.id, input });
    } else if (target.kind === "sku" && target.sku.menu_product_sku_id) {
      setSku.mutate({ menuProductSkuId: target.sku.menu_product_sku_id, input });
    } else if (target.kind === "topping") {
      setTopping.mutate({
        menuProductId: target.product.id,
        toppingItemId: target.item.id,
        input,
      });
    } else if (target.kind === "option") {
      bulkSkus.mutate({ menuProductSkuIds: target.skuIds, input });
    } else if (target.kind === "section") {
      bulk.mutate({ sectionId: target.section.id, input });
    }
    setDisableTarget(null);
  };

  const disableTargetName =
    disableTarget?.kind === "product"
      ? (disableTarget.product.product?.name ?? "")
      : disableTarget?.kind === "sku"
        ? `${disableTarget.product.product?.name ?? ""} · ${disableTarget.sku.variant_label ?? disableTarget.sku.name ?? disableTarget.sku.sku ?? ""}`
        : disableTarget?.kind === "topping"
          ? disableTarget.item.name
          : disableTarget?.kind === "option"
            ? `${disableTarget.product.product?.name ?? ""} · ${disableTarget.label}`
            : (disableTarget?.section.name ?? "");

  const disableAffectedCount =
    disableTarget?.kind === "section"
      ? disableTarget.section.products.filter((p) => p.is_active).length
      : null;

  // Which option value is mid-write, so its switch alone locks rather than the
  // whole strip. `variables` is the request in flight; TanStack clears it on
  // settle, so there is no state to reset by hand.
  const pendingOptionValueId =
    bulkSkus.isPending && disableTarget?.kind === "option" ? disableTarget.valueId : null;

  const isWritePending =
    setProduct.isPending ||
    setSku.isPending ||
    setTopping.isPending ||
    bulkSkus.isPending ||
    bulk.isPending;

  return (
    <div className="flex h-full flex-col bg-muted/20">
      <PosHeader
        shopName={shop?.name ?? ""}
        helpTopic="menu-availability"
        breadcrumb={{
          parent: t("menu_availability.breadcrumb_parent"),
          current: t("menu_availability.title"),
        }}
      />

      <div className="border-b bg-card">
        <div className="mx-auto flex max-w-[1600px] flex-wrap items-center gap-3 px-3 py-3 sm:px-6">
          {/* Back to the sales screen.

              An ABSOLUTE destination, not `navigate(-1)`, matching the revenue
              and order-history screens. A POS tablet gets reloaded and
              deep-linked constantly, and after a reload there is no in-app
              history to go back TO — `-1` then either does nothing or walks the
              cashier out of pos-web entirely. This screen is reached from one
              dropdown item on the sales screen, so "the screen before" and
              "the sales screen" are the same place in every case that matters.

              First in the wrap order, so it holds the top-left corner at every
              width — the same corner the back button occupies on the other
              sub-screens, which is what makes it findable without reading. */}
          <Button
            variant="outline"
            onClick={() => navigate(`/shop/${shopSlug}`)}
            // h-11, not the h-9 that reports use: it sits in a row of h-11
            // touch controls here, and a shorter neighbour reads as misaligned
            // as well as being the smallest target in the row.
            className="h-11 gap-1.5 px-3 shadow-sm"
            data-testid="availability-back"
          >
            <ArrowLeft className="size-4" />
            <span>{t("common.back")}</span>
          </Button>
          <div className="h-6 w-px bg-border" />

          <Select
            value={activeMenuId ?? ""}
            onValueChange={(v: string) => setSelectedMenuId(v)}
            disabled={menusQuery.isLoading || menus.length === 0}
          >
            <SelectTrigger className="h-11 w-56" data-testid="availability-menu-picker">
              <SelectValue placeholder={t("menu_availability.pick_menu")} />
            </SelectTrigger>
            <SelectContent>
              {menus.map((m) => (
                <SelectItem key={m.id} value={m.id}>
                  {m.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <div className="relative min-w-56 flex-1">
            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              value={search}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                setSearch(e.target.value)
              }
              placeholder={t("menu_availability.search_placeholder")}
              className="h-11 pr-9 pl-9"
              data-testid="availability-search"
            />
            {search && (
              <button
                type="button"
                onClick={() => setSearch("")}
                aria-label={t("common.clear")}
                className="absolute top-1/2 right-2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
              >
                <X className="size-4" />
              </button>
            )}
          </div>

          <label className="flex h-11 items-center gap-2 text-sm">
            <Switch
              checked={onlyOff}
              onCheckedChange={setOnlyOff}
              aria-label={t("menu_availability.only_off")}
              data-testid="availability-only-off"
            />
            <span className="whitespace-nowrap">
              {t("menu_availability.only_off")}
              {offCount > 0 && (
                <span className="ml-1 rounded bg-destructive/10 px-1.5 py-0.5 text-[11px] font-medium text-destructive tabular-nums">
                  {offCount}
                </span>
              )}
            </span>
          </label>

          {(menuQuery.isFetching || isWritePending) && (
            <Spinner className="size-4 text-muted-foreground" />
          )}
        </div>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto">
        <div className="mx-auto max-w-[1600px] px-3 py-4 sm:px-6">
          {menuQuery.isLoading && (
            <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground">
              <Spinner className="size-5" />
              {t("common.loading")}
            </div>
          )}

          {!menuQuery.isLoading && menus.length === 0 && (
            <EmptyState text={t("menu_availability.no_menus")} />
          )}

          {!menuQuery.isLoading && menus.length > 0 && sections.length === 0 && (
            <EmptyState
              text={
                search || onlyOff
                  ? t("menu_availability.no_matches")
                  : t("menu_availability.no_products")
              }
            />
          )}

          {sections.map((section) => (
            <AvailabilitySectionPanel
              key={section.id}
              section={section}
              isBulkPending={pendingSectionId === section.id}
              pendingProductId={pendingProductId}
              pendingSkuId={pendingSkuId}
              pendingToppingId={pendingToppingId}
              pendingOptionValueId={pendingOptionValueId}
              onBulk={handleBulk}
              onToggleProduct={handleToggleProduct}
              onToggleSku={handleToggleSku}
              onToggleTopping={handleToggleTopping}
              onToggleOptionValue={handleToggleOptionValue}
            />
          ))}
        </div>
      </div>

      <DisableReasonDialog
        open={disableTarget !== null}
        onOpenChange={(open) => {
          if (!open) setDisableTarget(null);
        }}
        targetName={disableTargetName}
        affectedCount={disableAffectedCount}
        isPending={isWritePending}
        onConfirm={confirmDisable}
      />
    </div>
  );
}

function EmptyState({ text }: { text: string }) {
  return (
    <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-16 text-center">
      <UtensilsCrossed className="size-8 text-muted-foreground/40" />
      <p className="mt-3 text-sm text-muted-foreground">{text}</p>
    </div>
  );
}
