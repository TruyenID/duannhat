"use client";

import { Card } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Label } from "@godxjp/ui";
import type { TranslatableValue } from "@godxjp/ui";
import { TranslatableRichText } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { currencySymbol, currencySymbolPosition } from "@/lib/currency";

/**
 * Shared "Thông tin cơ bản" card for Product new + edit pages.
 * Edit page can hide the base-sku/price row by passing `showBaseSkuPrice={false}`
 * (SKUs are managed in the SKUs tab there).
 */

export interface BasicInfoCardProps {
  name: TranslatableValue;
  onNameChange: (v: TranslatableValue) => void;
  slug: string;
  onSlugChange: (v: string) => void;
  description: TranslatableValue;
  onDescriptionChange: (v: TranslatableValue) => void;

  /** Whether to render the base SKU + cost-price row. Edit page disables it. */
  showBaseSkuPrice?: boolean;
  hasVariants?: boolean;
  baseSku?: string;
  onBaseSkuChange?: (v: string) => void;
  basePrice?: string;
  onBasePriceChange?: (v: string) => void;

  nameError?: string;
  descriptionError?: string;
  slugError?: string;
  basePriceError?: string;

  disabled?: boolean;
}

export function BasicInfoCard({
  name,
  onNameChange,
  slug,
  onSlugChange,
  description,
  onDescriptionChange,
  showBaseSkuPrice = false,
  hasVariants = false,
  baseSku = "",
  onBaseSkuChange,
  basePrice = "",
  onBasePriceChange,
  nameError,
  descriptionError,
  slugError,
  basePriceError,
  disabled = false,
}: BasicInfoCardProps) {
  const { t, locale } = useTranslation();
  // ¥ sits before the number, ₫ after it — the adornment has to move with it.
  const symbolFirst = currencySymbolPosition(locale) === "prefix";
  const showBaseRow = showBaseSkuPrice && !hasVariants;

  return (
    <Card className="p-4" data-slot="product-basic-info-card">
      <div className="mb-4 text-sm font-semibold">{t("hq.products.basic_info.title")}</div>
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="product-name" className="text-xs font-medium text-muted-foreground">
            {t("hq.products.basic_info.name")}
          </Label>
          <Input
            translatable
            id="product-name"
            value={name}
            onChange={onNameChange}
            placeholder="vd: Bít tết cá hồi nướng"
            className="h-9"
            disabled={disabled}
          />
          {nameError && <p className="text-[11px] text-red-500">{nameError}</p>}
        </div>
        <div className="flex flex-col gap-1.5">
          <Label htmlFor="product-slug" className="text-xs font-medium text-muted-foreground">
            (Slug)
          </Label>
          <Input
            id="product-slug"
            value={slug}
            onChange={(e) => onSlugChange(e.target.value)}
            placeholder="vd: bit-tet-ca-hoi"
            className="h-9 font-mono text-xs"
            disabled={disabled}
          />
          {slugError && <p className="text-[11px] text-red-500">{slugError}</p>}
        </div>

        <div className="flex flex-col gap-1.5">
          <Label className="text-xs font-medium text-muted-foreground">
            {t("hq.products.basic_info.description")}
          </Label>
          <TranslatableRichText value={description} onChange={onDescriptionChange} />
          {descriptionError && <p className="text-[11px] text-red-500">{descriptionError}</p>}
        </div>

        {showBaseRow && (
          <div className="grid grid-cols-2 gap-4 border-t border-dashed pt-4">
            <div className="flex flex-col gap-1.5">
              <label className="text-xs font-medium text-muted-foreground">
                {t("hq.products.basic_info.base_sku")}
              </label>
              <Input
                value={baseSku}
                onChange={(e) => onBaseSkuChange?.(e.target.value)}
                placeholder="vd: PROD-001"
                className="h-9"
                disabled={disabled}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <label className="text-xs font-medium text-muted-foreground">
                {t("hq.products.basic_info.cost_price")}
                <span className="ml-0.5 text-destructive">*</span>
              </label>
              <div className="relative">
                <Input
                  type="number"
                  min="0"
                  step="any"
                  value={basePrice}
                  onChange={(e) => onBasePriceChange?.(e.target.value)}
                  placeholder="0"
                  className={symbolFirst ? "h-9 pl-8" : "h-9 pr-8"}
                  disabled={disabled}
                  aria-invalid={basePriceError ? true : undefined}
                />
                <span
                  className={`absolute top-1/2 -translate-y-1/2 text-xs text-muted-foreground ${
                    symbolFirst ? "left-3" : "right-3"
                  }`}
                >
                  {currencySymbol(locale)}
                </span>
              </div>
              {basePriceError && <p className="text-[11px] text-red-500">{basePriceError}</p>}
            </div>
          </div>
        )}
      </div>
    </Card>
  );
}
