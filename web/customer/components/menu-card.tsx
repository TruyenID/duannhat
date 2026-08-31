"use client";

import { useTranslations } from "next-intl";
import { Card, CardContent } from "@/components/ui/card";
import { Plus, ThumbsUp } from "lucide-react";
import type { MenuItem } from "@/data/menu";
import { FloatingSectionBadge, HappyHourBadge, HappyHourPrice } from "@/components/happy-hour";
import { ItemImageGallery } from "@/components/item-image-gallery";

interface MenuCardProps {
  item: MenuItem;
  onClick: () => void;
  /** Khi có onAdd/onRemove → hiện nút +/− thay vì mở modal */
  onAdd?: () => void;
  onRemove?: () => void;
  qty?: number;
}

export default function MenuCard({ item, onClick, onAdd, onRemove, qty = 0 }: MenuCardProps) {
  void onRemove;
  const hasQtyControls = !!onAdd;
  // godx-tempo#1719 — nút "+" nổi chỉ có icon, không chữ. Thiếu nhãn thì screen
  // reader đọc ra một dãy "button" giống hệt nhau trên khắp lưới menu.
  const t = useTranslations("cart");

  return (
    <Card
      className="group cursor-pointer gap-0 p-0 transition-all duration-300 md:hover:-translate-y-1 md:hover:shadow-lg overflow-hidden border border-border/50 rounded-2xl"
      onClick={onClick}
    >
      <div className="relative aspect-square overflow-hidden rounded-t-2xl bg-muted m-2 mb-0">
        <ItemImageGallery
          images={item.images?.slice(0, 1)}
          alt={item.name}
          imgClassName="absolute inset-0 h-full w-full rounded-2xl object-cover"
          placeholder={
            <div className="flex h-full w-full items-center justify-center text-muted-foreground/40">
              <svg className="h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                  d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </div>
          }
        />

        {/* Add button - bottom right corner (always show) */}
        <button
          onClick={(e) => {
            e.stopPropagation();
            onClick();
          }}
          aria-label={`${t('addToCart')}: ${item.name}`}
          className="absolute bottom-3 right-3 flex size-10 items-center justify-center rounded-full text-white shadow-lg hover:opacity-90 transition-opacity"
          style={{ backgroundColor: '#27A14F' }}
        >
          <Plus className="size-3" strokeWidth={3.5} />
        </button>

        {/* Qty badge - top right */}
        {qty > 0 && (
          <span className="absolute right-2 top-2 flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-[11px] font-bold text-primary-foreground">
            {qty}
          </span>
        )}

        {/* plan-019 — Happy Hour Badge top-left + countdown when close to ends_at. */}
        {item.active_promotion && (
          <HappyHourBadge
            discountPercent={item.active_promotion.discount_percent}
            endsAt={item.active_promotion.ends_at}
          />
        )}

        {/* #1185 — khung giờ ưu đãi badge. Only when no Happy Hour already owns
            the top-left corner. The extra active_floating_section check comes
            from the other branch: it stops a stale base_price from painting a
            badge for a promo that is no longer running. */}
        {!item.active_promotion && item.active_floating_section && (
          <FloatingSectionBadge item={item} />
        )}
      </div>

      <CardContent className="px-4 pt-3 pb-4 text-left">
        <h3 className="mb-2 text-base font-bold leading-tight line-clamp-2">{item.name}</h3>
        <div className="flex items-center gap-2">
          {/* plan-043 — price + 税込/税抜 label + reference, shared with the list
              card via HappyHourPrice (also renders the Happy Hour strikethrough). */}
          <HappyHourPrice item={item} className="text-base" strikeClassName="text-xs" />
          {item.rating != null && item.reviewCount != null && item.reviewCount > 0 && (
            <div className="ml-auto flex items-center gap-1 text-sm font-semibold text-emerald-600">
              <ThumbsUp className="h-4 w-4" aria-hidden />
              <span>{item.rating}%</span>
              <span className="text-[11px] font-normal text-muted-foreground">({item.reviewCount})</span>
            </div>
          )}
        </div>
      </CardContent>
    </Card>
  );
}
