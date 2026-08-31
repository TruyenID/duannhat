"use client";

import { useState } from "react";

import {
	  Sheet,
	  SheetContent,
	  SheetFooter,
	} from "@/components/ui/sheet";
	import { Button } from "@/components/ui/button";
	import {
	  Dialog,
	  DialogContent,
	  DialogDescription,
	  DialogFooter,
	  DialogHeader,
	  DialogTitle,
	} from "@/components/ui/dialog";
	import ProductModal from "./product-modal";
import { useTranslations } from 'next-intl';
import { useRouter } from "@/i18n/routing";
import { useCart, resolveCartItemImage, type CartItem } from "@/context/cart-context";
import { useGlobalLoading } from "@/context/loading-context";
import { ArrowLeft, Minus, Plus, Trash2, Clock, X, AlertCircle, Info } from "lucide-react";
import Header from "@/components/Header";
import { CartItemOptionsList, buildCartOptionLines } from "./cart-item-options";
import { useCurrency } from "@/lib/currency";
import { useOrderingBlocked } from "@/hooks/use-branch-open-state";

interface CartDrawerProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

const CART_IMAGE_FALLBACK = "/images/craft-pho-bowl.webp";

function CartItemImage({
  primarySrc,
  fallbackSrc,
  alt,
  size,
}: {
  primarySrc?: string | null;
  fallbackSrc?: string | null;
  alt: string;
  size: "sm" | "lg";
}) {
  const [currentSrc, setCurrentSrc] = useState<string | null>(() =>
    primarySrc ?? fallbackSrc ?? null,
  );
  const [errored, setErrored] = useState(false);
  // Derived-state reset (React's recommended pattern over useEffect+setState):
  // when src props change we reset both pieces of local state in a single render
  // pass instead of triggering a cascading render in an effect.
  const [prevSrcKey, setPrevSrcKey] = useState(`${primarySrc}|${fallbackSrc}`);
  const nextKey = `${primarySrc}|${fallbackSrc}`;
  if (prevSrcKey !== nextKey) {
    setPrevSrcKey(nextKey);
    setErrored(false);
    setCurrentSrc(primarySrc ?? fallbackSrc ?? null);
  }

  const wrapperClasses =
    size === "sm"
      ? "flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-muted-foreground/30"
      : "flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-muted-foreground/30";

	  // When both the variant image and product image fail to load (or are
	  // missing), fall back to the shared bowl photo instead of a generic icon
	  // so the cart stays visually consistent with the menu grid and product
	  // modal.
	  if (!currentSrc || errored) {
	    const safeFallback = fallbackSrc ?? CART_IMAGE_FALLBACK;
	    return (
	      <div className={wrapperClasses}>
	        {/* eslint-disable-next-line @next/next/no-img-element */}
	        <img
	          src={safeFallback}
	          alt={alt}
	          className="h-full w-full object-cover"
	        />
	      </div>
	    );
	  }

	  return (
	    <div className={wrapperClasses}>
	      {/* eslint-disable-next-line @next/next/no-img-element */}
	      <img
	        src={currentSrc}
	        alt={alt}
	        className="h-full w-full object-cover"
	        onError={() => {
	          if (fallbackSrc && currentSrc !== fallbackSrc) {
	            setCurrentSrc(fallbackSrc);
	          } else {
	            setErrored(true);
	          }
	        }}
	      />
	    </div>
	  );
}

