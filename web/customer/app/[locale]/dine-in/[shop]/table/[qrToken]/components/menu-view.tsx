"use client";

import { useState, useCallback, useEffect, useRef } from "react";
import { useRouter } from "@/i18n/routing";
import { useTranslations, useLocale } from 'next-intl';
import { ShoppingBag, MapPin, Users, CheckCircle2, Clock, Loader2, Search, BellRing, CloudOff, RefreshCw, UtensilsCrossed } from "lucide-react";
import { toast } from "sonner";
import type { MenuItem, MenuCategory } from "@/data/menu";
import { apiFetch } from "@/lib/api";
import { classifyMenuError, type MenuErrorKind } from "@/lib/menu-availability";
import type { MergedMenuContext } from "@/lib/menu-item-match";
import { useCart, generateCartItemId, type CartItem } from "@/context/cart-context";
import { useBrand } from "@/context/brand-context";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { CategoryTabs } from "@/components/category-tabs";
import { MenuListItem } from "@/components/menu-list-item";
import { PromoSectionChip } from "@/components/promo-section-chip";
import { cn } from "@/lib/utils";
import ShopInfoBanner from "@/components/shop-info-banner";
import ShopClosedNotice from "@/components/shop-closed-notice";
import FeaturedItemsCarousel from "@/components/featured-items-carousel";
import ProductModal from "@/components/product-modal";
import MenuExpiredModal from "@/components/menu-expired-modal";
import MenuStateCard from "@/components/menu-state-card";
import { SearchPage } from "@/components/search-page";
import { effectiveUnitPrice } from "@/components/happy-hour";
import type { TableInfo } from "../page";
import { useCurrency } from "@/lib/currency";

interface MenuViewProps {
  table: TableInfo;
  qrToken: string;
  hasExistingOrder: boolean;
  /** plan-034 — true while POS staff holds the soft-lock on this order.
   *  Surface as a sticky banner + disable "+" buttons until staff releases. */
  editingByStaff?: boolean;
  onBack?: () => void;
  onPay?: () => void;
}

// Tạm ẩn nút "Gọi phục vụ" — bật lại bằng cách đổi thành true.
const SHOW_CALL_STAFF_BUTTON = false;

// Diacritic-insensitive normalize so "banh" matches "Bánh"; đ/Đ → d.
function normalizeSearch(s: string): string {
  return s
    .toLowerCase()
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/đ/g, "d");
}

