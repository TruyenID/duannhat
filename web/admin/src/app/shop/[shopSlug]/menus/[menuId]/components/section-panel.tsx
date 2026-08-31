"use client";

import { FolderOpen, Power, PowerOff } from "lucide-react";
import { Badge, Button, Spinner } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useBulkToggleSectionProducts } from "@/hooks/api/use-shop-menus";
import type { SectionGroup, ViewMode } from "./types";
import { MenuProductRow } from "./menu-product-row";
import { MenuProductCard } from "./menu-product-card";

export interface SectionPanelProps {
  section: SectionGroup;
  shopSlug: string;
  menuId: string;
  view: ViewMode;
  /**
   * #1227 — this section's OWN tax rate for this menu (tier 2), or null when it
   * sets none and its lines fall through to the menu. Read-only: HQ owns tax
   * (#1226). Undefined on responses that do not carry the map.
   */
  taxRate?: number | null;
}

export function SectionPanel({ section, shopSlug, menuId, view, taxRate }: SectionPanelProps) {
  const { t } = useTranslation();
  const bulkToggle = useBulkToggleSectionProducts(shopSlug, menuId);

  const activeCount = section.products.filter((mp) => mp.is_active).length;
  const total = section.products.length;
  const allOn = total > 0 && activeCount === total;
  const allOff = activeCount === 0;

  const runBulk = (isActive: boolean) => {
    if (bulkToggle.isPending || total === 0) return;
    bulkToggle.mutate({ sectionId: section.id, isActive });
  };

  return (
    <div className="mb-4 flex flex-col gap-2">
      <div className="flex items-center gap-2">
        <FolderOpen className="size-3.5 text-muted-foreground" />
        <span className="text-sm font-medium text-foreground">{section.name}</span>
        <Badge variant="outline" className="text-[10px] font-medium">
          {section.products.length}
        </Badge>
        <span className="text-[10px] text-muted-foreground">
          {t("shop.menu.detail.section_active_count", { active: activeCount, total })}
        </span>
        {/* #1227 — shown on every section, set or not, so the shop can read the
            tier itself rather than infer it from the lines. Unset says exactly
            what HQ's own select says for the same state ("inherit (menu)"),
            because the shop is reading back a decision HQ made and two names for
            one state is how you get asked whether they are the same thing. It
            deliberately carries NO number: the lines under one section can sit at
            different rates, so a figure here would misstate the tier. */}
        <Badge
          variant="outline"
          className="text-[10px] font-normal tabular-nums"
          title={t("shop.menu.detail.tax_readonly_hint")}
        >
          {taxRate != null
            ? t("shop.menu.detail.tax_section_badge", { rate: taxRate })
            : t("shop.menu.detail.tax_section_inherit")}
        </Badge>
        <div className="ml-auto flex items-center gap-1">
          {bulkToggle.isPending && <Spinner className="size-3" />}
          <Button
            variant="ghost"
            size="sm"
            className="h-6 gap-1 px-2 text-[11px]"
            onClick={() => runBulk(true)}
            disabled={bulkToggle.isPending || total === 0 || allOn}
          >
            <Power className="size-3" />
            {t("shop.menu.detail.section_bulk_on")}
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="h-6 gap-1 px-2 text-[11px] text-muted-foreground"
            onClick={() => runBulk(false)}
            disabled={bulkToggle.isPending || total === 0 || allOff}
          >
            <PowerOff className="size-3" />
            {t("shop.menu.detail.section_bulk_off")}
          </Button>
        </div>
      </div>

      {view === "list" ? (
        <div className="flex min-h-20 flex-col gap-1.5 rounded-lg border border-dashed border-border bg-muted/20 p-2.5">
          {section.products.map((mp) => (
            <MenuProductRow key={mp.id} menuProduct={mp} shopSlug={shopSlug} menuId={menuId} />
          ))}
          {section.products.length === 0 && (
            <div className="flex items-center justify-center py-4 text-xs text-muted-foreground">
              {t("shop.menu.detail.no_products_in_section")}
            </div>
          )}
        </div>
      ) : (
        <div className="min-h-20 rounded-lg border border-dashed border-border bg-muted/20 p-2.5">
          {section.products.length === 0 ? (
            <div className="flex items-center justify-center py-4 text-xs text-muted-foreground">
              {t("shop.menu.detail.no_products_in_section")}
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
              {section.products.map((mp) => (
                <MenuProductCard key={mp.id} menuProduct={mp} shopSlug={shopSlug} menuId={menuId} />
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}
