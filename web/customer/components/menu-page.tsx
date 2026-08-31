"use client";

import { useState, useCallback, useEffect, useRef } from "react";
import { useTranslations, useLocale } from 'next-intl';
import Link from "next/link";
import type { MenuCategory, MenuItem } from "@/data/menu";
import { apiFetch } from "@/lib/api";
import type { MergedMenuContext } from "@/lib/menu-item-match";
import {
  classifyMenuError,
  formatMenuAvailability,
  type MenuAvailability,
} from "@/lib/menu-availability";
import { useBrand } from "@/context/brand-context";
import { Loader2, Search, Clock, CloudOff, RefreshCw, UtensilsCrossed } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Button, buttonVariants } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { cn } from "@/lib/utils";

import { useCart } from "@/context/cart-context";
import { useOrderingBlocked } from "@/hooks/use-branch-open-state";
import { CategoryTabs } from "./category-tabs";
import { MenuListItem } from "./menu-list-item";
import ProductModal from "./product-modal";
import CartBar from "./cart-bar";
import ShopInfoBanner from "./shop-info-banner";
import ShopClosedScreen from "./shop-closed-screen";
import FeaturedItemsCarousel from "./featured-items-carousel";
import MenuExpiredModal from "./menu-expired-modal";
import MenuStateCard from "./menu-state-card";
import { SearchPage } from "./search-page";

interface MenuPageProps {
  /** True nếu có menuBranch banner (takeaway/booking) → scroll offset lớn hơn */
  hasMenuBanner?: boolean;
}

// Diacritic-insensitive normalize so "banh" matches "Bánh"; đ/Đ → d.
function normalizeSearch(s: string): string {
  return s
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/đ/g, "d");
}

