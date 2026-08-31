"use client";

import { useState, useCallback, useMemo, useEffect, useRef } from "react";
import { useTranslations } from 'next-intl';
import type { MenuItem, ProductOption, ToppingGroup } from "@/data/menu";
import {
  Dialog,
  DialogContent,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Minus, Plus, X, ImageIcon, Loader2 } from "lucide-react";
import { toast } from "sonner";
import {
  useCart,
  generateCartItemId,
  calculateUnitPrice,
} from "@/context/cart-context";
import { HappyHourBadge, applyPromotionPercent, effectiveUnitPrice } from "@/components/happy-hour";
import { useCurrency } from "@/lib/currency";
import { useBrand } from "@/context/brand-context";
import { resolveLineRate } from "@/lib/tax";
import { describeToppingGroupRule } from "@/lib/topping-group-rule";
import { findActiveCheckoutDraft } from "@/lib/checkout-draft";
import { useRouter } from "@/i18n/routing";
import { useOrderingBlocked } from "@/hooks/use-branch-open-state";

interface ProductModalProps {
	  item: MenuItem;
	  open: boolean;
	  onOpenChange: (open: boolean) => void;
	  /**
	   * Mode:
	   *  - "add"  (default): add a new line to the cart
	   *  - "edit": edit an existing cart line (replace it with updated config)
	   */
	  mode?: "add" | "edit";
	  /** Initial values when editing an existing cart item */
	  initialQuantity?: number;
	  initialSelections?: Record<string, string[]>;
	  initialToppingQuantities?: Record<string, Record<string, number>>;
	  initialToppingItemVariants?: Record<string, string>;
	  initialNote?: string;
	  cartItemIdToReplace?: string;
	}

type Selections = Record<string, Set<string>>;
type ToppingQuantities = Record<string, Record<string, number>>; // groupId -> { itemId: quantity }
// Variant selections for topping items: toppingItemId -> variantId
type ToppingItemVariantSelections = Record<string, string>;

/**
 * Image with broken-URL fallback.
 *
 * Backend menu URLs (storage paths, signed URLs) and mock-data paths can
 * 404 in dev; the default `<img>` then renders a browser-rendered broken
 * icon + alt text bleeding into the layout. Swap for a neutral placeholder
 * tile so the row keeps its rhythm.
 */
function ProductImage({
  src,
  alt,
  fallbackSrc,
  className,
}: {
  src: string | null | undefined;
  fallbackSrc?: string | null;
  alt: string;
  className?: string;
}) {
  const [currentSrc, setCurrentSrc] = useState<string | null>(() =>
    src ?? fallbackSrc ?? null,
  );
  const [errored, setErrored] = useState(false);

  // Reset when src/fallbackSrc changes
  useEffect(() => {
    setErrored(false);
    setCurrentSrc(src ?? fallbackSrc ?? null);
  }, [src, fallbackSrc]);

  if (!currentSrc || errored) {
    return (
      <div
        className={`flex items-center justify-center bg-gray-100 text-muted-foreground/40 ${className ?? ""}`}
      >
        <ImageIcon className="h-1/2 w-1/2" strokeWidth={1.5} />
      </div>
    );
  }

  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={currentSrc}
      alt={alt}
      className={className}
      onError={() => {
        // If primary src fails and we have a different fallback, try that once
        if (fallbackSrc && currentSrc !== fallbackSrc) {
          setCurrentSrc(fallbackSrc);
        } else {
          setErrored(true);
        }
      }}
    />
  );
}

const HERO_FALLBACK_IMAGE = "/images/craft-pho-bowl.webp";

function initSelections(
	  options?: ProductOption[],
	  initial?: Record<string, string[]>,
): Selections {
	  const selections: Selections = {};
	  if (!options) return selections;
	  for (const opt of options) {
	    const initialForOpt = initial?.[opt.id];
	    const defaults =
	      initialForOpt && initialForOpt.length > 0
	        ? initialForOpt
	        : opt.variants.filter((v) => v.default).map((v) => v.id);
	    selections[opt.id] = new Set(defaults.length > 0 ? defaults : []);
	  }
	  return selections;
	}

function initToppingQuantities(
	  groups?: ToppingGroup[],
	  initial?: ToppingQuantities,
): ToppingQuantities {
	  if (initial) {
	    const cloned: ToppingQuantities = {};
	    for (const [groupId, items] of Object.entries(initial)) {
	      cloned[groupId] = { ...items };
	    }
	    return cloned;
	  }
	  const quantities: ToppingQuantities = {};
	  if (!groups) return quantities;
	  for (const group of groups) {
	    quantities[group.id] = {};
	    // Mirror BE's autoFillDefaultToppings: only seed defaults when the
	    // group has a minimum (min_select ≥ 1) so they satisfy the cap. For
	    // optional groups (min_select = 0) leave the slate empty — the
	    // customer keeps the full max_select budget for explicit picks, and
	    // pre-selected "suggestions" don't silently eat slots and cause
	    // BE 422 toppings_above_max when the customer tries to add more.
	    if (group.min_select > 0) {
	      const defaults = group.items
	        .filter((t) => t.default)
	        .slice(0, group.min_select);
	      for (const item of defaults) {
	        quantities[group.id][item.id] = 1;
	      }
	    }
	  }
	  return quantities;
	}