export default function MenuView({ table, qrToken, hasExistingOrder, editingByStaff, onBack, onPay }: MenuViewProps) {
  const router = useRouter();
  const locale = useLocale();
  const t = useTranslations('dineIn');
  const { format: fmt } = useCurrency();
  const tMenu = useTranslations('menu');
  const tCommon = useTranslations('common');
  const { currentBranch } = useBrand();

  // Get current path params for navigation
  const currentShop = currentBranch.slug;
  const {
    items,
    totalItems,
    totalPrice,
    addToCart,
    updateQuantity,
    setCartMetadata,
    isItemExpired,
    reconcileCrossTimeItems,
  } = useCart();

  // When the menu rolls over, BE returns the NEXT menu's `cart_deadline_iso`
  // (e.g. 2h+ from now). The cart items still in localStorage are from the
  // OLD menu — their per-item deadlines have already passed. Counting against
  // the new deadline would mislead the user into thinking they have hours
  // left for items they can no longer order.
  const cartItemsExpired =
    items.length > 0 && items.every((it) => isItemExpired(it));

  const [categories, setCategories] = useState<MenuCategory[] | null>(null);
  const [featuredItems, setFeaturedItems] = useState<MenuItem[]>([]);
  // Was a bare boolean, which threw away the reason: a shop that has simply
  // published no dine-in menu got told its connection was broken.
  const [menuError, setMenuError] = useState<MenuErrorKind | null>(null);

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
  // Bumped when the user dismisses the expiry modal — re-runs the menu
  // fetch effect so whichever time-of-day menu is active now gets loaded.
  const [refreshKey, setRefreshKey] = useState(0);

  const [activeCategory, setActiveCategory] = useState("");
  const [selectedItem, setSelectedItem] = useState<MenuItem | null>(null);
  const [editingCartItem, setEditingCartItem] = useState<CartItem | null>(null);
  const [staffCalled, setStaffCalled] = useState(false);
  const [staffCalling, setStaffCalling] = useState(false);
  const [isUserScrolling, setIsUserScrolling] = useState(false);
  const scrollTimeoutRef = useRef<NodeJS.Timeout | null>(null);
  const [searchQuery, setSearchQuery] = useState("");
  const [showSearchPage, setShowSearchPage] = useState(false);
  const [showCategorySheet, setShowCategorySheet] = useState(false);

  useEffect(() => {
    let cancelled = false;
    async function loadMenu() {
      try {
        // No `?brand=` filter — see menu-page.tsx for rationale: multi-brand
        // shops would otherwise hide menus not on the branch's default brand.
        const res = await apiFetch<{
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
          `/api/v1/customer/tables/${qrToken}/menu`,
        );
        if (cancelled) return;

        // Store menu metadata for expiry and cart timeout tracking
        setMenuMetadata({
          menuId: res.data.menu_id,
          menuName: res.data.menu_name,
          scheduleStartTime: res.data.schedule_start_time,
          scheduleEndTime: res.data.schedule_end_time,
          cartTimeoutMinutes: res.data.cart_timeout_minutes,
          cartDeadlineIso: res.data.cart_deadline_iso,
        });

        // Menu metadata loaded

        // Cross-time reconciliation (TC-DICONF03): đối chiếu món trong giỏ với
        // menu vừa load. Món còn giá y nguyên → re-ref sang menu mới (vẫn đặt
        // được); món đổi giá / biến mất → flag để chặn xác nhận.
        reconcileCrossTimeItems({
          menuId: res.data.menu_id,
          menuName: res.data.menu_name,
          scheduleEndTime: res.data.schedule_end_time,
          cartTimeoutMinutes: res.data.cart_timeout_minutes,
          categories: res.data.categories,
          menus: res.data.menus,
        });

        // Propagate cascade-resolved deadline to the cart context so the
        // countdown + isExpired flag are available wherever useCart() is used.
        setCartMetadata(
          res.data.cart_deadline_iso
            ? {
                created_at: new Date().toISOString(),
                branch_slug: currentBranch.slug,
                timeout_minutes: res.data.cart_timeout_minutes ?? null,
                deadline_iso: res.data.cart_deadline_iso,
              }
            : null,
        );

        // #1187 — the shop decides, via MenuSection.is_featured. This used to
        // scan the display name for a handful of hard-coded words and emoji, so
        // renaming a section silently emptied the carousel and a shop outside
        // those three languages could never fill it.
        const featuredCategories = res.data.categories.filter((cat) => cat.is_featured);

        // Render EVERY section in the main list in the admin-set display order.
        // The featured carousel is an ADDITIONAL highlight above the list — it
        // must not pull a section out of its admin position, which is what broke
        // the customer-facing order (e.g. おすすめ jumped out of slot 2).
        const enriched = res.data.categories.map((cat) => ({
          ...cat,
          items: cat.items.map((item) => ({ ...item, categoryName: cat.name })),
        }));

        setCategories(enriched);
        setFeaturedItems(featuredCategories.flatMap((cat) => cat.items));
        if (enriched.length > 0) {
          setActiveCategory(enriched[0].id);
        }
      } catch (error) {
        if (!cancelled) setMenuError(classifyMenuError(error));
      }
    }
    loadMenu();
    return () => { cancelled = true; };
  }, [qrToken, locale, refreshKey]);

  // #1715 — menu bàn là nơi khách ngồi LÂU NHẤT, mà trước đây `refreshKey` chỉ
  // nhích khi khách bấm "xem menu kế" trong modal hết giờ ⇒ giỏ được soát đúng
  // một lần lúc mở trang. Khung giờ ưu đãi đóng giữa bữa thì giá trong giỏ đứng
  // yên ở con số cũ. 60s, cùng nhịp với trang menu takeaway.
  useEffect(() => {
    const tick = setInterval(() => setRefreshKey((k) => k + 1), 60_000);
    return () => clearInterval(tick);
  }, []);

  // Menu expiry watcher — check if the current menu schedule has ended
  useEffect(() => {
    if (!menuMetadata) return;

    const { cartDeadlineIso, cartTimeoutMinutes, scheduleEndTime } = menuMetadata;

    // Prefer backend ISO deadline + timeout cascade to avoid timezone drift
    let scheduleEndMs: number | null = null;

    if (cartDeadlineIso && typeof cartTimeoutMinutes === "number") {
      const deadlineMs = new Date(cartDeadlineIso).getTime();
      scheduleEndMs = deadlineMs - cartTimeoutMinutes * 60 * 1000;
    } else if (scheduleEndTime) {
      // Fallback for older payloads: interpret schedule_end_time as local time
      const [hours, minutes, seconds] = scheduleEndTime.split(":").map(Number);
      const endTime = new Date();
      endTime.setHours(hours, minutes, seconds || 0, 0);
      scheduleEndMs = endTime.getTime();
    } else {
      // No schedule info → menu always available, nothing to watch
      return;
    }

    const checkMenuExpiry = () => {
      if (Date.now() >= (scheduleEndMs as number)) {
        setShowMenuExpiredModal(true);
      }
    };

    // Check immediately
    checkMenuExpiry();

    // Then check every 10 seconds
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

  // Scroll spy
  useEffect(() => {
    if (!filteredCategories || filteredCategories.length === 0) return;

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting && !isUserScrolling) {
          setActiveCategory(entry.target.id);
        }
      });
    }, {
      root: null,
      rootMargin: '-200px 0px -60% 0px',
      threshold: 0,
    });

    filteredCategories.forEach((cat) => {
      const el = document.getElementById(cat.id);
      if (el) observer.observe(el);
    });

    return () => observer.disconnect();
  }, [filteredCategories, isUserScrolling]);

  async function callStaff() {
    if (staffCalled || staffCalling) return;
    setStaffCalling(true);
    try {
      await apiFetch(
        `/api/v1/customer/tables/${qrToken}/call-staff`,
        { method: "POST" },
      );
      setStaffCalled(true);
      toast(t('staffComing'), { icon: <BellRing className="h-4 w-4" aria-hidden />, description: t('tableZone', { code: table.code, zone: table.zone }) });
    } catch (err) {
      console.error('[MenuView] Staff call failed:', err);
      toast.error(t('callStaffError'), { description: t('callStaffRetry') });
    } finally {
      setStaffCalling(false);
    }
  }

  const handleCategorySelect = useCallback((id: string) => {
    setIsUserScrolling(true);
    if (scrollTimeoutRef.current) clearTimeout(scrollTimeoutRef.current);
    scrollTimeoutRef.current = setTimeout(() => setIsUserScrolling(false), 1000);

    setActiveCategory(id);
    const el = document.getElementById(id);
    if (el) {
      el.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }, []);

  const handleItemClick = useCallback((item: MenuItem) => {
    setSelectedItem(item);
  }, []);

  const handleAddToCart = useCallback(async (item: MenuItem) => {
    // If item has options or toppings, open modal
    if ((item.options && item.options.length > 0) || (item.toppingGroups && item.toppingGroups.length > 0)) {
      setSelectedItem(item);
      return;
    }
    const id = generateCartItemId(item.id, {});
    const existing = items.find((i) => i.id === id);
    if (existing) {
      updateQuantity(id, existing.quantity + 1);
    } else {
      await addToCart({
        id,
        product: item,
        selections: {},
        quantity: 1,
        // plan-019 — use the Happy Hour discounted price when active so the
        // cart total matches what the backend snapshots at order time.
        unitPrice: effectiveUnitPrice(item),
      });
    }
  }, [items, addToCart, updateQuantity]);

  const isLoadingMenu = categories === null && !menuError;

  if (isLoadingMenu) {
    return (
      <div className="flex flex-col items-center justify-center min-h-screen gap-3">
        <Loader2 className="size-8 animate-spin text-primary" />
        <p className="text-sm text-muted-foreground">{t('loadingMenu')}</p>
      </div>
    );
  }

  // #1750 — the load FAILURE and the genuinely empty menu used to share one
  // screen, so a customer whose fetch merely timed out was told the shop had
  // set up no menu, and given nothing to press. Split, and the failure now
  // offers the retry that fixes it. No "choose another store" on either: this
  // customer is sitting at a table in this one.
  if (menuError) {
    // The shop is up and answered — it just has no dine-in menu open, or the
    // menu's schedule window is shut. Neither is a fault and neither is fixed
    // by retrying, so say what is true and point at the one thing that works
    // from a table: ask a member of staff.
    if (menuError.kind !== "technical") {
      return (
        <MenuStateCard
          icon={menuError.kind === "outside-hours" ? Clock : UtensilsCrossed}
          tone={menuError.kind === "outside-hours" ? "amber" : "neutral"}
          titleId="dine-in-menu-unavailable-title"
          title={
            menuError.kind === "outside-hours"
              ? tMenu('outsideHoursTitle')
              : tMenu('unavailableDineInTitle')
          }
          description={tMenu('unavailableDineInDescription')}
        />
      );
    }

    return (
      <MenuStateCard
        icon={CloudOff}
        tone="danger"
        titleId="dine-in-menu-load-error-title"
        title={tMenu('loadErrorTitle')}
        description={tMenu('loadErrorDescription')}
        actions={
          <Button
            className="w-full sm:w-auto"
            onClick={() => {
              setMenuError(null);
              setCategories(null);
              setRefreshKey((k) => k + 1);
            }}
          >
            <RefreshCw className="size-4" aria-hidden="true" />
            {tCommon('retry')}
          </Button>
        }
      />
    );
  }

  if (!categories || categories.length === 0) {
    return (
      <MenuStateCard
        icon={UtensilsCrossed}
        tone="neutral"
        titleId="dine-in-menu-empty-title"
        title={t('noMenu')}
        description={t('noMenuDesc')}
      />
    );
  }

  return (
    <>
      <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
        {/* plan-034 — POS soft-lock banner. Pops in via the
            useTableSessionRealtime hook when staff calls /start-edit and
            disappears when /end-edit fires (or 60s passes). While
            visible, "+" buttons stop calling /items so we don't race
            staff's edit. */}
        {editingByStaff && (
          <div className="bg-amber-50 border-b border-amber-200 px-4 py-2 text-center text-sm font-medium text-amber-900 shrink-0">
            {t('staffEditingBanner')}
          </div>
        )}
        {/* Table context bar - hidden, info moved to confirmation dialog */}
      <div className="hidden border-b bg-[#FAFAFA] py-3 shrink-0 md:bg-primary/5 md:py-2">
        <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 md:px-6">
          {/* Mobile: Card style with icon */}
          <div className="flex flex-1 items-center gap-3 rounded-xl border border-border/40 bg-card p-3 md:border-0 md:bg-transparent md:p-0">
            {/* Icon - mobile only */}
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 md:hidden">
              <svg className="h-5 w-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
              </svg>
            </div>

            {/* Content */}
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2">
                <span className="text-sm font-bold text-foreground md:text-sm">
                  {t('menuTable', { title: hasExistingOrder ? t('orderMore') : t('menu'), code: table.code })}
                </span>
              </div>
              <div className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                <MapPin className="h-3 w-3 shrink-0" />
                <span>{table.zone}</span>
                <span>·</span>
                <Users className="h-3 w-3 shrink-0" />
                <span>{t('seats', { count: table.seats })}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Shop Info Banner */}
      <ShopInfoBanner branch={currentBranch} />

      {/* #1167 — dine-in is advisory only: the customer is AT the table, so a
          party still seated past closing keeps ordering. Take-away is blocked
          (see menu-page.tsx + useOrderingBlocked). */}
      <ShopClosedNotice />

      {/* Featured Items */}
      {featuredItems.length > 0 && (
        <FeaturedItemsCarousel
          items={featuredItems}
          onAddToCart={handleAddToCart}
          onItemClick={setSelectedItem}
        />
      )}

      <div id="sticky-sentinel" className="h-px" />

      {/* Sticky menu header — chui 1px xuống dưới global Header (h-12=48px / h-14=56px)
          để tránh khe hở sub-pixel + giật khi cuộn trên mobile (Header z-50 che 1px này).
          Bỏ `shadow-sm` always-on — shadow giờ do CategoryTabs bên trong xử lý
          (conditional theo isSticky qua IntersectionObserver). */}
      <div className="sticky top-[47px] md:top-[55px] z-40 bg-[#FAFAFA]">
        <div className="bg-[#FAFAFA]">
          <div className="mx-auto w-full max-w-7xl px-4 py-2.5 md:px-6 md:py-4">
            <div className="flex flex-col gap-1">
              {/* Row 1: Menu name + Search icon (aligned) */}
              <div className="flex items-center justify-between gap-3">
                <h1 className="truncate text-base font-semibold text-neutral-900 md:text-xl flex-1 min-w-0">
                  {menuMetadata?.menuName || tMenu('title')}
                </h1>

                {/* Mobile search icon - aligned with menu name */}
                <button
                  className="shrink-0 p-2 -mr-2 md:hidden"
                  onClick={() => setShowSearchPage(true)}
                  aria-label={tMenu('searchPlaceholder')}
                >
                  <Search className="h-5 w-5 text-neutral-600" />
                </button>

                {/* Desktop search */}
                <div className="relative hidden w-48 lg:w-64 md:block">
                  <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                  <Input
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    placeholder={tMenu('searchPlaceholder')}
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
                    <circle cx="10" cy="10" r="8" stroke="currentColor" strokeWidth="1.5"/>
                    <path d="M10 6V10H13" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
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
          categories={searchQuery ? filteredCategories : categories}
          activeId={activeCategory}
          onSelect={handleCategorySelect}
          onMenuClick={() => setShowCategorySheet(true)}
        />
      </div>

      {/* Menu items */}
      <main className="flex-1 bg-[#FAFAFA] pb-40 md:pb-28">
        <div className="mx-auto w-full max-w-7xl px-4 py-4 md:px-6 md:py-6">
          {searchQuery && filteredCategories.length === 0 ? (
            <div className="flex flex-col items-center justify-center py-12 text-center">
              <Search className="mb-3 size-12 text-muted-foreground/40" />
              <p className="text-sm font-medium text-muted-foreground">
                {tMenu('noResults')}
              </p>
              <p className="mt-1 text-xs text-muted-foreground/70">
                {tMenu('tryDifferentKeyword')}
              </p>
            </div>
          ) : (
            <div className="space-y-6 md:space-y-8">
              {filteredCategories.map((category) => (
                <section
                  key={category.id}
                  id={category.id}
                  className="space-y-3 md:space-y-4 scroll-mt-[168px] md:scroll-mt-48"
                  role="tabpanel"
                  aria-labelledby={`tab-${category.id}`}
                >
                  {/* #1185 — a Floating Section is a promo window, not a menu
                      section: mark it so the two are told apart at a glance. */}
                  <h2 className="flex items-center gap-2 text-lg font-bold md:text-xl">
                    {category.name}
                    {category.is_floating_section && <PromoSectionChip />}
                  </h2>
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
          )}
        </div>
      </main>

      {/* Bottom action bar — z-50 so it stays above the sticky menu header (z-40) */}
      <div className={cn(
        "fixed bottom-0 left-0 right-0 z-50 border-t bg-white px-4 py-3 shadow-[0_-2px_10px_rgba(0,0,0,0.05)] md:px-6",
        // Desktop: chỉ ẩn khi không có nội dung để show (chưa thêm món + chưa
        // có đơn cũ). Khi có món mới (totalItems > 0) → bar show "Xác nhận đơn".
        // Khi có đơn cũ (onBack/onPay defined) → bar show "Lịch sử" + "Thanh toán".
        // Mobile vẫn show tất cả trường hợp như behavior cũ.
        totalItems === 0 && !onBack && !onPay && "md:hidden",
      )}>
        <div className="mx-auto max-w-7xl">
          {totalItems > 0 ? (
            <div className="flex items-center justify-between gap-3">
              <span className="flex items-center gap-2 text-sm md:text-base">
                <ShoppingBag className="size-4 shrink-0 text-muted-foreground" />
                <span className="text-muted-foreground md:text-[18px]">{t('items', { count: totalItems })}</span>
                <span className="text-muted-foreground">·</span>
                <span className="text-lg font-extrabold text-primary tabular-nums md:text-[18px] md:text-[#006A34]">{fmt(totalPrice)}</span>
              </span>
              <button onClick={() => router.push(`/dine-in/${currentShop}/table/${qrToken}/confirm`)}
                className="shrink-0 h-10 rounded-xl bg-primary text-primary-foreground text-sm font-semibold flex items-center gap-2 px-5 hover:bg-primary/90 transition-all md:text-[18px]">
                {t('confirmOrder')}
              </button>
            </div>
          ) : (
            <div className="flex items-center gap-2 md:justify-end">
              {onBack && (
                <button onClick={onBack}
                  className="flex flex-1 md:flex-none items-center justify-center gap-2 h-12 px-4 rounded-xl bg-white border border-neutral-300 text-neutral-700 text-sm font-medium hover:bg-neutral-50 active:scale-[0.98] transition-all whitespace-nowrap md:text-base">
                  <svg className="size-5 shrink-0" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <g clipPath="url(#clip0_1_3937)">
                      <path d="M5.636 18.3639C7.10845 19.8364 9.04594 20.7527 11.1183 20.9567C13.1906 21.1607 15.2696 20.6398 17.0009 19.4827C18.7322 18.3256 20.0087 16.604 20.6129 14.6112C21.217 12.6184 21.1115 10.4778 20.3142 8.55408C19.5169 6.63039 18.0772 5.0427 16.2404 4.06158C14.4037 3.08045 12.2835 2.7666 10.2413 3.17352C8.19909 3.58043 6.36116 4.68293 5.04073 6.29312C3.72031 7.90332 2.99909 9.92157 3 12.0039V13.9999" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
                      <path d="M1 12L3 14L5 12M11 8V13H16" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round"/>
                    </g>
                    <defs>
                      <clipPath id="clip0_1_3937">
                        <rect width="24" height="24" fill="white"/>
                      </clipPath>
                    </defs>
                  </svg>
                  <span className="hidden xs:inline sm:hidden md:inline">{t('orderHistoryTitle', { code: table.code })}</span>
                  <span className="xs:hidden sm:inline md:hidden">{t('history')}</span>
                </button>
              )}

              {onPay && (
                <button onClick={onPay}
                  className="flex flex-1 md:flex-none items-center justify-center gap-2 h-12 px-4 rounded-xl bg-[#27A14F] text-white text-sm font-semibold hover:bg-[#27A14F]/90 active:scale-[0.98] transition-all whitespace-nowrap md:text-[16px]">
                  <svg className="size-5 shrink-0" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M1.5 7.125C1.5 6.22989 1.85558 5.37145 2.48851 4.73851C3.12145 4.10558 3.97989 3.75 4.875 3.75H19.125C20.0201 3.75 20.8785 4.10558 21.5115 4.73851C22.1444 5.37145 22.5 6.22989 22.5 7.125V16.875C22.5 17.7701 22.1444 18.6285 21.5115 19.2615C20.8785 19.8944 20.0201 20.25 19.125 20.25H4.875C3.97989 20.25 3.12145 19.8944 2.48851 19.2615C1.85558 18.6285 1.5 17.7701 1.5 16.875V7.125ZM4.875 5.25C4.37772 5.25 3.90081 5.44754 3.54917 5.79917C3.19754 6.15081 3 6.62772 3 7.125V8.25H21V7.125C21 6.62772 20.8025 6.15081 20.4508 5.79917C20.0992 5.44754 19.6223 5.25 19.125 5.25H4.875ZM3 16.875C3 17.3723 3.19754 17.8492 3.54917 18.2008C3.90081 18.5525 4.37772 18.75 4.875 18.75H19.125C19.6223 18.75 20.0992 18.5525 20.4508 18.2008C20.8025 17.8492 21 17.3723 21 16.875V9.75H3V16.875ZM15.75 14.25H18C18.1989 14.25 18.3897 14.329 18.5303 14.4697C18.671 14.6103 18.75 14.8011 18.75 15C18.75 15.1989 18.671 15.3897 18.5303 15.5303C18.3897 15.671 18.1989 15.75 18 15.75H15.75C15.5511 15.75 15.3603 15.671 15.2197 15.5303C15.079 15.3897 15 15.1989 15 15C15 14.8011 15.079 14.6103 15.2197 14.4697C15.3603 14.329 15.5511 14.25 15.75 14.25Z" fill="currentColor"/>
                  </svg>
                  <span>{t('pay')}</span>
                </button>
              )}


            </div>
          )}
        </div>
      </div>

      {selectedItem && (
        <ProductModal
          item={selectedItem}
          open={!!selectedItem}
          onOpenChange={(o) => {
            if (!o) {
              setSelectedItem(null);
              setEditingCartItem(null);
            }
          }}
          mode={editingCartItem ? "edit" : "add"}
          initialQuantity={editingCartItem?.quantity}
          initialSelections={editingCartItem?.selections}
          initialToppingQuantities={editingCartItem?.toppingQuantities}
          initialToppingItemVariants={editingCartItem?.toppingItemVariants}
          initialNote={editingCartItem?.note}
          cartItemIdToReplace={editingCartItem?.id}
        />
      )}

      <MenuExpiredModal
        open={showMenuExpiredModal}
        onOpenChange={setShowMenuExpiredModal}
        menuName={menuMetadata?.menuName}
        cartDeadlineIso={menuMetadata?.cartDeadlineIso}
        cartItemsExpired={cartItemsExpired}
        graceWindowMinutes={menuMetadata?.cartTimeoutMinutes}
        onViewNextMenu={() => {
          setCategories(null);
          setFeaturedItems([]);
          setMenuError(null);
          setMenuMetadata(null);
          setShowMenuExpiredModal(false);
          setRefreshKey((k) => k + 1);
        }}
      />

      {/* Floating Call Staff Button - Sticky right side (tạm ẩn) */}
      {SHOW_CALL_STAFF_BUTTON && (
      <button
        disabled={staffCalled || staffCalling}
        onClick={callStaff}
            className={`fixed right-3 bottom-20 md:right-4 md:bottom-24 z-40 flex h-[60px] w-[60px] md:h-[81px] md:w-[81px] flex-col items-center justify-center gap-1 md:gap-1.5 rounded-full shadow-xl transition-all ${staffCalled
          ? "bg-[#FEF3C7] cursor-not-allowed"
          : staffCalling
            ? "bg-[#FEF3C7] cursor-not-allowed opacity-80"
            : "bg-[#FEF3C7] hover:shadow-2xl active:scale-95"
          }`}
        title={staffCalled ? t('staffCalled') : t('callStaff')}
      >
        {staffCalled ? (
          <>
            <CheckCircle2 className="h-5 w-5 md:h-7 md:w-7 text-green-600" />
            <span className="text-[8px] md:text-[10px] font-bold text-green-600 text-center leading-tight">
              {t('staffCalled')}
            </span>
          </>
        ) : staffCalling ? (
          <>
            <span className="h-4 w-4 md:h-6 md:w-6 rounded-full border-3 border-amber-600 border-t-transparent animate-spin" />
            <span className="text-[8px] md:text-[10px] font-bold text-amber-600 text-center leading-tight">
              {t('staffCalling')}
            </span>
          </>
        ) : (
          <>
            <svg className="h-6 w-6 md:h-9 md:w-9" viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
              <g clipPath="url(#clip0_469_1404)">
                <path d="M31.1261 32.5132L30.9271 32.6602L18.1162 32.6784L5.30594 32.6967L4.99094 32.5378C4.57961 32.3304 4.33563 31.8895 4.38274 31.4395C4.4186 31.0901 4.58454 30.8327 4.92133 30.6035L5.15407 30.4453H30.8856L31.1247 30.593C31.6155 30.896 31.7955 31.6737 31.4826 32.1335C31.3954 32.2615 31.2351 32.4323 31.1261 32.5132ZM26.0214 27.1406L25.9806 28.3711L18.0648 28.3887C13.7111 28.3985 10.128 28.3859 10.102 28.3598C10.0759 28.3338 10.0548 28.0491 10.0548 27.7263V27.1406H26.0214Z" fill="#FEE086"/>
                <path d="M4.67587 34.6065C3.55228 34.2915 2.71205 33.4534 2.4322 32.3692C2.0033 30.7084 2.87939 29.1102 4.54509 28.5132C4.81439 28.4169 5.21025 28.3832 6.41611 28.3543L7.94541 28.3178V27.7349V27.152L6.61439 27.1288C5.15751 27.1035 5.09634 27.0866 4.75041 26.6204C4.61541 26.4376 4.60627 26.3378 4.61681 25.1052C4.62384 24.2776 4.67095 23.5126 4.74337 23.0549C5.20533 20.139 6.39923 17.7428 8.43056 15.658C10.4619 13.5732 13.0114 12.245 15.8907 11.7725C16.2198 11.7184 16.591 11.6741 16.7169 11.6734L16.9454 11.672V10.9689V10.2657H16.1192C15.4731 10.2657 15.2396 10.239 15.0484 10.1427C14.3185 9.77566 14.2925 8.67809 15.0062 8.33988C15.2122 8.24215 15.6144 8.22668 18.0001 8.22668C20.3858 8.22668 20.788 8.24215 20.994 8.33988C21.7077 8.67809 21.6817 9.77566 20.9518 10.1427C20.7606 10.239 20.5271 10.2657 19.881 10.2657H19.0548V10.9689V11.672H19.2882C19.907 11.672 22.0142 12.1396 22.3454 12.3505C22.7652 12.6177 22.9135 13.318 22.6435 13.7603C22.3665 14.2145 21.8581 14.3108 21.0313 14.0654C19.047 13.4762 16.5355 13.5416 14.4493 14.2356C11.2192 15.3107 8.66541 17.7428 7.40611 20.9427C6.98634 22.0086 6.729 23.2672 6.65869 24.5919L6.63548 25.0314H17.9987H29.3619L29.3408 24.5568C29.2241 21.9482 28.2432 19.4774 26.5831 17.6099C25.8357 16.7682 25.7225 16.5854 25.7225 16.2156C25.7225 15.8176 25.8906 15.5251 26.2302 15.3346C26.7688 15.0322 27.1576 15.1792 27.8867 15.9611C29.3317 17.5107 30.4398 19.5533 30.9699 21.6465C31.2744 22.8453 31.3707 23.6462 31.3827 25.0778C31.3939 26.3385 31.3855 26.4376 31.2498 26.6204C30.9031 27.0866 30.8427 27.1035 29.3858 27.1288L28.0548 27.152V27.7349V28.3178L29.5841 28.3543C30.7899 28.3832 31.1858 28.4169 31.4551 28.5132C31.9979 28.708 32.3966 28.9583 32.7777 29.3464C34.4856 31.0811 33.6439 34.0271 31.2702 34.6227C30.8687 34.7232 29.5081 34.7338 17.9607 34.7296C6.12923 34.7253 5.064 34.7155 4.67587 34.6065ZM31.126 32.5133C31.235 32.4325 31.3953 32.2616 31.4825 32.1336C31.7954 31.6738 31.6154 30.8961 31.1246 30.5931L30.8856 30.4454H18.0198H5.154L4.92126 30.6036C4.58447 30.8328 4.41853 31.0902 4.38267 31.4396C4.33556 31.8896 4.57955 32.3305 4.99087 32.5379L5.30587 32.6968L18.1161 32.6786L30.927 32.6603L31.126 32.5133ZM26.001 27.756L26.0213 27.1407H18.0381H10.0548V27.7264C10.0548 28.0492 10.0759 28.3339 10.1019 28.36C10.1279 28.386 13.711 28.3986 18.0648 28.3888L25.9806 28.3712L26.001 27.756ZM0.578061 19.1511C-0.122954 18.7982 -0.174282 17.8271 0.485249 17.3863C0.712358 17.2344 0.788999 17.2267 2.03212 17.2267C3.20494 17.2274 3.36314 17.2414 3.56494 17.3645C4.0065 17.6338 4.17665 18.3735 3.88697 18.7616C3.55298 19.2088 3.47775 19.2292 2.08697 19.2489C1.014 19.2643 0.771421 19.2489 0.578061 19.1511ZM32.5864 19.1666C31.8446 18.8439 31.7553 17.7793 32.4352 17.3645C32.637 17.2414 32.7952 17.2274 33.9681 17.2267C35.2112 17.2267 35.2878 17.2344 35.5149 17.3863C36.1794 17.83 36.1217 18.8143 35.4109 19.1518C35.0945 19.3016 32.9225 19.3136 32.5864 19.1666ZM7.83853 9.34816C7.70705 9.30949 7.27673 8.93191 6.70228 8.35184C5.95275 7.59457 5.76923 7.36887 5.72986 7.15934C5.59134 6.41543 6.31416 5.75449 7.01869 5.9823C7.29923 6.07301 8.86087 7.54465 9.04087 7.88918C9.18361 8.16058 9.16533 8.64223 9.0029 8.90871C8.78072 9.27293 8.25619 9.47121 7.83853 9.34816ZM27.5443 9.32144C27.3988 9.26941 27.2307 9.18644 27.1702 9.13652C26.8707 8.88762 26.7638 8.26113 26.9593 7.88918C27.1393 7.54465 28.701 6.07301 28.9815 5.9823C29.686 5.75449 30.4088 6.41543 30.2703 7.15934C30.231 7.36887 30.0474 7.59457 29.2993 8.35043C28.6278 9.02894 28.3086 9.30176 28.1279 9.35238C27.8284 9.43535 27.8692 9.43746 27.5443 9.32144ZM17.4587 5.13574C17.0291 4.87418 16.9806 4.68363 16.9806 3.26965C16.9806 1.83738 17.0235 1.68691 17.5114 1.4127C17.844 1.22637 18.1562 1.22637 18.4888 1.4127C18.9767 1.68691 19.0196 1.83738 19.0196 3.26965C19.0196 4.35527 19.0013 4.56832 18.8931 4.7666C18.7201 5.08371 18.3861 5.27285 18.0001 5.27285C17.8102 5.27285 17.5937 5.21801 17.4587 5.13574Z" fill="#DE8C00"/>
              </g>
              <defs>
                <clipPath id="clip0_469_1404">
                  <rect width="36" height="36" fill="white"/>
                </clipPath>
              </defs>
            </svg>
            <span className="text-center leading-tight px-1 text-[8px] md:text-[10px]" style={{ color: '#7F5000', fontWeight: 600 }}>
              {t('callStaff')}
            </span>
          </>
        )}
      </button>
      )}

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

      {/* Category Navigation Sheet (Mobile only) */}
      <Sheet open={showCategorySheet} onOpenChange={setShowCategorySheet}>
        <SheetContent side="bottom" className="rounded-t-3xl p-0" showCloseButton={false} style={{ height: '80vh', maxHeight: '80vh' }}>
          <div className="flex h-full flex-col">
            {/* Header */}
            <SheetHeader className="border-b px-6 py-4">
              <SheetTitle className="text-center text-lg font-semibold">
                {menuMetadata?.menuName || tMenu('title')}
              </SheetTitle>
              {menuMetadata?.scheduleStartTime && menuMetadata?.scheduleEndTime && (
                <p className="text-center text-sm text-neutral-600">
                  {menuMetadata.scheduleStartTime.slice(0, 5)} - {menuMetadata.scheduleEndTime.slice(0, 5)}
                </p>
              )}
            </SheetHeader>

            {/* Category List */}
            <div className="flex-1 overflow-y-auto px-6 py-4">
              <div className="space-y-2">
                {categories?.map((category) => {
                  const isActive = activeCategory === category.id;
                  return (
                    <button
                      key={category.id}
                      onClick={() => {
                        handleCategorySelect(category.id);
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
              </div>
            </div>

            {/* Footer Button */}
            <div className="border-t px-6 py-4">
              <Button
                onClick={() => setShowCategorySheet(false)}
                className="w-full h-12 text-base font-semibold"
                style={{ backgroundColor: '#171717' }}
              >
                {t('skip')}
              </Button>
            </div>
          </div>
        </SheetContent>
      </Sheet>
      </div>
    </>
  );
}