export default function MenuPage({ hasMenuBanner = false }: MenuPageProps) {
  const { currentBranch, isLoadingBranches } = useBrand();
  const locale = useLocale();
  const t = useTranslations('menu');
  const tc = useTranslations('common');
  const { items: cartItems, setCartMetadata, isItemExpired, reconcileCrossTimeItems } = useCart();
  // #1167 — shut shop ⇒ no menu at all (see the early return below).
  const { blocked: orderingBlocked } = useOrderingBlocked();

  // True when every cart item's per-item deadline has passed. On menu
  // rollover the BE returns the NEXT menu's `cart_deadline_iso` (often 2h+
  // away) — counting down against that for items the user can no longer
  // order would be misleading. See menu-expired-modal.tsx for the copy swap.
  const cartItemsExpired =
    cartItems.length > 0 && cartItems.every((it) => isItemExpired(it));

  const [categories, setCategories] = useState<MenuCategory[] | null>(null);
  const [featuredItems, setFeaturedItems] = useState<MenuItem[]>([]);
  const [menuError, setMenuError] = useState<
    | { kind: "outside-hours"; branchSlug: string; availability: MenuAvailability }
    // The shop simply has no menu open for online ordering (unpublished,
    // inactive, or none carrying this service type). Backend says so with
    // `code: "menu_unavailable"` and it used to fall into `technical` below —
    // so a normal shop state was reported to the customer as a connection
    // fault, under a retry button that could never succeed.
    | { kind: "unavailable"; branchSlug: string }
    // No `message` — the copy lives in the card that renders it, so the two
    // halves of one screen can't drift apart.
    | { kind: "technical"; branchSlug: string }
    | null
  >(null);
  const [activeCategory, setActiveCategory] = useState<string>("");
  const [selectedItem, setSelectedItem] = useState<MenuItem | null>(null);
  // Suppresses the scroll spy while a programmatic (tab/arrow) scroll is in
  // flight, so the spy doesn't hijack the freshly-selected tab mid-scroll.
  // A ref (not state) avoids recreating the IntersectionObserver on toggle.
  const suppressSpyRef = useRef(false);
  const scrollTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const [searchQuery, setSearchQuery] = useState<string>("");
  const [showCategorySheet, setShowCategorySheet] = useState(false);
  const [showSearchPage, setShowSearchPage] = useState(false);

  // Menu expiry tracking
  const [menuMetadata, setMenuMetadata] = useState<{
    menuId: string;
    menuName: string;
    scheduleStartTime: string | null; // HH:MM:SS format
    scheduleEndTime: string | null; // HH:MM:SS format
    cartTimeoutMinutes: number;
    cartDeadlineIso: string | null; // ISO 8601 format
  } | null>(null);
  const [showMenuExpiredModal, setShowMenuExpiredModal] = useState(false);

  // Track previous menu_id across the 60s auto-refetch ticks. Dine-in doesn't
  // have auto-refetch, so its watcher catches expiry from STALE menuMetadata
  // (Date.now() ≥ scheduleEndMs). Takeaway refetches every 60s → BE always
  // returns the CURRENT menu → stale view is impossible → schedule-end
  // watcher never trips. We detect rollover here instead: when a refetch
  // returns a DIFFERENT menu_id than the previously-loaded one, the menu
  // just rolled over — open the modal so the user sees the same notice the
  // dine-in flow surfaces.
  const prevMenuIdRef = useRef<string | null>(null);

  /**
   * Plan-019 subtle issue 1 — bumping this key re-runs the menu fetch.
   * The promotion-expiry watcher below auto-bumps it when any visible
   * item's `active_promotion.ends_at` is about to pass, so the customer
   * doesn't see a stale Happy Hour Badge after the window closes.
   */
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    if (!currentBranch.slug) return;
    const ac = new AbortController();
    // No `?brand=` filter: a single branch may host multiple brands' menus
    // (food-court / multi-brand shop). Letting the backend pick the active
    // menu by time-of-day + priority is what the operator wants — otherwise
    // a menu set on brand X stays hidden when the branch's default brand is Y.
    apiFetch<{
      data: {
        menu_id: string;
        menu_name: string;
        schedule_start_time: string | null;
        schedule_end_time: string | null;
        cart_timeout_minutes: number;
        cart_deadline_iso: string | null;
        categories: MenuCategory[];
        menus?: MergedMenuContext[];
      }
    }>(
      `/api/v1/customer/branches/${currentBranch.slug}/menu`,
      { signal: ac.signal },
    )
      .then(({ data }) => {
        if (ac.signal.aborted) return;
        setMenuError(null);

        // Rollover detection — fire BEFORE setMenuMetadata so the modal
        // opens with the OLD menu_name (more user-friendly: "Thực đơn Sáng
        // đã kết thúc"). Skip on cold load (prev null) — only trip when an
        // already-loaded menu_id changes mid-session.
        const prevMenuId = prevMenuIdRef.current;
        const hasItems = cartItems.length > 0;
        if (prevMenuId && prevMenuId !== data.menu_id && hasItems) {
          setShowMenuExpiredModal(true);
        }
        prevMenuIdRef.current = data.menu_id;

        // Store menu metadata for expiry and cart timeout tracking
        setMenuMetadata({
          menuId: data.menu_id,
          menuName: data.menu_name,
          scheduleStartTime: data.schedule_start_time,
          scheduleEndTime: data.schedule_end_time,
          cartTimeoutMinutes: data.cart_timeout_minutes,
          cartDeadlineIso: data.cart_deadline_iso,
        });

        // Propagate cascade-resolved deadline to the cart context so the
        // countdown + isExpired flag are available wherever useCart() is used.
        setCartMetadata(
          data.cart_deadline_iso
            ? {
              created_at: new Date().toISOString(),
              branch_slug: currentBranch.slug,
              timeout_minutes: data.cart_timeout_minutes ?? null,
              deadline_iso: data.cart_deadline_iso,
            }
            : null,
        );

        // #1187 — the shop decides, via MenuSection.is_featured (see menu-view).
        const featuredCategories = data.categories.filter((cat) => cat.is_featured);

        // Some products in the menu may not have a gallery image configured.
        // To keep the UI consistent (especially in the cart drawer), normalize
        // them to a shared fallback thumbnail so `product.image` is never null
        // on the client.
        const FALLBACK_IMAGE = "/images/craft-pho-bowl.webp";

        const enrichCategory = (cat: MenuCategory): MenuCategory => ({
          ...cat,
          items: cat.items.map((item) => {
            const resolvedImage = item.image ?? FALLBACK_IMAGE;
            // Normalize the gallery so cards can always rely on a non-empty
            // array. Backend sends every gallery photo in `images`; fall back
            // to the single resolved thumbnail when a product has no gallery.
            const gallery =
              item.images && item.images.length > 0
                ? item.images
                : [resolvedImage];
            return {
              ...item,
              categoryName: cat.name,
              image: resolvedImage,
              images: gallery,
              comboItems: item.comboItems?.map((combo) => ({
                ...combo,
                image: combo.image ?? FALLBACK_IMAGE,
              })),
            };
          }),
        });

        // Render EVERY section in the main list in the admin-set display order.
        // The featured carousel is an ADDITIONAL highlight above the list — it
        // must not pull a section out of its admin position, which is what broke
        // the customer-facing order (e.g. おすすめ jumped out of slot 2).
        const enriched = data.categories.map(enrichCategory);

        const featuredItemsFlat = featuredCategories
          .map(enrichCategory)
          .flatMap((cat) => cat.items);

        setCategories(enriched);
        setFeaturedItems(featuredItemsFlat);
        setActiveCategory(enriched[0]?.id ?? "");

        // Cross-time reconciliation (TC-DICONF03): đối chiếu giỏ với menu vừa
        // load. Món còn giá y nguyên → re-ref sang menu mới; đổi giá / biến
        // mất → flag chặn đặt hàng.
        reconcileCrossTimeItems({
          menuId: data.menu_id,
          menuName: data.menu_name,
          scheduleEndTime: data.schedule_end_time,
          cartTimeoutMinutes: data.cart_timeout_minutes,
          categories: enriched,
          menus: data.menus,
        });
      })
      .catch((error: unknown) => {
        if (ac.signal.aborted) return;

        setMenuError({ ...classifyMenuError(error), branchSlug: currentBranch.slug });
      });
    return () => ac.abort();
  }, [currentBranch.slug, locale, refreshKey]);

  // Plan-019 — periodic refetch every 60s catches BOTH start AND end of
  // any Happy Hour window without the customer having to reload. Aligns
  // with the backend MenuPromotionService cache TTL (60s) so refresh-to-
  // truth cadence matches. The ends_at watcher below is the precision
  // pass for items already showing a discount (refetches right at the
  // second the window closes); this interval is the wide-net pass that
  // also catches promotions that just STARTED.
  useEffect(() => {
    if (!currentBranch.slug) return;
    const tick = setInterval(() => setRefreshKey((k) => k + 1), 60_000);
    return () => clearInterval(tick);
  }, [currentBranch.slug]);

  // Plan-019 subtle issue 1 — when any visible item's Happy Hour window is
  // about to close, schedule a refetch right after the earliest ends_at so
  // the UI doesn't keep showing a stale discounted price. Without this the
  // customer could view at 19:59, add at 20:01, and be confused by the
  // server's recomputed (full-price) line.
  useEffect(() => {
    if (!categories) return;
    const now = Date.now();
    const allItems = [
      ...featuredItems,
      ...categories.flatMap((c) => c.items),
    ];
    const upcomingEnds = allItems
      .map((it) => it.active_promotion?.ends_at)
      .filter((v): v is string => !!v)
      .map((iso) => new Date(iso).getTime())
      .filter((ts) => ts > now)
      .sort((a, b) => a - b);
    if (upcomingEnds.length === 0) return;
    // Refetch ~3s after the soonest expiry (small buffer for server clock skew).
    const ms = Math.max(5000, upcomingEnds[0] - now + 3000);
    const timer = setTimeout(() => setRefreshKey((k) => k + 1), ms);
    return () => clearTimeout(timer);
  }, [categories, featuredItems]);

  // Menu expiry watcher — fires when the current menu's schedule has ended.
  // Backend has already resolved the 4-tier cascade and emitted:
  //   • cart_deadline_iso   = scheduleEnd + cart_timeout_minutes (in branch tz)
  //   - cart_timeout_minutes = effective timeout (tier 4 -> 3 -> 2 -> 1 cascade)
  // We back-compute scheduleEndMs from those to avoid timezone drift when
  // the customer's browser is in a different tz than the shop.
  // Fallback: if backend only sent schedule_end_time (HH:MM:SS) we parse it
  // as local time (legacy payloads only).
  useEffect(() => {
    if (!menuMetadata) return;

    const { cartDeadlineIso, cartTimeoutMinutes, scheduleEndTime } = menuMetadata;

    let scheduleEndMs: number | null = null;
    if (cartDeadlineIso && typeof cartTimeoutMinutes === "number") {
      scheduleEndMs = new Date(cartDeadlineIso).getTime() - cartTimeoutMinutes * 60 * 1000;
    } else if (scheduleEndTime) {
      const [hours, minutes, seconds] = scheduleEndTime.split(":").map(Number);
      const endTime = new Date();
      endTime.setHours(hours, minutes, seconds || 0, 0);
      scheduleEndMs = endTime.getTime();
    } else {
      return; // No schedule → menu always available
    }

    const checkMenuExpiry = () => {
      if (Date.now() >= (scheduleEndMs as number)) {
        setShowMenuExpiredModal(true);
      }
    };

    checkMenuExpiry();
    const interval = setInterval(checkMenuExpiry, 10000);
    return () => clearInterval(interval);
  }, [menuMetadata]);

  // Filter categories based on search query
  const filteredCategories = categories?.map((cat) => {
    if (!searchQuery.trim()) return cat;

    // Match by món NAME only (diacritic-insensitive). Searching the description
    // too dragged in unrelated items whose description contained the query.
    const query = normalizeSearch(searchQuery.trim());
    const matchedItems = cat.items.filter((item) =>
      normalizeSearch(item.name).includes(query),
    );

    return { ...cat, items: matchedItems };
  }).filter((cat) => cat.items.length > 0) ?? [];

  const activeMenuError = menuError?.branchSlug === currentBranch.slug ? menuError : null;
  const isLoadingMenu = categories === null && !activeMenuError;

  // Scroll spy — auto-update active tab khi scroll đến section.
  // rootMargin top should approximate the total sticky stack so a section is
  // only considered "in view" once it has cleared the sticky header + tabs.
  useEffect(() => {
    if (!filteredCategories || filteredCategories.length === 0) return;

    // Mobile: 49px header + ~60px menu title + ~60px tabs ≈ 169px → 180px safe
    // Desktop: 57px header + sticky menu section ≈ 192px → also fits 180px
    // (After hiding "Các cửa hàng khác" row, both takeaway and menuorder
    // have the same header height on mobile)
    const topOffsetPx = 180;

    const options = {
      root: null,
      rootMargin: `-${topOffsetPx}px 0px -60% 0px`,
      threshold: 0,
    };

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting && !suppressSpyRef.current) {
          setActiveCategory(entry.target.id);
        }
      });
    }, options);

    // Observe tất cả section
    filteredCategories.forEach((cat) => {
      const el = document.getElementById(cat.id);
      if (el) observer.observe(el);
    });

    return () => observer.disconnect();
  }, [filteredCategories]);

  const handleSelect = useCallback((id: string) => {
    // Suppress the scroll spy until the programmatic smooth-scroll actually
    // settles. A fixed 1s timeout was unreliable: long scrolls (or rapid
    // presses) take longer, so the spy would re-fire mid-scroll and snap the
    // active tab back to whatever section was passing by.
    suppressSpyRef.current = true;
    if (scrollTimeoutRef.current) clearTimeout(scrollTimeoutRef.current);

    setActiveCategory(id);
    const el = document.getElementById(id);
    if (el) {
      el.scrollIntoView({ behavior: "smooth", block: "start" });
    }

    // Re-enable the spy once scrolling stops (debounced ~120ms after the last
    // scroll event), with a 2s hard cap in case no scroll events fire (e.g.
    // the target section is already at the top).
    let settleTimer: ReturnType<typeof setTimeout> | null = null;
    const finish = () => {
      window.removeEventListener("scroll", onScroll);
      if (settleTimer) clearTimeout(settleTimer);
      if (scrollTimeoutRef.current) clearTimeout(scrollTimeoutRef.current);
      scrollTimeoutRef.current = null;
      suppressSpyRef.current = false;
    };
    const onScroll = () => {
      if (settleTimer) clearTimeout(settleTimer);
      settleTimer = setTimeout(finish, 120);
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    scrollTimeoutRef.current = setTimeout(finish, 2000);
    // Kick the debounce once so an already-in-view target still releases.
    onScroll();
  }, []);

  const handleItemClick = useCallback((item: MenuItem) => {
    setSelectedItem(item);
  }, []);

  // The featured carousel takes an `onAddToCart` prop but never calls it —
  // every tile routes through `onItemClick` into the detail modal, which is
  // what actually adds to the cart. Deliberate no-op until that changes.
  const handleAddToCart = useCallback(() => {}, []);

  const handleModalChange = useCallback((open: boolean) => {
    if (!open) setSelectedItem(null);
  }, []);



  // Triggered when the user confirms the expiry modal. Resets local menu
  // state and bumps `refreshKey` so the menu fetch effect re-runs, picking
  // up whichever menu schedule is now active server-side.
  const handleViewNextMenu = useCallback(() => {
    setCategories(null);
    setFeaturedItems([]);
    setMenuError(null);
    setMenuMetadata(null);
    setShowMenuExpiredModal(false);
    setRefreshKey((k) => k + 1);
  }, []);

  // The menu-expiry modal must remain mounted regardless of loading/error
  // state — without this, the periodic /menu refetch returning 404 (because
  // the schedule window closed) would unmount the whole UI and the modal
  // along with it, swallowing the notification. Render it from a wrapper
  // around every return path.
  const expiredModal = (
    <MenuExpiredModal
      open={showMenuExpiredModal}
      onOpenChange={setShowMenuExpiredModal}
      menuName={menuMetadata?.menuName}
      cartDeadlineIso={menuMetadata?.cartDeadlineIso}
      cartItemsExpired={cartItemsExpired}
      graceWindowMinutes={menuMetadata?.cartTimeoutMinutes}
      onViewNextMenu={handleViewNextMenu}
    />
  );

  if (isLoadingBranches || isLoadingMenu) {
    return (
      <>
        <div className="flex flex-1 items-center justify-center gap-2 text-muted-foreground">
          <Loader2 className="h-5 w-5 animate-spin" />
          <span className="text-sm">{t('loadingMenu')}</span>
        </div>
        {expiredModal}
      </>
    );
  }

  // #1167 — shut shop: the menu does NOT render. A browsable list of dishes
  // nobody can order is exactly the confusion this fixes, so the whole menu is
  // replaced by the closed screen (with the next opening spelled out). Sits
  // after the loading return so we never flash it while branches are still in
  // flight, and after every hook — the early return must not reorder them.
  if (orderingBlocked) {
    return (
      <>
        <ShopClosedScreen branchName={currentBranch.name} />
        {expiredModal}
      </>
    );
  }

  // Only surface the error UI on a true cold-start failure. If we already
  // have categories from a prior successful fetch, a subsequent 404 most
  // likely means the schedule window just closed — keep the (now stale)
  // menu visible underneath while the expiry modal takes over.
  if (activeMenuError && categories === null) {
    if (activeMenuError.kind === "outside-hours") {
      const schedule = formatMenuAvailability(activeMenuError.availability, locale);

      return (
        <>
          <MenuStateCard
            icon={Clock}
            tone="amber"
            titleId="menu-outside-hours-title"
            title={t('outsideHoursTitle')}
            description={t('outsideHoursDescription', {
              branch: activeMenuError.availability.branch_name,
            })}
            details={
              <dl className="mt-5 divide-y rounded-xl border bg-neutral-50 px-4 text-left text-sm">
                <div className="flex justify-between gap-4 py-3">
                  <dt className="text-neutral-500">{t('nextOpening')}</dt>
                  <dd className="text-right font-semibold text-neutral-900">
                    {schedule.date}<br />
                    {schedule.closesAt
                      ? `${schedule.opensAt}–${schedule.closesAt}`
                      : schedule.opensAt}
                  </dd>
                </div>
                <div className="flex justify-between gap-4 py-3">
                  <dt className="text-neutral-500">{t('timezone')}</dt>
                  <dd className="font-medium text-neutral-900">{activeMenuError.availability.timezone}</dd>
                </div>
              </dl>
            }
            hint={t('outsideHoursHint')}
            actions={
              <Link
                href={`/${locale}/select-branch?next=takeaway`}
                className={buttonVariants({ className: "w-full sm:w-auto" })}
              >
                {t('chooseAnotherStore')}
              </Link>
            }
          />
          {expiredModal}
        </>
      );
    }

    // Not a failure: the shop has nothing open for online ordering. Neutral
    // tone, no retry — pressing one would just fail again — and the only real
    // way forward is another store.
    if (activeMenuError.kind === "unavailable") {
      return (
        <>
          <MenuStateCard
            icon={UtensilsCrossed}
            tone="neutral"
            titleId="menu-unavailable-title"
            title={t('unavailableTitle')}
            description={t('unavailableDescription', { branch: currentBranch.name })}
            hint={t('unavailableHint')}
            actions={
              <Link
                href={`/${locale}/select-branch?next=takeaway`}
                className={buttonVariants({ className: "w-full sm:w-auto" })}
              >
                {t('chooseAnotherStore')}
              </Link>
            }
          />
          {expiredModal}
        </>
      );
    }

    return (
      <>
        <MenuStateCard
          icon={CloudOff}
          tone="danger"
          titleId="menu-load-error-title"
          title={t('loadErrorTitle')}
          description={t('loadErrorDescription')}
          hint={t('loadErrorHint')}
          actions={
            <>
              <Button
                className="w-full sm:w-auto"
                onClick={() => {
                  setMenuError(null);
                  setCategories(null);
                  setRefreshKey((key) => key + 1);
                }}
              >
                <RefreshCw className="size-4" aria-hidden="true" />
                {tc('retry')}
              </Button>
              <Link
                href={`/${locale}/select-branch?next=takeaway`}
                className={buttonVariants({ variant: "outline", className: "w-full sm:w-auto" })}
              >
                {t('chooseAnotherStore')}
              </Link>
            </>
          }
        />
        {expiredModal}
      </>
    );
  }

  // Menu loaded, but the shop has published nothing in it. Distinct from the
  // failure above (nothing to retry) and from `noResults` below (that one is a
  // search miss) — hence its own copy rather than either neighbour's.
  if (categories?.length === 0) {
    return (
      <>
        <MenuStateCard
          icon={UtensilsCrossed}
          tone="neutral"
          titleId="menu-empty-title"
          title={t('emptyMenuTitle')}
          description={t('emptyMenuDescription', { branch: currentBranch.name })}
          actions={
            <Link
              href={`/${locale}/select-branch?next=takeaway`}
              className={buttonVariants({ variant: "outline", className: "w-full sm:w-auto" })}
            >
              {t('chooseAnotherStore')}
            </Link>
          }
        />
        {/* The cart outlives the menu: a customer can hold items the 60s
            refetch then finds emptied. Dropping the bar here would strand
            them with no way to check out. It hides itself when empty. */}
        <CartBar />
        {expiredModal}
      </>
    );
  }

  return (
    // Root bg: #FAFAFA theo design system flow Takeaway/Dine-in. Sticky
    // header bar bên dưới giữ bg-white riêng để nổi trên nền này.
    <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
      <ShopInfoBanner branch={currentBranch} />

      {featuredItems.length > 0 && (
        <FeaturedItemsCarousel
          items={featuredItems}
          onAddToCart={handleAddToCart}
          onItemClick={setSelectedItem}
        />
      )}

      <div id="sticky-sentinel" className="h-px" />

      {/* Sticky menu header — sits directly below the main Header.
          Heights (box-sizing: border-box):
            • mobile, with menuBranch (takeaway): row1 h-12 (48) + border-b (1) = 49px
              (row2 "Các cửa hàng khác" hidden per user request)
            • mobile, no menuBranch (menuorder):  row1 h-12 (48) + border-b (1) = 49px
            • desktop (≥ md):                     single row h-14 (56) + border-b (1) = 57px
          We subtract 1px from the mobile value so the sticky pins 1px under
          the header's border-b instead of 1px below it — the overlap is
          hidden by the header's higher z-index (z-50 vs z-40), and removes
          a subpixel hairline gap during scroll. */}
      <div className="sticky top-[48px] md:top-[56px] z-40 bg-[#FAFAFA]">
        <div className="bg-[#FAFAFA]">
          <div className="mx-auto w-full max-w-7xl px-4 py-3 md:px-6 md:py-4">
            <div className="flex flex-col gap-1">
              {/* Row 1: Menu name + Search icon (aligned) */}
              <div className="flex items-center justify-between gap-3">
                <h1 className="truncate text-base font-semibold text-neutral-900 md:text-xl flex-1 min-w-0">
                  {menuMetadata?.menuName || t('title')}
                </h1>

                {/* Mobile search icon - aligned with menu name */}
                <button
                  className="shrink-0 p-2 -mr-2 md:hidden"
                  onClick={() => setShowSearchPage(true)}
                  aria-label={t('searchPlaceholder')}
                >
                  <Search className="h-5 w-5 text-neutral-600" />
                </button>

                {/* Desktop search */}
                <div className="relative hidden w-48 lg:w-64 md:block">
                  <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                  <Input
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder={t('searchPlaceholder')}
                    // pt/pb lệch 4px (6/2) so với `py-1` mặc định → text lẫn
                    // placeholder tụt xuống đúng 2px mà chiều cao ô không đổi.
                    className="h-9 pl-9 text-sm rounded-[20px] pt-1.5 pb-0.5 focus-visible:border-input focus-visible:ring-0"
                  />
                </div>
              </div>

              {/* Row 2: Schedule time */}
              {menuMetadata?.scheduleStartTime && menuMetadata?.scheduleEndTime && (
                <div className="flex items-center gap-1.5 text-xs md:text-sm text-neutral-600">
                  <svg className="h-3.5 w-3.5 md:h-4 md:w-4 shrink-0" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.5" />
                    <path d="M10 6V10H13" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                  </svg>
                  <span className="truncate font-normal">
                    {menuMetadata.scheduleStartTime.slice(0, 5)} - {menuMetadata.scheduleEndTime.slice(0, 5)}
                  </span>
                </div>
              )}
            </div>
          </div>
        </div>

        <CategoryTabs
          categories={filteredCategories ?? []}
          activeId={activeCategory}
          onSelect={handleSelect}
          onMenuClick={() => setShowCategorySheet(true)}
        />
      </div>

      <main className="flex-1 bg-[#FAFAFA] pb-20 md:pb-8">
        <div className="mx-auto w-full max-w-7xl px-4 py-4 md:px-6 md:py-6">
          {/* Desktop search miss. `noResults` used to be wired to an empty
              MENU instead (its own screen now), which left a no-match desktop
              search rendering a blank page with no explanation at all. */}
          {filteredCategories.length === 0 && (
            <div className="flex flex-col items-center gap-1 py-16 text-center">
              <p className="text-sm font-medium text-neutral-700">{t('noResults')}</p>
              <p className="text-xs text-neutral-500">{t('tryDifferentKeyword')}</p>
            </div>
          )}
          <div className="space-y-6 md:space-y-8">
            {filteredCategories.map((category) => (
              <section
                key={category.id}
                id={category.id}
                className="space-y-3 scroll-mt-[180px] md:space-y-4 md:scroll-mt-48"
                role="tabpanel"
                aria-labelledby={`tab-${category.id}`}
              >
                {/* Mobile: 49px header + ~60px menu title + ~60px tabs ≈ 169px → 180px safe
                    Desktop: 57px header + sticky menu section ≈ 192px → 48*4=192px */}
                <h2 className="text-lg font-bold md:text-xl">{category.name}</h2>
                {/* Mobile: 2 columns, Desktop: 2 columns (MenuListItem handles its own layout) */}
                <div className="grid grid-cols-2 gap-3 md:gap-4">
                  {category.items.map((item) => (
                    <MenuListItem
                      key={item.id}
                      item={item}
                      onClick={() => handleItemClick(item)}
                    />
                  ))}
                </div>
              </section>
            ))}
          </div>
        </div>
      </main>

      <CartBar />

      {selectedItem && (
        <ProductModal
          item={selectedItem}
          open={!!selectedItem}
          onOpenChange={handleModalChange}
        />
      )}

      {expiredModal}

      {/* Category Navigation Sheet (Mobile only) */}
      <Sheet open={showCategorySheet} onOpenChange={setShowCategorySheet}>
        <SheetContent side="bottom" className="rounded-t-3xl p-0" showCloseButton={false} style={{ height: '80vh', maxHeight: '80vh' }}>
          <div className="flex h-full flex-col">
            {/* Header */}
            <SheetHeader className="border-b px-6 py-4">
              <SheetTitle className="text-center text-lg font-semibold">
                {menuMetadata?.menuName || t('title')}
              </SheetTitle>
              {menuMetadata?.scheduleStartTime && menuMetadata?.scheduleEndTime && (
                <p className="text-center text-sm text-neutral-600">
                  {menuMetadata.scheduleStartTime.slice(0, 5)} - {menuMetadata.scheduleEndTime.slice(0, 5)}
                </p>
              )}
            </SheetHeader>

            {/* Category List */}
            <div className="flex-1 overflow-y-auto px-6 py-4">
              <nav className="space-y-1">
                {filteredCategories?.map((category) => {
                  const isActive = activeCategory === category.id;
                  return (
                    <button
                      key={category.id}
                      onClick={() => {
                        handleSelect(category.id);
                        setShowCategorySheet(false);
                      }}
                      className={cn(
                        "w-full text-left px-4 py-3 rounded-lg text-base transition-colors",
                        isActive
                          ? "bg-[#27A14F]/10 text-[#27A14F] font-semibold"
                          : "text-neutral-900 hover:bg-neutral-100"
                      )}
                    >
                      {category.name}
                    </button>
                  );
                })}
              </nav>
            </div>

            {/* Footer Button */}
            <div className="border-t px-6 py-4">
              <Button
                onClick={() => setShowCategorySheet(false)}
                className="w-full h-12 text-base font-semibold"
                style={{ backgroundColor: '#171717' }}
              >
                Bỏ qua
              </Button>
            </div>
          </div>
        </SheetContent>
      </Sheet>

      {/* Search Page (Mobile only) */}
      {showSearchPage && (
        <SearchPage
          allItems={categories?.flatMap((cat) => cat.items) ?? []}
          onClose={() => setShowSearchPage(false)}
          onItemClick={(item) => {
            setShowSearchPage(false);
            setSelectedItem(item);
          }}
        />
      )}
    </div>
  );
}
