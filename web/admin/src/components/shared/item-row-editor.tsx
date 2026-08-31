"use client";

import { useMemo } from "react";
import { Trash2 } from "lucide-react";
import {
  Button,
  Combobox,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  type ComboboxOption,
} from "@godxjp/ui";
import { useMaterialLookup, useProductSkuLookup } from "@/hooks/api/use-catalog-lookup";

type ItemKind = "variant" | "material";

/**
 * A single row in the inventory items editor. Each row represents one
 * StockTransactionItem / StockTransferItem / DisposalItem — exactly one
 * of `product_sku_id` / `material_id` is set, never both.
 */
export interface ItemRow {
  /** Local-only key so React keys survive re-sorting / reindexing. */
  key: string;
  /** "variant:<id>" or "material:<id>" — the canonical picker value. */
  selection: string;
  quantity: string;
  unit: string;
  note: string;
}

export interface ItemRowEditorProps {
  shopSlug: string;
  rows: ItemRow[];
  onChange: (rows: ItemRow[]) => void;
  /** When true, the unit-price column is rendered and tracked per row. */
  withUnitPrice?: boolean;
  unitPrices?: string[];
  onUnitPricesChange?: (prices: string[]) => void;
}

export function emptyItemRow(): ItemRow {
  return {
    key: crypto.randomUUID(),
    selection: "",
    quantity: "",
    unit: "",
    note: "",
  };
}

/**
 * Translate a row's `selection` value (e.g. "variant:abc") back into the
 * payload shape the backend expects. Returns `null` when the row is
 * incomplete so the caller can skip it.
 */
export function itemRowToPayload(row: ItemRow): {
  product_sku_id?: string | null;
  material_id?: string | null;
  quantity: number;
  unit?: string | null;
  note?: string | null;
} | null {
  if (!row.selection || !row.quantity) return null;
  const [kind, id] = row.selection.split(":");
  if (!id) return null; // sentinel "kind:" — kind picked but no item chosen yet
  const qty = Number(row.quantity);
  if (!Number.isFinite(qty) || qty <= 0) return null;
  const base = {
    quantity: qty,
    unit: row.unit?.trim() || null,
    note: row.note?.trim() || null,
  };
  if (kind === "variant") {
    return { ...base, product_sku_id: id, material_id: null };
  }
  if (kind === "material") {
    return { ...base, product_sku_id: null, material_id: id };
  }
  return null;
}

