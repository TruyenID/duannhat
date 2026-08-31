"use client"

import { useTranslations } from "next-intl"
import { Plus, ThumbsUp, UtensilsCrossed } from "lucide-react"
import type { MenuItem } from "@/data/menu"
import { Button } from "@/components/ui/button"
import { FloatingSectionBadge, HappyHourBadge, HappyHourPrice } from "@/components/happy-hour"
import { ItemImageGallery } from "@/components/item-image-gallery"

interface MenuListItemProps {
  item: MenuItem
  onClick: () => void
}

/**
 * MenuListItem — Responsive layout
 *
 * Mobile (< md):
 * - Vertical card layout (2 columns grid in parent)
 * - Square image on top
 * - Title below image
 * - Price + Rating below title
 * - Floating + button on bottom-right of image
 *
 * Desktop (≥ md):
 * - Horizontal list layout (1 column in parent)
 * - Title: Bilingual (Japanese + English)
 * - Price + Rating on left
 * - Image on right (160x160)
 * - Floating + button overlays image
 * - Description with [欧堂限定] badge
 */
export function MenuListItem({ item, onClick }: MenuListItemProps) {
  const rating = item.rating
  const reviewCount = item.reviewCount
  // godx-tempo#1719 — hai nút "+" nổi bên dưới (bản mobile và bản desktop) chỉ
  // có icon; `aria-label={item.name}` ở thẻ bao ngoài không nói được HÀNH ĐỘNG.
  const t = useTranslations("cart")

  return (
    <>
      {/* Mobile Layout (< md): Vertical card */}
      <div
        role="button"
        tabIndex={0}
        data-menu-item
        aria-label={item.name}
        onClick={onClick}
        onKeyDown={(event) => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault()
            onClick()
          }
        }}
        className="group flex w-full cursor-pointer flex-col rounded-2xl border border-border/40 bg-card p-3 text-left transition-all active:scale-[0.98] md:hidden"
      >
        {/* Image Container */}
        <div className="relative aspect-square w-full overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-primary/10 to-accent/10">
          <ItemImageGallery
            images={item.images?.slice(0, 1)}
            alt={item.name}
            imgClassName="h-full w-full object-cover"
            placeholder={
              <div className="flex h-full w-full items-center justify-center text-muted-foreground/20">
                <UtensilsCrossed className="h-10 w-10" aria-hidden />
              </div>
            }
          />

          {/* Floating Add Button - Bottom Right */}
          <Button
            size="icon"
            className="absolute bottom-2 right-2 h-8 w-8 rounded-full shadow-lg hover:opacity-90 hover:scale-105 active:scale-95 transition-all"
            style={{ backgroundColor: '#27A14F' }}
            aria-label={`${t('addToCart')}: ${item.name}`}
            onClick={(e) => {
              e.stopPropagation()
              onClick()
            }}
          >
            <Plus className="h-3 w-3 stroke-[3.5]" />
          </Button>

          {/* plan-019 — Happy Hour badge */}
          {item.active_promotion && (
            <HappyHourBadge
              discountPercent={item.active_promotion.discount_percent}
              endsAt={item.active_promotion.ends_at}
            />
          )}

          {/* #1185 — khung giờ ưu đãi badge */}
          {!item.active_promotion && item.active_floating_section && (
            <FloatingSectionBadge item={item} />
          )}
        </div>

        {/* Content Below Image */}
        <div className="space-y-1 pt-2">
          {/* Title */}
          <h3 className="text-sm font-semibold leading-tight text-foreground line-clamp-2">
            {item.name}
          </h3>

          {/* Price + Rating — rating sát ngay sau giá (bỏ `ml-auto`).
              Price font-bold (font-weight 700) đã có sẵn ở HappyHourPrice. */}
          <div className="flex items-center gap-2">
            <HappyHourPrice
              item={item}
              className="text-xs font-bold tabular-nums"
              strikeClassName="text-[11px]"
            />
            {rating != null && reviewCount != null && reviewCount > 0 && (
              <div className="flex items-center gap-1">
                <span className="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[11px] font-medium" style={{ backgroundColor: '#F0FDF4', color: '#27A14F' }}>
                  <ThumbsUp className="h-3 w-3 fill-current" />
                  {rating}%
                </span>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Desktop Layout (≥ md): Horizontal list */}
      <div
        role="button"
        tabIndex={0}
        data-menu-item
        aria-label={item.name}
        onClick={onClick}
        onKeyDown={(event) => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault()
            onClick()
          }
        }}
        className="group hidden w-full cursor-pointer items-center gap-3 rounded-xl border border-border/40 bg-card p-3 text-left shadow-sm transition-all hover:shadow-md md:flex"
      >
        {/* Left: Content */}
        <div className="flex-1 min-w-0 space-y-1">
          {/* Title */}
          <h3 className="text-base font-semibold leading-tight text-foreground line-clamp-1">
            {item.name}
          </h3>

          {/* Price + Rating — rating sát ngay sau giá (bỏ `ml-auto`).
              Price font-bold (font-weight 700) đã có sẵn ở HappyHourPrice. */}
          <div className="flex items-center gap-2 flex-wrap">
            <HappyHourPrice item={item} className="text-base font-bold tabular-nums" />
            {rating != null && reviewCount != null && reviewCount > 0 && (
              <div className="flex items-center gap-1">
                <span className="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[11px] font-medium" style={{ backgroundColor: '#F0FDF4', color: '#27A14F' }}>
                  <ThumbsUp className="h-3 w-3 fill-current" />
                  {rating}%
                </span>
                <span className="text-[10px] text-muted-foreground">({reviewCount})</span>
              </div>
            )}
          </div>

          {/* Description */}
          {item.description && (
            <p className="text-sm leading-relaxed text-muted-foreground line-clamp-2">
              {item.description}
            </p>
          )}
        </div>

        {/* Right: Image + Add Button */}
        <div className="relative shrink-0">
          <div className="relative h-[150px] w-[150px] overflow-hidden rounded-lg bg-gradient-to-br from-primary/10 to-accent/10">
            <ItemImageGallery
              images={item.images?.slice(0, 1)}
              alt={item.name}
              imgClassName="h-full w-full object-cover rounded-xl"
              placeholder={
                <div className="flex h-full w-full items-center justify-center text-muted-foreground/20">
                  <UtensilsCrossed className="h-8 w-8" aria-hidden />
                </div>
              }
            />

            {/* plan-019 — Happy Hour badge */}
            {item.active_promotion && (
              <HappyHourBadge
                discountPercent={item.active_promotion.discount_percent}
                endsAt={item.active_promotion.ends_at}
              />
            )}

            {/* #1185 — khung giờ ưu đãi badge */}
            {!item.active_promotion && item.active_floating_section && (
              <FloatingSectionBadge item={item} />
            )}
          </div>

          {/* Floating Add Button */}
          <Button
            size="icon"
            className="absolute bottom-2 right-2 h-9 w-9 rounded-full shadow-lg hover:opacity-90 hover:scale-105 active:scale-95 transition-all"
            style={{ backgroundColor: '#27A14F' }}
            aria-label={`${t('addToCart')}: ${item.name}`}
            onClick={(e) => {
              e.stopPropagation()
              onClick()
            }}
          >
            <Plus className="h-3 w-3" strokeWidth={3.5} />
          </Button>
        </div>
      </div>
    </>
  )
}
