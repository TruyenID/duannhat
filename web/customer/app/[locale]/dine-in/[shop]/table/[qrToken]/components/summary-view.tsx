"use client";

import { UtensilsCrossed, CreditCard, ArrowLeft, Minus, Plus } from "lucide-react";
import { useTranslations, useLocale } from 'next-intl';
import type { ActiveOrder, ActiveOrderItem } from "@/data/orders";
import type { TableInfo } from "../page";
import { useCurrency } from "@/lib/currency";
import { shortOrderCode } from "@/lib/utils";
import { TaxBreakdownLines } from "@/components/tax-breakdown-lines";
import { formatGuestDate, formatGuestTime } from '@/lib/date-format'


interface SummaryViewProps {
  table: TableInfo;
  order: ActiveOrder | null;
  onBack: () => void;
  onPay: () => void;
  onCancelItem?: (itemId: string) => void;
  onUpdateItemQty?: (itemId: string, newQty: number) => void;
  onEditItem?: (item: ActiveOrderItem) => void;
}

export default function SummaryView({ table, order, onBack, onPay, onCancelItem, onUpdateItemQty, onEditItem }: SummaryViewProps) {
  const t = useTranslations('dineIn');
  const locale = useLocale();
  const { format: fmt } = useCurrency();
  // plan-043 — per-rate consumption-tax breakdown from the order payload.
  // vatAmount (total − subtotal) is the legacy fallback for payloads without
  // a `tax_breakdown`; note it is only meaningful in tax-excluded mode.
  const taxBreakdown = order?.tax_breakdown;
  const isTaxIncluded = order?.is_tax_included ?? false;
  const vatAmount = order ? Math.max(0, order.total - order.subtotal) : 0;

  return (
    <div className="flex flex-col min-h-dvh bg-white md:bg-neutral-50 overflow-x-clip">
      {/* Mobile Header — sticky ngay dưới global Header (h-12 = 48px) */}
      <div className="md:hidden sticky top-12 z-30 bg-white pb-4 shrink-0 border-b border-neutral-200">
        <div className="mx-auto max-w-6xl flex items-center gap-3 px-4">
          <button
            aria-label={t('back')}
            onClick={onBack}
            className="size-10 flex items-center justify-center -ml-1"
          >
            <ArrowLeft className="size-6" />
          </button>
          <h1 className="text-lg font-bold text-neutral-900">{t('orderHistoryHeader')}</h1>
        </div>
      </div>

      {/* Desktop Header — inner container `max-w-7xl` để khớp width với
          global Header (mặc định max-w-7xl). Back arrow + "Lịch sử đặt món"
          align cùng cột dọc với logo brand ở Header trên desktop. */}
      <div className="hidden md:block pt-3 pb-4 shrink-0">
        <div className="mx-auto flex max-w-7xl items-center gap-2 px-4 md:gap-3 md:px-6">
          <button
            aria-label={t('back')}
            onClick={onBack}
            className="size-7 flex items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-foreground transition-colors shrink-0"
          >
            <ArrowLeft className="size-4" />
          </button>
          <span className="flex-1 min-w-0 truncate font-semibold text-neutral-800 md:text-[16px]">
            {t('orderHistoryHeader')}
          </span>
        </div>
      </div>

      {/* Order list */}
      <main className="flex-1 py-5 pb-8">
        <div className="mx-auto w-full max-w-2xl px-4 md:px-6">
        {order && order.items.length > 0 ? (
          <>
          <div className="space-y-4">
            {/* Main card wrapper - từ Order header đến button Thêm món */}
            <div className="rounded-xl border border-neutral-200 bg-white shadow-sm" style={{ boxShadow: '0px 1px 3px 0px rgba(0, 0, 0, 0.1)' }}>
              {/* Order header */}
              <div className="p-4">
                <div className="flex items-center justify-between gap-3 mb-2">
                  <h2 className="text-neutral-900 flex-1" style={{ fontSize: 'clamp(16px, 4vw, 20px)', fontWeight: 600, lineHeight: '1.2' }}>{t('currentOrder')}</h2>
                  <p className="inline-block px-2 py-1 font-bold text-[#22C55E] shrink-0" style={{ fontSize: '12px', lineHeight: '16px', borderRadius: '8px', backgroundColor: '#22C55E1A' }}>#{shortOrderCode(order.code)}</p>
                </div>
                <div className="flex items-center justify-between gap-3 text-neutral-600" style={{ fontSize: '14px', fontWeight: 400, lineHeight: '20px' }}>
                  <p>{t('tableLabel', { code: table.code })}</p>
                  <p>{formatGuestDate(order.placed_at, locale)} - {formatGuestTime(order.placed_at, locale)}</p>
                </div>
              </div>

              {/* Items list - no grouping by round */}
              <div className="p-4">
                <h3 className="text-neutral-900 mb-3" style={{ fontSize: 'clamp(15px, 3.5vw, 18px)', fontWeight: 600, lineHeight: '1.3' }}>{t('itemListTitle')}</h3>
                <div className="space-y-3">
                {order.items.map((item) => {
                          // Build customization display from options (variants/toppings with price > 0)
                          // Backend returns toppings in item.options with: { id, name, unit_price, quantity }
                          // Format: "Boba pearls (¥80)" or "Aloe vera x2 (¥160)" for quantity > 1
                          const customizationParts: string[] = [];

                          if (item.options && item.options.length > 0) {
                            item.options.forEach((opt) => {
                              // Only show options with price > 0 (same as confirm page)
                              if (opt.unit_price > 0) {
                                const displayName = opt.quantity > 1 ? `${opt.name} x${opt.quantity}` : opt.name;
                                const totalPrice = opt.unit_price * opt.quantity;
                                customizationParts.push(`${displayName} (${fmt(totalPrice)})`);
                              }
                            });
                          }

                          // Fallback: if backend doesn't send options, parse variant text field
                          if (customizationParts.length === 0 && item.variant) {
                            const lines = item.variant.split('\n').filter(Boolean);
                            customizationParts.push(...lines);
                          }

                          // Display max 2 items, show "..." if more
                          const displayedCustomizations = customizationParts.slice(0, 2);
                          const hasMore = customizationParts.length > 2;
                          const hasCustomization = displayedCustomizations.length > 0;

                          return (
                            <div key={item.id} className="flex gap-3 p-4 rounded-xl border border-neutral-200 bg-white">
                              {/* Left: image */}
                              <div className="relative flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted">
                                {item.image_url ? (
                                  // eslint-disable-next-line @next/next/no-img-element
                                  <img
                                    src={item.image_url}
                                    alt={item.name}
                                    className="h-full w-full object-cover"
                                  />
                                ) : (
                                  <svg className="h-8 w-8 text-muted-foreground/40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                                      d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                  </svg>
                                )}
                              </div>

                              {/* Right: details */}
                              <div className="min-w-0 flex-1">
                                {/* Title row */}
                                <div className="flex items-start justify-between gap-2 mb-1">
                                  <h4 className="text-neutral-900 flex-1" style={{ fontSize: '16px', lineHeight: '22px', fontWeight: 700 }}>
                                    {item.name}
                                  </h4>
                                  <div className="flex items-center gap-2 flex-shrink-0">
                                    {/* Quantity badge */}
                                    <span className="font-bold text-neutral-900 tabular-nums" style={{ fontSize: '16px', lineHeight: '20px' }}>
                                      x{item.qty}
                                    </span>
                                    {/* Trash icon */}
                                    {onCancelItem && (
                                      <button
                                        onClick={() => onCancelItem(item.id)}
                                        className="p-0.5 hover:opacity-70 transition-opacity"
                                        aria-label={t('removeItem')}
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
                                    )}
                                  </div>
                                </div>

                                {/* Variant & toppings - max 2 items */}
                                {hasCustomization && (
                                  <div className="mb-1.5 space-y-0.5" style={{ fontSize: '12px', lineHeight: '16px', fontWeight: 500 }}>
                                    {displayedCustomizations.map((part, idx) => (
                                      <p key={`custom-${idx}`} className="text-neutral-500">+ {part}</p>
                                    ))}
                                    {hasMore && (
                                      <p className="text-neutral-400">+ ...</p>
                                    )}
                                  </div>
                                )}

                                {/* Edit link */}
                                {onEditItem && (
                                  <button
                                    onClick={() => onEditItem(item)}
                                    className="text-[#002AAF] hover:text-[#002AAF]/80 mb-1.5 inline-block italic"
                                    style={{ fontSize: '14px', lineHeight: '16px', fontWeight: 500 }}
                                  >
                                    {t('editItem')}
                                  </button>
                                )}

                                {/* Price & quantity row */}
                                <div className="flex items-center justify-between">
                                  <p className="text-sm font-semibold text-neutral-900">
                                    {fmt(item.unit_price)}
                                  </p>

                                  {/* Quantity controls (if interactive) */}
                                  {onUpdateItemQty && (
                                    <div className="flex items-center gap-3">
                                      <button
                                        type="button"
                                        onClick={() => onUpdateItemQty(item.id, item.qty - 1)}
                                        className="flex h-6 w-6 items-center justify-center rounded-full text-neutral-600 hover:text-neutral-900 transition-colors disabled:opacity-30 disabled:cursor-not-allowed"
                                        style={{ backgroundColor: '#EEEEEE' }}
                                        disabled={item.qty <= 1}
                                        aria-label={t('decreaseQuantity')}
                                      >
                                        <Minus className="h-4 w-4" strokeWidth={2} />
                                      </button>
                                      <span className="min-w-[20px] text-center text-sm font-medium text-neutral-900 tabular-nums">
                                        {item.qty}
                                      </span>
                                      <button
                                        type="button"
                                        onClick={() => onUpdateItemQty(item.id, item.qty + 1)}
                                        className="flex h-6 w-6 items-center justify-center rounded-full text-neutral-600 hover:text-neutral-900 transition-colors"
                                        style={{ backgroundColor: '#EEEEEE' }}
                                        aria-label={t('increaseQuantity')}
                                      >
                                        <Plus className="h-4 w-4" strokeWidth={2} />
                                      </button>
                                    </div>
                                  )}
                                </div>
                              </div>
                            </div>
                          );
                        })}
                </div>
                <div className="mt-3 flex justify-end">
                  <button
                    onClick={onBack}
                    className="inline-flex items-center justify-center text-neutral-700 transition-colors hover:bg-neutral-200 active:scale-95 text-sm md:text-[20px]"
                    style={{
                      backgroundColor: '#F5F5F5',
                      fontWeight: 500,
                      lineHeight: '1',
                      borderRadius: '20px',
                      height: '36px',
                      paddingLeft: '16px',
                      paddingRight: '16px',
                      gap: '6px'
                    }}
                  >
                    <span>+</span>
                    <span>{t('addMore')}</span>
                  </button>
                </div>
              </div>
            </div>

            {/* Payment Summary */}
            <div className="rounded-xl bg-white p-3 sm:p-4">
              <h3 className="font-bold text-neutral-900 mb-2 sm:mb-3 text-base md:text-[18px]" style={{ lineHeight: '1.3' }}>{t('paymentSummary')}</h3>
              <div className="space-y-1.5 sm:space-y-2">
                {/* Dòng 1: mobile "Tổng hóa đơn" / desktop "Tạm tính" (theo từng Figma) */}
                <div className="flex items-center justify-between text-sm">
                  <span className="text-neutral-600">
                    <span className="md:hidden">{t('billTotal')}</span>
                    <span className="hidden md:inline">{t('subtotalSimple')}</span>
                  </span>
                  <span className="font-medium text-neutral-900 tabular-nums md:text-[16px]">{fmt(order.subtotal)}</span>
                </div>
                {/* plan-043 — per-rate consumption-tax breakdown (8%対象 /
                    10%対象). Legacy fallback: single VAT line when the payload
                    lacks a breakdown. */}
                {taxBreakdown && taxBreakdown.length > 0 ? (
                  <TaxBreakdownLines
                    breakdown={taxBreakdown}
                    isTaxIncluded={isTaxIncluded}
                    format={fmt}
                    namespace="dineIn"
                    className="space-y-1.5 sm:space-y-2"
                  />
                ) : vatAmount > 0 ? (
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-neutral-600">{t('tax')}</span>
                    <span className="font-medium text-neutral-900 tabular-nums md:text-[16px]">{fmt(vatAmount)}</span>
                  </div>
                ) : null}
                {/* Tổng cần trả */}
                <div className="flex items-center justify-between border-t border-neutral-100 pt-1.5 sm:pt-2">
                  <span className="font-bold text-neutral-900 text-base md:text-[18px]">
                    {t('totalDue')}
                    <span className="ml-1.5 align-middle text-[11px] font-medium text-neutral-500">
                      ({t('taxIncludedBadge')})
                    </span>
                  </span>
                  <span className="font-bold tabular-nums text-lg md:text-[24px]" style={{ color: '#006A34' }}>{fmt(order.total)}</span>
                </div>
              </div>
            </div>

            {/* Desktop: nút thanh toán inline trong cột (mobile dùng sticky footer) */}
            <button
              onClick={onPay}
              className="hidden md:flex w-full rounded-lg bg-[#2D8336] hover:bg-[#25692C] text-white items-center justify-center gap-2 disabled:opacity-60 transition-all"
              style={{ height: '56px', fontSize: '16px', fontWeight: 500, lineHeight: '24px' }}
            >
              <CreditCard className="size-4 shrink-0" />
              <span className="truncate">{t('payNow')}</span>
            </button>

          </div>
        </>
        ) : (
          <div className="flex flex-col items-center justify-center py-20 gap-3 text-center lg:col-span-2">
            <div className="flex size-16 items-center justify-center rounded-full bg-neutral-100">
              <UtensilsCrossed className="size-8 text-neutral-300" />
            </div>
            <div className="space-y-1">
              <p className="text-sm font-semibold text-neutral-700">{t('noItems')}</p>
              <p className="text-xs text-muted-foreground">{t('noItemsDesc')}</p>
            </div>
            <button
              onClick={onBack}
              className="mt-2 h-10 px-5 rounded-xl bg-primary text-primary-foreground text-sm font-semibold hover:bg-primary/90 active:scale-[0.98] transition-all"
            >
              {t('backToMenu')}
            </button>
          </div>
        )}
        </div>
      </main>

      {/* Sticky footer — nút thanh toán ghim đáy màn hình (mobile). Desktop dùng nút inline trong cột. */}
      {order && order.items.length > 0 && (
        <div
          className="md:hidden sticky bottom-0 z-20 bg-white px-4 py-[18px] safe-area-bottom"
          style={{ boxShadow: '0px -6px 16px 0px #0000000D' }}
        >
          <div className="mx-auto w-full max-w-2xl md:px-6">
            <button
              onClick={onPay}
              className="w-full rounded-lg bg-[#2D8336] hover:bg-[#25692C] text-white flex items-center justify-center gap-2 disabled:opacity-60 transition-all"
              style={{
                height: '56px',
                fontSize: '16px',
                fontWeight: 500,
                lineHeight: '24px'
              }}
            >
              <CreditCard className="size-4 shrink-0" />
              <span className="truncate">{t('payNow')}</span>
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