function initToppingItemVariants(
	  groups?: ToppingGroup[],
	  initial?: ToppingItemVariantSelections,
): ToppingItemVariantSelections {
	  if (initial) {
	    return { ...initial };
	  }
	  const selections: ToppingItemVariantSelections = {};
	  if (!groups) return selections;

	  for (const group of groups) {
	    for (const item of group.items) {
	      if (item.variants && item.variants.length > 0) {
	        // Auto-select the default variant (or first one if no default)
	        const defaultVariant = item.variants.find((v) => v.default) || item.variants[0];
	        if (defaultVariant) {
	          selections[item.id] = defaultVariant.id;
	        }
	      }
	    }
	  }

	  return selections;
	}

function selectionsToRecord(sel: Selections): Record<string, string[]> {
	  const result: Record<string, string[]> = {};
	  for (const [key, set] of Object.entries(sel)) {
	    if (set.size > 0) result[key] = [...set];
	  }
	  return result;
	}

export default function ProductModal({
	  item,
	  open,
	  onOpenChange,
	  mode = "add",
	  initialQuantity,
	  initialSelections,
	  initialToppingQuantities,
	  initialToppingItemVariants,
	  initialNote,
	  cartItemIdToReplace,
}: ProductModalProps) {
	  const { addToCart, removeFromCart, orderType } = useCart();
	  // Format money in the current branch's currency (was hard-coded ¥ / ja-JP,
	  // which made the product modal disagree with the menu list on non-JPY shops).
	  const { format: fmt } = useCurrency();
	  // issue #1042 — 総額表示: the modal shows the stored price as-is. The
	  // branch's prices_include_tax toggle only drives the 税込/税抜 label (below),
	  // NOT the number, so every price in the modal matches the menu card AND what
	  // checkout charges. The values fed into addToCart stay raw (the cart /
	  // checkout + backend own the authoritative charge).
	  const { currentBranch } = useBrand();
	  const modalRate = resolveLineRate(item, currentBranch);
	  const modalIncludeTax = currentBranch.prices_include_tax ?? false;
	  // issue #1042 — show the stored price as-is; the toggle only drives the label.
	  const pd = (base: number) => base;
  const tc = useTranslations('cart');
  const tMenu = useTranslations('menu');
  const tCommon = useTranslations('common');
	  const tOc = useTranslations('orderConfirm');
	  const tShop = useTranslations('shop');
	  const router = useRouter();
	  const [showDraftBlocker, setShowDraftBlocker] = useState<{ id: string } | null>(null);
	  const [selections, setSelections] = useState<Selections>(() =>
	    initSelections(item.options, initialSelections),
	  );
	  const [toppingQuantities, setToppingQuantities] = useState<ToppingQuantities>(() =>
	    initToppingQuantities(item.toppingGroups, initialToppingQuantities as ToppingQuantities | undefined),
	  );
	  const [toppingItemVariants, setToppingItemVariants] = useState<ToppingItemVariantSelections>(() =>
	    initToppingItemVariants(item.toppingGroups, initialToppingItemVariants),
	  );
	  const [quantity, setQuantity] = useState(initialQuantity ?? 1);
	  const [note, setNote] = useState(initialNote ?? "");
	  // addToCart là async (fetch cart-config) và modal chỉ đóng sau khi nó
	  // resolve → cửa sổ vài trăm ms mà nút vẫn bấm được. Ref chặn ngay trong
	  // cùng một tick (setState còn batching nên state chưa kịp cập nhật giữa
	  // 2 cú tap liên tiếp); state chỉ để render spinner + disabled.
	  const submittingRef = useRef(false);
	  const [submitting, setSubmitting] = useState(false);

	  // Re-initialize state whenever the modal is (re)opened for a given item.
	  useEffect(() => {
	    if (!open) return;
	    setSelections(initSelections(item.options, initialSelections));
	    setToppingQuantities(
	      initToppingQuantities(
	        item.toppingGroups,
	        initialToppingQuantities as ToppingQuantities | undefined,
	      ),
	    );
	    setToppingItemVariants(
	      initToppingItemVariants(item.toppingGroups, initialToppingItemVariants),
	    );
	    setQuantity(initialQuantity ?? 1);
	    setNote(initialNote ?? "");
	    submittingRef.current = false;
	    setSubmitting(false);
	  }, [
	    open,
	    item,
	    initialSelections,
	    initialToppingQuantities,
	    initialToppingItemVariants,
	    initialQuantity,
	    initialNote,
	  ]);

  const handleSingleSelect = useCallback(
    (optionId: string, variantId: string) => {
      setSelections((prev) => ({
        ...prev,
        [optionId]: new Set([variantId]),
      }));
    },
    [],
  );

  const handleMultipleSelect = useCallback(
    (optionId: string, variantId: string, max?: number) => {
      setSelections((prev) => {
        const current = new Set(prev[optionId] || []);
        if (current.has(variantId)) {
          current.delete(variantId);
        } else {
          if (max && current.size >= max) return prev;
          current.add(variantId);
        }
        return { ...prev, [optionId]: current };
      });
    },
    [],
  );

  const handleToppingAdd = useCallback(
    (
      groupId: string,
      itemId: string,
      maxSelect: number | null,
      maxQtyPerItem: number,
    ) => {
      setToppingQuantities((prev) => {
        const groupQty = prev[groupId] || {};
        const currentQty = groupQty[itemId] || 0;
        // max_select counts unique item types across the WHOLE group
        // (Backend/Product/ToppingGroup.yaml: "Số lượng loại topping tối đa").
        // The UI splits free (combo) and paid sections only for layout —
        // both share the same group budget, so a default-selected free item
        // does count toward the cap and must be deselected to free a slot
        // for a paid pick. Previous per-section split here let the wire
        // payload exceed max_select and trip BE toppings_above_max.
        const totalSelected = Object.keys(groupQty).filter(
          (id) => (groupQty[id] || 0) > 0,
        ).length;

        if (currentQty === 0 && maxSelect && totalSelected >= maxSelect) {
          return prev;
        }

        if (currentQty >= maxQtyPerItem) {
          return prev;
        }

        return {
          ...prev,
          [groupId]: {
            ...groupQty,
            [itemId]: currentQty + 1,
          },
        };
      });
    },
    [],
  );

  // Single-select group (max_select=1) — picking an item replaces any prior
  // selection in the group, matching radio-button UX.
  const handleToppingSingleSelect = useCallback(
    (groupId: string, itemId: string) => {
      setToppingQuantities((prev) => ({
        ...prev,
        [groupId]: { [itemId]: 1 },
      }));
    },
    [],
  );

  const handleToppingRemove = useCallback(
    (groupId: string, itemId: string) => {
      setToppingQuantities((prev) => {
        const groupQty = { ...(prev[groupId] || {}) };
        const currentQty = groupQty[itemId] || 0;

        if (currentQty <= 1) {
          delete groupQty[itemId];
        } else {
          groupQty[itemId] = currentQty - 1;
        }

        return {
          ...prev,
          [groupId]: groupQty,
        };
      });
    },
    [],
  );

  const toppingExtra = useMemo(() => {
    if (!item.toppingGroups) return 0;
    let extra = 0;
    for (const group of item.toppingGroups) {
      const groupQty = toppingQuantities[group.id];
      if (!groupQty) continue;
      for (const topping of group.items) {
        const qty = groupQty[topping.id] || 0;
        if (qty > 0) {
          // Base topping price
          const basePrice = topping.price || 0;

          // If topping has variants, add the selected variant's extra price
          let variantExtra = 0;
          if (topping.variants && topping.variants.length > 0) {
            const selectedVariantId = toppingItemVariants[topping.id];
            if (selectedVariantId) {
              const selectedVariant = topping.variants.find((v) => v.id === selectedVariantId);
              if (selectedVariant) {
                variantExtra = selectedVariant.price;
              }
            }
          }

          extra += (basePrice + variantExtra) * qty;
        }
      }
    }
    return extra;
  }, [item.toppingGroups, toppingQuantities, toppingItemVariants]);

  // plan-019 — original variant price (before Happy Hour) for strikethrough.
  const baseUnitPrice = useMemo(() => {
    const sel = selectionsToRecord(selections);
    return calculateUnitPrice(item, sel);
  }, [item, selections]);

  // Discounted price used for the cart line. Promotion applies to the SKU unit
  // price only — toppings (toppingExtra) are added at full price afterwards,
  // matching the backend snapshot (discount on unit_price, not topping_subtotal).
  const unitPrice = useMemo(
    () => applyPromotionPercent(baseUnitPrice, item.active_promotion),
    [baseUnitPrice, item.active_promotion],
  );

	const totalPrice = (unitPrice + toppingExtra) * quantity;

	const handleSubmit = useCallback(async () => {
	    // Chặn double-submit: nhấn nhiều lần trước khi modal kịp đóng sẽ thêm
	    // trùng dòng vào giỏ.
	    if (submittingRef.current) return;
	    // Block khi đang có draft (đơn đang chờ xác nhận trên /order-confirm).
	    // Customer phải xác nhận hoặc huỷ trước khi đặt món mới.
	    const activeDraft = findActiveCheckoutDraft();
	    if (activeDraft) {
	      setShowDraftBlocker({ id: activeDraft.id });
	      return;
	    }
	    submittingRef.current = true;
	    setSubmitting(true);
	    const sel = selectionsToRecord(selections);
	    const id = generateCartItemId(item.id, sel, toppingQuantities, toppingItemVariants);

	    // Per-unit price stored on the cart line MUST include the topping extras
	    // (promotion already applied to the base unit price only — toppings are
	    // added at full price, matching the modal total and the backend snapshot).
	    const lineUnitPrice = unitPrice + toppingExtra;

	    const cartPayload = {
	      id,
	      product: item,
	      selections: sel,
	      quantity,
	      unitPrice: lineUnitPrice,
	      toppingQuantities,
	      toppingItemVariants,
	      note: note.trim() || undefined,
	    };

	    try {
	      if (mode === "edit" && cartItemIdToReplace) {
	        // Replace the existing line instead of incrementing it.
	        removeFromCart(cartItemIdToReplace);
	        await addToCart(cartPayload);
	        toast.success(tc('updatedInCart'), {
	          description: `${quantity} x ${fmt(pd(lineUnitPrice))}`,
	        });
	      } else {
	        await addToCart(cartPayload);
	        toast.success(tc('addedToCart', { name: item.name }), {
	          description: tc('addedToCartDesc', { quantity, price: fmt(pd(lineUnitPrice)) }),
	        });
	      }
	    } finally {
	      // Mở khoá lại: nếu addToCart ném lỗi, khách vẫn phải bấm lại được.
	      submittingRef.current = false;
	      setSubmitting(false);
	    }

	    // Reset state & close
	    setQuantity(initialQuantity ?? 1);
	    setSelections(initSelections(item.options, initialSelections));
	    setToppingQuantities(
	      initToppingQuantities(
	        item.toppingGroups,
	        initialToppingQuantities as ToppingQuantities | undefined,
	      ),
	    );
	    setToppingItemVariants(
	      initToppingItemVariants(item.toppingGroups, initialToppingItemVariants),
	    );
	    setNote(initialNote ?? "");
	    onOpenChange(false);
	  }, [
	    item,
	    selections,
	    quantity,
	    note,
	    unitPrice,
	    toppingExtra,
	    toppingQuantities,
	    toppingItemVariants,
	    addToCart,
	    removeFromCart,
	    cartItemIdToReplace,
	    mode,
	    tc,
	    initialQuantity,
	    initialSelections,
	    initialToppingQuantities,
	    initialToppingItemVariants,
	    initialNote,
	    onOpenChange,
	  ]);

  const hasOptions = item.options && item.options.length > 0;
  const hasToppingGroups = item.toppingGroups && item.toppingGroups.length > 0;
  const hasComboItems = item.is_combo && item.comboItems && item.comboItems.length > 0;
  const hasAnyCustomization = hasOptions || hasToppingGroups || hasComboItems;

  // Ghi chú cho khách — hiển thị cho mọi sản phẩm (kể cả không có option).
  const noteSection = (
    <div className="px-4 py-3">
      <h3 className="mb-2 font-medium text-foreground" style={{ fontSize: '20px', lineHeight: '24px' }}>
        {tc('note')}
      </h3>
      <textarea
        value={note}
        onChange={(e) => setNote(e.target.value)}
        placeholder={tc('notePlaceholder')}
        rows={2}
        maxLength={200}
        className="w-full resize-none rounded-lg border border-gray-200 px-3 py-2.5 text-sm text-foreground placeholder:text-gray-400 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500"
      />
    </div>
  );

  // Validate required topping groups (min_select > 0)
  const unsatisfiedGroups = useMemo(() => {
    if (!item.toppingGroups) return [];
    return item.toppingGroups.filter((group) => {
      if (group.min_select <= 0) return false;
      const groupQty = toppingQuantities[group.id] || {};
      const selectedCount = Object.values(groupQty).filter((q) => q > 0).length;
      return selectedCount < group.min_select;
    });
  }, [item.toppingGroups, toppingQuantities]);

	// #1167 — a shut shop takes no take-away orders, so the basket can't grow
	// either. Dine-in is never blocked (see useOrderingBlocked).
	const { blocked: orderingBlocked } = useOrderingBlocked();

	const canAddToCart = unsatisfiedGroups.length === 0 && !orderingBlocked;

	const { heroPrimarySrc, heroFallbackSrc } = useMemo(() => {
	  const base = item.image ?? HERO_FALLBACK_IMAGE;
	  if (!item.options) {
	    return { heroPrimarySrc: base, heroFallbackSrc: null };
	  }
	  // Prefer the currently selected variant's own image when available,
	  // but fall back to the product image if that URL fails to load.
	  for (let i = item.options.length - 1; i >= 0; i--) {
	    const option = item.options[i];
	    const selectedIds = selections[option.id];
	    if (!selectedIds || selectedIds.size === 0) {
	      continue;
	    }
	    const match = option.variants.find(
	      (v) => selectedIds.has(v.id) && v.image,
	    );
	    if (match?.image) {
	      return { heroPrimarySrc: match.image, heroFallbackSrc: base };
	    }
	  }
	  return { heroPrimarySrc: base, heroFallbackSrc: null };
	}, [item, selections]);

  return (
    <>
    <Dialog open={open} onOpenChange={onOpenChange}>
	      <DialogContent
	        className="flex h-full max-h-screen w-full max-w-full sm:max-h-[95vh] sm:max-w-md flex-col gap-0 overflow-hidden rounded-none sm:rounded-2xl p-0"
	        showCloseButton={false}
	      >
	        {/* Hero image with overlay info */}
	        <div className="relative w-full shrink-0 overflow-hidden bg-gradient-to-br from-muted/60 to-muted h-[180px] sm:h-[235px]">
	          <ProductImage
	            src={heroPrimarySrc}
	            fallbackSrc={heroFallbackSrc}
	            alt={item.name}
	            className="absolute inset-0 h-full w-full object-cover"
	          />

          {/* Close button */}
          <button
            type="button"
            aria-label={tCommon('close')}
            onClick={() => onOpenChange(false)}
            className="absolute right-3 top-3 z-10 flex h-10 w-10 items-center justify-center rounded-full bg-white text-black shadow-md transition-all hover:bg-gray-100 active:scale-95"
          >
            <X className="h-5 w-5" strokeWidth={2} />
          </button>

          {/* plan-019 — Happy Hour badge */}
          {item.active_promotion && (
            <HappyHourBadge
              discountPercent={item.active_promotion.discount_percent}
              endsAt={item.active_promotion.ends_at}
            />
          )}

          {/* Product info overlay - bottom.
              pointer-events-none: pt-12 stretches this element up past the
              close button's hit area; without it, clicks on the X land on
              the overlay (later sibling, same z-10) and never reach the
              button. The overlay is purely decorative — gradient + label —
              so disabling pointer events here is safe. */}
          <div className="pointer-events-none absolute bottom-0 left-0 right-0 z-10 bg-gradient-to-t from-black/80 via-black/60 to-transparent px-4 pb-3 pt-12">
            {item.categoryName && (
              <Badge className="mb-2 inline-block rounded-md bg-white px-2.5 py-0.5 font-normal text-foreground shadow-sm" style={{ fontSize: '11px' }}>
                {item.categoryName}
              </Badge>
            )}
            <h2 className="font-semibold text-white" style={{ fontSize: '24px', lineHeight: '28px' }}>{item.name}</h2>
            <div className="mt-1 flex items-center gap-1.5 text-white">
              {item.active_promotion ? (
                <>
                  <span className="font-medium text-white/70 line-through" style={{ fontSize: '14px', lineHeight: '20px' }}>
                    {fmt(pd(item.price))}
                  </span>
                  <span className="font-bold text-amber-300" style={{ fontSize: '22px', lineHeight: '20px' }}>
                    {fmt(pd(effectiveUnitPrice(item)))}
                  </span>
                </>
              ) : (
                <span className="font-bold" style={{ fontSize: '22px', lineHeight: '20px' }}>
                  {fmt(pd(item.price))}
                </span>
              )}
            </div>
            {/* issue #1042 — tax-status label so the customer knows whether the
                shown price already includes tax (税込) or not (税抜). */}
            {modalRate != null && (
              <span className="mt-0.5 block text-white/80" style={{ fontSize: '11px' }}>
                {modalIncludeTax ? tMenu('taxIncluded') : tMenu('taxExcluded')}
              </span>
            )}
            {item.description && (
              <p className="mt-0.5 text-white/95 line-clamp-2" style={{ fontSize: '12px' }}>{item.description}</p>
            )}
          </div>
        </div>

        {/* Body */}
        <div className="flex flex-1 flex-col overflow-hidden bg-white">
          {/* Options & Topping Groups — scrollable */}
          {hasAnyCustomization ? (
            <div className="flex-1 overflow-y-auto">
              {hasOptions && item.options!.map((option) => (
                <div key={option.id} className="border-b border-gray-200">
                  {/* Group header */}
                  <div className="flex items-center justify-between gap-2 px-3 sm:px-4 py-2 sm:py-3 border-b border-gray-100">
                    <h3 className="font-medium text-foreground" style={{ fontSize: '20px', lineHeight: '24px' }}>{option.name}</h3>
                    <span className="rounded-lg px-2 sm:px-3 py-0.5 sm:py-1 text-gray-500 whitespace-nowrap" style={{ backgroundColor: '#F0F0F0', fontSize: '12px' }}>
                      {option.required
                        ? tc('optionRequiredChooseOne')
                        : option.type === "single"
                          ? tc('optionalChooseOne')
                          : option.max
                            ? tc('optionalChooseUpTo', { max: option.max })
                            : tc('optional')}
                    </span>
                  </div>

                  {/* Variants */}
                  <div className="space-y-0">
                    {option.variants.map((variant) => {
                      const isSelected = selections[option.id]?.has(variant.id) ?? false;
                      const hasImage = variant.image;

                      return (
                        <button
                          key={variant.id}
                          type="button"
                          onClick={() =>
                            option.type === "single"
                              ? handleSingleSelect(option.id, variant.id)
                              : handleMultipleSelect(option.id, variant.id, option.max)
                          }
                          className="w-full text-left transition-all"
                        >
                          <div
                            className={`flex items-center gap-2 sm:gap-3 px-3 sm:px-4 transition-all ${hasImage ? "py-2.5 sm:py-3" : "py-2 sm:py-2.5"} hover:bg-gray-50/50`}
                          >
                            {hasImage && (
                              <ProductImage
                                src={variant.image}
                                alt={variant.name}
                                className="h-12 w-12 shrink-0 overflow-hidden rounded-lg object-cover"
                              />
                            )}
                            <div className="min-w-0 flex-1">
                              <p className="font-normal text-foreground" style={{ fontSize: '18px' }}>{variant.name}</p>
                              {variant.price > 0 && (
                                <p className="mt-0.5 text-gray-400" style={{ fontSize: '12px' }}>
                                  {fmt(pd(variant.price))}
                                </p>
                              )}
                            </div>
                            {/* Checkmark indicator - always show */}
                            <div
                              className={`flex h-5 w-5 shrink-0 items-center justify-center transition-all ${option.type === "single" ? "rounded-full" : "rounded"
                                } ${!isSelected ? "border-2 border-gray-300 bg-white" : ""
                                }`}
                              style={isSelected ? { backgroundColor: '#2D8336', borderColor: '#2D8336', border: '2px solid #2D8336' } : undefined}
                            >
                              {isSelected && (
                                <svg className="h-3 w-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                                </svg>
                              )}
                            </div>
                          </div>
                        </button>
                      );
                    })}
                  </div>
                </div>
              ))}

              {/* Topping Groups — one section per group, unified +/- counter UI
                  for ALL items regardless of price. Free items (price=0) just
                  omit the price line; the row + handlers behave the same as
                  paid items. Previous split (combo checkboxes vs paid counters)
                  surprised users — same group rendered as two boxes with
                  different controls. */}
              {hasToppingGroups && item.toppingGroups!.map((group) => {
                const groupSelections = toppingQuantities[group.id] || {};
                const isUnsatisfied = unsatisfiedGroups.some((g) => g.id === group.id);
                const selectedCount = Object.values(groupSelections).filter((q) => q > 0).length;
                // #1429 — the badge used to be built straight off `min_select`
                // ("Chọn 1") and that was the ONLY thing the group said. Three
                // gaps: a `min=1, max=3` group also read "Chọn 1" so nobody
                // found the other two; an optional group rendered no badge at
                // all, so it looked like it might be required; and
                // `max_qty_per_item` was never mentioned anywhere.
                //
                // Split in two now — badge = is this required, sentence = what
                // exactly to do — with the "a cap that cannot bite is not shown"
                // rule preserved inside describeToppingGroupRule().
                const rule = describeToppingGroupRule(group);
                const ruleText = [
                  rule.kind === 'exact'
                    ? tc('ruleExact', { count: rule.count! })
                    : rule.kind === 'range'
                      ? tc('ruleRange', { min: rule.min!, max: rule.max! })
                      : rule.kind === 'atLeast'
                        ? tc('ruleAtLeast', { count: rule.min! })
                        : rule.kind === 'upTo'
                          ? tc('ruleUpTo', { count: rule.max! })
                          : tc('ruleAny'),
                  rule.perItem ? tc('rulePerItem', { count: rule.perItem }) : null,
                  // Progress only where there is a bar to clear. On an optional
                  // group "0/0" would read like an unmet requirement.
                  rule.required
                    ? tc('ruleProgress', { selected: selectedCount, required: group.min_select })
                    : null,
                ]
                  .filter(Boolean)
                  .join(' · ');

                return (
                  <div key={`group-${group.id}`} className="border-b border-gray-200">
                    <div className="flex items-start justify-between gap-2 px-4 py-3">
                      <div className="min-w-0 flex-1">
                        <h3 className={`min-w-0 truncate font-medium ${isUnsatisfied ? "text-red-600" : "text-foreground"}`} style={{ fontSize: '20px', lineHeight: '24px' }}>
                          {group.name}
                        </h3>
                        <p
                          className={`mt-0.5 ${isUnsatisfied ? "text-red-600" : "text-gray-500"}`}
                          style={{ fontSize: '13px', lineHeight: '18px' }}
                        >
                          {ruleText}
                        </p>
                      </div>
                      <span
                        className={`mt-0.5 shrink-0 rounded-lg px-3 py-1 whitespace-nowrap ${isUnsatisfied ? "text-red-600" : "text-gray-500"}`}
                        style={{ backgroundColor: '#F0F0F0', fontSize: '12px' }}
                      >
                        {rule.required ? tc('groupRequired') : tc('groupOptional')}
                      </span>
                    </div>

                    <div className="space-y-0">
                      {group.items.map((topping) => {
                        const currentQty = groupSelections[topping.id] || 0;
                        const hasVariants = topping.variants && topping.variants.length > 0;
                        const selectedVariantId = toppingItemVariants[topping.id];
                        const price = topping.price ?? 0;
                        // UI rule:
                        //   max_qty_per_item > 1  ⇒ +/- counter  (stack qty)
                        //   else max_select === 1 ⇒ radio        (chỉ được 1 loại)
                        //   else                  ⇒ checkbox     (multi / không cap)
                        // Radio quyết định bởi max_select thôi, KHÔNG kèm min_select.
                        // Nhóm optional exact-one (min=0, max=1) trước đây rơi vào
                        // checkbox: khách tick A rồi bấm B thì handleToppingAdd gặp
                        // totalSelected >= maxSelect và return prev im lặng — không
                        // toast, không disable, khách tưởng app đơ. min_select giờ
                        // chỉ còn quyết định được-bỏ-chọn-hay-không (xem onClick).
                        const stackable = (group.max_qty_per_item ?? 1) > 1;
                        const isRadio = !stackable && group.max_select === 1;
                        const isCheckbox = !stackable && !isRadio;
                        const isSelected = currentQty > 0;

                        return (
                          <div key={topping.id}>
                            {isRadio || isCheckbox ? (
                              <button
                                type="button"
                                aria-pressed={isSelected}
                                onClick={() => {
                                  if (isRadio) {
                                    // Radio: bấm item khác thì thay tại chỗ. Bấm lại
                                    // item đang chọn thì tuỳ min_select — nhóm optional
                                    // (min=0) cho bỏ chọn, nếu khoá thì khách lỡ tay
                                    // chọn topping mất phí sẽ không gỡ ra được. Nhóm
                                    // bắt buộc (min>=1) giữ no-op, không cho về rỗng.
                                    if (!isSelected) {
                                      handleToppingSingleSelect(group.id, topping.id);
                                    } else if (group.min_select === 0) {
                                      handleToppingRemove(group.id, topping.id);
                                    }
                                  } else if (isSelected) {
                                    handleToppingRemove(group.id, topping.id);
                                  } else {
                                    handleToppingAdd(group.id, topping.id, group.max_select, group.max_qty_per_item);
                                  }
                                }}
                                className="w-full text-left transition-all"
                              >
                                <div className="flex items-center gap-3 px-4 py-3 hover:bg-gray-50/50">
                                  {topping.image ? (
                                    <ProductImage
                                      src={topping.image}
                                      alt={topping.name}
                                      className="h-12 w-12 shrink-0 overflow-hidden rounded-lg object-cover"
                                    />
                                  ) : (
                                    <div className="h-12 w-12 shrink-0 rounded-lg bg-gray-100" />
                                  )}
                                  <div className="min-w-0 flex-1">
                                    <p className="font-normal text-foreground" style={{ fontSize: '18px' }}>{topping.name}</p>
                                    <p className="mt-0.5 text-gray-400" style={{ fontSize: '12px' }}>
                                      {fmt(pd(price))}
                                    </p>
                                  </div>
                                  <div
                                    className={`flex h-5 w-5 shrink-0 items-center justify-center transition-all ${isRadio ? "rounded-full" : "rounded"} ${!isSelected ? "border-2 border-gray-300 bg-white" : ""}`}
                                    style={isSelected ? { backgroundColor: '#2D8336', borderColor: '#2D8336', border: '2px solid #2D8336' } : undefined}
                                  >
                                    {isSelected && (
                                      isRadio ? (
                                        <div className="h-2 w-2 rounded-full bg-white" />
                                      ) : (
                                        <svg className="h-3 w-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={3} d="M5 13l4 4L19 7" />
                                        </svg>
                                      )
                                    )}
                                  </div>
                                </div>
                              </button>
                            ) : (
                              <div className="flex items-center gap-3 px-4 py-3">
                                {topping.image ? (
                                  <ProductImage
                                    src={topping.image}
                                    alt={topping.name}
                                    className="h-12 w-12 shrink-0 overflow-hidden rounded-lg object-cover"
                                  />
                                ) : (
                                  <div className="h-12 w-12 shrink-0 rounded-lg bg-gray-100" />
                                )}
                                <div className="min-w-0 flex-1">
                                  <p className="font-normal text-foreground" style={{ fontSize: '18px' }}>{topping.name}</p>
                                  <p className="mt-0.5 text-gray-400" style={{ fontSize: '12px' }}>
                                    {fmt(pd(price))}
                                  </p>
                                </div>

                                {currentQty > 0 ? (
                                  <div className="flex items-center gap-2">
                                    <button
                                      type="button"
                                      aria-label={`${tc('decreaseQty')}: ${topping.name}`}
                                      onClick={() => handleToppingRemove(group.id, topping.id)}
                                      className="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-700 transition-colors hover:bg-gray-200"
                                    >
                                      <Minus className="h-4 w-4" />
                                    </button>
                                    <span className="w-6 text-center text-sm font-medium tabular-nums text-foreground">
                                      {currentQty}
                                    </span>
                                    <button
                                      type="button"
                                      aria-label={`${tc('increaseQty')}: ${topping.name}`}
                                      onClick={() =>
                                        handleToppingAdd(group.id, topping.id, group.max_select, group.max_qty_per_item)
                                      }
                                      className="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-700 transition-colors hover:bg-gray-200"
                                    >
                                      <Plus className="h-4 w-4" />
                                    </button>
                                  </div>
                                ) : (
                                  <button
                                    type="button"
                                    aria-label={`${tc('increaseQty')}: ${topping.name}`}
                                    onClick={() =>
                                      handleToppingAdd(group.id, topping.id, group.max_select, group.max_qty_per_item)
                                    }
                                    className="flex h-9 w-9 items-center justify-center rounded-full border border-gray-200 bg-gray-50 text-gray-700 transition-colors hover:bg-gray-100"
                                  >
                                    <Plus className="h-4 w-4" />
                                  </button>
                                )}
                              </div>
                            )}

                            {/* Variant selector — visible only when this item is picked */}
                            {currentQty > 0 && hasVariants && (
                              <div className="border-t bg-muted/30 px-3 py-2.5">
                                <div className="space-y-2">
                                  <p className="text-xs font-medium text-muted-foreground">{tc('chooseVariant')}</p>
                                  <div className="flex flex-wrap gap-2">
                                    {topping.variants!.map((variant) => {
                                      const isSelected = selectedVariantId === variant.id;

                                      return (
                                        <button
                                          key={variant.id}
                                          type="button"
                                          onClick={() => {
                                            setToppingItemVariants((prev) => ({
                                              ...prev,
                                              [topping.id]: variant.id,
                                            }));
                                          }}
                                          className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${isSelected
                                            ? "border-emerald-500 bg-emerald-500 text-white"
                                            : "border-border bg-background hover:bg-muted"
                                            }`}
                                        >
                                          {variant.name}
                                          {variant.price > 0 &&
                                            ` (+${fmt(pd(variant.price))})`}
                                        </button>
                                      );
                                    })}
                                  </div>
                                </div>
                              </div>
                            )}
                          </div>
                        );
                      })}
                    </div>
                  </div>
                );
              })}

              {noteSection}
            </div>
          ) : (
            <div className="flex-1 overflow-y-auto">{noteSection}</div>
          )}

          {/* Footer */}
          <div className="shrink-0 bg-white px-3 sm:px-4 py-3 sm:py-4 space-y-2 sm:space-y-3" style={{ boxShadow: '0px -6px 16px 0px #0000000D' }}>
            {/* #1167 — why the add button is dead */}
            {orderingBlocked && (
              <div className="rounded-lg bg-red-50 border border-red-200 px-3 py-2.5">
                <p className="text-red-700 font-medium" style={{ fontSize: '10px' }}>
                  {tShop('closedOrderingDisabled')}
                </p>
              </div>
            )}

            {/* Validation warning */}
            {!orderingBlocked && unsatisfiedGroups.length > 0 && (
              <div className="rounded-lg bg-red-50 border border-red-200 px-3 py-2.5">
                <p className="text-red-700 font-medium" style={{ fontSize: '10px' }}>
                  {tc('pleaseChoose', { groups: unsatisfiedGroups.map((g) => g.name).join(", ") })}
                </p>
              </div>
            )}

            {/* Total row */}
            <div className="flex items-center justify-between py-3">
              <span className="tabular-nums" style={{ fontWeight: 600, fontSize: '20px', lineHeight: '20px', color: '#646567' }}>{tc('total')}</span>
              <span className="tabular-nums" style={{ fontWeight: 600, fontSize: '20px', lineHeight: '20px' }}>
                {fmt(pd(totalPrice))}
              </span>
            </div>

            {/* Quantity + Add to Cart */}
            <div className="flex items-center gap-3">
              {/* Quantity counter — three separate circular buttons */}
              <div className="flex flex-1 items-center justify-center gap-3">
                <button
                  type="button"
                  aria-label={tc('decreaseQty')}
                  onClick={() => setQuantity((q) => Math.max(1, q - 1))}
                  className="flex items-center justify-center rounded-full bg-gray-100 text-gray-700 transition-colors hover:bg-gray-200 disabled:opacity-40 disabled:hover:bg-gray-100"
                  style={{ width: '36px', height: '36px' }}
                  disabled={quantity <= 1}
                >
                  <Minus className="h-4 w-4" />
                </button>
                <span className="min-w-[2rem] text-center font-semibold tabular-nums text-foreground" style={{ fontSize: '20px' }}>{quantity}</span>
                <button
                  type="button"
                  aria-label={tc('increaseQty')}
                  onClick={() => setQuantity((q) => q + 1)}
                  className="flex items-center justify-center rounded-full bg-gray-100 text-gray-700 transition-colors hover:bg-gray-200"
                  style={{ width: '36px', height: '36px' }}
                >
                  <Plus className="h-4 w-4" />
                </button>
              </div>

              {/* Add button */}
              <Button
                className="flex-1 rounded-lg font-semibold disabled:bg-gray-200 disabled:text-gray-400"
                style={{ backgroundColor: '#2D8336', height: '56px', fontSize: '16px' }}
	                onClick={handleSubmit}
                disabled={!canAddToCart || submitting}
              >
                {submitting ? (
                  <Loader2 className="h-5 w-5 animate-spin" />
                ) : mode === "edit" ? (
                  tc('updateItem')
                ) : orderType === "dine_in" ? (
                  tc('addItem')
                ) : (
                  tc('addToCartLong')
                )}
              </Button>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>

    {/* Block khi đang có draft chờ xác nhận trên /order-confirm */}
    <Dialog open={!!showDraftBlocker} onOpenChange={(o) => !o && setShowDraftBlocker(null)}>
      <DialogContent showCloseButton={false} className="max-w-sm rounded-2xl p-0">
        <div className="px-5 pt-5 pb-3">
          <h2 className="text-base font-bold text-neutral-900">{tOc('draftBlockerTitle')}</h2>
          <p className="mt-2 text-sm leading-relaxed text-neutral-600">{tOc('draftBlockerMessage')}</p>
        </div>
        <div className="grid grid-cols-2 gap-2 border-t border-neutral-200 p-3">
          <button
            type="button"
            onClick={() => setShowDraftBlocker(null)}
            className="flex h-10 items-center justify-center rounded-xl border border-neutral-200 bg-white text-sm font-semibold text-neutral-700 transition-colors hover:bg-neutral-50"
          >
            {tOc('draftBlockerStay')}
          </button>
          <button
            type="button"
            onClick={() => {
              const id = showDraftBlocker?.id;
              setShowDraftBlocker(null);
              if (id) router.push(`/order-confirm/${id}`);
            }}
            className="flex h-10 items-center justify-center rounded-xl bg-emerald-500 text-sm font-semibold text-white transition-colors hover:bg-emerald-600"
          >
            {tOc('draftBlockerGoConfirm')}
          </button>
        </div>
      </DialogContent>
    </Dialog>
    </>
  );
}
