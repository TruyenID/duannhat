import { useEffect, useMemo, useRef, useState } from "react";
import {
  AlertCircleIcon,
  BookOpenIcon,
  CalendarIcon,
  ClockIcon,
  LayersIcon,
  PackageIcon,
  PlusIcon,
  SearchIcon,
} from "lucide-react";
import {
  Button,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";
import { Spinner } from "@/components/ui/spinner";
import { cn } from "@/lib/utils";
import { HelpButton } from "@/help/help-button";
import { useTranslation } from "@/providers/app-provider";
import {
  useShopMenuProducts,
  useShopMenuSections,
  useShopMenusByDay,
} from "@/hooks/api/use-shop-menus";
import { useFloatingSections } from "@/hooks/api/use-floating-sections";
import { keepPreviousData, useQueries } from "@tanstack/react-query";
import { shopMenuKeys } from "@/hooks/api/query-keys";
import { shopMenuService } from "@/services/shop-menu-service";
import { useLocale } from "@/providers/app-provider";
import type {
  CustomerOrderType,
  ShopMenuByDayResource,
  ShopMenuProduct,
  ShopMenuProductSku,
  ShopMenuSection,
} from "../types";
import { menuDisplayPrice, productRate } from "../lib/tax-display";
import { effectiveSkuPrice } from "../lib/menu-price";
import { shouldUseProductOptionsDialog } from "../lib/product-selection-surface";
import { formatCurrency } from "../lib/totals";
import {
  isAutoSelectableMenu,
  orderTypeToServiceType,
} from "../lib/menu-service-type";
import { pickActiveMenu } from "../lib/pick-active-menu";
import { MenuServiceTypeBadge } from "./menu-service-type-badge";
import { ProductImageCarousel } from "./product-image-carousel";
import { ProductOptionsDialog } from "./product-options-dialog";
import { PromotionBadge, StrikethroughPrice } from "./promotion-badge";
import type { ToppingSelection } from "../types";

/**
 * Trim trailing seconds and any leading zero on the hour from a PHP TIME
 * string ("HH:MM:SS"). The backend returns seconds for completeness, but
 * staff-facing labels read better without them ("11:00 – 14:30" beats
 * "11:00:00 – 14:30:00").
 */
function formatScheduleTime(t: string | null | undefined): string {
  if (!t) return "";
  const m = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(t);
  if (!m) return t;
  return `${m[1].padStart(2, "0")}:${m[2]}`;
}

/** Carbon-style 0=Sun … 6=Sat day index. Captured at mount in MenuCatalog. */
function todayDayOfWeek(): number {
  return new Date().getDay();
}

export interface MenuCatalogProps {
  shopSlug: string;
  /** Selected menu — null = no menu picked yet, grid stays locked. */
  menuId: string | null;
  onSelectMenuId: (menuId: string) => void;
  /**
   * Disable add-to-cart clicks (e.g. when there's no active tab, or the
   * active order is past the open status).
   */
  disabled?: boolean;
  /**
   * #481 — active order's service type. Gates the menu list so a dine_in
   * order only shows DineIn (+ Both / master-inherited) menus and takeaway
   * shows Takeaway. `spot` / no order shows every menu — but for `spot` the
   * AUTO-pick is still held to DineIn (#1765), so reaching a Takeaway menu on
   * a counter sale takes a deliberate choice from the dropdown.
   */
  orderType?: CustomerOrderType | null;
  /**
   * Push one concrete SKU of a product into the cart. The caller resolves
   * unit_price from the server-resolved effective SKU price and display name from
   * `product.name` + `sku.product_sku?.name` for the receipt.
   */
  onAddItem: (
    product: ShopMenuProduct,
    sku: ShopMenuProductSku,
    toppings?: ToppingSelection[],
    note?: string,
  ) => Promise<void> | void;
  /**
   * plan-043 — 総額表示 mode from the shop's order settings. Drives BOTH the
   * displayed price (excluded → net; included → net + tax) and the tax-status
   * label ("税込 / Đã gồm thuế" vs "税抜 / Chưa gồm thuế"). Defaults to excluded.
   */
  pricesIncludeTax?: boolean;
  /** Currency for rounding the tax-added (included-mode) price to its minor unit. */
  currencyCode?: string | null;
  className?: string;
}

/** Sentinel section key for products with no menu_section_id. */
const UNSECTIONED = "__unsectioned__";

/** #1320 — prefix for the spotlight pseudo-sections, so their group ids cannot
 *  collide with a real menu_section_id. */
const SPOTLIGHT_PREFIX = "__spotlight__:";

/**
 * Shop-side menu catalog. Search row (left) + active-menu chip (right) sit
 * at the top, then a horizontal section-pill row (acts as a scrollspy +
 * jump-to-section nav), then a single scrollable column that lists every
 * section with its grid of product cards.
 */
export function MenuCatalog({
  shopSlug,
  menuId,
  onSelectMenuId,
  disabled,
  orderType,
  onAddItem,
  pricesIncludeTax = false,
  currencyCode,
  className,
}: MenuCatalogProps) {
  const { t } = useTranslation();
  // #3163 — khoá truy vấn theo section phải mang `locale`: backend bản địa hoá
  // tên món theo `Accept-Language`, nên cùng một section trả về tên khác nhau
  // theo ngôn ngữ. Thiếu nó thì đổi ngôn ngữ vẫn hiện tên cũ tới khi tải lại
  // cứng — đúng lý do `shopMenuKeys` đã mang `locale` từ đầu.
  const { locale } = useLocale();
  const [search, setSearch] = useState("");
  const [committedSearch, setCommittedSearch] = useState("");
  const [activeSectionId, setActiveSectionId] = useState<string | null>(null);

  const [dayOfWeek] = useState<number>(() => todayDayOfWeek());
  const serviceType = orderTypeToServiceType(orderType);
  const menusQuery = useShopMenusByDay(shopSlug, dayOfWeek, {
    per_page: 20,
    service_type: serviceType,
  });
  // #3163 — TÌM KIẾM giữ đường cũ: đi hết các trang của cả thực đơn, có trần.
  // Một truy vấn theo section không trả lời được "món này nằm ở đâu", mà đó
  // đúng là câu người ta gõ vào ô tìm kiếm.
  const searching = committedSearch.trim().length > 0;

  const productsQuery = useShopMenuProducts(
    shopSlug,
    menuId,
    {
      search: committedSearch || undefined,
      // Request size for the page-walk inside the hook, not a display cap —
      // 100 is the biggest page Cloud will serve. The grid below renders every
      // row the walk returns; a menu that outgrows one page must not lose its
      // trailing sections (デザート・飲み物 vanished off 本郷's dinner menu that
      // way, and nothing on screen hinted at it).
      per_page: 100,
    },
    // Ngoài lúc tìm kiếm thì TẮT HẲN. Để nó chạy nền là giữ nguyên chi phí cũ
    // (638 KB mỗi 60 giây mỗi tablet) trong khi màn hình vẫn đúng — tức không
    // ai phát hiện ra.
    { enabled: searching },
  );

  // Thanh pill lấy từ đây, không suy ra từ món đã tải: nó rẻ và LUÔN ĐỦ, nên
  // section đúng ngay cả khi phần lớn món chưa về. Đó là thứ #3159 phải chữa
  // bằng cách kéo về 100% số dòng.
  const sectionsQuery = useShopMenuSections(shopSlug, menuId);
  const sectionRows = useMemo(
    () => sectionsQuery.data?.data ?? [],
    [sectionsQuery.data],
  );

  // Khoá của một section trong lưới. `null` (chưa xếp) dùng chung sentinel với
  // nhóm cũ để phần render bên dưới không phải biết hai từ vựng.
  const sectionKeys = useMemo(
    () => sectionRows.map((s) => s.id ?? UNSECTIONED),
    [sectionRows],
  );

  // Section nào ĐÃ được yêu cầu tải. Bắt đầu bằng hai section đầu — đủ lấp màn
  // hình đầu tiên — rồi lớn dần theo lượt cuộn.
  const [loadedKeys, setLoadedKeys] = useState<string[]>([]);
  const sectionsKey = sectionKeys.join("|");
  const [lastSectionsKey, setLastSectionsKey] = useState<string>("");
  if (sectionsKey !== lastSectionsKey) {
    setLastSectionsKey(sectionsKey);
    setLoadedKeys(sectionKeys.slice(0, 2));
  }

  // Mỗi section một truy vấn. `combine` chứ không phải `useMemo` trên
  // `results`: TanStack v5 chỉ bảo đảm tham chiếu ổn định cho kết quả của
  // `combine`, còn `results` là mảng mới mỗi lần render — quy tắc của repo.
  const { itemsByKey } = useQueries({
    queries: loadedKeys.map((key) => ({
      queryKey: shopMenuKeys.products(shopSlug, menuId ?? "", locale, {
        section_id: key === UNSECTIONED ? "none" : key,
      }),
      queryFn: () =>
        shopMenuService.listAllProducts(shopSlug, menuId as string, {
          section_id: key === UNSECTIONED ? "none" : key,
          per_page: 100,
        }),
      enabled: !!shopSlug && !!menuId && !searching,
      // Giá và Happy Hour đổi theo phút — nhịp này giữ nguyên như đường cũ,
      // nhưng nay chỉ áp cho những section đang MỞ, không phải cả thực đơn.
      refetchInterval: 60_000,
      refetchIntervalInBackground: false,
      placeholderData: keepPreviousData,
    })),
    combine: (results) => {
      // Chỉ trả về thứ lưới THẬT SỰ đọc. `data === undefined` đã là tín hiệu
      // "chưa có món" mà `pending` bên dưới dùng, nên một tập `isLoading` riêng
      // chỉ là cùng một sự thật kể hai lần — và hai chỗ kể một sự thật thì sớm
      // muộn lệch nhau.
      const map = new Map<string, ShopMenuProduct[]>();
      results.forEach((r, i) => {
        if (r.data) {
          map.set(
            loadedKeys[i],
            r.data.data.filter((mp) => mp.is_active),
          );
        }
      });
      return { itemsByKey: map };
    },
  });

  function submitSearch() {
    setCommittedSearch(search.trim());
  }

  const menus = menusQuery.data?.data ?? [];
  const activeMenu = useMemo(
    () => menus.find((m) => m.id === menuId) ?? null,
    [menus, menuId],
  );

  // Auto-select by current time window. The parent persists `menuId` to
  // localStorage scoped per shop, so on a fresh page load `menuId` arrives
  // already set to the previously-picked value. Without the ref below, the
  // `menuIsValid` guard would always bail because a stored menu is almost
  // always still present in today's list — the time-of-day match would
  // never run. We force the time-match exactly once per mount (the first
  // tick where menus is non-empty), even if a stored menuId is "valid".
  // Subsequent runs only auto-pick when the current menu falls out of
  // today's list (e.g. the menu was archived), so the user's mid-session
  // manual override sticks.
  const hasInitialPickedRef = useRef(false);
  useEffect(() => {
    if (menus.length === 0) return;

    // #1765 — auto-pick may not land on a Takeaway menu for a `spot` order.
    const autoSelectable = (menu: ShopMenuByDayResource) =>
      isAutoSelectableMenu(orderType, menu);

    if (!hasInitialPickedRef.current) {
      hasInitialPickedRef.current = true;
      const picked = pickActiveMenu(menus, new Date(), autoSelectable);
      if (picked && picked.id !== menuId) onSelectMenuId(picked.id);
      return;
    }

    const menuIsValid = menuId !== null && menus.some((m) => m.id === menuId);
    if (menuIsValid) return;
    const picked = pickActiveMenu(menus, new Date(), autoSelectable);
    if (picked) onSelectMenuId(picked.id);
  }, [menuId, menus, onSelectMenuId, orderType]);

  const products = useMemo(
    () => (productsQuery.data?.data ?? []).filter((mp) => mp.is_active),
    [productsQuery.data],
  );

  // #1320 — the spotlight. Open sections come from the WORKSTATION, which
  // evaluates the schedule window on the shop clock; an empty list is the
  // ordinary answer on a Cloud-only POS and renders nothing.
  const floatingQuery = useFloatingSections(shopSlug);

  // Map each spotlight member onto the menu shape so the existing grid, price
  // display and options dialog render it unchanged. Two fields carry the
  // difference: `selling_price` is the PROMO price, and every SKU is stamped
  // with `floating_section_product_id` — that stamp is what stops the add-item
  // path from sending a spotlight id in the `menu_product_sku_id` slot, which
  // names a row in a different table entirely.
  const spotlightGroups = useMemo(() => {
    return (floatingQuery.data ?? []).map((section) => ({
      id: SPOTLIGHT_PREFIX + section.id,
      name: section.name,
      items: section.products.map((fp): ShopMenuProduct => ({
        // The membership id stands in for menu_product_id: it is the row that
        // says "this product, from this surface".
        id: fp.floating_section_product_id,
        menu_id: "",
        product_id: fp.product_id,
        menu_section_id: null,
        is_active: true,
        display_order: fp.display_order,
        product: {
          id: fp.product_id,
          name: fp.name,
          image_url: fp.image_url,
        } as ShopMenuProduct["product"],
        skus: fp.skus.map((sk) => ({
          id: sk.id,
          menu_product_id: fp.floating_section_product_id,
          floating_section_product_id: fp.floating_section_product_id,
          product_sku_id: sk.id,
          selling_price: sk.selling_price,
          is_price_overridden: true,
          is_active: true,
          product_sku: { id: sk.id, name: sk.name },
        })) as ShopMenuProduct["skus"],
      })),
    })).filter((g) => g.items.length > 0);
  }, [floatingQuery.data]);

  // The product collection is the only menu request on this screen. Every
  // row already carries its section, so fetching the eager-loaded menu detail
  // in parallel only made the workstation hydrate the same catalog twice.
  // Truy vấn QUYẾT ĐỊNH trạng thái màn hình. Ở chế độ section, thứ phải có
  // trước khi vẽ được gì là DANH SÁCH SECTION — món của từng khối về sau, và
  // mỗi khối tự hiện khung chờ của nó. Trỏ trạng thái vào đường đi-cả-thực-đơn
  // (nay đã tắt ngoài lúc tìm kiếm) sẽ làm lưới đứng ở "đang tải" vĩnh viễn.
  const catalogQuery = searching ? productsQuery : sectionsQuery;

  // TÌM KIẾM: nhóm suy ra từ kết quả trả về, y như trước — một truy vấn theo
  // section không trả lời được "món này nằm ở đâu".
  const searchSections: ShopMenuSection[] = useMemo(() => {
    const seen = new Map<string, ShopMenuSection>();
    for (const mp of products) {
      if (mp.menu_section_id && !seen.has(mp.menu_section_id)) {
        seen.set(mp.menu_section_id, {
          id: mp.menu_section_id,
          name: mp.section?.name ?? mp.menu_section_id,
        });
      }
    }
    return [...seen.values()];
  }, [products]);

  // Group products by section id (UNSECTIONED bucket for null). The
  // pill row + the rendered grid both iterate over `groupedSections`
  // so order matches between the spy nav and the scroll content.
  const groupedSections = useMemo(() => {
    const groups: {
      id: string;
      name: string;
      items: ShopMenuProduct[];
      /** #3163 — số món do BACKEND đếm; hiện được cả khi món chưa tải về. */
      expected: number;
      /** #3163 — section đã có mặt trong lưới nhưng chưa có món. */
      pending: boolean;
    }[] = [];

    if (searching) {
      for (const sec of searchSections) {
        const items = products.filter((mp) => mp.menu_section_id === sec.id);
        if (items.length === 0) continue;
        groups.push({
          id: sec.id,
          name: sec.name,
          items,
          expected: items.length,
          pending: false,
        });
      }
      const unsectioned = products.filter((mp) => !mp.menu_section_id);
      if (unsectioned.length > 0) {
        groups.push({
          id: UNSECTIONED,
          name: t("pos.menu.section_other"),
          items: unsectioned,
          expected: unsectioned.length,
          pending: false,
        });
      }
    } else {
      // Lưới dựng từ DANH SÁCH SECTION, không từ món đã tải. Nhờ vậy thanh pill
      // đủ ngay từ lượt vẽ đầu — kể cả section chưa ai cuộn tới — mà không phải
      // kéo về cả thực đơn để biết chúng tồn tại.
      for (const row of sectionRows) {
        const key = row.id ?? UNSECTIONED;
        const items = itemsByKey.get(key);
        groups.push({
          id: key,
          name: row.name ?? t("pos.menu.section_other"),
          items: items ?? [],
          expected: row.products_count,
          // `pending` khác `rỗng`: một section thật sự không còn món nào phải
          // hiện "0 món", còn một section CHƯA TẢI phải hiện khung chờ. Gộp hai
          // thứ đó là dựng lại đúng #3159 — món có thật mà màn hình nói không.
          pending: items === undefined,
        });
      }
    }

    // #1320 — spotlight FIRST. It is time-limited and priced below the menu, so
    // burying it under the ordinary sections is how a promotion goes unsold.
    return [
      ...spotlightGroups.map((g) => ({
        ...g,
        expected: g.items.length,
        pending: false,
      })),
      ...groups,
    ];
  }, [
    searching,
    searchSections,
    products,
    sectionRows,
    itemsByKey,
    t,
    spotlightGroups,
  ]);

  // Refs to each section heading inside the scroll area + the scroll
  // container itself. The IntersectionObserver uses the container as its
  // root so spy state reflects the catalog's own viewport rather than the
  // page's.
  const scrollContainerRef = useRef<HTMLDivElement>(null);
  const sectionNodesRef = useRef<Map<string, HTMLDivElement>>(new Map());
  // While the user clicks a pill we run a smooth scrollIntoView. The
  // observer would briefly flag intermediate sections as "active" during
  // that animation and override the user's pick — guard with a flag that
  // releases as soon as the targeted section is visible.
  const programmaticScrollRef = useRef<string | null>(null);

  // Default the active pill to the first group whenever the group set
  // changes (new menu, new search). React 19's "reset state during render"
  // pattern avoids the set-state-in-effect lint.
  const groupsKey = groupedSections.map((g) => g.id).join("|");
  const [lastGroupsKey, setLastGroupsKey] = useState<string>(groupsKey);
  if (groupsKey !== lastGroupsKey) {
    setLastGroupsKey(groupsKey);
    setActiveSectionId(groupedSections[0]?.id ?? null);
  }

  // Scrollspy — observer fires whenever a section heading crosses into
  // the top 35% of the scroll container. The first section to do so wins.
  useEffect(() => {
    const root = scrollContainerRef.current;
    if (!root) return;
    if (groupedSections.length === 0) return;

    const observer = new IntersectionObserver(
      (entries) => {
        // Pick the entry whose top is closest-to-but-still-above the
        // observation band's bottom edge — that's the section the user
        // is currently reading. Sort by top position; the smallest
        // non-negative delta is the winner.
        const candidates = entries
          .filter((e) => e.isIntersecting)
          .map((e) => ({
            id: e.target.getAttribute("data-section-id") ?? "",
            top: e.boundingClientRect.top,
          }))
          .filter((e) => !!e.id);
        if (candidates.length === 0) return;
        candidates.sort((a, b) => a.top - b.top);
        const winner = candidates[0].id;

        // If we're mid-programmatic-scroll, ignore observer hits that
        // don't match the destination — only release the lock once the
        // destination is the winning entry.
        const target = programmaticScrollRef.current;
        if (target && target !== winner) return;
        if (target && target === winner) {
          programmaticScrollRef.current = null;
        }
        setActiveSectionId(winner);
      },
      {
        root,
        // Top 35% is the "now reading" band: heading enters when scrolled
        // past the toolbar, leaves before the next heading takes over.
        rootMargin: "0px 0px -65% 0px",
        threshold: 0,
      },
    );

    for (const node of sectionNodesRef.current.values()) {
      observer.observe(node);
    }
    return () => observer.disconnect();
  }, [groupsKey, groupedSections.length]);

  // #3163 — observer thứ hai, và nó KHÔNG gộp được với observer scrollspy ở
  // trên: cái kia dùng dải hẹp "đang đọc" (`-65%`), còn cái này phải bắt sớm
  // hơn nhiều để món kịp về trước khi người ta cuộn tới. Gộp lại thì hoặc
  // scrollspy nhảy lung tung, hoặc lưới hiện khung chờ đúng lúc cần món.
  useEffect(() => {
    if (searching) return;
    const root = scrollContainerRef.current;
    if (!root) return;
    if (sectionKeys.length === 0) return;

    const observer = new IntersectionObserver(
      (entries) => {
        const arriving = entries
          .filter((e) => e.isIntersecting)
          .map((e) => e.target.getAttribute("data-section-id") ?? "")
          .filter(Boolean);
        if (arriving.length === 0) return;

        setLoadedKeys((prev) => {
          const next = arriving.filter((id) => !prev.includes(id));
          return next.length === 0 ? prev : [...prev, ...next];
        });
      },
      {
        root,
        // Một màn hình đệm mỗi phía. Đủ để lượt tải xong trước khi section vào
        // tầm mắt ở tốc độ cuộn thường, mà vẫn không kéo về cả thực đơn.
        rootMargin: "600px 0px 600px 0px",
        threshold: 0,
      },
    );

    for (const node of sectionNodesRef.current.values()) {
      observer.observe(node);
    }
    return () => observer.disconnect();
  }, [searching, sectionsKey, sectionKeys.length, groupsKey]);

  function scrollToSection(id: string) {
    // Bấm pill là YÊU CẦU TẢI ngay, không đợi observer: người dùng nhảy tới một
    // section ở xa thì cuộn mượt mất vài trăm ms, và trong lúc đó lượt tải nên
    // đã chạy rồi. Đợi observer nghĩa là tới nơi mới bắt đầu tải.
    setLoadedKeys((prev) => (prev.includes(id) ? prev : [...prev, id]));

    const node = sectionNodesRef.current.get(id);
    if (!node) return;
    programmaticScrollRef.current = id;
    setActiveSectionId(id);
    node.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function handleAddSingle(mp: ShopMenuProduct, sku: ShopMenuProductSku) {
    onAddItem(mp, sku);
  }

  return (
    <section
      data-slot="menu-catalog"
      className={cn("flex h-full min-h-0 flex-col overflow-hidden", className)}
    >
      {/* Search + active-menu chip ----------------------------------- */}
      <div className="shrink-0 border-b bg-card px-3 py-2 sm:px-6 sm:py-3">
        <div className="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:gap-3">
          {/* Search pill — focus-within ring lifts the whole container so
              the input + button read as a single control. flex-1 + min-w-0
              lets it claim every remaining pixel after the menu chip. */}
          <div
            className={cn(
              "flex h-11 min-w-0 flex-1 items-stretch overflow-hidden rounded-xl border bg-muted/40 transition-colors sm:h-12",
              "focus-within:border-primary focus-within:bg-card focus-within:ring-2 focus-within:ring-ring/40",
            )}
          >
            <Input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") submitSearch();
              }}
              placeholder={t("pos.menu.search_placeholder")}
              disabled={!menuId}
              className="h-full flex-1 rounded-none border-0 bg-transparent px-3 text-sm shadow-none focus-visible:ring-0 focus-visible:ring-offset-0 sm:px-4"
            />
            <button
              type="button"
              aria-label={t("pos.menu.search_button")}
              onClick={submitSearch}
              disabled={!menuId}
              className="flex w-12 shrink-0 cursor-pointer items-center justify-center border-l bg-muted text-muted-foreground transition-colors hover:bg-muted/70 hover:text-foreground disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-muted disabled:hover:text-muted-foreground sm:w-14"
            >
              <SearchIcon className="size-5" />
            </button>
          </div>

          <Select
            value={menuId ?? undefined}
            onValueChange={onSelectMenuId}
            disabled={menusQuery.isLoading || menus.length === 0}
          >
            {/* On mobile this stacks under the search bar full width; from
                sm+ it sits beside as a 220-300px sidekick. */}
            <SelectTrigger
              className="h-11 w-full shrink-0 rounded-xl border bg-card px-3 shadow-none sm:h-12 sm:w-[220px] lg:w-[260px] xl:w-[300px]"
              data-slot="menu-catalog-menu-select"
            >
              {activeMenu ? (
                <span className="flex w-full min-w-0 items-center gap-2 text-left">
                  <span className="flex size-7 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <CalendarIcon className="size-4" />
                  </span>
                  <span className="flex min-w-0 flex-1 flex-col leading-tight">
                    <span className="truncate text-sm font-medium text-foreground">
                      {activeMenu.name}
                    </span>
                    {/* The selected menu is auto-picked by time window (and
                        falls back to menus[0]), so the cashier may never have
                        chosen it — the trigger has to state which service type
                        they are ringing up against, not just the list. */}
                    <span className="flex min-w-0 items-center gap-1.5">
                      <MenuServiceTypeBadge menu={activeMenu} t={t} />
                      {typeof activeMenu.menu_products_count === "number" && (
                        <span className="truncate text-[10px] tabular-nums text-muted-foreground">
                          {activeMenu.menu_products_count}{" "}
                          {t("pos.menu.product_count")}
                        </span>
                      )}
                    </span>
                  </span>
                  <span className="inline-flex shrink-0 items-center rounded-md bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-600 dark:text-emerald-400">
                    Active
                  </span>
                </span>
              ) : (
                <SelectValue
                  placeholder={
                    menusQuery.isLoading
                      ? t("pos.menu.loading_for_today")
                      : menus.length === 0
                        ? t("pos.menu.no_menu_today")
                        : t("pos.menu.select_placeholder")
                  }
                />
              )}
            </SelectTrigger>
            <SelectContent>
              {menus.map((menu) => {
                const window = `${formatScheduleTime(menu.start_time)} – ${formatScheduleTime(menu.end_time)}`;
                return (
                  <SelectItem key={menu.id} value={menu.id}>
                    <span className="flex items-center gap-2">
                      <BookOpenIcon className="size-3.5 shrink-0 text-muted-foreground" />
                      <span className="font-medium">{menu.name}</span>
                      {/* Right after the name: the distinction the cashier is
                          scanning for, before the schedule/count detail. */}
                      <MenuServiceTypeBadge menu={menu} t={t} />
                      <span
                        className="inline-flex items-center gap-1 rounded-sm bg-muted px-1.5 py-0.5 text-[10px] font-medium tabular-nums text-muted-foreground"
                        aria-label={t("pos.menu.schedule_window_label")}
                      >
                        <ClockIcon className="size-3" />
                        {window}
                      </span>
                      {typeof menu.menu_products_count === "number" && (
                        <span className="text-[10px] text-muted-foreground">
                          · {menu.menu_products_count}{" "}
                          {t("pos.menu.product_count")}
                        </span>
                      )}
                    </span>
                  </SelectItem>
                );
              })}
            </SelectContent>
          </Select>

          {/* Beside the menu picker: the questions this grid raises ("why is
              THAT menu selected", "why is the price 税抜", "why is the grid
              locked") are all answered by the same guide. */}
          <HelpButton
            topic="menu-catalog"
            className="hidden shrink-0 self-center sm:inline-flex"
          />
        </div>
      </div>

      {/* Section pills row (scrollspy + jump nav) -------------------- */}
      {!!menuId && !menusQuery.isError && groupedSections.length > 0 && (
        <div className="shrink-0 border-b bg-card">
          <div
            className="flex items-center gap-1 overflow-x-auto px-3 sm:px-6 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            data-slot="menu-catalog-section-tabs"
          >
            {groupedSections.map((g) => (
              <SectionPill
                key={g.id}
                label={g.name}
                count={g.items.length}
                active={activeSectionId === g.id}
                onClick={() => scrollToSection(g.id)}
              />
            ))}
          </div>
        </div>
      )}

      {/* Menu errors / no-menu states ------------------------------- */}
      {menusQuery.isError && (
        <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 bg-muted/20 p-6 text-center">
          <AlertCircleIcon className="size-8 text-destructive" />
          <div className="text-sm font-medium text-foreground">
            {t("pos.menu.error_load_title")}
          </div>
          <div className="max-w-xs text-xs text-muted-foreground">
            {menusQuery.error instanceof Error
              ? menusQuery.error.message
              : t("common.error_unknown")}
          </div>
          <Button size="sm" variant="outline" onClick={() => menusQuery.refetch()}>
            {t("common.retry")}
          </Button>
        </div>
      )}

      {!menusQuery.isLoading &&
        !menusQuery.isError &&
        menus.length === 0 && (
          <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 bg-muted/20 p-6 text-center">
            <BookOpenIcon className="size-10 text-muted-foreground/60" />
            <div className="text-sm font-medium text-foreground">
              {t("pos.menu.no_menus_title")}
            </div>
            <p className="max-w-md text-xs text-muted-foreground">
              {t("pos.menu.no_menus_desc")}
            </p>
          </div>
        )}

      {!menusQuery.isError && menus.length > 0 && !menuId ? (
        <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-3 bg-muted/20 p-6 text-center">
          <BookOpenIcon className="size-10 text-muted-foreground/60" />
          <div className="text-sm font-medium text-foreground">
            {t("pos.menu.no_selected_title")}
          </div>
          <p className="max-w-xs text-xs text-muted-foreground">
            {t("pos.menu.no_selected_desc")}
          </p>
        </div>
      ) : !menuId ? null : (
        <div
          ref={scrollContainerRef}
          className="min-h-0 flex-1 overflow-y-auto bg-muted/20 px-3 py-3 pb-24 sm:px-6 sm:py-5 lg:pb-5"
        >
          {catalogQuery.isLoading && (
            <div className="flex h-full items-center justify-center gap-2 text-sm text-muted-foreground">
              <Spinner className="size-4" />
              {t("pos.menu.loading")}
            </div>
          )}

          {catalogQuery.isError && (
            <div className="flex h-full flex-col items-center justify-center gap-3 text-center">
              <AlertCircleIcon className="size-8 text-destructive" />
              <div className="text-sm font-medium text-foreground">
                {t("pos.menu.error_load_products")}
              </div>
              <div className="max-w-xs text-xs text-muted-foreground">
                {catalogQuery.error instanceof Error
                  ? catalogQuery.error.message
                  : t("common.error_unknown")}
              </div>
              <Button
                size="sm"
                variant="outline"
                onClick={() => catalogQuery.refetch()}
              >
                {t("common.retry")}
              </Button>
            </div>
          )}

          {!catalogQuery.isLoading &&
            !catalogQuery.isError &&
            groupedSections.length === 0 && (
              <div className="flex h-full flex-col items-center justify-center text-center text-sm text-muted-foreground">
                {committedSearch
                  ? t("pos.menu.no_products_search", { search: committedSearch })
                  : t("pos.menu.no_products")}
              </div>
            )}

          {!catalogQuery.isLoading && groupedSections.length > 0 && (
            <div className="flex flex-col gap-8">
              {groupedSections.map((group) => (
                <div
                  key={group.id}
                  ref={(el) => {
                    if (el) sectionNodesRef.current.set(group.id, el);
                    else sectionNodesRef.current.delete(group.id);
                  }}
                  data-section-id={group.id}
                  data-slot="menu-catalog-section"
                  // scroll-margin-top accounts for the section pill row
                  // sitting above the scroll container, so a click that
                  // does scrollIntoView lands the heading just below the
                  // pill row instead of at the very top of the viewport.
                  className="scroll-mt-4"
                >
                  <div className="mb-3 flex items-center gap-2">
                    <h3 className="text-sm font-semibold text-foreground">
                      {group.name}
                    </h3>
                    <span className="text-[10px] text-muted-foreground/70">
                      {/* #3163 — số món do BACKEND đếm, nên nó đúng cả khi khối
                          này chưa tải. Lấy `items.length` sẽ hiện "0 món" cho
                          một section đầy hàng, tức nói dối đúng lúc người ta
                          đang quyết định có cuộn tới đó không. */}
                      {group.pending ? group.expected : group.items.length}{" "}
                      {t("pos.menu.product_count")}
                    </span>
                    <div className="h-px flex-1 bg-border" />
                  </div>
                  {group.pending && (
                    // Khối CHƯA TẢI. Giữ đúng chiều cao của một hàng thẻ để
                    // thanh cuộn không giật khi món về — và để observer nạp
                    // trước có một node thật mà quan sát.
                    <div
                      data-slot="menu-catalog-section-pending"
                      className="flex h-24 items-center justify-center rounded-md border border-dashed border-border/60"
                    >
                      <Spinner className="size-4" />
                    </div>
                  )}
                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-2.5 md:grid-cols-4 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5">
                    {group.items.map((mp) => {
                      const activeSkus = (mp.skus ?? []).filter((s) => s.is_active);
                      const variantCount = activeSkus.length;
                      const hasVariants = variantCount > 1;
                      const toppingGroups = mp.product?.topping_groups ?? [];
                      const needsDialog = shouldUseProductOptionsDialog(
                        variantCount,
                        toppingGroups.length,
                      );
                      const prices = activeSkus.map(effectiveSkuPrice);
                      const minPrice = prices.length > 0 ? Math.min(...prices) : 0;
                      const maxPrice = prices.length > 0 ? Math.max(...prices) : 0;
                      const cardDisabled = disabled || variantCount === 0;
                      // BrandCoreCatalogService::ensureCombo writes the
                      // canonical lowercase 'combo' code; older brands
                      // don't have the type set so guard on the field.
                      const isCombo = mp.product?.product_type_code === "combo";
                      // Number of choice groups — surfaced under the title
                      // so staff know upfront how many picks the dialog
                      // will ask for ("セット 3項目").
                      const comboPickCount = isCombo
                        ? toppingGroups.filter((g) => g.effective_min_select > 0).length
                        : 0;

                      const cardButton = (
                        <button
                          key={mp.id}
                          type="button"
                          data-slot="menu-catalog-item"
                          data-combo={isCombo ? "true" : undefined}
                          disabled={cardDisabled}
                          onClick={
                            needsDialog || variantCount === 0
                              ? undefined
                              : () => handleAddSingle(mp, activeSkus[0])
                          }
                          className={cn(
                            "group relative flex cursor-pointer flex-col items-stretch gap-2 rounded-lg border-2 bg-card p-2 text-left transition-all duration-150",
                            isCombo
                              ? "border-amber-400/70 bg-gradient-to-br from-amber-50/60 to-card hover:border-amber-500 hover:shadow-md focus-visible:border-amber-500 active:border-amber-500"
                              : "border-border/60 hover:border-primary/50 hover:shadow-md focus-visible:border-primary active:border-primary",
                            "focus-visible:outline-none",
                            "disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:shadow-none",
                            isCombo
                              ? "disabled:hover:border-amber-400/70"
                              : "disabled:hover:border-border/60",
                          )}
                        >
                          <div className="relative">
                            <ProductImageCarousel
                              gallery={mp.product?.gallery}
                              name={mp.product?.name ?? t("pos.menu.product_fallback")}
                              className="w-full"
                            />
                            {/* Soft primary tint over the image when the card
                             * is hovered/focused — mirrors the selected-card
                             * look in the design reference. */}
                            <div
                              aria-hidden
                              className={cn(
                                "pointer-events-none absolute inset-0 rounded-md transition-colors duration-200",
                                isCombo
                                  ? "bg-amber-500/0 group-hover:bg-amber-500/[0.08] group-focus-visible:bg-amber-500/10 group-active:bg-amber-500/10"
                                  : "bg-primary/0 group-hover:bg-primary/[0.06] group-focus-visible:bg-primary/10 group-active:bg-primary/10",
                              )}
                            />
                            {isCombo && (
                              <span
                                className="pointer-events-none absolute left-2 top-2 inline-flex items-center gap-1 rounded-full bg-amber-500 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-white shadow-sm"
                                data-slot="combo-badge"
                              >
                                <PackageIcon className="size-3" />
                                {t("pos.menu.combo_badge")}
                              </span>
                            )}
                            {/* plan-019 — Happy Hour Badge top-right corner.
                                Doesn't clash with the combo Badge on the left.
                                Tooltip shows the ends_at clock so staff can
                                tell the customer "Happy Hour ends at 23:00". */}
                            {mp.active_promotion && (
                              <div className="pointer-events-none absolute right-2 top-2">
                                <PromotionBadge
                                  discountPercent={mp.active_promotion.discount_percent}
                                  endsAt={mp.active_promotion.ends_at}
                                />
                              </div>
                            )}
                            <span
                              className={cn(
                                "pointer-events-none absolute bottom-1.5 right-1.5 flex size-8 items-center justify-center rounded-full bg-background text-foreground shadow-md transition-colors",
                                isCombo
                                  ? "group-hover:bg-amber-500 group-hover:text-white"
                                  : "group-hover:bg-primary group-hover:text-primary-foreground",
                              )}
                              aria-hidden="true"
                            >
                              <PlusIcon className="size-3.5" />
                            </span>
                          </div>
                          <div className="flex flex-col gap-0.5 px-0.5 pb-0.5">
                            <div className="flex items-start justify-between gap-2">
                              <div className="min-w-0 truncate text-sm font-semibold text-foreground">
                                {mp.product?.name ?? "—"}
                              </div>
                              {hasVariants && !isCombo && (
                                <span className="inline-flex shrink-0 items-center gap-0.5 text-[11px] font-medium tabular-nums text-muted-foreground/80">
                                  <LayersIcon className="size-3" />
                                  {variantCount}
                                </span>
                              )}
                              {isCombo && comboPickCount > 0 && (
                                <span className="inline-flex shrink-0 items-center gap-0.5 rounded-md bg-amber-100 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-amber-800">
                                  {t("pos.menu.combo_pick_count", { count: comboPickCount })}
                                </span>
                              )}
                            </div>
                            <div
                              className={cn(
                                "text-[13px] tabular-nums",
                                isCombo
                                  ? "font-semibold text-amber-700"
                                  : "text-muted-foreground",
                              )}
                            >
                              {(() => {
                                // plan-043 — every number on the card is run through
                                // the shop's 総額表示 toggle: excluded mode shows the
                                // net (stored) price, included mode shows net + tax.
                                // So the displayed amount visibly differs between the
                                // two modes (¥2,350 → ¥2,585). rate == null (no tax
                                // info) leaves the price untouched.
                                const rate = productRate(mp.product);
                                const withTax = (v: number) =>
                                  menuDisplayPrice(v, rate, pricesIncludeTax, currencyCode);
                                const minShown = withTax(minPrice);
                                const maxShown = withTax(maxPrice);
                                const promo = mp.active_promotion;
                                if (promo) {
                                  // Apply discount to both ends of the range so
                                  // a variant-rich product still shows a clean
                                  // range. Source of truth is server's
                                  // discounted_price for the picked SKU; for
                                  // multi-variant display we recompute the
                                  // bounds locally using the same percent. Tax is
                                  // added AFTER the discount, matching the cart.
                                  const factor = (100 - promo.discount_percent) / 100;
                                  const minDisc = withTax(Math.round(minPrice * factor));
                                  const maxDisc = withTax(Math.round(maxPrice * factor));
                                  if (hasVariants && minPrice !== maxPrice) {
                                    return (
                                      <span className="inline-flex items-baseline gap-1">
                                        <span className="text-xs line-through opacity-60">
                                          {formatCurrency(minShown)}-{formatCurrency(maxShown)}
                                        </span>
                                        <span className="font-semibold text-amber-600">
                                          {formatCurrency(minDisc)}-{formatCurrency(maxDisc)}
                                        </span>
                                      </span>
                                    );
                                  }
                                  return (
                                    <StrikethroughPrice
                                      current={minDisc}
                                      original={minShown}
                                      formatCurrency={formatCurrency}
                                    />
                                  );
                                }
                                return hasVariants && minPrice !== maxPrice
                                  ? `${formatCurrency(minShown)} - ${formatCurrency(maxShown)}`
                                  : formatCurrency(minShown);
                              })()}
                            </div>
                            {/* plan-043 — single tax-status label naming what the
                                price above represents: 税込 (Đã gồm thuế) when the
                                shop's prices_include_tax toggle is on (the shown
                                number already has tax added), 税抜 (Chưa gồm thuế)
                                when off. Hidden when the product has no resolved rate
                                (fresh org / a workstation build that predates the
                                tax fields). */}
                            {(() => {
                              const rate = productRate(mp.product);
                              if (rate == null) return null;
                              return (
                                <div className="mt-0.5 text-[10px] font-medium leading-tight text-muted-foreground">
                                  {pricesIncludeTax
                                    ? t("pos.menu.taxIncluded")
                                    : t("pos.menu.taxExcluded")}
                                </div>
                              );
                            })()}
                          </div>
                        </button>
                      );

                      if (!needsDialog) return cardButton;

                      return (
                        <ProductOptionsDialog
                          key={mp.id}
                          product={mp}
                          skus={activeSkus}
                          toppingGroups={toppingGroups}
                          priceForDisplay={(base) =>
                            menuDisplayPrice(
                              base,
                              productRate(mp.product),
                              pricesIncludeTax,
                              currencyCode,
                            )
                          }
                          onSubmit={(sku, toppings, note) =>
                            onAddItem(mp, sku, toppings, note)
                          }
                        >
                          {cardButton}
                        </ProductOptionsDialog>
                      );
                    })}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}
    </section>
  );
}

interface SectionPillProps {
  label: string;
  active: boolean;
  count?: number;
  onClick: () => void;
}

function SectionPill({ label, active, count, onClick }: SectionPillProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      data-active={active}
      className={cn(
        "group inline-flex shrink-0 cursor-pointer items-center gap-1.5 border-b-2 px-3 py-2 text-sm transition-colors",
        active
          ? "border-foreground font-semibold text-foreground"
          : "border-transparent text-muted-foreground hover:text-foreground",
      )}
    >
      <span>{label}</span>
      {typeof count === "number" && (
        <span
          className={cn(
            "inline-flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-medium tabular-nums",
            active
              ? "bg-foreground text-background"
              : "bg-muted text-muted-foreground",
          )}
        >
          {count}
        </span>
      )}
    </button>
  );
}
