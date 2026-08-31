"use client"

import { useRef, useEffect, useState } from "react"
import { useTranslations } from "next-intl"
import { ChevronLeft, ChevronRight } from "lucide-react"
import { cn } from "@/lib/utils"
import type { MenuCategory } from "@/data/menu"

interface CategoryTabsProps {
  categories: MenuCategory[]
  activeId: string
  onSelect: (id: string) => void
  onMenuClick?: () => void
}

/**
 * CategoryTabs — Horizontal scrollable tabs (KFC-style)
 *
 * Design:
 * - Horizontal scroll, one tab per section name
 * - Active tab has underline + bold text
 * - Scroll buttons on desktop
 * - Sticky below header
 */
export function CategoryTabs({ categories, activeId, onSelect, onMenuClick }: CategoryTabsProps) {
  const t = useTranslations("common")
  const scrollRef = useRef<HTMLDivElement>(null)
  /** True khi tabs container overflow ngang. Khi false (mọi tab fit hết
      vào width desktop) → ẩn hẳn 2 mũi tên prev/next theo yêu cầu. */
  const [hasOverflow, setHasOverflow] = useState(false)
  /** True khi tabs đang sticky (sentinel #sticky-sentinel đã rời viewport).
      Dùng để toggle shadow bottom — chỉ apply ở desktop (md:shadow-sm). */
  const [isSticky, setIsSticky] = useState(false)

  useEffect(() => {
    const sentinel = document.getElementById('sticky-sentinel')
    if (!sentinel) return
    const observer = new IntersectionObserver(
      ([entry]) => setIsSticky(!entry.isIntersecting),
      { threshold: 0 },
    )
    observer.observe(sentinel)
    return () => observer.disconnect()
  }, [])

  useEffect(() => {
    const el = scrollRef.current
    if (!el) return
    const measure = () => {
      // 1px tolerance để tránh false-positive do sub-pixel rounding
      setHasOverflow(el.scrollWidth - el.clientWidth > 1)
    }
    measure()
    if (typeof ResizeObserver === 'undefined') return
    const ro = new ResizeObserver(measure)
    ro.observe(el)
    // Cũng observe từng tab — font/icon load xong sẽ đổi scrollWidth
    Array.from(el.children).forEach((child) => ro.observe(child))
    return () => ro.disconnect()
  }, [categories.length])

  // Auto-scroll the active tab into view — horizontally only. We set the
  // container's scrollLeft directly instead of activeTab.scrollIntoView()
  // because scrollIntoView walks every scrollable ancestor (including the
  // window) and would cancel the page's in-flight smooth scroll to the
  // selected section, leaving the page stuck on the previous section.
  useEffect(() => {
    const container = scrollRef.current
    if (!container) return

    // Only scroll the strip when it actually overflows — otherwise an
    // unnecessary smooth scrollTo() here can interrupt the page's smooth
    // scroll to the selected section (browsers coalesce concurrent smooth
    // scrolls), leaving the page stranded on the previous section.
    if (container.scrollWidth <= container.clientWidth) return

    const activeTab = container.querySelector<HTMLElement>(`[data-category-id="${activeId}"]`)
    if (!activeTab) return

    const target =
      activeTab.offsetLeft - (container.clientWidth - activeTab.clientWidth) / 2
    container.scrollTo({ left: Math.max(0, target), behavior: "smooth" })
  }, [activeId])

  // Index of the currently active category (defaults to the first tab when
  // nothing is selected yet, e.g. right after the menu loads).
  const activeIndex = Math.max(
    0,
    categories.findIndex((c) => c.id === activeId),
  )
  const canGoPrev = activeIndex > 0
  const canGoNext = activeIndex < categories.length - 1

  // The arrows navigate between categories (prev/next) rather than nudge the
  // scroll position — with only a few tabs the strip rarely overflows, so a
  // raw scrollBy() looked like a dead button. Selecting the adjacent category
  // also scrolls the page to that section (via onSelect) and brings the tab
  // into view (auto-scroll effect below), so the arrows always do something.
  const goToAdjacent = (direction: "left" | "right") => {
    const nextIndex = direction === "left" ? activeIndex - 1 : activeIndex + 1
    const next = categories[nextIndex]
    if (next) {
      onSelect(next.id)
    }
  }

  // We intentionally avoid the native `disabled` attribute on the arrows:
  // when a focused button becomes disabled mid-click (i.e. on reaching the
  // first/last category), the browser blurs it and cancels the in-flight
  // smooth scroll to the section. aria-disabled + pointer-events keeps the
  // element enabled in the DOM so the scroll completes.
  const arrowBase =
    "hidden size-7 shrink-0 items-center justify-center rounded-full text-neutral-700 transition-colors hover:bg-muted md:flex"

  return (
    <div
      className={cn(
        // Bg đồng nhất với page #FAFAFA theo design system flow
        // Takeaway/Dine-in. Giữ backdrop-blur cho mượt khi sticky.
        "bg-[#FAFAFA]/95 backdrop-blur-md supports-[backdrop-filter]:bg-[#FAFAFA]/90 transition-shadow duration-200",
        // Shadow-bottom hiện khi tabs đang sticky — cả mobile và desktop.
        isSticky && "shadow-sm",
      )}
    >
      <div className="mx-auto flex w-full max-w-7xl items-center md:px-6">
        {/* Mobile hamburger menu / Desktop left scroll button */}
        <button
          className="shrink-0 p-2 pl-3 md:hidden"
          onClick={onMenuClick}
          aria-label={t('menu')}
        >
          <svg className="h-6 w-6 text-neutral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
        {/* Desktop arrows — chỉ render khi tabs overflow ngang. Mobile thì
            arrowBase đã có `hidden md:flex` nên không bao giờ hiện. */}
        {hasOverflow && (
          <button
            type="button"
            className={cn(arrowBase, !canGoPrev && "pointer-events-none opacity-40")}
            onClick={() => goToAdjacent("left")}
            aria-disabled={!canGoPrev}
            aria-label={t('previousCategory')}
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
        )}

        {/* Tabs container */}
        <div
          ref={scrollRef}
          className="flex flex-1 gap-2 overflow-x-auto pr-4 md:gap-3 md:px-0 md:py-3 scrollbar-hide scroll-smooth [-webkit-overflow-scrolling:touch] [scroll-snap-type:x_proximity]"
          role="tablist"
          aria-label={t('menuCategories')}
        >
          {categories.map((category) => {
            const isActive = activeId === category.id

            return (
              <button
                key={category.id}
                data-category-id={category.id}
                onClick={() => onSelect(category.id)}
                role="tab"
                aria-selected={isActive}
                aria-controls={category.id}
                className={cn(
                  // Underline style chung cho cả mobile và desktop (theo Figma
                  // desktop: gạch xanh dưới active tab, không pill). Desktop
                  // tăng padding ngang để các tab thoáng hơn.
                  "relative shrink-0 whitespace-nowrap px-3 pb-2 pt-2.5 text-sm transition-all [scroll-snap-align:center] md:px-5 md:py-3 md:text-base",
                  isActive
                    ? "font-semibold text-neutral-900"
                    : "font-normal text-neutral-600 hover:text-neutral-900 active:scale-95"
                )}
              >
                <span className="relative z-10">{category.name}</span>
                {/* Green underline cho active tab — hiện ở cả mobile và desktop */}
                {isActive && (
                  <span className="absolute bottom-0 left-0 right-0 h-[3px] bg-[#27A14F] rounded-t-sm" />
                )}
              </button>
            )
          })}
        </div>

        {/* Right scroll button (desktop only, chỉ khi overflow) */}
        {hasOverflow && (
          <button
            type="button"
            className={cn(arrowBase, !canGoNext && "pointer-events-none opacity-40")}
            onClick={() => goToAdjacent("right")}
            aria-disabled={!canGoNext}
            aria-label={t('nextCategory')}
          >
            <ChevronRight className="h-4 w-4" />
          </button>
        )}
      </div>
    </div>
  )
}
