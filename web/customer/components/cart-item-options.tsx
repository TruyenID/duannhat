"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, ChevronUp, Minus, Plus } from "lucide-react";
import type { CartItem } from "@/context/cart-context";

const OPTIONS_COLLAPSE_THRESHOLD = 2;

export type CartOptionLineKind = "add" | "remove" | "info";

export interface CartOptionLine {
  key: string;
  label: string;
  kind: CartOptionLineKind;
}

// Heuristic phân loại nhóm option/topping theo tên — không cần thay đổi
// backend schema. Nhóm "Bỏ bớt..." → remove, nhóm "Thêm..." → add, còn lại
// (Size, Mức độ cay...) → info. Hỗ trợ vi/en/ja.
function classify(groupName: string | null | undefined): CartOptionLineKind {
  // Guard: a malformed / untranslated menu payload can leave the group or
  // option name null → `.toLowerCase()` would throw and crash the whole cart
  // render. Fall back to "info" (the neutral kind) when there's no name.
  const n = (groupName ?? "").toLowerCase();
  if (/bỏ|remove|抜|hold|without/.test(n)) return "remove";
  if (/thêm|add|extra|追加|topping/.test(n)) return "add";
  return "info";
}

// Xoá tiền tố "No "/"Không "/"なし" trong tên item cho nhóm remove để dòng
// đọc gọn (icon − đã thể hiện ý "bỏ").
function stripNegation(name: string): string {
  return name.replace(/^(no|không|なし)\s+/i, "").trim();
}

export function buildCartOptionLines(item: CartItem): CartOptionLine[] {
  const lines: CartOptionLine[] = [];

  // Legacy product.options + selections — 1 dòng / variant
  if (item.product.options) {
    for (const opt of item.product.options) {
      const selectedIds = item.selections[opt.id];
      if (!selectedIds || selectedIds.length === 0) continue;
      const kind = classify(opt.name);
      for (const v of opt.variants) {
        if (!selectedIds.includes(v.id)) continue;
        const value = kind === "remove" ? stripNegation(v.name) : v.name;
        lines.push({
          key: `opt-${opt.id}-${v.id}`,
          kind,
          label: kind === "info" ? `${opt.name}: ${value}` : value,
        });
      }
    }
  }

  // Plan 015 toppingGroups + toppingQuantities — 1 dòng / topping
  if (item.product.toppingGroups && item.toppingQuantities) {
    for (const group of item.product.toppingGroups) {
      const groupQty = item.toppingQuantities[group.id];
      if (!groupQty) continue;
      const kind = classify(group.name);
      for (const top of group.items) {
        const qty = groupQty[top.id];
        if (!qty || qty <= 0) continue;
        let value = kind === "remove" ? stripNegation(top.name) : top.name;
        const variantId = item.toppingItemVariants?.[top.id];
        if (variantId && top.variants) {
          const v = top.variants.find((x) => x.id === variantId);
          if (v) value += ` - ${v.name}`;
        }
        if (qty > 1) value += ` x${qty}`;
        lines.push({
          key: `top-${group.id}-${top.id}`,
          kind,
          label: kind === "info" ? `${group.name}: ${value}` : value,
        });
      }
    }
  }

  return lines;
}

export function CartItemOptionsList({ lines }: { lines: CartOptionLine[] }) {
  const tCart = useTranslations("cart");
  const [expanded, setExpanded] = useState(false);

  if (lines.length === 0) return null;

  const canCollapse = lines.length > OPTIONS_COLLAPSE_THRESHOLD;
  const visible = canCollapse && !expanded ? lines.slice(0, OPTIONS_COLLAPSE_THRESHOLD) : lines;

  return (
    <div className="mt-1 space-y-0.5">
      {visible.map((line) => (
        <p
          key={line.key}
          className={`flex items-center gap-1 text-xs ${
            line.kind === "remove"
              ? "text-red-600"
              : line.kind === "add"
                ? "text-emerald-700"
                : "text-muted-foreground"
          }`}
        >
          {line.kind === "add" && <Plus className="h-3 w-3 shrink-0" />}
          {line.kind === "remove" && <Minus className="h-3 w-3 shrink-0" />}
          <span>{line.label}</span>
        </p>
      ))}
      {canCollapse && (
        <button
          type="button"
          onClick={() => setExpanded((v) => !v)}
          className="inline-flex items-center gap-0.5 text-xs font-medium text-muted-foreground hover:text-foreground"
        >
          {expanded ? (
            <>
              {tCart("showLess")}
              <ChevronUp className="h-3 w-3" />
            </>
          ) : (
            <>
              {tCart("showMore")}
              <ChevronDown className="h-3 w-3" />
            </>
          )}
        </button>
      )}
    </div>
  );
}
