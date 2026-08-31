"use client";

import { useEffect, useRef, useState } from "react";
import { useTranslations } from 'next-intl';
import { ChevronLeft, ChevronRight, Plus, ThumbsUp, Package } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { HappyHourBadge, HappyHourPrice } from "@/components/happy-hour";
import { ItemImageGallery } from "@/components/item-image-gallery";
import type { MenuItem as FullMenuItem } from "@/data/menu";

interface ComboItemInfo {
  name: string;
  image?: string | null;
}

interface MenuItem {
  id: string;
  name: string;
  name_ja?: string;
  price: number;
  image: string | null;
  images?: string[];
  rating?: number;
  reviewCount?: number;
  is_combo?: boolean;
  comboItems?: ComboItemInfo[];
  /** plan-019 — Happy Hour overlay (same shape as data/menu MenuItem). */
  active_promotion?: FullMenuItem["active_promotion"];
  /** #1099 — per-item effective tax rate for 税込/税抜 display. */
  tax_rate?: number | null;
}

interface FeaturedItemsCarouselProps {
  items: MenuItem[];
  onAddToCart: (item: MenuItem) => void;
  onItemClick: (item: MenuItem) => void;
}

/** Auto-advance cadence. Every `AUTO_ADVANCE_MS` we smooth-scroll the rail
 *  forward by EXACTLY one card stride (card width + flex gap), measured
 *  from the live DOM so the step matches the responsive card sizing on
 *  every breakpoint (mobile uses `calc(50vw-16px)`, desktop
 *  `calc((100%-60px)/4)`). Applies to both takeaway (`/menu-page`) and
 *  dine-in (`menu-view`) since both mount the same component. */
const AUTO_ADVANCE_MS = 2000;

