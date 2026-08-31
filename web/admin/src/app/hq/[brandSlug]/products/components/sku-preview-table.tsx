"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@godxjp/ui";
import { Checkbox } from "@godxjp/ui";
import { Input } from "@godxjp/ui";
import { Button } from "@godxjp/ui";
import { Banknote } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  DialogDescription,
} from "@godxjp/ui";
import { Label } from "@godxjp/ui";
import type { DraftOption } from "./options-builder";
import { useTranslation } from "@/providers/app-provider";
import { currencySymbol, currencySymbolPosition } from "@/lib/currency";

export interface DraftSku {
  tempId: string;
  valueIndices: number[];
  displayName: string;
  selected: boolean;
  costPrice: string;
}

interface SkuPreviewTableProps {
  options: DraftOption[];
  skus: DraftSku[];
  onChange: (next: DraftSku[]) => void;
}

function cartesianValueIndices(options: DraftOption[]): number[][] {
  if (options.length === 0) return [];
  return options.reduce<number[][]>(
    (acc, option) => {
      const next: number[][] = [];
      for (const prefix of acc) {
        for (let i = 0; i < option.values.length; i++) {
          next.push([...prefix, i]);
        }
      }
      return next;
    },
    [[]]
  );
}

function buildDisplayName(options: DraftOption[], valueIndices: number[]): string {
  return valueIndices
    .map((vi, oi) => options[oi]?.values[vi]?.label ?? "")
    .filter(Boolean)
    .join(" / ");
}

function comboKey(valueIndices: number[]): string {
  return valueIndices.join(":");
}

let tempIdCounter = Date.now();
function nextTempId(): string {
  tempIdCounter += 1;
  return `sku-${tempIdCounter}`;
}