// Cart line price. When the món has an active promotion (Happy Hour), show the
// pre-promotion "giá gốc" struck through next to the discounted "giá sale".
function LinePrice({ item, className }: { item: CartItem; className?: string }) {
  const { format: fmt } = useCurrency();
  const t = useTranslations('cart');
  const promo = item.product.active_promotion;
  // #1715 — giá dòng vừa được đồng bộ lại với menu đang phát. Nói ra bằng chữ:
  // đổi con số trong giỏ mà không giải thích thì khách chỉ thấy tổng tiền tự
  // nhảy. `from` là giá lúc khách CHỌN món, nên câu này luôn so với cái họ nhớ.
  const adjusted = item.priceAdjusted;
  const adjustedNote = adjusted ? (
    <p className="text-xs font-medium text-amber-700">
      {adjusted.to > adjusted.from
        ? t('priceAdjustedUp', { from: fmt(adjusted.from) })
        : t('priceAdjustedDown', { from: fmt(adjusted.from) })}
    </p>
  ) : null;
  const pct = promo?.discount_percent ?? 0;
  if (!promo || pct <= 0 || pct >= 100) {
    return (
      <div className={className}>
        <p className="text-lg font-bold">{fmt(item.unitPrice)}</p>
        {adjustedNote}
      </div>
    );
  }
  // unitPrice is already discounted; reverse the percent to recover the original
  // (exact for items with no toppings; a slight over-estimate otherwise).
  const original = Math.round((item.unitPrice * 100) / (100 - pct));
  return (
    <div className={className}>
      <div className="flex items-baseline gap-1.5">
        <span className="text-xs font-medium text-neutral-400 line-through">{fmt(original)}</span>
        <span className="text-lg font-bold text-neutral-900">{fmt(item.unitPrice)}</span>
      </div>
      {adjustedNote}
    </div>
  );
}

