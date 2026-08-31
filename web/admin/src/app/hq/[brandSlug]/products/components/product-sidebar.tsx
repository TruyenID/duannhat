"use client";

import { useState } from "react";
import { Check, ChevronsUpDown, X } from "lucide-react";
import { Badge } from "@godxjp/ui";
import { Card } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@godxjp/ui";
import { Label } from "@godxjp/ui";
import { Popover, PopoverContent, PopoverTrigger } from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";
import { cn } from "@/lib/utils";
import type { ProductStatus } from "@/services/product-service";
import type { TaxTypeLookupItem } from "@/services/tax-type-service";
import { useTranslation } from "@/providers/app-provider";
import { ProductStatusBadge } from "./product-status-badge";

/**
 * Shared right-sidebar cards for Product new + edit pages:
 * Trạng thái phát hành / Loại sản phẩm / Danh mục menu.
 */

export interface LookupItem {
  id: string;
  /** Optional product-type code — used to detect combos ('combo'). */
  code?: string | null;
  name: string;
}

// Radix Select can't use "" as an item value, so `null` (inherit) is
// represented by this sentinel — mirrors the shop settings tax picker.
const TAX_INHERIT_SENTINEL = "__none__";

export interface ProductSidebarProps {
  /**
   * "create" → render the Draft notice (status is locked on the BE).
   * "edit"   → render the current status as a read-only badge; transitions
   *            are triggered by workflow buttons in the page header, not a
   *            picker, so PUT /products/{id} never mutates status.
   */
  mode?: "create" | "edit";
  status: ProductStatus;
  isHidden: boolean;
  onIsHiddenChange: (v: boolean) => void;

  productTypeId: string;
  onProductTypeIdChange: (v: string) => void;
  productTypes: LookupItem[];
  productTypesLoading?: boolean;

  categoryIds: string[];
  onCategoryIdsChange: (ids: string[]) => void;
  categories: LookupItem[];
  categoriesLoading?: boolean;

  /**
   * plan-043 — per-product tax assignment. `taxTypeId` null = inherit the
   * brand default. Special-rate products (e.g. alcohol) are configured by
   * assigning the appropriate tax type here — there is no dedicated flag.
   */
  taxTypeId: string | null;
  onTaxTypeIdChange: (v: string | null) => void;
  taxTypes: TaxTypeLookupItem[];
  taxTypesLoading?: boolean;
  taxTypeError?: string;

  productTypeError?: string;
  disabled?: boolean;
  /** Set false to omit the status card — caller renders it separately at desired position. */
  showStatus?: boolean;
}