export function ProductSkuPreviewTable({ options, skus, onChange }: SkuPreviewTableProps) {
  const { t, locale } = useTranslation();
  // ¥ sits before the number, ₫ after it — the adornment has to move with it.
  const symbolFirst = currencySymbolPosition(locale) === "prefix";
  const [bulkPriceOpen, setBulkPriceOpen] = useState(false);
  const [bulkValue, setBulkValue] = useState("");

  // Latest-value refs so the regen useEffect below doesn't have to depend on
  // `skus` / `onChange` (which would re-trigger it on every parent render).
  // Updating them inside an effect (instead of during render) keeps render
  // pure — the React Compiler refs rule rejects bare assignment in render.
  const skusRef = useRef(skus);
  const onChangeRef = useRef(onChange);
  useEffect(() => {
    skusRef.current = skus;
    onChangeRef.current = onChange;
  });

  useEffect(() => {
    // Defensive (v.label ?? "") / (o.name ?? "") — hydrated options from the
    // server may contain null translations or freshly added rows with not-yet
    // entered names; calling .trim() on null would throw.
    const validOptions = options
      .map((o) => ({
        ...o,
        values: o.values.filter((v) => (v.label ?? "").trim() !== ""),
      }))
      .filter((o) => o.values.length > 0 && (o.name ?? "").trim() !== "");

    if (validOptions.length === 0) {
      if (skusRef.current.length > 0) {
        onChangeRef.current([]);
      }
      return;
    }

    const combos = cartesianValueIndices(validOptions);
    const previousByKey = new Map(skusRef.current.map((s) => [comboKey(s.valueIndices), s]));

    const next: DraftSku[] = combos.map((indices) => {
      const key = comboKey(indices);
      const existing = previousByKey.get(key);
      return {
        tempId: existing?.tempId ?? nextTempId(),
        valueIndices: indices,
        displayName: buildDisplayName(validOptions, indices),
        selected: existing?.selected ?? true,
        costPrice: existing?.costPrice ?? "",
      };
    });

    const sameLength = next.length === skusRef.current.length;
    const sameOrder =
      sameLength &&
      next.every(
        (s, i) =>
          s.tempId === skusRef.current[i]?.tempId &&
          s.displayName === skusRef.current[i]?.displayName
      );

    if (!sameOrder || !sameLength) {
      onChangeRef.current(next);
    }
  }, [options]);

  const selectedCount = useMemo(() => skus.filter((s) => s.selected).length, [skus]);

  const allSelected = skus.length > 0 && selectedCount === skus.length;

  if (skus.length === 0) {
    return (
      <div className="flex flex-col items-center gap-2 rounded-md border border-dashed bg-muted/20 p-10 text-center">
        <p className="text-xs font-medium text-muted-foreground">
          {t("hq.products.skus.add_attributes_hint")}
        </p>
      </div>
    );
  }

  function toggle(tempId: string) {
    onChange(skus.map((s) => (s.tempId === tempId ? { ...s, selected: !s.selected } : s)));
  }

  function toggleAll() {
    const next = !allSelected;
    onChange(skus.map((s) => ({ ...s, selected: next })));
  }

  function setCostPrice(tempId: string, value: string) {
    onChange(skus.map((s) => (s.tempId === tempId ? { ...s, costPrice: value } : s)));
  }

  function handleApplyBulk() {
    onChange(skus.map((s) => (s.selected ? { ...s, costPrice: bulkValue } : s)));
    setBulkPriceOpen(false);
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center justify-between text-xs font-medium">
        <div className="text-muted-foreground">
          {t("hq.products.skus.selected_count", { selected: selectedCount, total: skus.length })}
        </div>
        <Button
          variant="outline"
          size="sm"
          className="h-8 gap-2 text-xs"
          onClick={() => setBulkPriceOpen(true)}
          disabled={selectedCount === 0}
        >
          <Banknote className="size-3.5" />
          {t("hq.products.skus.bulk_price")}
        </Button>
      </div>

      <div className="overflow-hidden rounded-md border">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="w-10 text-center">
                <Checkbox checked={allSelected} onCheckedChange={toggleAll} />
              </TableHead>
              <TableHead className="text-[11px] font-bold text-muted-foreground uppercase">
                {t("hq.products.skus.classification")}
              </TableHead>
              <TableHead className="w-40 text-right text-[11px] font-bold text-muted-foreground uppercase">
                {t("hq.products.skus.cost_price")}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {skus.map((sku) => (
              <TableRow key={sku.tempId} className={!sku.selected ? "opacity-40" : ""}>
                <TableCell className="text-center">
                  <Checkbox checked={sku.selected} onCheckedChange={() => toggle(sku.tempId)} />
                </TableCell>
                <TableCell className="py-2.5 text-xs font-medium">{sku.displayName}</TableCell>
                <TableCell>
                  <div className="group relative">
                    <Input
                      type="number"
                      value={sku.costPrice}
                      onChange={(e) => setCostPrice(sku.tempId, e.target.value)}
                      disabled={!sku.selected}
                      placeholder="0"
                      className={
                        symbolFirst
                          ? "h-8 pl-6 text-right text-xs font-bold"
                          : "h-8 pr-6 text-right text-xs font-bold"
                      }
                    />
                    <span
                      className={`absolute top-1/2 -translate-y-1/2 text-[10px] text-muted-foreground ${
                        symbolFirst ? "left-2" : "right-2"
                      }`}
                    >
                      {currencySymbol(locale)}
                    </span>
                  </div>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <Dialog open={bulkPriceOpen} onOpenChange={setBulkPriceOpen}>
        <DialogContent className="sm:max-w-xs">
          <DialogHeader>
            <DialogTitle className="text-sm">{t("hq.products.skus.bulk_price")}</DialogTitle>
            <DialogDescription className="text-xs">
              {t("hq.products.skus.bulk_price_desc", { n: selectedCount })}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3 py-2">
            <div className="space-y-1">
              <Label className="text-xs text-muted-foreground">
                {t("hq.products.skus.apply_price")}
              </Label>
              <div className="relative">
                <Input
                  type="number"
                  placeholder="0"
                  value={bulkValue}
                  onChange={(e) => setBulkValue(e.target.value)}
                  className={symbolFirst ? "h-9 pl-8 font-bold" : "h-9 pr-8 font-bold"}
                />
                <span
                  className={`absolute top-1/2 -translate-y-1/2 text-xs text-muted-foreground ${
                    symbolFirst ? "left-3" : "right-3"
                  }`}
                >
                  {currencySymbol(locale)}
                </span>
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setBulkPriceOpen(false)}>
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={handleApplyBulk}>
              {t("hq.products.skus.apply")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
