"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { AlertCircle, Calculator, CheckCircle2, ExternalLink, TrendingDown } from "lucide-react";
import {
  Badge,
  Button,
  Input,
  MultiCombobox,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  type ComboboxOption,
} from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import {
  useCalculateProduction,
  useCalculateShortage,
  useVariantsWithRecipe,
} from "@/hooks/api/use-production-calculator";
import { useWarehouseLookup } from "@/hooks/api/use-warehouses";
import { useTranslation } from "@/providers/app-provider";
import type { CalculateResult, VariantCalculation } from "@/services/production-calculator-service";

function formatNumber(n: number | null | undefined, fractionDigits = 2): string {
  if (n == null) return "—";
  return n.toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: fractionDigits,
  });
}

export default function ProductionCalculatorPage() {
  const { t } = useTranslation();
  const { shopSlug } = useParams<{ shopSlug: string }>();

  const warehousesQuery = useWarehouseLookup(shopSlug);
  const warehouses = warehousesQuery.data?.data ?? [];

  const variantsQuery = useVariantsWithRecipe(shopSlug);
  const variants = variantsQuery.data?.data ?? [];
  const variantOptions: ComboboxOption[] = variants.map((v) => ({
    value: v.id,
    label: v.sku ? `${v.name ?? v.sku} (${v.sku})` : (v.name ?? v.id),
  }));

  const [warehouseId, setWarehouseId] = useState<string>("");
  const [selected, setSelected] = useState<string[]>([]);

  const calcMutation = useCalculateProduction(shopSlug);
  const shortageMutation = useCalculateShortage(shopSlug);

  // Desired quantity per variant id, used by the reverse-shortage flow.
  // Seeded from `max_producible` when the user clicks "Reverse calc".
  const [desiredQty, setDesiredQty] = useState<Record<string, string>>({});

  const result = calcMutation.data?.data;
  const shortage = shortageMutation.data?.data;
  const [shortageVariantId, setShortageVariantId] = useState<string>("");

  function runCalculate() {
    if (!warehouseId || selected.length === 0) return;
    shortageMutation.reset();
    calcMutation.mutate({ warehouse_id: warehouseId, variant_ids: selected });
  }

  function runShortage(variantId: string) {
    if (!warehouseId) return;
    const qty = Number(desiredQty[variantId] ?? "");
    if (!Number.isFinite(qty) || qty <= 0) return;
    setShortageVariantId(variantId);
    shortageMutation.mutate({
      warehouse_id: warehouseId,
      variant_id: variantId,
      desired_quantity: qty,
    });
  }

  const resultProducts: CalculateResult["products"] = result?.products ?? [];

  return (
    <>
      <PageHeader title={t("shop.production.calculator.title")}>
        <Button variant="outline" size="sm" asChild>
          <Link href={`/shop/${shopSlug}/production/orders/new`}>
            <ExternalLink className="mr-1.5 size-3.5" />
            {t("shop.production.calculator.create_order")}
          </Link>
        </Button>
        <HelpPanel
          title={t("shop.production.calculator.title")}
          subtitle={t("help.panel.shop_production_calculator.subtitle")}
          purpose={t("help.panel.shop_production_calculator.purpose")}
          usage={[
            t("help.panel.shop_production_calculator.usage.1"),
            t("help.panel.shop_production_calculator.usage.2"),
            t("help.panel.shop_production_calculator.usage.3"),
            t("help.panel.shop_production_calculator.usage.4"),
          ]}
          checks={[
            t("help.panel.shop_production_calculator.checks.1"),
            t("help.panel.shop_production_calculator.checks.2"),
            t("help.panel.shop_production_calculator.checks.3"),
            t("help.panel.shop_production_calculator.checks.4"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_production_calculator.glossary.max_producible.term"),
              description: t("help.panel.shop_production_calculator.glossary.max_producible.desc"),
            },
            {
              term: t("help.panel.shop_production_calculator.glossary.bottleneck.term"),
              description: t("help.panel.shop_production_calculator.glossary.bottleneck.desc"),
            },
            {
              term: t("help.panel.shop_production_calculator.glossary.shared.term"),
              description: t("help.panel.shop_production_calculator.glossary.shared.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <div className="mb-4 grid grid-cols-1 gap-3 rounded-md border bg-card p-4 sm:grid-cols-[1fr_2fr_auto]">
          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.calculator.warehouse")}
            </label>
            <Select value={warehouseId} onValueChange={setWarehouseId}>
              <SelectTrigger className="h-9 text-sm">
                <SelectValue placeholder={t("shop.production.calculator.select_warehouse")} />
              </SelectTrigger>
              <SelectContent>
                {warehouses.map((w) => (
                  <SelectItem key={w.id} value={w.id}>
                    {w.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="space-y-1.5">
            <label className="text-xs font-medium text-muted-foreground">
              {t("shop.production.calculator.variants_label")}
            </label>
            <MultiCombobox
              options={variantOptions}
              value={selected}
              onChange={setSelected}
              placeholder={t("shop.production.calculator.pick_one_or_more")}
              searchPlaceholder={t("shop.production.calculator.search_by_name_sku")}
            />
          </div>
          <div className="flex items-end">
            <Button
              onClick={runCalculate}
              disabled={
                !warehouseId ||
                selected.length === 0 ||
                calcMutation.isPending ||
                variantsQuery.isLoading
              }
              className="h-9"
            >
              {calcMutation.isPending && <Spinner className="mr-1.5 size-3.5" />}
              <Calculator className="mr-1.5 size-3.5" />
              {t("shop.production.calculator.calculate")}
            </Button>
          </div>
        </div>

        {calcMutation.isError && (
          <div className="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
            {(calcMutation.error as Error).message || t("shop.production.calculator.calc_failed")}
          </div>
        )}

        {result && result.shared_ingredients.length > 0 && (
          <div className="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
            <div className="mb-1 flex items-center gap-2 font-medium">
              <AlertCircle className="size-4" />
              {t("shop.production.calculator.shared_ingredients")}
            </div>
            <ul className="list-disc pl-5 text-xs">
              {result.shared_ingredients.map((s) => (
                <li key={s.track_key}>
                  <span className="font-medium">{s.ingredient_name}</span>{" "}
                  {t("shop.production.calculator.used_by", {
                    products: s.used_by_products.join(", "),
                  })}
                </li>
              ))}
            </ul>
          </div>
        )}

        {resultProducts.length === 0 && result && (
          <div className="rounded-md border p-6 text-center text-sm text-muted-foreground">
            {t("shop.production.calculator.no_recipe_data")}
          </div>
        )}

        <div className="space-y-4">
          {resultProducts.map((prod) => (
            <div key={prod.product.id ?? prod.product.name} className="rounded-md border bg-card">
              <div className="border-b px-4 py-3 text-sm font-medium">
                {prod.product.name ?? "—"}
              </div>
              <div className="divide-y">
                {prod.variants.map((v) => (
                  <VariantPanel
                    key={v.variant.id}
                    v={v}
                    desiredQty={desiredQty[v.variant.id] ?? ""}
                    onDesiredQtyChange={(value) =>
                      setDesiredQty((prev) => ({
                        ...prev,
                        [v.variant.id]: value,
                      }))
                    }
                    onRunShortage={() => runShortage(v.variant.id)}
                    onSeed={() =>
                      setDesiredQty((prev) => ({
                        ...prev,
                        [v.variant.id]: String(v.max_producible || ""),
                      }))
                    }
                    shortageLoading={
                      shortageMutation.isPending && shortageVariantId === v.variant.id
                    }
                    shortage={shortageVariantId === v.variant.id ? shortage : undefined}
                    warehouseId={warehouseId}
                    shopSlug={shopSlug}
                  />
                ))}
              </div>
            </div>
          ))}
        </div>
      </PageContent>
    </>
  );
}

function VariantPanel({
  v,
  desiredQty,
  onDesiredQtyChange,
  onRunShortage,
  onSeed,
  shortageLoading,
  shortage,
  warehouseId,
  shopSlug,
}: {
  v: VariantCalculation;
  desiredQty: string;
  onDesiredQtyChange: (value: string) => void;
  onRunShortage: () => void;
  onSeed: () => void;
  shortageLoading: boolean;
  shortage?: {
    desired_quantity: number;
    is_feasible: boolean;
    ingredients: Array<{
      name: string;
      type: string;
      required_total: number;
      available_stock: number | null;
      shortage: number;
      unit: string | null;
      is_sufficient: boolean | null;
    }>;
  };
  warehouseId: string;
  shopSlug: string;
}) {
  const { t } = useTranslation();
  const maxColor =
    v.max_producible > 0
      ? "text-emerald-600 dark:text-emerald-400"
      : "text-red-600 dark:text-red-400";

  return (
    <div className="p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div>
          <div className="text-sm font-medium">
            {v.variant.name ?? v.variant.sku ?? v.variant.id}
            {v.variant.sku && (
              <span className="ml-2 font-mono text-xs text-muted-foreground">{v.variant.sku}</span>
            )}
          </div>
          {v.variant.recipe_multiplier !== 1 && (
            <div className="text-xs text-muted-foreground">
              {t("shop.production.calculator.multiplier_tag", { n: v.variant.recipe_multiplier })}
            </div>
          )}
        </div>
        <div className="flex items-center gap-3">
          <div className="text-right">
            <div className="text-xs tracking-wide text-muted-foreground uppercase">
              {t("shop.production.calculator.max_producible")}
            </div>
            <div className={`text-2xl font-semibold tabular-nums ${maxColor}`}>
              {v.max_producible}
            </div>
            {v.bottleneck_ingredient && (
              <div className="text-xs text-amber-600 dark:text-amber-400">
                {t("shop.production.calculator.bottleneck_label", {
                  name: v.bottleneck_ingredient,
                })}
              </div>
            )}
          </div>
          <Button
            variant="outline"
            size="sm"
            asChild
            disabled={!warehouseId || v.max_producible === 0}
          >
            <Link
              href={`/shop/${shopSlug}/production/orders/new?output_variant_id=${v.variant.id}&warehouse_id=${warehouseId}&planned_quantity=${v.max_producible}`}
            >
              {t("shop.production.calculator.create_order_for_variant")}
            </Link>
          </Button>
        </div>
      </div>

      <div className="overflow-hidden rounded-md border">
        <table className="w-full text-sm">
          <thead className="bg-muted/40 text-xs">
            <tr>
              <th className="px-2 py-1.5 text-left">
                {t("shop.production.calculator.col.ingredient")}
              </th>
              <th className="px-2 py-1.5 text-left">{t("shop.production.calculator.col.type")}</th>
              <th className="px-2 py-1.5 text-right">
                {t("shop.production.calculator.col.required_per_batch")}
              </th>
              <th className="px-2 py-1.5 text-right">
                {t("shop.production.calculator.col.available")}
              </th>
              <th className="px-2 py-1.5 text-right">
                {t("shop.production.calculator.col.max_batches")}
              </th>
            </tr>
          </thead>
          <tbody>
            {v.ingredients.map((ing, i) => {
              const isBottleneck =
                ing.max_batches_from_this !== null &&
                ing.max_batches_from_this === v.max_producible;
              return (
                <tr
                  key={i}
                  className={`border-t ${
                    isBottleneck ? "bg-amber-50/60 dark:bg-amber-950/40" : ""
                  }`}
                >
                  <td className="px-2 py-1.5">
                    <span className={isBottleneck ? "font-medium" : ""}>{ing.name}</span>
                    {isBottleneck && (
                      <Badge variant="outline" className="ml-2 border-amber-300 text-amber-700">
                        {t("shop.production.calculator.bottleneck_badge")}
                      </Badge>
                    )}
                  </td>
                  <td className="px-2 py-1.5">
                    <TypeBadge type={ing.type} />
                  </td>
                  <td className="px-2 py-1.5 text-right tabular-nums">
                    {formatNumber(ing.required_per_batch)} {ing.unit ?? ""}
                  </td>
                  <td className="px-2 py-1.5 text-right tabular-nums">
                    {ing.type === "raw" ? "N/A" : formatNumber(ing.available_stock)}
                  </td>
                  <td
                    className={`px-2 py-1.5 text-right tabular-nums ${
                      isBottleneck ? "font-medium text-amber-700 dark:text-amber-300" : ""
                    }`}
                  >
                    {ing.type === "raw" ? "—" : (ing.max_batches_from_this ?? "—")}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2 rounded-md border bg-muted/20 p-3">
        <span className="text-xs text-muted-foreground">
          {t("shop.production.calculator.reverse_calc_label")}
        </span>
        <Input
          type="number"
          step="0.01"
          min="0"
          value={desiredQty}
          onChange={(e) => onDesiredQtyChange(e.target.value)}
          className="h-8 w-32 text-sm"
        />
        <Button variant="outline" size="sm" onClick={onSeed}>
          {t("shop.production.calculator.seed_from_max")}
        </Button>
        <Button
          size="sm"
          onClick={onRunShortage}
          disabled={shortageLoading || !desiredQty || Number(desiredQty) <= 0}
        >
          {shortageLoading && <Spinner className="mr-1.5 size-3.5" />}
          {t("shop.production.calculator.check_shortage")}
        </Button>
      </div>

      {shortage && (
        <div className="mt-3 rounded-md border p-3">
          <div
            className={`mb-2 flex items-center gap-2 text-sm font-medium ${
              shortage.is_feasible
                ? "text-emerald-600 dark:text-emerald-400"
                : "text-red-600 dark:text-red-400"
            }`}
          >
            {shortage.is_feasible ? (
              <CheckCircle2 className="size-4" />
            ) : (
              <TrendingDown className="size-4" />
            )}
            {shortage.is_feasible
              ? t("shop.production.calculator.feasible", {
                  n: formatNumber(shortage.desired_quantity),
                })
              : t("shop.production.calculator.infeasible", {
                  n: formatNumber(shortage.desired_quantity),
                })}
          </div>
          <table className="w-full text-xs">
            <thead className="text-muted-foreground">
              <tr>
                <th className="px-2 py-1 text-left">
                  {t("shop.production.calculator.col.ingredient")}
                </th>
                <th className="px-2 py-1 text-left">{t("shop.production.calculator.col.type")}</th>
                <th className="px-2 py-1 text-right">
                  {t("shop.production.calculator.col.required")}
                </th>
                <th className="px-2 py-1 text-right">
                  {t("shop.production.calculator.col.available")}
                </th>
                <th className="px-2 py-1 text-right">
                  {t("shop.production.calculator.col.shortage")}
                </th>
                <th className="px-2 py-1 text-left">
                  {t("shop.production.calculator.col.status")}
                </th>
              </tr>
            </thead>
            <tbody>
              {shortage.ingredients.map((row, i) => (
                <tr key={i} className="border-t border-muted/40">
                  <td className="px-2 py-1">{row.name}</td>
                  <td className="px-2 py-1">
                    <TypeBadge type={row.type} />
                  </td>
                  <td className="px-2 py-1 text-right tabular-nums">
                    {formatNumber(row.required_total)} {row.unit ?? ""}
                  </td>
                  <td className="px-2 py-1 text-right tabular-nums">
                    {row.type === "raw" ? "N/A" : formatNumber(row.available_stock)}
                  </td>
                  <td
                    className={`px-2 py-1 text-right tabular-nums ${
                      row.shortage > 0 ? "text-red-600 dark:text-red-400" : ""
                    }`}
                  >
                    {row.type === "raw" ? "—" : row.shortage > 0 ? formatNumber(row.shortage) : "0"}
                  </td>
                  <td className="px-2 py-1">
                    {row.is_sufficient === null ? (
                      <span className="text-muted-foreground">
                        {t("shop.production.calculator.not_tracked")}
                      </span>
                    ) : row.is_sufficient ? (
                      <span className="text-emerald-600 dark:text-emerald-400">
                        {t("shop.production.calculator.sufficient")}
                      </span>
                    ) : (
                      <span className="text-red-600 dark:text-red-400">
                        {t("shop.production.calculator.short")}
                      </span>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function TypeBadge({ type }: { type: string }) {
  const { t } = useTranslation();
  const className =
    type === "variant"
      ? "bg-sky-100 text-sky-700 dark:bg-sky-900 dark:text-sky-100"
      : type === "material"
        ? "bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-100"
        : "bg-slate-100 text-slate-700";
  const label =
    type === "variant"
      ? t("shop.production.calculator.type.variant")
      : type === "material"
        ? t("shop.production.calculator.type.material")
        : t("shop.production.calculator.type.raw");
  return <Badge className={className}>{label}</Badge>;
}