export default function CartDrawer({ open, onOpenChange }: CartDrawerProps) {
  const router = useRouter();
  const t = useTranslations('cart');
  const tCommon = useTranslations('common');
  const tShop = useTranslations('shop');
  const { format: fmt } = useCurrency();
  const { showLoading } = useGlobalLoading();
  const {
    items,
    totalItems,
    updateQuantity,
    removeFromCart,
    clearCart,
    isItemExpired,
    cartMetadata,
    secondsRemaining,
  } = useCart();

  const [editingItemId, setEditingItemId] = useState<string | null>(null);
  const [confirmClearOpen, setConfirmClearOpen] = useState(false);
  // Confirm-before-remove: holds the id of the item pending deletion.
  const [removeTarget, setRemoveTarget] = useState<string | null>(null);

  function handleConfirmClear() {
    clearCart();
    setConfirmClearOpen(false);
    onOpenChange(false);
  }

  function handleConfirmRemove() {
    if (removeTarget) removeFromCart(removeTarget);
    setRemoveTarget(null);
  }

  // Helper: format seconds to mm:ss
  function formatCountdown(seconds: number): string {
    const mm = Math.floor(seconds / 60);
    const ss = seconds % 60;
    return `${mm.toString().padStart(2, "0")}:${ss.toString().padStart(2, "0")}`;
  }

	  // Group items: active vs expired
	  const activeItems = items.filter((item) => !isItemExpired(item));
	  const expiredItems = items.filter((item) => isItemExpired(item));
	  const editingItem = items.find((item) => item.id === editingItemId) ?? null;

  // Disable checkout if any item expired
  const hasExpiredItem = expiredItems.length > 0;

  // #1167 — …or if the shop shut while the drawer sat open. Take-away only;
  // dine-in keeps ordering (see useOrderingBlocked).
  const { blocked: orderingBlocked } = useOrderingBlocked();
  const checkoutDisabled = hasExpiredItem || orderingBlocked;

  // Cart-level timeout (menu rollover grace period) — show countdown ONLY
  // after the menu has switched. We derive this from the global cart
  // deadline and the configured timeout_minutes from the backend:
  //   • When menu đang chạy: secondsRemaining > timeout_seconds
  //   • Khi menu đã chuyển (grace window): 0 < secondsRemaining <= timeout_seconds
  const cartTimeoutSeconds =
    typeof cartMetadata?.timeout_minutes === "number"
      ? cartMetadata.timeout_minutes * 60
      : null;

  const isCartInGraceWindow =
    !!cartTimeoutSeconds &&
    secondsRemaining > 0 &&
    secondsRemaining <= cartTimeoutSeconds &&
    activeItems.length > 0;

  // Total price from ACTIVE items only
  const activeTotalPrice = activeItems.reduce((sum, i) => sum + i.unitPrice * i.quantity, 0);
  // godx-tempo#1719 — đếm theo SỐ LƯỢNG, không phải số dòng. Con số này đứng
  // ngay cạnh `activeTotalPrice`, mà giá đó đã nhân số lượng — nên đếm dòng
  // làm hai vế cùng một hàng nói hai chuyện khác nhau ("Tạm tính 1 món ￥2.700"
  // cho một dòng số lượng 2). Phạm vi vẫn là món CÒN HẠN, để khớp đúng những
  // gì số tiền đang cộng.
  const activeTotalQuantity = activeItems.reduce((sum, i) => sum + i.quantity, 0);

  return (
    <>
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" showCloseButton={false} className="flex flex-col p-0 !w-full md:!max-w-md">
        {/* Mobile block (Header global + sub-header "← Giỏ hàng") đã được
            ẩn theo yêu cầu — section "Giỏ hàng (N món) ..." bên dưới giờ là
            phần đầu của trang giỏ hàng trên mobile, vẫn có nút X để đóng.
            Desktop không bị động đến (block này vốn `md:hidden`). */}
        <div className="hidden">
          <Header hideSwitcher showLogo hideOrderCta hideShadow />
          <div className="sticky top-12 z-30 flex items-center gap-2 border-b bg-white px-4 py-2">
            <button
              aria-label={tCommon('back')}
              onClick={() => onOpenChange(false)}
              className="-ml-1 flex size-7 items-center justify-center rounded-lg text-neutral-700 transition-colors hover:bg-muted"
            >
              <ArrowLeft className="size-5" />
            </button>
            <span className="text-base font-bold text-neutral-900">{t('heading')}</span>
          </div>
        </div>

        {/* Header: tiêu đề + subtitle + Xóa tất cả + đóng. Mobile padding-y
            20px (py-5) theo yêu cầu — content thoáng hơn. Desktop giữ py-3. */}
        <div className="border-b px-4 py-5 md:py-3">
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <h2 className="text-base md:text-lg font-bold leading-6 whitespace-nowrap">
                {t('title', { count: totalItems })}
              </h2>
              <p className="mt-0.5 text-xs text-muted-foreground">{t('reviewSubtitle')}</p>
            </div>
            <div className="flex shrink-0 items-center gap-2">
              {items.length > 0 && (
                <button
                  onClick={() => setConfirmClearOpen(true)}
                  className="shrink-0 inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs md:text-sm font-medium transition-colors hover:opacity-80 bg-white"
                  style={{
                    color: "#4B5563",
                    boxShadow: "0px 4px 4px 0px #00000040",
                  }}
                >
                  <Trash2 className="h-3.5 w-3.5 text-red-500" />
                  {t('clearAll')}
                </button>
              )}
              <button
                onClick={() => onOpenChange(false)}
                className="flex size-7 shrink-0 items-center justify-center rounded-full text-neutral-600 transition-colors hover:bg-muted"
                aria-label={t('clearAllCancel')}
              >
                <X className="size-5" />
              </button>
            </div>
          </div>
        </div>

        {/* Items list (scrollable) */}
        <div className="flex-1 overflow-y-auto">
          {items.length === 0 ? (
            <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
              {t('empty')}
            </div>
          ) : (
            <div className="space-y-3">
              {/* Grace-window info banner: các món menu hiện tại sẽ hết hiệu
                  lực sau N phút chuyển đổi thực đơn (hiện trước khi món thật sự
                  hết hạn). */}
              {isCartInGraceWindow && activeItems.length > 0 && (
                <div className="mx-4 mt-3 flex items-start gap-2 rounded-lg px-3 py-2.5" style={{ backgroundColor: '#FEF3C7' }}>
                  <Info className="h-4 w-4 shrink-0 mt-0.5 text-amber-600" />
                  <p className="text-xs leading-relaxed text-amber-900">
                    {t('graceWindowNotice', { menu: activeItems[0]?.menuName || t('fallbackMenuCurrent'), minutes: cartMetadata?.timeout_minutes ?? 15 })}
                  </p>
                </div>
              )}

              {/* Warning banner for expired items */}
              {expiredItems.length > 0 && (
                <div className="mx-4 mt-3 flex items-start gap-2 rounded-lg px-3 py-2.5" style={{ backgroundColor: '#FEF3C7' }}>
                  <AlertCircle className="h-4 w-4 shrink-0 mt-0.5 text-amber-700" />
                  <p className="text-xs leading-relaxed text-amber-900">
                    {t('expiredItemsNotice', { menu: expiredItems[0]?.menuName || t('fallbackMenuPrevious') })}
                  </p>
                </div>
              )}

              {/* Active items section */}
              {activeItems.length > 0 && (
                <div className="space-y-2 px-2">
                  {activeItems.map((item) => {
                    const itemImage = resolveCartItemImage(item);
                    const productImage = item.product.image ?? CART_IMAGE_FALLBACK;

                    // Per-item deadline is still stored on each item, but the
                    // visible countdown should only appear during the cart
                    // timeout grace window after the menu has switched.
                    const seconds = isCartInGraceWindow
                      ? secondsRemaining
                      : 0;
                    const showItemCountdown = isCartInGraceWindow;

                    return (
                      <div
                        key={item.id}
                        className="rounded-lg border border-gray-200 bg-white px-4 py-3"
                      >
                        <div className="flex gap-3">
                          {/* Left: Image with variant → product fallback */}
                          <CartItemImage
                            primarySrc={itemImage}
                            fallbackSrc={productImage}
                            alt={item.product.name}
                            size="sm"
                          />

                          {/* Right: Details */}
                          <div className="min-w-0 flex-1">
                            {/* Top row: Title + Delete button */}
                            <div className="flex items-start justify-between gap-2">
                              <h4 className="text-sm font-semibold leading-tight">
                                {item.product.name}
                              </h4>
                              <button
                                type="button"
                                aria-label={t('removeItemConfirmTitle')}
                                onClick={() => setRemoveTarget(item.id)}
                                className="shrink-0 p-1 text-muted-foreground hover:text-destructive"
                              >
                                <svg width="18" height="22" viewBox="0 0 18 22" fill="none" xmlns="http://www.w3.org/2000/svg">
                                  <g clipPath="url(#clip0_1_4141)">
                                    <path d="M16 7V18.6C16 19.2365 15.7471 19.847 15.2971 20.2971C14.847 20.7471 14.2365 21 13.6 21H4.4C3.76348 21 3.15303 20.7471 2.70294 20.2971C2.25286 19.847 2 19.2365 2 18.6V7M13 4V2.2C13 1.54 12.46 1 11.8 1H6.2C5.54 1 5 1.54 5 2.2V4M13 4H5M13 4H18M5 4H0M9 10V16M12 10V16M6 10V16" stroke="#ef4444" strokeWidth="1.5" strokeMiterlimit="10" strokeLinecap="round" strokeLinejoin="round"/>
                                  </g>
                                  <defs>
                                    <clipPath id="clip0_1_4141">
                                      <rect width="18" height="22" fill="white"/>
                                    </clipPath>
                                  </defs>
                                </svg>
                              </button>
                            </div>

                            {/* Options + Toppings */}
                            <CartItemOptionsList lines={buildCartOptionLines(item)} />

                            {/* Per-item note */}
                            {item.note && (
                              <p className="mt-1 text-xs italic text-muted-foreground">
                                {t('notePrefix')}: {item.note}
                              </p>
                            )}

                            {/* Edit link */}
	                            <button
	                              type="button"
	                              onClick={() => setEditingItemId(item.id)}
	                              className="mt-1 text-sm font-medium italic leading-4"
	                              style={{ color: "#002AAF" }}
	                            >
	                              {t('edit')}
	                            </button>

                            {/* Bottom row: Price + Quantity controls */}
                            <div className="mt-2 flex items-center justify-between">
                              {/* Price */}
                              <LinePrice item={item} />

                              {/* Quantity controls */}
                              <div className="flex items-center gap-2">
                                {/* godx-tempo#1719 — nhãn kèm TÊN MÓN: drawer
                                    liệt kê nhiều dòng, nên "Giảm số lượng"
                                    trần vẫn để screen reader không biết đang
                                    đứng ở món nào. Cùng cách product-modal.tsx
                                    đã làm cho nút số lượng của topping. */}
                                <button
                                  type="button"
                                  onClick={() =>
                                    updateQuantity(item.id, item.quantity - 1)
                                  }
                                  aria-label={`${t('decreaseQty')}: ${item.product.name}`}
                                  className="flex h-[30px] w-[30px] items-center justify-center rounded-full border text-foreground transition-opacity hover:opacity-60 disabled:opacity-30"
                                  style={{ backgroundColor: "#EEEEEE" }}
                                  disabled={item.quantity <= 1}
                                >
                                  <Minus className="h-4 w-4" />
                                </button>
                                <span className="w-6 text-center text-sm font-bold">
                                  {item.quantity}
                                </span>
                                <button
                                  type="button"
                                  onClick={() =>
                                    updateQuantity(item.id, item.quantity + 1)
                                  }
                                  aria-label={`${t('increaseQty')}: ${item.product.name}`}
                                  className="flex h-[30px] w-[30px] items-center justify-center rounded-full border text-foreground transition-opacity hover:opacity-60"
                                  style={{ backgroundColor: "#EEEEEE" }}
                                >
                                  <Plus className="h-4 w-4" />
                                </button>
                              </div>
                            </div>

                            {/* Countdown badge — đáy thẻ (khớp Figma) */}
                            {showItemCountdown && (
                              <div
                                className="mt-2 inline-flex items-center gap-1 px-2"
                                style={{
                                  backgroundColor: "#F59E0B1A",
                                  color: "#F59E0B",
                                  borderRadius: 8,
                                  height: 24,
                                }}
                              >
                                <Clock className="h-3.5 w-3.5 shrink-0" />
                                <span className="text-[11px] font-medium tabular-nums leading-none">
                                  {t('expiresAfter', { time: formatCountdown(seconds) })}
                                </span>
                              </div>
                            )}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}

              {/* Expired items section */}
              {expiredItems.length > 0 && (
                <div>
                  <div className="px-4 py-2">
                    <h3 className="text-sm font-semibold text-muted-foreground">
                      {t('expiredSectionTitle', { menu: expiredItems[0]?.menuName || t('fallbackMenuPrevious') })}
                    </h3>
                  </div>
                  <div className="space-y-2 px-2 opacity-60">
                    {expiredItems.map((item) => {
                      const itemImage = resolveCartItemImage(item);
                      const productImage = item.product.image ?? CART_IMAGE_FALLBACK;
                      return (
                        <div
                          key={item.id}
                          className="rounded-lg border border-gray-200 bg-white px-4 py-3"
                        >
                          <div className="flex gap-3">
                            <CartItemImage
                              primarySrc={itemImage}
                              fallbackSrc={productImage}
                              alt={item.product.name}
                              size="sm"
                            />
                            <div className="min-w-0 flex-1">
                              <div className="flex items-start justify-between gap-2">
                                <h4 className="text-sm font-semibold leading-tight">
                                  {item.product.name}
                                </h4>
                                <button
                                  aria-label={`${tCommon('remove')}: ${item.product.name}`}
                                  onClick={() => setRemoveTarget(item.id)}
                                  className="shrink-0 p-1 text-muted-foreground hover:text-destructive"
                                >
                                  <Trash2 className="h-4 w-4 text-red-500" />
                                </button>
                              </div>

                              {/* Options + Toppings */}
                              <CartItemOptionsList lines={buildCartOptionLines(item)} />

                              {/* Per-item note */}
                              {item.note && (
                                <p className="mt-1 text-xs italic text-muted-foreground">
                                  {t('notePrefix')}: {item.note}
                                </p>
                              )}

                              {/* Edit link */}
                              <button
                                type="button"
                                onClick={() => setEditingItemId(item.id)}
                                className="mt-1 text-sm font-medium italic leading-4"
                                style={{ color: "#002AAF" }}
                              >
                                {t('edit')}
                              </button>

                              <LinePrice item={item} className="mt-2" />
                            </div>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              )}
            </div>
          )}

          {/* Add more button */}
          {items.length > 0 && (
            <div className="px-4 py-3 flex justify-end">
              <button
                type="button"
                onClick={() => onOpenChange(false)}
                className="inline-flex items-center justify-center gap-1.5 rounded-full bg-gray-100 text-sm font-medium text-gray-900 transition-colors hover:bg-gray-200"
                style={{ width: 126, height: 36 }}
              >
                <Plus className="h-4 w-4" />
                <span>{t('addMore')}</span>
              </button>
            </div>
          )}
        </div>

        {/* Footer: Coupon + Summary + Checkout */}
        {items.length > 0 && (
          <SheetFooter className="px-4 py-4">
            <div className="w-full space-y-3">
              {/* Total */}
              <div className="flex items-baseline justify-between border-t border-b py-3" style={{ color: '#6B7280' }}>
                <span className="text-base font-medium">{t('subtotalCount', { count: activeTotalQuantity })}</span>
                <span className="text-lg font-semibold">
                  {fmt(activeTotalPrice)}
                </span>
              </div>

              <button
                onClick={() => {
                  if (checkoutDisabled) return;
                  onOpenChange(false);
                  showLoading();
                  router.push("/checkout");
                }}
                disabled={checkoutDisabled}
                className={`w-full flex items-center justify-center gap-2 text-base font-semibold transition-colors ${
                  checkoutDisabled
                    ? "bg-gray-200 text-gray-500 cursor-not-allowed"
                    : "bg-[#2D8336] hover:bg-[#25682d] text-white"
                }`}
                style={{
                  height: '56px',
                  borderRadius: '8px',
                }}
              >
                {!checkoutDisabled && (
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M17 18C17.5304 18 18.0391 18.2107 18.4142 18.5858C18.7893 18.9609 19 19.4696 19 20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22C16.4696 22 15.9609 21.7893 15.5858 21.4142C15.2107 21.0391 15 20.5304 15 20C15 18.89 15.89 18 17 18ZM1 2H4.27L5.21 4H20C20.2652 4 20.5196 4.10536 20.7071 4.29289C20.8946 4.48043 21 4.73478 21 5C21 5.17 20.95 5.34 20.88 5.5L17.3 11.97C16.96 12.58 16.3 13 15.55 13H8.1L7.2 14.63L7.17 14.75C7.17 14.8163 7.19634 14.8799 7.24322 14.9268C7.29011 14.9737 7.3537 15 7.42 15H19V17H7C6.46957 17 5.96086 16.7893 5.58579 16.4142C5.21071 16.0391 5 15.5304 5 15C5 14.65 5.09 14.32 5.24 14.04L6.6 11.59L3 4H1V2ZM7 18C7.53043 18 8.03914 18.2107 8.41421 18.5858C8.78929 18.9609 9 19.4696 9 20C9 20.5304 8.78929 21.0391 8.41421 21.4142C8.03914 21.7893 7.53043 22 7 22C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20C5 18.89 5.89 18 7 18ZM16 11L18.78 6H6.14L8.5 11H16Z" fill="white"/>
                  </svg>
                )}
                {hasExpiredItem
                  ? t('hasExpiredCannotOrder')
                  : orderingBlocked
                    ? tShop('closedNoticeTitle')
                    : t('confirmOrder')}
              </button>
            </div>
          </SheetFooter>
        )}
	      </SheetContent>

	      {/* Edit cart item modal */}
	      {editingItem && (
	        <ProductModal
	          item={editingItem.product}
	          open={!!editingItem}
	          onOpenChange={(open) => {
	            if (!open) setEditingItemId(null);
	          }}
	          mode="edit"
	          initialQuantity={editingItem.quantity}
	          initialSelections={editingItem.selections}
	          initialToppingQuantities={editingItem.toppingQuantities}
	          initialToppingItemVariants={editingItem.toppingItemVariants}
	          initialNote={editingItem.note}
	          cartItemIdToReplace={editingItem.id}
	        />
	      )}
	    </Sheet>

	    {/* Confirm "clear all" — rendered OUTSIDE the Sheet so base-ui treats it
	        as a top-level dialog and renders its own backdrop (a nested dialog's
	        backdrop is suppressed). overlayClassName darkens the dim on mobile. */}
	    <Dialog open={confirmClearOpen} onOpenChange={setConfirmClearOpen}>
	      <DialogContent
	        showCloseButton={false}
	        className="z-[60] max-w-[340px] gap-0 overflow-hidden p-0"
	        overlayClassName="z-[60] max-md:bg-black/60"
	      >
	        <div className="flex flex-col items-center gap-3 px-6 pt-6 pb-2 text-center">
	          <div className="flex h-14 w-14 items-center justify-center rounded-full bg-red-50">
	            <Trash2 className="h-7 w-7 text-red-500" />
	          </div>
	          <DialogHeader className="items-center gap-1.5">
	            <DialogTitle className="text-lg font-bold text-foreground">
	              {t('clearAllConfirmTitle')}
	            </DialogTitle>
	            <DialogDescription className="text-center text-sm leading-relaxed text-muted-foreground">
	              {t('clearAllConfirmMessage', { count: totalItems })}
	            </DialogDescription>
	          </DialogHeader>
	        </div>
	        <DialogFooter className="mt-4 flex-row gap-2 border-t-0 bg-transparent px-6 pb-6 pt-2">
	          <Button
	            variant="outline"
	            className="h-11 flex-1 rounded-xl text-sm font-semibold"
	            onClick={() => setConfirmClearOpen(false)}
	          >
	            {t('clearAllCancel')}
	          </Button>
	          <Button
	            className="h-11 flex-1 rounded-xl bg-red-500 text-sm font-semibold text-white hover:bg-red-600"
	            onClick={handleConfirmClear}
	          >
	            {t('clearAllConfirm')}
	          </Button>
	        </DialogFooter>
	      </DialogContent>
	    </Dialog>

	    {/* Confirm removing a single item from the cart */}
	    <Dialog open={removeTarget !== null} onOpenChange={(o) => !o && setRemoveTarget(null)}>
	      <DialogContent
	        showCloseButton={false}
	        className="z-[60] max-w-[340px] gap-0 overflow-hidden p-0"
	        overlayClassName="z-[60] max-md:bg-black/60"
	      >
	        <div className="flex flex-col items-center gap-3 px-6 pt-6 pb-2 text-center">
	          <div className="flex h-14 w-14 items-center justify-center rounded-full bg-red-50">
	            <Trash2 className="h-7 w-7 text-red-500" />
	          </div>
	          <DialogHeader className="items-center gap-1.5">
	            <DialogTitle className="text-lg font-bold text-foreground">
	              {t('removeItemConfirmTitle')}
	            </DialogTitle>
	            <DialogDescription className="text-center text-sm leading-relaxed text-muted-foreground">
	              {t('removeItemConfirmMessage')}
	            </DialogDescription>
	          </DialogHeader>
	        </div>
	        <DialogFooter className="mt-4 flex-row gap-2 border-t-0 bg-transparent px-6 pb-6 pt-2">
	          <Button
	            variant="outline"
	            className="h-11 flex-1 rounded-xl text-sm font-semibold"
	            onClick={() => setRemoveTarget(null)}
	          >
	            {t('clearAllCancel')}
	          </Button>
	          <Button
	            className="h-11 flex-1 rounded-xl bg-red-500 text-sm font-semibold text-white hover:bg-red-600"
	            onClick={handleConfirmRemove}
	          >
	            {t('removeItemConfirm')}
	          </Button>
	        </DialogFooter>
	      </DialogContent>
	    </Dialog>
	    </>
	  );
	}