export default function FeaturedItemsCarousel({
  items,
  onAddToCart,
  onItemClick,
}: FeaturedItemsCarouselProps) {
  const t = useTranslations('featured');
  const tCommon = useTranslations('common');
  // godx-tempo#1719 — nhãn cho hai nút "+" chỉ-có-icon bên dưới. Namespace
  // riêng vì chuỗi dùng chung với cart drawer / menu card.
  const tCart = useTranslations('cart');
  const scrollContainerRef = useRef<HTMLDivElement>(null);
  const [showLeftArrow, setShowLeftArrow] = useState(false);
  const [showRightArrow, setShowRightArrow] = useState(false);
  /** Tổng nội dung carousel có rộng hơn container không. Nếu không có
      overflow (vd: chỉ 3 món fit hết desktop width) → ẩn hẳn cả 2 mũi tên. */
  const [hasOverflow, setHasOverflow] = useState(false);
  /** Auto-advance pause. Toggled by hover (desktop), pointer-down (touch),
   *  and tab-visibility — so the user can scroll manually or read without
   *  the carousel pulling under their finger. */
  const [paused, setPaused] = useState(false);
  /** Monotonically-increasing logical card index. Driven by the auto-tick
   *  and the manual chevrons. The effect below maps it onto the rendered
   *  DOM (copy 1 + copy 2 clones) by indexing into `el.children[idx]` and
   *  always targeting that child's exact `offsetLeft` — that's a real,
   *  browser-rounded integer pixel, so the rail never drifts off-grid
   *  no matter how many ticks accumulate. When the index sweeps past one
   *  full copy we silently subtract `loop` from scrollLeft after the
   *  glide finishes; the pixels at the new position are byte-identical
   *  to the ones the user was watching (clone === original). */
  const [logicalIdx, setLogicalIdx] = useState(0);
  /** Ticks every time the ResizeObserver sees the rail or its children
   *  change size — image loads, font swaps, viewport resize. Added to
   *  the scroll effect's deps so we re-snap to `children[k].offsetLeft`
   *  with the FINAL pixel positions, not the stale ones from before
   *  CSS / image layout settled. Without this the carousel happily
   *  scrolled to the right value at first paint (with intrinsic card
   *  widths) and then never re-aligned once the real widths shipped. */
  const [geometryVersion, setGeometryVersion] = useState(0);

  /** Live geometry of the rail, read fresh on every tick so it survives
   *  responsive breakpoints (mobile/tablet/desktop) and image-load
   *  reflows without us caching stale values:
   *
   *   - `stride`   — `children[1] - children[0]` offsetLeft. Exact pixel
   *                  distance from one card's start to the next (card
   *                  width + flex gap).
   *   - `loop`     — `children[N] - children[0]` offsetLeft = `N * stride`.
   *                  The distance we subtract on wrap so the snap lands
   *                  on a card boundary in copy 1, NOT on the empty
   *                  scroll-padding that lives to the left of item 0.
   *                  Earlier this returned `children[N].offsetLeft` which
   *                  includes the rail's left scroll-padding — snapping
   *                  by that amount left the viewport sitting on the
   *                  padding instead of flush against item 1 and the
   *                  next smooth-scroll then overshot by exactly one
   *                  padding's worth, producing the half-cut card the
   *                  user reported on desktop.
   *   - `wrap`     — `children[N].offsetLeft`, the scrollLeft threshold
   *                  at which we trigger the snap.
   */
  const getRailGeometry = (): { stride: number; loop: number; wrap: number } | null => {
    const el = scrollContainerRef.current;
    if (!el) return null;
    const first = el.children[0] as HTMLElement | undefined;
    const second = el.children[1] as HTMLElement | undefined;
    const firstClone = el.children[items.length] as HTMLElement | undefined;
    if (!first || !second || !firstClone) return null;
    const stride = second.offsetLeft - first.offsetLeft;
    const loop = firstClone.offsetLeft - first.offsetLeft;
    const wrap = firstClone.offsetLeft;
    return { stride, loop, wrap };
  };

  const measure = () => {
    const el = scrollContainerRef.current;
    if (!el) return;
    // Use the gap-aware loop distance as the natural width of ONE copy.
    // Falls back to scrollWidth/2 before the clones have mounted.
    const oneCopyWidth = getRailGeometry()?.loop ?? el.scrollWidth / 2;
    const overflow = oneCopyWidth - el.clientWidth > 1;
    setHasOverflow(overflow);
    // Looping carousel never has a true "start" or "end", so both arrows
    // stay enabled the whole time when overflow exists.
    setShowLeftArrow(overflow);
    setShowRightArrow(overflow);
  };

  // Đo lúc mount + khi resize (viewport hoặc font/image vừa load gây
  // scrollWidth thay đổi). ResizeObserver bao trùm cả 2 trường hợp.
  useEffect(() => {
    measure();
    const el = scrollContainerRef.current;
    if (!el || typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(() => {
      measure();
      setGeometryVersion((v) => v + 1);
    });
    ro.observe(el);
    // Cũng observe các children — ảnh load xong sẽ đổi scrollWidth
    Array.from(el.children).forEach((child) => ro.observe(child));
    return () => ro.disconnect();
  }, [items.length]);

  const scroll = (direction: "left" | "right") => {
    setLogicalIdx((prev) => prev + (direction === "left" ? -1 : 1));
  };

  const handleScroll = () => {
    measure();
  };

  // Auto-advance: bump `logicalIdx` every AUTO_ADVANCE_MS. The actual
  // scrolling is done by the effect below — separating the ticker from
  // the scroll math means we never read a transient `scrollLeft`
  // mid-animation and round it the wrong way (the old bug that left a
  // sliver of the prev card peeking in at the left).
  //
  // Pauses on hover (desktop), pointer-down (touch), and when the tab
  // is hidden so we don't burn CPU off-screen.
  useEffect(() => {
    if (!hasOverflow || paused) return;
    if (typeof window === 'undefined') return;
    const id = window.setInterval(() => {
      setLogicalIdx((prev) => prev + 1);
    }, AUTO_ADVANCE_MS);
    return () => window.clearInterval(id);
  }, [hasOverflow, paused]);

  // Drive scrollLeft from `logicalIdx`. Always targets a real child's
  // `offsetLeft` (browser-rounded integer pixel) so the rail can't drift
  // off-grid no matter how many ticks accumulate. The double-rendered
  // items list gives us a second pass of clones; when the logical index
  // sweeps into that second copy the smooth-scroll target is the clone's
  // offset, and once the glide finishes we silently subtract the loop
  // distance so the next tick resolves back inside copy 1. The pixels
  // at the snapped position are byte-identical to the ones the user was
  // watching (clone === original).
  useEffect(() => {
    const el = scrollContainerRef.current;
    if (!el) return;
    const N = items.length;
    if (N === 0) return;
    // Wrap into [0, 2N) so we always pick a real child. Negative indexes
    // (from a left-arrow click at boundary 0) get folded in too.
    const ringIdx = ((logicalIdx % (2 * N)) + 2 * N) % (2 * N);
    const targetChild = el.children[ringIdx] as HTMLElement | undefined;
    if (!targetChild) return;
    // Centering math: only applied on mobile, where the card-width
    // formula intentionally leaves a 16 px slack (one inter-card gap)
    // for breathing room — we shift `scrollLeft` left by half of that
    // so the row sits 8 px in from each viewport edge.
    //
    // Desktop sizes cards to fill the rail exactly so any non-zero
    // slack the JS measures is rounding noise (or the rail being
    // wider than `max-w-7xl`'s 1280 px, e.g. when the parent layout
    // lets it stretch). In either case shifting `scrollLeft` would
    // expose part of the *previous* card on the left, which is the
    // "card đầu bị lấp" the user kept seeing. So on desktop we don't
    // shift — `scrollTo(children[k].offsetLeft)` puts card k flush
    // against the viewport's left edge and the next three cards line
    // up neatly to its right. If the rail happens to be a few pixels
    // wider than the 4-card span, the leftover space simply sits as
    // unused padding on the right rather than as a half-card lệch.
    const firstChild = el.children[0] as HTMLElement | undefined;
    const secondChild = el.children[1] as HTMLElement | undefined;
    const cardWidth = firstChild?.offsetWidth ?? 0;
    const stride =
      firstChild && secondChild
        ? secondChild.offsetLeft - firstChild.offsetLeft
        : cardWidth;
    const gap = Math.max(0, stride - cardWidth);
    // Mobile vs. desktop is keyed off the rail's own `clientWidth`
    // (not the card width — that overlapped between the two layouts
    // at intermediate viewports and miscategorised tablets). 768 px
    // is the `md:` Tailwind breakpoint where the desktop 4-up grid
    // takes over.
    const isDesktopLayout = el.clientWidth >= 768;
    let centerOffset = 0;
    if (!isDesktopLayout) {
      const visibleCards = Math.max(
        1,
        Math.floor((el.clientWidth + gap) / Math.max(stride, 1)),
      );
      const visibleArea = visibleCards * cardWidth + Math.max(0, visibleCards - 1) * gap;
      const rawSlack = el.clientWidth - visibleArea;
      centerOffset = Math.max(0, Math.min(rawSlack / 2, 12));
    }
    el.scrollTo({
      left: Math.max(0, Math.round(targetChild.offsetLeft - centerOffset)),
      behavior: 'smooth',
    });

    // If we've glided into the trailing clone (ringIdx >= N), wait for
    // the scroll to finish, then teleport back into copy 1 by subtracting
    // one loop distance. The scrollend event is the cleanest signal;
    // `setTimeout` fallback for the few engines that don't fire it.
    if (ringIdx >= N) {
      const first = el.children[0] as HTMLElement | undefined;
      const firstClone = el.children[N] as HTMLElement | undefined;
      if (!first || !firstClone) return;
      const loop = firstClone.offsetLeft - first.offsetLeft;
      let done = false;
      const finish = () => {
        if (done) return;
        done = true;
        el.scrollLeft -= loop;
        // Re-anchor logical index inside copy 1 without triggering
        // another scroll (we just reverted to the equivalent pixel
        // position by hand).
        setLogicalIdx((prev) => prev - N);
        el.removeEventListener('scrollend', finish);
        window.clearTimeout(timeoutId);
      };
      el.addEventListener('scrollend', finish);
      // 600ms covers the slowest realistic native smooth-scroll.
      const timeoutId = window.setTimeout(finish, 600);
    }
  }, [logicalIdx, items.length, geometryVersion]);

  // Pause when the tab is hidden so a backgrounded page doesn't keep
  // scrolling. visibilitychange fires both on tab switch and on screen-off
  // for mobile browsers.
  useEffect(() => {
    if (typeof document === 'undefined') return;
    const onVisibility = () => setPaused(document.hidden);
    document.addEventListener('visibilitychange', onVisibility);
    return () => document.removeEventListener('visibilitychange', onVisibility);
  }, []);

  if (!items || items.length === 0) {
    return null;
  }

  return (
    // Outer bg theo design system flow Takeaway/Dine-in (#FAFAFA). Card mỗi
    // sản phẩm bên trong giữ `bg-card` riêng.
    <div className="bg-[#FAFAFA]">
      <div className="mx-auto w-full max-w-7xl px-4 py-6 md:px-6">
        {/* Header — chỉ render cụm mũi tên khi carousel thật sự overflow.
            Mỗi mũi tên trong cụm dùng opacity-0/pointer-events-none khi
            đã cuộn tới đầu/cuối để giữ chỗ và tránh layout jump. */}
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-bold md:text-xl">{t('recommended')}</h2>
          {hasOverflow && (
            <div className="flex gap-2">
              <Button
                aria-label={tCommon('previousItem')}
                variant="outline"
                size="icon"
                className={cn(
                  "h-8 w-8 rounded-full transition-opacity",
                  !showLeftArrow && "pointer-events-none opacity-0"
                )}
                onClick={() => scroll("left")}
              >
                <ChevronLeft className="h-4 w-4" />
              </Button>
              <Button
                aria-label={tCommon('nextItem')}
                variant="outline"
                size="icon"
                className={cn(
                  "h-8 w-8 rounded-full transition-opacity",
                  !showRightArrow && "pointer-events-none opacity-0"
                )}
                onClick={() => scroll("right")}
              >
                <ChevronRight className="h-4 w-4" />
              </Button>
            </div>
          )}
        </div>

        {/* Carousel — flex/overflow-x rail on mobile; on tablet/desktop the
            same rail becomes a column-flow CSS grid (`md:grid
            md:grid-flow-col`). At the `md`/`lg` range (iPad, 768–1279 px)
            4 implicit columns of `auto-cols-[(100% − 60) / 4]` tile the
            rail's content width exactly (3 × 20 px gaps subtracted first) —
            four cards keep each card and its food image large enough to
            read on iPad-width screens. At `xl` (≥1280 px, desktop) we
            switch back to 5 columns `auto-cols-[(100% − 80) / 5]` (4 × 20 px
            gaps) so wider monitors show the original 5-up. The 4-up change
            was only ever meant for iPad, not desktop. Using `100%` instead of `100vw`
            keeps the math scrollbar-aware: the grid's own content box,
            not the viewport, drives column width, so a vertical page
            scrollbar never pushes the 5th card into view. Both copies
            of `items` render at every breakpoint so the seamless
            wrap-around stays available. */}
        <div
          ref={scrollContainerRef}
          onScroll={handleScroll}
          onMouseEnter={() => setPaused(true)}
          onMouseLeave={() => setPaused(false)}
          onTouchStart={() => setPaused(true)}
          onTouchEnd={() => setPaused(false)}
          // `overflow-x-auto` cũng cắt trục dọc, nên shadow đáy card bị xén.
          // Chừa padding dọc trong rail (kéo lại bằng -my-3) để bóng đổ hiện
          // đủ mà không đội layout lên.
          className="scrollbar-hide -mx-4 -my-3 flex gap-4 overflow-x-auto px-4 py-3 [-webkit-overflow-scrolling:touch] md:mx-0 md:grid md:grid-flow-col md:grid-rows-1 md:auto-cols-[calc((100%-60px)/4)] md:gap-5 md:px-0 md:py-3 md:snap-x md:snap-mandatory xl:auto-cols-[calc((100%-80px)/5)]"
        >
          {/* Mobile renders both copies (for the seamless wrap-around);
              desktop hides the second copy since it's a flat grid. */}
          {[...items, ...items].map((item, idx) => {
            const isClone = idx >= items.length;
            const ratingPercentage = item.rating;
            const ratingCount = item.reviewCount;

            return (
              <div
                key={`${item.id}-${idx}`}
                aria-hidden={isClone || undefined}
                // Mobile width math: each card occupies exactly half
                // the *stride* (card + flex gap), so two cards span
                // the viewport and the third starts at the right
                // edge.
                //
                // Desktop sizing is owned by the rail's grid
                // `auto-cols`, so the card itself only needs
                // `md:w-auto` to step out of the way and let the grid
                // track govern column width.
                className={cn(
                  "group relative shrink-0 cursor-pointer rounded-2xl border border-border/40 bg-card p-3 transition-all active:scale-[0.98] w-[calc(50vw-16px)] md:w-auto md:snap-start md:rounded-xl md:border-border/60 md:p-0 md:shadow-sm md:hover:shadow-lg md:hover:-translate-y-1",
                )}
                onClick={() => onItemClick(item)}
              >
                {/* Mobile: Vertical card like menu items */}
                <div className="md:hidden">
                  {/* Image Container */}
                  <div className="relative aspect-square w-full overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-primary/10 to-accent/10">
                    <ItemImageGallery
                      images={item.images?.slice(0, 1)}
                      alt={item.name}
                      imgClassName="h-full w-full object-cover"
                      placeholder={
                        <div className="flex h-full w-full items-center justify-center text-4xl text-muted-foreground/20">
                          🍜
                        </div>
                      }
                    />

                    {/* Floating Add Button - Bottom Right */}
                    <Button
                      size="icon"
                      className="absolute bottom-2 right-2 h-8 w-8 rounded-full shadow-lg hover:opacity-90 hover:scale-105 active:scale-95 transition-all"
                      style={{ backgroundColor: '#27A14F' }}
                      aria-label={`${tCart('addToCart')}: ${item.name}`}
                      onClick={(e) => {
                        e.stopPropagation();
                        onItemClick(item);
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
                  </div>

                  {/* Content Below Image */}
                  <div className="space-y-1 pt-2">
                    {/* Title */}
                    <h3 className="text-sm font-semibold leading-tight text-foreground line-clamp-2">
                      {item.name}
                    </h3>

                    {/* Price + Rating — wrap khi có giá khuyến mãi (gốc gạch +
                        giá giảm) để không tràn ra ngoài card; like badge giữ
                        nguyên kích thước, đẩy xuống dòng khi chật. */}
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                      <HappyHourPrice
                        item={{ price: item.price, active_promotion: item.active_promotion, tax_rate: item.tax_rate }}
                        className="text-xs tabular-nums"
                        strikeClassName="text-[11px]"
                      />
                      {ratingPercentage != null && ratingCount != null && ratingCount > 0 && (
                        <div className="ml-auto flex items-center gap-1">
                          <span className="inline-flex shrink-0 items-center gap-0.5 rounded px-1.5 py-0.5 text-[11px] font-medium" style={{ backgroundColor: '#F0FDF4', color: '#27A14F' }}>
                            <ThumbsUp className="h-3 w-3 fill-current" />
                            {ratingPercentage}%
                          </span>
                        </div>
                      )}
                    </div>
                  </div>
                </div>

                {/* Desktop: Original style */}
                <div className="hidden md:block">
                  {/* Image with padding */}
                  <div className="p-3">
                    <div className="relative aspect-square overflow-hidden rounded-lg bg-muted">
                      <ItemImageGallery
                        images={item.images?.slice(0, 1)}
                        alt={item.name}
                        imgClassName="h-full w-full object-cover rounded-xl"
                        placeholder={
                          <div className="flex h-full w-full items-center justify-center text-muted-foreground/40">
                            <svg className="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5}
                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                          </div>
                        }
                      />

                      {/* Add button - bottom right corner */}
                      <button
                        onClick={(e) => {
                          e.stopPropagation();
                          onItemClick(item);
                        }}
                        aria-label={`${tCart('addToCart')}: ${item.name}`}
                        className="absolute bottom-2 right-2 flex size-9 items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg hover:bg-emerald-600 transition-colors"
                      >
                        <Plus className="size-5" />
                      </button>

                      {/* plan-019 — Happy Hour badge */}
                      {item.active_promotion && (
                        <HappyHourBadge
                          discountPercent={item.active_promotion.discount_percent}
                          endsAt={item.active_promotion.ends_at}
                        />
                      )}
                    </div>
                  </div>

                  {/* Content */}
                  <div className="px-3 pb-3">
                    <h3 className="mb-1 text-sm font-bold leading-tight line-clamp-1">
                      {item.name}
                    </h3>

                    {/* Combo includes */}
                    {item.is_combo && item.comboItems && item.comboItems.length > 0 && (
                      <div className="mb-1.5 flex items-start gap-1">
                        <Package className="mt-0.5 size-3 shrink-0 text-amber-600" />
                        <p className="text-[11px] leading-tight text-muted-foreground line-clamp-2">
                          {item.comboItems.map((ci) => ci.name).join(", ")}
                        </p>
                      </div>
                    )}

                    <div className="flex items-center justify-between gap-2">
                      <HappyHourPrice
                        item={{ price: item.price, active_promotion: item.active_promotion, tax_rate: item.tax_rate }}
                        className="text-base"
                      />
                      {ratingPercentage != null && ratingCount != null && ratingCount > 0 && (
                        <div className="flex items-center gap-1 text-xs">
                          <ThumbsUp className="size-3.5 fill-emerald-600 text-emerald-600" />
                          <span className="font-semibold text-emerald-600">{ratingPercentage}%</span>
                          <span className="text-muted-foreground">({ratingCount})</span>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}
