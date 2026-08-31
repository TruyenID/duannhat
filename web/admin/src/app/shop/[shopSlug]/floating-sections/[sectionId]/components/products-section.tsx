"use client";

import { useState } from "react";
import Image from "next/image";
import {
  Alert,
  AlertDescription,
  AlertTitle,
  Badge,
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
  Input,
  Skeleton,
  Spinner,
  StatusBadge,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@godxjp/ui";
import {
  ChevronDown,
  ChevronRight,
  EllipsisVertical,
  ImageIcon,
  Pencil,
  Power,
  RotateCcw,
} from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import {
  useShopFloatingSection,
  useToggleShopFloatingSectionProduct,
  useToggleShopFloatingSectionProductSku,
  useOverrideShopFloatingSectionSkuPrice,
  useResetShopFloatingSectionSkuPrice,
} from "@/hooks/api/use-shop-floating-sections";
import type { FloatingSectionProduct } from "@/types/models/FloatingSectionProduct";
import type { FloatingSectionProductSku } from "@/types/models/FloatingSectionProductSku";
import { cn } from "@/lib/utils";
import { ShopFloatingSectionToppingGroupsSection } from "./topping-groups-section";

function formatPrice(value: number | null | undefined): string {
  if (value == null || Number.isNaN(value)) return "—";
  return new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(value);
}

function variantLabel(sku: FloatingSectionProductSku): string {
  const productSku = sku.productSku;
  const parts = [
    productSku?.optionValue1?.label,
    productSku?.optionValue2?.label,
    productSku?.optionValue3?.label,
  ].filter(Boolean);
  return parts.length > 0 ? parts.join(" / ") : (productSku?.name ?? productSku?.sku ?? "—");
}

// Shop-side sibling of the HQ products-section. A floating section is a
// scaled-down menu with exactly one (implicit) section, so each product row
// mirrors Menu's shop MenuProductRow exactly: card row with an image, name +
// variant count, a Switch for is_active, and an expand chevron — no separate
// table columns. Promotional pricing lives per-SKU inside the expanded
// SkuTable (Chỉnh giá / Đặt lại giá mặc định / Tạm ngưng via a dropdown),
// never at the product level: a multi-variant product can't share one flat
// price.
export function ShopFloatingSectionProductsSection({
  shopSlug,
  sectionId,
}: {
  shopSlug: string;
  sectionId: string;
}) {
  const { t } = useTranslation();

  const { data, isLoading, isError, refetch } = useShopFloatingSection(shopSlug, sectionId);
  const products: FloatingSectionProduct[] = data?.data.products ?? [];

  const toggleMutation = useToggleShopFloatingSectionProduct(shopSlug);
  const toggleSkuMutation = useToggleShopFloatingSectionProductSku(shopSlug);
  const overridePriceMutation = useOverrideShopFloatingSectionSkuPrice(shopSlug);
  const resetPriceMutation = useResetShopFloatingSectionSkuPrice(shopSlug);

  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [editingSku, setEditingSku] = useState<{ productId: string; sku: FloatingSectionProductSku } | null>(
    null
  );
  const [editDraft, setEditDraft] = useState("");

  function toggleExpanded(productRowId: string) {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(productRowId)) {
        next.delete(productRowId);
      } else {
        next.add(productRowId);
      }
      return next;
    });
  }

  function openEditPrice(productId: string, sku: FloatingSectionProductSku) {
    setEditDraft(String(sku.selling_price ?? 0));
    setEditingSku({ productId, sku });
  }

  function handleSavePrice() {
    const next = Number(editDraft);
    if (!editingSku || !Number.isFinite(next) || next < 0) return;
    overridePriceMutation.mutate(
      { sectionId, productId: editingSku.productId, skuId: editingSku.sku.id, sellingPrice: next },
      // Close on success only — on error keep the dialog open so the operator's
      // typed price survives and they can retry (the mutation's onError toasts).
      { onSuccess: () => setEditingSku(null) }
    );
  }

  return (
    <div className="px-4 py-3">
      <div className="mb-2.5 flex items-center gap-2">
        <span className="text-sm font-semibold">{t("hq.floating_sections.products.tab")}</span>
        {!isLoading && products.length > 0 && (
          <Badge variant="outline" className="h-5 text-[10px]">
            {products.length}
          </Badge>
        )}
      </div>

      {isLoading ? (
        <div className="space-y-1.5">
          {[1, 2].map((i) => (
            <Skeleton key={i} className="h-9 w-full" />
          ))}
        </div>
      ) : isError ? (
        <Alert variant="destructive" className="py-2">
          <AlertTitle className="text-xs">{t("common.error_loading")}</AlertTitle>
          <AlertDescription>
            <Button
              variant="outline"
              size="sm"
              className="mt-1 h-6 text-xs"
              onClick={() => refetch()}
            >
              {t("common.retry")}
            </Button>
          </AlertDescription>
        </Alert>
      ) : products.length === 0 ? (
        <div className="rounded-md border border-dashed px-3 py-3 text-center text-xs text-muted-foreground">
          {t("hq.floating_sections.products.empty_desc")}
        </div>
      ) : (
        <div className="space-y-2">
          {products.map((p) => {
            const skus = p.skus ?? [];
            const isExpanded = expanded.has(p.id);
            return (
              <div
                key={p.id}
                className={cn(
                  "flex flex-col overflow-hidden rounded-md border bg-card transition-colors hover:border-primary/40",
                  !p.is_active && "opacity-60"
                )}
              >
                <div
                  role="button"
                  tabIndex={0}
                  onClick={() => toggleExpanded(p.id)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter" || e.key === " ") {
                      e.preventDefault();
                      toggleExpanded(p.id);
                    }
                  }}
                  className="flex cursor-pointer items-center gap-2 p-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                  <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded bg-muted">
                    {p.product?.image_url ? (
                      <Image
                        src={p.product.image_url}
                        alt={p.product.name}
                        width={40}
                        height={40}
                        className="h-full w-full object-cover"
                      />
                    ) : (
                      <ImageIcon className="size-3.5 text-muted-foreground" />
                    )}
                  </div>
                  <div className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">
                      {p.product?.name ?? "—"}
                    </span>
                    <span className="text-[11px] text-muted-foreground">
                      {t("hq.menus.items.variants_count", { n: skus.length })}
                    </span>
                  </div>

                  <div
                    onClick={(e) => e.stopPropagation()}
                    onKeyDown={(e) => e.stopPropagation()}
                    className="mx-2 flex items-center justify-center"
                  >
                    <Switch
                      checked={p.is_active}
                      disabled={toggleMutation.isPending}
                      onCheckedChange={() => toggleMutation.mutate({ sectionId, productId: p.id })}
                      aria-label={t("shop.menu.detail.toggle_aria", { name: p.product?.name ?? "" })}
                    />
                  </div>

                  <div className="flex items-center pr-1 text-muted-foreground">
                    {isExpanded ? (
                      <ChevronDown className="size-4" />
                    ) : (
                      <ChevronRight className="size-4" />
                    )}
                  </div>
                </div>

                {isExpanded && (
                  <div className="border-t bg-muted/10 p-3">
                    {skus.length === 0 ? (
                      <div className="flex flex-col items-center justify-center py-6 text-sm text-muted-foreground">
                        {t("shop.menu.detail.no_variants_short")}
                      </div>
                    ) : (
                      <div className="overflow-x-auto rounded-md border">
                        <Table>
                          <TableHeader>
                            <TableRow>
                              <TableHead className="h-8 w-8 text-center text-xs">
                                {t("shop.menu.detail.no_col")}
                              </TableHead>
                              <TableHead className="h-8 w-14 text-xs">
                                {t("shop.menu.detail.image_col")}
                              </TableHead>
                              <TableHead className="h-8 text-xs">
                                {t("shop.menu.detail.sku_col")}
                              </TableHead>
                              <TableHead className="h-8 text-xs">
                                {t("hq.floating_sections.products.variant_col")}
                              </TableHead>
                              <TableHead className="h-8 text-right text-xs">
                                {t("shop.menu.detail.default_price_col")}
                              </TableHead>
                              <TableHead className="h-8 text-right text-xs">
                                {t("shop.menu.detail.price_col")}
                              </TableHead>
                              <TableHead className="h-8 w-24 text-xs">{t("common.status")}</TableHead>
                              <TableHead className="h-8 w-14 text-right text-xs">
                                {t("shop.menu.detail.action_col")}
                              </TableHead>
                            </TableRow>
                          </TableHeader>
                          <TableBody>
                            {skus.map((sku, idx) => {
                              const skuImage = sku.productSku?.image_url ?? p.product?.image_url;
                              const defaultPrice = sku.productSku?.selling_price;
                              return (
                                <TableRow
                                  key={sku.id}
                                  className={cn(!sku.is_active && "opacity-50")}
                                >
                                  <TableCell className="py-2 text-center text-xs text-muted-foreground tabular-nums">
                                    {idx + 1}
                                  </TableCell>
                                  <TableCell className="py-2">
                                    {skuImage ? (
                                      <div className="size-10 shrink-0 overflow-hidden rounded bg-muted">
                                        <Image
                                          src={skuImage}
                                          alt={variantLabel(sku)}
                                          width={40}
                                          height={40}
                                          className="h-full w-full object-cover"
                                        />
                                      </div>
                                    ) : (
                                      <div className="flex size-10 shrink-0 items-center justify-center overflow-hidden rounded border bg-muted">
                                        <ImageIcon className="size-4 text-muted-foreground" />
                                      </div>
                                    )}
                                  </TableCell>
                                  <TableCell className="py-2">
                                    <code className="rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground">
                                      {sku.productSku?.sku ?? "—"}
                                    </code>
                                  </TableCell>
                                  <TableCell className="py-2 text-xs font-medium">
                                    {variantLabel(sku)}
                                  </TableCell>
                                  <TableCell className="py-2 text-right text-sm text-muted-foreground tabular-nums">
                                    {formatPrice(defaultPrice)}
                                  </TableCell>
                                  <TableCell className="py-2 text-right">
                                    {sku.is_price_overridden ? (
                                      <span className="text-sm font-medium tabular-nums">
                                        {formatPrice(sku.selling_price)}
                                      </span>
                                    ) : (
                                      <span className="text-xs text-muted-foreground">—</span>
                                    )}
                                  </TableCell>
                                  <TableCell className="py-2">
                                    <StatusBadge status={sku.is_active ? "active" : "inactive"} />
                                  </TableCell>
                                  <TableCell className="py-2 text-right">
                                    <DropdownMenu>
                                      <DropdownMenuTrigger asChild>
                                        <Button variant="ghost" size="icon" className="size-7">
                                          <EllipsisVertical className="size-4" />
                                        </Button>
                                      </DropdownMenuTrigger>
                                      <DropdownMenuContent align="end">
                                        <DropdownMenuItem onClick={() => openEditPrice(p.id, sku)}>
                                          <Pencil className="mr-2 size-3.5" />{" "}
                                          {t("shop.menu.detail.action_edit_price")}
                                        </DropdownMenuItem>
                                        {sku.is_price_overridden && (
                                          <DropdownMenuItem
                                            onClick={() =>
                                              resetPriceMutation.mutate({
                                                sectionId,
                                                productId: p.id,
                                                skuId: sku.id,
                                              })
                                            }
                                          >
                                            <RotateCcw className="mr-2 size-3.5" />{" "}
                                            {t("shop.menu.detail.reset_to_default")}
                                          </DropdownMenuItem>
                                        )}
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                          onClick={() =>
                                            toggleSkuMutation.mutate({
                                              sectionId,
                                              productId: p.id,
                                              skuId: sku.id,
                                            })
                                          }
                                          disabled={toggleSkuMutation.isPending}
                                        >
                                          <Power className="mr-2 size-3.5" />
                                          {sku.is_active
                                            ? t("common.deactivate")
                                            : t("common.activate")}
                                        </DropdownMenuItem>
                                      </DropdownMenuContent>
                                    </DropdownMenu>
                                  </TableCell>
                                </TableRow>
                              );
                            })}
                          </TableBody>
                        </Table>
                      </div>
                    )}
                    {/* Toppings — a floating section is a menu thu nhỏ, so each
                        product shows its topping groups + shop price overrides
                        exactly like the menu. Renders nothing when the product
                        has no topping groups. */}
                    <ShopFloatingSectionToppingGroupsSection
                      sectionProduct={p}
                      shopSlug={shopSlug}
                      sectionId={sectionId}
                    />
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      <Dialog
        open={!!editingSku}
        onOpenChange={(o) => {
          if (!o && !overridePriceMutation.isPending) setEditingSku(null);
        }}
      >
        <DialogContent className="sm:max-w-xs">
          <DialogHeader>
            <DialogTitle>{t("shop.menu.detail.edit_price_title")}</DialogTitle>
            <DialogDescription>
              {editingSku
                ? t("shop.menu.detail.edit_price_description_sku", {
                    sku: editingSku.sku.productSku?.sku ?? "SKU",
                  })
                : t("shop.menu.detail.edit_price_description")}
            </DialogDescription>
          </DialogHeader>
          <Input
            type="number"
            inputMode="decimal"
            min={0}
            step="0.01"
            autoFocus
            value={editDraft}
            onChange={(e) => setEditDraft(e.target.value)}
            onFocus={(e) => e.currentTarget.select()}
            onKeyDown={(e) => {
              if (e.key === "Enter" && !e.nativeEvent.isComposing) handleSavePrice();
              if (e.key === "Escape") setEditingSku(null);
            }}
            disabled={overridePriceMutation.isPending}
            className="text-right tabular-nums"
          />
          <DialogFooter>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setEditingSku(null)}
              disabled={overridePriceMutation.isPending}
            >
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={handleSavePrice} disabled={overridePriceMutation.isPending}>
              {overridePriceMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
              {t("shop.menu.detail.overwrite_price.confirm")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