export function ProductSidebar({
  mode = "edit",
  status,
  isHidden,
  onIsHiddenChange,
  productTypeId,
  onProductTypeIdChange,
  productTypes,
  productTypesLoading,
  categoryIds,
  onCategoryIdsChange,
  categories,
  categoriesLoading,
  taxTypeId,
  onTaxTypeIdChange,
  taxTypes,
  taxTypesLoading,
  taxTypeError,
  productTypeError,
  disabled = false,
  showStatus = true,
}: ProductSidebarProps) {
  const { t } = useTranslation();

  // Combo detection — the selected product type's `code` is the canonical
  // combo marker (lowercase 'combo'; see BrandCoreCatalogService::ensureCombo).
  const isCombo =
    productTypes.find((pt) => pt.id === productTypeId)?.code?.toLowerCase() === "combo";

  return (
    <div className="flex flex-col gap-4" data-slot="product-sidebar">
      {/* Classification — required field, rendered first */}
      <Card className="p-4">
        <div className="mb-3 text-sm font-semibold">{t("hq.products.sidebar.classification")}</div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="product-type" className="text-xs font-medium text-muted-foreground">
            {t("hq.products.sidebar.product_type")}
          </Label>
          <Select
            value={productTypeId}
            onValueChange={(v) => onProductTypeIdChange(v ?? "")}
            disabled={disabled || productTypesLoading}
          >
            <SelectTrigger className="h-9 w-full">
              <SelectValue
                placeholder={
                  productTypesLoading ? t("common.loading") : t("hq.products.sidebar.select_type")
                }
              />
            </SelectTrigger>
            <SelectContent>
              {productTypes.map((pt) => (
                <SelectItem key={pt.id} value={pt.id}>
                  {pt.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {productTypeError && <p className="text-[11px] text-red-500">{productTypeError}</p>}
        </div>

        {/* plan-043 — tax type: null (inherit) is the default, mapped to the
            "__none__" sentinel because Radix Select can't use "". */}
        <div className="mt-3 flex flex-col gap-1.5">
          <Label htmlFor="product-tax-type" className="text-xs font-medium text-muted-foreground">
            {t("hq.products.sidebar.tax_type")}
          </Label>
          <Select
            value={taxTypeId ?? TAX_INHERIT_SENTINEL}
            onValueChange={(v) => onTaxTypeIdChange(v === TAX_INHERIT_SENTINEL ? null : v)}
            disabled={disabled || taxTypesLoading}
          >
            <SelectTrigger
              id="product-tax-type"
              className="h-9 w-full"
              aria-invalid={taxTypeError ? true : undefined}
            >
              <SelectValue placeholder={taxTypesLoading ? t("common.loading") : undefined} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={TAX_INHERIT_SENTINEL}>
                {t("hq.products.sidebar.tax_type_inherit")}
              </SelectItem>
              {taxTypes.map((tt) => (
                <SelectItem key={tt.id} value={tt.id}>
                  {tt.name} ·{" "}
                  {t("hq.tax_types.rate_display", { rate: String(tt.rate) })}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {taxTypeError ? (
            <p className="text-[11px] text-red-500">{taxTypeError}</p>
          ) : (
            <p className="text-[11px] text-muted-foreground">
              {t("hq.products.sidebar.tax_type_hint")}
            </p>
          )}
        </div>

        {/* plan-043 — combo (一体資産) tax hint. Rates are never escalated
            automatically; this static reminder tells the operator to assign
            the correct tax type themselves whenever the type is a combo. */}
        {isCombo && (
          <p className="mt-3 rounded-md border border-amber-300 bg-amber-50 px-2.5 py-2 text-[11px] leading-relaxed text-amber-900">
            {t("hq.products.sidebar.combo_tax_hint")}
          </p>
        )}
      </Card>

      {/* Categories */}
      <Card className="p-4">
        <div className="mb-3 text-sm font-semibold">{t("hq.products.sidebar.menu_categories")}</div>
        <CategoryMultiSelect
          categories={categories}
          categoriesLoading={categoriesLoading}
          selectedIds={categoryIds}
          onChange={onCategoryIdsChange}
          disabled={disabled}
        />
      </Card>

      {/* Status — least interactive (workflow-driven), rendered last or omitted */}
      {showStatus && (
        <Card className="p-4">
          <div className="mb-3 text-sm font-semibold">
            {t("hq.products.sidebar.publish_status")}
          </div>
          <ProductStatusBadge status={status} variant="panel" />
          <div className="mt-2 border-t pt-2">
            <Label
              htmlFor="product-hidden-toggle"
              className="flex w-full cursor-pointer items-center justify-between gap-2 text-xs"
            >
              <span className="text-muted-foreground">
                {t("hq.products.sidebar.hide_from_menu")}
              </span>
              <Checkbox
                id="product-hidden-toggle"
                checked={isHidden}
                onCheckedChange={(v) => onIsHiddenChange(v === true)}
                disabled={disabled}
              />
            </Label>
          </div>
        </Card>
      )}
    </div>
  );
}

interface CategoryMultiSelectProps {
  categories: LookupItem[];
  categoriesLoading?: boolean;
  selectedIds: string[];
  onChange: (ids: string[]) => void;
  disabled?: boolean;
}

function CategoryMultiSelect({
  categories,
  categoriesLoading,
  selectedIds,
  onChange,
  disabled,
}: CategoryMultiSelectProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);

  if (categoriesLoading) {
    return (
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <Spinner className="size-3.5" /> {t("common.loading")}
      </div>
    );
  }

  if (categories.length === 0) {
    return (
      <div className="text-xs text-muted-foreground italic">
        {t("hq.products.sidebar.no_categories")}
      </div>
    );
  }

  const selected = categories.filter((c) => selectedIds.includes(c.id));

  function toggle(id: string) {
    onChange(selectedIds.includes(id) ? selectedIds.filter((x) => x !== id) : [...selectedIds, id]);
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <div
          role="combobox"
          aria-expanded={open}
          aria-disabled={disabled}
          tabIndex={disabled ? -1 : 0}
          onClick={() => !disabled && setOpen((v) => !v)}
          onKeyDown={(e) => {
            if ((e.key === "Enter" || e.key === " ") && !disabled) {
              e.preventDefault();
              setOpen((v) => !v);
            }
          }}
          data-slot="category-multi-select"
          className={cn(
            "flex min-h-9 w-full cursor-pointer items-start rounded-md border border-input bg-background px-2 py-1.5 text-sm shadow-xs ring-offset-background select-none",
            "focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none",
            open && "ring-2 ring-ring ring-offset-2",
            disabled && "cursor-not-allowed opacity-50"
          )}
        >
          <div className="flex flex-1 flex-wrap gap-1">
            {selected.length === 0 ? (
              <span className="px-0.5 text-xs text-muted-foreground">
                {t("hq.products.sidebar.select_categories_placeholder")}
              </span>
            ) : (
              selected.map((c) => (
                <Badge
                  key={c.id}
                  variant="secondary"
                  className="gap-1 px-1.5 py-0.5 text-xs font-normal"
                >
                  {c.name}
                  <span
                    role="button"
                    tabIndex={0}
                    aria-label={`Remove ${c.name}`}
                    onClick={(e) => {
                      e.stopPropagation();
                      toggle(c.id);
                    }}
                    onKeyDown={(e) => {
                      if (e.key === "Enter" || e.key === " ") {
                        e.preventDefault();
                        e.stopPropagation();
                        toggle(c.id);
                      }
                    }}
                    className="flex size-3.5 cursor-pointer items-center justify-center rounded-sm hover:bg-muted-foreground/20"
                  >
                    <X className="size-3" />
                  </span>
                </Badge>
              ))
            )}
          </div>
          <ChevronsUpDown className="mt-0.5 ml-2 size-4 shrink-0 opacity-50" />
        </div>
      </PopoverTrigger>
      <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
        <Command>
          <CommandInput placeholder={t("hq.products.sidebar.search_categories")} />
          <CommandList>
            <CommandEmpty>{t("common.no_results")}</CommandEmpty>
            <CommandGroup>
              {categories.map((c) => {
                const isSelected = selectedIds.includes(c.id);
                return (
                  <CommandItem key={c.id} value={c.name} onSelect={() => toggle(c.id)}>
                    <Check
                      className={cn("mr-2 size-4", isSelected ? "opacity-100" : "opacity-0")}
                    />
                    {c.name}
                  </CommandItem>
                );
              })}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