export function ItemRowEditor({
  shopSlug,
  rows,
  onChange,
  withUnitPrice = false,
  unitPrices = [],
  onUnitPricesChange,
}: ItemRowEditorProps) {
  const skusQuery = useProductSkuLookup(shopSlug);
  const materialsQuery = useMaterialLookup(shopSlug);

  // cmdk filters by `value` field and uses command-score fuzzy matching.
  // The previous encoding `prefix:UUID|display` made the UUID dominate the
  // match score and pushed newly-created items below the visibility cutoff
  // when the search query landed somewhere inside the display tail. Swap
  // to `display·prefix:id` so the human-readable text leads the value and
  // cmdk scores it against the head of the string. The id is recovered
  // after the trailing `·` sentinel.
  const VALUE_SEP = "·"; // U+00B7 MIDDLE DOT — won't appear in names

  const skuOptions: ComboboxOption[] = useMemo(() => {
    return (skusQuery.data?.data ?? []).map((sku) => {
      const variantName = sku.name ?? sku.sku ?? sku.id;
      const productName = sku.product?.name?.trim();
      const display =
        productName && productName !== variantName
          ? `${productName} — ${variantName}${sku.sku ? ` (${sku.sku})` : ""}`
          : `${variantName}${sku.sku ? ` (${sku.sku})` : ""}`;
      return {
        value: `${display}${VALUE_SEP}variant:${sku.id}`,
        label: display,
      };
    });
  }, [skusQuery.data]);

  const materialOptions: ComboboxOption[] = useMemo(() => {
    return (materialsQuery.data?.data ?? []).map((mat) => {
      const display = `${mat.name}${mat.sku ? ` (${mat.sku})` : ""}`;
      return {
        value: `${display}${VALUE_SEP}material:${mat.id}`,
        label: display,
      };
    });
  }, [materialsQuery.data]);

  function getRowKind(row: ItemRow): ItemKind {
    // Sentinel-only rows (e.g. "material:" with no id) still encode the
    // user-picked kind so we don't bounce back to "variant" when they switch.
    return row.selection.startsWith("material") ? "material" : "variant";
  }

  function getRowSelectionForCombobox(row: ItemRow): string {
    if (!row.selection) return "";
    const [prefix, id] = row.selection.split(":");
    if (!id) return ""; // sentinel "kind:" — no item picked yet
    const list = prefix === "material" ? materialOptions : skuOptions;
    return list.find((o) => o.value.endsWith(`${VALUE_SEP}${prefix}:${id}`))?.value ?? "";
  }

  function handlePickerChange(index: number, encoded: string) {
    if (!encoded) {
      updateRow(index, { selection: "" });
      return;
    }
    // Strip the leading display segment to recover the canonical "kind:id".
    const sepIdx = encoded.lastIndexOf(VALUE_SEP);
    const tail = sepIdx >= 0 ? encoded.slice(sepIdx + 1) : encoded;
    const [prefix, id] = tail.split(":");
    if (!prefix || !id) return;
    // Auto-fill unit from the canonical source. Per the locked plan-024
    // decision (input is always base unit, no conversion), always overwrite
    // — user cannot override since the field is readonly.
    let suggestedUnit = "";
    if (prefix === "material") {
      const picked = materialsQuery.data?.data.find((m) => m.id === id);
      // Stock is tracked at the base unit, so fill the material's base unit
      // (base-first in `units`). Raw materials have yield_unit = null — only
      // produced ones set it — so falling back to yield_unit left the column
      // empty for the common purchase/stock-in case. yield_unit stays as a
      // last resort for legacy materials with no registered units.
      const base = picked?.units?.find((u) => u.is_base) ?? picked?.units?.[0];
      suggestedUnit = base?.unit ?? picked?.yield_unit ?? "";
    } else if (prefix === "variant") {
      // ProductSku stock-out is always counted in pieces; no per-SKU unit.
      suggestedUnit = "pcs";
    }
    updateRow(index, { selection: `${prefix}:${id}`, unit: suggestedUnit });
  }

  function updateRow(index: number, patch: Partial<ItemRow>) {
    onChange(rows.map((r, i) => (i === index ? { ...r, ...patch } : r)));
  }

  function removeRow(index: number) {
    if (rows.length <= 1) return;
    onChange(rows.filter((_, i) => i !== index));
    if (onUnitPricesChange) {
      onUnitPricesChange(unitPrices.filter((_, i) => i !== index));
    }
  }

  function addRow() {
    onChange([...rows, emptyItemRow()]);
    if (onUnitPricesChange) {
      onUnitPricesChange([...unitPrices, ""]);
    }
  }

  function updateUnitPrice(index: number, value: string) {
    if (!onUnitPricesChange) return;
    const next = [...unitPrices];
    while (next.length < rows.length) next.push("");
    next[index] = value;
    onUnitPricesChange(next);
  }

  return (
    <div className="space-y-2">
      <div className="overflow-hidden rounded-md border">
        <table className="w-full text-sm">
          <thead className="bg-muted/40 text-xs">
            <tr>
              <th className="w-10 px-2 py-2 text-left">#</th>
              <th className="w-32 px-2 py-2 text-left">Loại</th>
              <th className="px-2 py-2 text-left">Item</th>
              <th className="w-28 px-2 py-2 text-left">Quantity</th>
              <th className="w-24 px-2 py-2 text-left">Unit</th>
              {withUnitPrice && <th className="w-32 px-2 py-2 text-left">Unit price</th>}
              <th className="px-2 py-2 text-left">Note</th>
              <th className="w-10 px-2 py-2" aria-label="Remove" />
            </tr>
          </thead>
          <tbody>
            {rows.map((row, i) => (
              <tr key={row.key} className="border-t">
                <td className="px-2 py-1.5 text-xs text-muted-foreground">{i + 1}</td>
                <td className="px-2 py-1.5">
                  <Select
                    value={getRowKind(row)}
                    onValueChange={(v: ItemKind) =>
                      updateRow(i, { selection: v === "material" ? "material:" : "variant:" })
                    }
                  >
                    <SelectTrigger className="h-8 text-sm">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="variant">SKU</SelectItem>
                      <SelectItem value="material">Nguyên liệu</SelectItem>
                    </SelectContent>
                  </Select>
                </td>
                <td className="px-2 py-1.5">
                  <Combobox
                    options={getRowKind(row) === "material" ? materialOptions : skuOptions}
                    value={getRowSelectionForCombobox(row)}
                    onChange={(v) => handlePickerChange(i, v)}
                    placeholder={getRowKind(row) === "material" ? "Chọn nguyên liệu…" : "Chọn SKU…"}
                    searchPlaceholder="Tìm theo tên hoặc mã…"
                  />
                </td>
                <td className="px-2 py-1.5">
                  <Input
                    type="number"
                    step="0.0001"
                    min="0"
                    value={row.quantity}
                    onChange={(e) => updateRow(i, { quantity: e.target.value })}
                    className="h-8 text-sm"
                  />
                </td>
                <td className="px-2 py-1.5">
                  <Input
                    value={row.unit}
                    readOnly
                    tabIndex={-1}
                    placeholder="—"
                    className="h-8 cursor-not-allowed bg-muted/40 text-sm text-muted-foreground"
                  />
                </td>
                {withUnitPrice && (
                  <td className="px-2 py-1.5">
                    <Input
                      type="number"
                      step="0.01"
                      min="0"
                      value={unitPrices[i] ?? ""}
                      onChange={(e) => updateUnitPrice(i, e.target.value)}
                      className="h-8 text-sm"
                    />
                  </td>
                )}
                <td className="px-2 py-1.5">
                  <Input
                    value={row.note}
                    onChange={(e) => updateRow(i, { note: e.target.value })}
                    className="h-8 text-sm"
                  />
                </td>
                <td className="px-2 py-1.5">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => removeRow(i)}
                    disabled={rows.length <= 1}
                    className="size-7 p-0 text-muted-foreground"
                    aria-label="Remove row"
                  >
                    <Trash2 className="size-3.5" />
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex items-center justify-between">
        <Button type="button" variant="outline" size="sm" onClick={addRow}>
          + Add item
        </Button>
        <span className="text-xs text-muted-foreground">
          {rows.length} item{rows.length === 1 ? "" : "s"}
        </span>
      </div>
    </div>
  );
}
