"use client";

import { useEffect, useRef, useState } from "react";
import { Link, useRouter, usePathname } from "@/i18n/routing";
import { useLocale, useTranslations } from 'next-intl';
import { Button } from "@/components/ui/button";
import { useBrand } from "@/context/brand-context";
import { useAuth } from "@/context/auth-context";
import BrandSwitcherSheet from "@/components/brand-switcher-sheet";
import UserMenu from "@/components/user-menu";
import { LanguageSwitcher } from "@/components/language-switcher";
import { useLocaleSwitch } from "@/hooks/use-locale-switch";
import { ArrowLeft, Check, ChevronDown, ChevronRight, History, LogIn, LogOut, Menu, Receipt, Store, UserPlus, UtensilsCrossed } from "lucide-react";
import { cn } from "@/lib/utils";
import type { Branch } from "@/data/brands";
import { apiFetch } from "@/lib/api";
import { FEATURES } from "@/lib/feature-flags";
import { loadGuestOrders } from "@/lib/guest-orders";
import { accountHref, authEntryPointsAllowed, headerAuthSlot, loginHref, registerHref } from "@/lib/shop-routes";
import { locales, localeNames, localeFlags, type Locale } from "@/i18n/config";

interface HeaderProps {
  hideSwitcher?: boolean;
  hideAuth?: boolean;
  showBack?: boolean;
  backHref?: string;
  showLogo?: boolean;
  branchListHref?: string;
  hideBranchInfo?: boolean;
  menuBranch?: Branch;
  hideOrderCta?: boolean;
  /** Bỏ shadow-sm dưới header (vd: trang Lịch sử món muốn liền mạch với header con). */
  hideShadow?: boolean;
  /** Hiện nút Đăng nhập/Đăng ký CHỈ trên desktop (md+), ẩn trên mobile. */
  authDesktopOnly?: boolean;
  /** Ẩn nút "Lịch sử đơn hàng" (cả desktop button + item trong mobile menu). */
  hideOrderHistory?: boolean;
  /** Ẩn nút "Đăng ký" trên desktop (giữ "Đăng nhập"). */
  hideRegister?: boolean;
  /**
   * Đích của logo ở menu mode (takeaway / dine-in). Mặc định "/" — về trang
   * chủ. Dine-in truyền lại URL bàn để khách không rớt khỏi phiên đã quét QR.
   */
  logoHref?: string;
  /** Override className của inner container (mặc định `max-w-7xl px-3 md:px-6`).
      Truyền vd `max-w-6xl` để Header content khớp width với main content
      của page đang dùng `max-w-6xl`. Class mới ghi đè class cũ qua cn(). */
  containerClassName?: string;
}

const ACTIVE_ORDER_STATUSES = ["open", "dining", "checkout", "paying", "pending"];

interface GuestOrderApiResult {
	id: string;
	status: string;
	total?: number | null;
	paid?: number | null;
	is_fully_paid?: boolean | null;
	payment_due_at?: string | null;
}

/**
 * Tính tổng số guest orders + số đơn "Chưa thanh toán" theo cùng rule
 * filter của trang /orders?tab=pending.
 */
async function getGuestOrdersCountsForHeader(): Promise<{ total: number; pending: number }> {
	if (typeof window === "undefined") return { total: 0, pending: 0 };

	const pointers = loadGuestOrders();
	const total = pointers.length;
	if (total === 0) return { total: 0, pending: 0 };

	try {
		const res = await apiFetch<{ data: GuestOrderApiResult[] }>(
			"/api/v1/customer/orders/batch",
			{
				method: "POST",
				body: JSON.stringify({ ids: pointers.map((p) => p.id) }),
				headers: { "Content-Type": "application/json" },
				silent401: true,
			},
		);

		const pending = res.data.filter((order) => {
			if (!order) return false;

			// Bỏ qua cancelled
			if (order.status === "cancelled") return false;

			// plan-037: đơn awaiting_confirmation chưa "vào sổ" — user còn ở
			// bước review/xác nhận. Không tính là pending, không hiển thị
			// trong header count cho đến khi commit.
			if (order.status === "awaiting_confirmation") return false;

			const totalVal = typeof order.total === "number" ? order.total : 0;
			const paidVal = typeof order.paid === "number" ? order.paid : 0;
			const isFullyPaid =
				typeof order.is_fully_paid === "boolean"
					? order.is_fully_paid
					: totalVal > 0 && paidVal >= totalVal;

			// Đơn đã closed (kiosk pay xong → close) không tính pending
			if (order.status === "closed") return false;
			// Đã fully paid không tính pending
			if (isFullyPaid) return false;

			// 1) Đơn có payment_due_at — countdown vẫn còn (chưa quá hạn) → pending
			//    Quá hạn (BE chưa expire kịp) → không pending nữa.
			if (order.payment_due_at) {
				const dueMs = Date.parse(order.payment_due_at);
				if (!Number.isNaN(dueMs)) {
					return dueMs > Date.now();
				}
			}

			// 2) Mọi đơn chưa thanh toán đủ
			if (!isFullyPaid) return true;

			// 3) Fallback: status còn đang active
			if (ACTIVE_ORDER_STATUSES.includes(order.status)) return true;

			return false;
		}).length;

		return { total, pending };
	} catch (err) {
		console.error("[Header] Failed to compute guest pending orders count:", err);
		// Fallback: coi tất cả guest orders là "chưa thanh toán"
		return { total, pending: total };
	}
}

function BrandBadge({ brandName }: { brandName: string }) {
	  // GHIM CỨNG logo ngang (Viet Origin / ベト屋) cho MỌI header của Customer.
	  // Trước đây src lấy từ `brand.customer_header_logo_url ?? brand.logo_url`,
	  // nên header đổi theo dữ liệu brand trong DB — mỗi môi trường (local /
	  // staging / prod) hiện một logo khác nhau, và một lần sửa nhầm ở admin là
	  // đổi logo toàn bộ site. Yêu cầu vận hành là logo cố định, không phụ
	  // thuộc DB nữa.
	  const src = "/images/brand-logo-horizontal.png";

	  return (
	    <span className="flex h-8 shrink-0 items-center md:h-10">
	      {/* eslint-disable-next-line @next/next/no-img-element */}
	      <img src={src} alt={brandName} className="h-full w-auto object-contain" loading="lazy" />
	    </span>
	  );
	}

export default function Header({ hideSwitcher = false, hideAuth = false, showBack = false, backHref, showLogo = false, branchListHref, hideBranchInfo = false, menuBranch, hideOrderCta = false, hideShadow = false, authDesktopOnly = false, hideOrderHistory = false, hideRegister = false, logoHref = "/", containerClassName }: HeaderProps) {
	  const { currentBranch, openSwitcher } = useBrand();
  const { isLoggedIn } = useAuth();
  const router = useRouter();
  const pathname = usePathname();
  const t = useTranslations('header');

  // Mobile: khi có menuBranch (takeaway/dine-in) → hiện title "Đặt hàng" + login button
  const isMobileMenuMode = !!menuBranch;

  // Khu tài khoản chỉ có entry-point khi biết chắc cửa hàng (#1717). KHÔNG
  // dựa vào `currentBranch` cho việc này: nó đọc localStorage, nên ở trang
  // chủ nút Đăng nhập sẽ lặng lẽ gắn khách vào chi nhánh của phiên trước —
  // đúng cái sai mà #1505 sinh ra để chặn.
  //
  // Quyết định nằm ở `headerAuthSlot` (thuần, có test) chứ không rải trong
  // JSX: trước #1747 hai call-site dưới đây diễn giải cùng một luật theo hai
  // cách khác nhau, và chip tài khoản lọt qua cổng ở cả hai.
  const authSlot = headerAuthSlot({
    featureAuthEnabled: FEATURES.auth,
    guestCtaEnabled: FEATURES.authEntryPoints,
    isLoggedIn,
    pathname,
  });

  return (
    <>
      <header className={`sticky top-0 z-50 bg-white ${hideShadow ? "md:shadow-sm" : "shadow-sm"}`}>
        <div className={cn("mx-auto max-w-7xl px-3 md:px-6", containerClassName)}>
          <div className="flex h-12 items-center gap-1.5 md:h-14 md:gap-3">
            {showBack && (
              backHref ? (
                <Link href={backHref} aria-label={t('back')}>
                  <Button variant="ghost" size="icon" className="h-8 w-8 shrink-0" aria-label={t('back')}>
                    <ArrowLeft className="h-4 w-4" />
                  </Button>
                </Link>
              ) : (
                <Button
                  aria-label={t('back')}
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8 shrink-0"
                  onClick={() => router.back()}
                >
                  <ArrowLeft className="h-4 w-4" />
                </Button>
              )
            )}

            {/* Menu mode (takeaway/dine-in): logo + brand badge + login/UserMenu.
                Hiển thị giống nhau ở cả mobile và desktop theo yêu cầu —
                gỡ md:hidden để dùng chung markup. Desktop không còn hiển thị
                "Các cửa hàng khác" button (block riêng cũ đã bị gỡ). */}
            {isMobileMenuMode && (
              <>
                {/* Logo ở menu mode phải là LINK, không phải nút mở brand
                    switcher: <BrandSwitcherSheet /> không được render khi có
                    `menuBranch` (xem cuối file) nên nút cũ bấm vào không xảy
                    ra gì cả. Mặc định về trang chủ; dine-in truyền `logoHref`
                    trỏ lại bàn đang quét. */}
                <Link
                  href={logoHref}
                  aria-label={t('home')}
                  className="flex min-w-0 shrink items-center gap-1.5 rounded-lg px-1.5 py-1.5 transition-colors hover:bg-muted/60 md:gap-2 md:px-2"
                >
                  <BrandBadge brandName={menuBranch.brand.name} />
                </Link>
                {/* Spacer — không thuộc tap-target nào. Tránh empty area
                    giữa brand button và icon "chưa thanh toán" vô tình
                    mở brand switcher khi user nhắm hụt icon nhỏ. */}
                <div className="flex-1" aria-hidden />
                {!hideAuth && (
                  <>
                    {/* Mobile: icon "Chưa thanh toán" + hamburger menu
                        (md:hidden). Icon pending orders hiển thị ngoài navbar. */}
                    <div className="flex items-center gap-1 md:hidden">
                      {!hideOrderHistory && <PendingOrdersButton />}
                      <MobileNavMenu hideOrderHistory={hideOrderHistory} />
                    </div>

                    {/* Desktop (≥md): Chưa thanh toán | Lịch sử | Ngôn ngữ | Đăng nhập (ngoài cùng).
                        Tất cả font-weight 400 (regular), không có background fill
                        — match Figma cho takeaway flow. */}
                    <div className="hidden items-center gap-2 md:flex">
                      {!hideOrderHistory && (
                        <>
                          <PendingOrdersButton />
                          <OrderHistoryButton />
                        </>
                      )}
                      {/* Chip tài khoản và nút Đăng nhập đi chung một cổng
                          (#1747) — cả hai đều là đường VÀO khu tài khoản và
                          đều dựng href từ cửa hàng. `none` (flag #47 tắt, hoặc
                          URL không xác định được cửa hàng) → chỉ còn language
                          switcher. */}
                      {authSlot === 'chip' ? (
                        <UserMenu />
                      ) : authSlot === 'none' ? (
                        <LanguageSwitcher />
                      ) : (
                        <>
                          <LanguageSwitcher />
                          <Link href={loginHref(currentBranch.slug)}>
                            <Button
                              size="sm"
                              className="h-8 gap-1.5 px-3 text-sm font-normal text-white hover:opacity-90"
                              style={{ backgroundColor: '#16A34A' }}
                            >
                              <LogIn className="h-3.5 w-3.5" />
                              {t('login')}
                            </Button>
                          </Link>
                        </>
                      )}
                    </div>
                  </>
                )}
              </>
            )}

            {!menuBranch && !hideSwitcher && !branchListHref && (
              <button
                aria-label={t('otherStoresButton')}
                type="button"
                onClick={openSwitcher}
                className="group flex shrink-0 items-center gap-1.5 rounded-lg px-1.5 py-1.5 transition-colors hover:bg-muted/60 md:gap-2 md:px-2"
              >
                <BrandBadge brandName={currentBranch.brand.name} />
                <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground/60 transition-transform group-hover:text-muted-foreground" />
              </button>
            )}

            {!menuBranch && branchListHref && (
              <Link
                href={branchListHref}
                className="group flex min-w-0 shrink-0 items-center gap-1.5 rounded-lg pl-2 pr-0 py-1.5 transition-colors hover:bg-muted/60 md:pl-2.5"
              >
                <Store className="h-4 w-4 shrink-0 text-muted-foreground" />
                <span className="truncate text-[13px] font-medium leading-tight md:text-sm">
                  {t('storeList')}
                </span>
                <ChevronRight className="h-3.5 w-3.5 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5 group-hover:text-muted-foreground" />
              </Link>
            )}

            {!menuBranch && hideSwitcher && !showLogo && !branchListHref && !hideBranchInfo && (
              <div className="flex shrink-0 items-center gap-1.5 px-1.5 py-1.5 md:gap-2 md:px-2">
                <BrandBadge brandName={currentBranch.brand.name} />
              </div>
            )}

            {/* hideSwitcher + showLogo: logo link về trang chủ (home) nhưng
                KHÔNG mở branch switcher — dùng cho dine-in (đã quét QR khóa
                bàn), checkout (cart gắn với 1 branch), account pages, v.v. */}
            {!menuBranch && hideSwitcher && showLogo && !branchListHref && (
              <Link
	                href={logoHref}
                aria-label={t('home')}
                className="flex shrink-0 items-center gap-1.5 px-1.5 py-1.5 md:gap-2 md:px-2"
              >
                <BrandBadge brandName={currentBranch.brand.name} />
              </Link>
            )}

            {!menuBranch && !hideOrderCta && (
              <Link
                href="/order"
                aria-label={t('order')}
                className="group relative -ml-1.5 flex shrink-0 items-center gap-1.5 rounded-lg pl-0 pr-1.5 py-1 leading-tight text-foreground transition-all hover:-translate-y-0.5 hover:text-primary md:gap-2 md:pr-2"
              >
                <span className="relative flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-primary/10 ring-1 ring-inset ring-primary/20 transition-colors group-hover:bg-primary/20 group-hover:ring-primary/40 md:h-8 md:w-8">
                  <UtensilsCrossed className="h-3.5 w-3.5 text-primary transition-transform duration-300 group-hover:rotate-[-8deg] group-hover:scale-110 md:h-4 md:w-4" />
                  <span className="pointer-events-none absolute -right-0.5 -top-0.5 flex h-2 w-2">
                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-primary/60" />
                    <span className="relative inline-flex h-2 w-2 rounded-full bg-primary" />
                  </span>
                </span>
                <span className="hidden text-[11px] font-bold tracking-wide sm:inline md:text-xs">
                  {t('order')}
                </span>
              </Link>
            )}

            {!menuBranch && <div className="flex-1" />}

            {/* Desktop header order theo Figma:
                  Lịch sử | Ngôn ngữ | Đăng nhập | Đăng ký
                Mobile: icon "Chưa thanh toán" + hamburger. */}
            <div className="flex shrink-0 items-center gap-1 md:gap-1.5">
              {/* Mobile: icon "Chưa thanh toán" + hamburger menu */}
              {!menuBranch && !hideOrderHistory && (
                <div className="md:hidden">
                  <PendingOrdersButton />
                </div>
              )}

              {/* Mobile hamburger — render khi mobile có >1 item cần gom
                  (vd: Login + Lịch sử + Ngôn ngữ). Khi chỉ còn Ngôn ngữ
                  (dine-in: hideAuth && hideOrderHistory) thì hiển trực tiếp
                  LanguageSwitcher icon, không cần hamburger. */}
              {!menuBranch && !((hideAuth && !authDesktopOnly) && hideOrderHistory) && (
                <MobileNavMenu hideAuth={hideAuth && !authDesktopOnly} hideOrderHistory={hideOrderHistory} />
              )}

              {/* Pending orders + Order history — desktop only. Đặt đầu hàng. */}
              {!menuBranch && (!hideAuth || authDesktopOnly) && !hideOrderHistory && (
                <div className="hidden items-center gap-2 md:flex">
                  <PendingOrdersButton />
                  <OrderHistoryButton />
                </div>
              )}

              {/* Language switcher. Mặc định chỉ render desktop (mobile gom
                  vào hamburger). Riêng trường hợp dine-in (hideAuth +
                  hideOrderHistory → không có hamburger) thì render trên cả
                  mobile để user vẫn đổi được ngôn ngữ. menuBranch path tự
                  render LanguageSwitcher inline trong auth block bên trên
                  → outer skip để không double-render. */}
              {!menuBranch && (
                <div className={cn(
                  "md:block",
                  (hideAuth && !authDesktopOnly) && hideOrderHistory ? "block" : "hidden"
                )}>
                  <LanguageSwitcher />
                </div>
              )}

              {/* Desktop auth — Đăng nhập + Đăng ký (hoặc UserMenu). Cuối hàng.
                  Cả khách vãng lai lẫn người đã đăng nhập đều phải qua cùng
                  một cổng (#1717, #1747): nút Đăng ký VÀ chip tài khoản đều
                  dựng href từ `currentBranch`, tức localStorage, nên trên URL
                  không mang cửa hàng chúng quy khách về chi nhánh phiên trước.
                  Lưu ý: KHÔNG dùng `hideAuth` để ẩn auth toàn cục vì prop đó
                  còn gate cả hamburger + "Lịch sử đơn hàng" (guest /orders). */}
              {!menuBranch && authSlot !== 'none' && (!hideAuth || authDesktopOnly) && (
                <div className="hidden items-center gap-2 md:flex">
                  {authSlot === 'chip' ? (
                    <UserMenu />
                  ) : (
                    <>
                      <Link href={loginHref(currentBranch.slug)}>
                        <Button
                          size="sm"
                          className="h-8 gap-1.5 px-3 text-sm font-normal text-white hover:opacity-90"
                          style={{ backgroundColor: '#16A34A' }}
                        >
                          <LogIn className="h-3.5 w-3.5" />
                          {t('login')}
                        </Button>
                      </Link>
                      {!hideRegister && (
                        <Link href={registerHref(currentBranch.slug)}>
                          <Button size="sm" className="h-8 gap-1.5 px-3 text-sm">
                            <UserPlus className="h-3.5 w-3.5" />
                            {t('register')}
                          </Button>
                        </Link>
                      )}
                    </>
                  )}
                </div>
              )}
            </div>
          </div>
          {/* Hidden per user request: "Các cửa hàng khác" button on mobile
          {menuBranch && (
            <div className="flex h-11 items-center gap-3 border-t text-xs md:hidden">
              <Link href="/select-branch?next=takeaway" className="ml-auto">
                <Button size="sm" className="h-8 px-3 text-xs">{t('otherStores')}</Button>
              </Link>
            </div>
          )}
          */}
        </div>
      </header>
      {(!hideSwitcher || showLogo) && !branchListHref && !menuBranch && <BrandSwitcherSheet />}
    </>
  );
}

/**
 * Tiny icon button "Lịch sử đơn hàng" rendered next to UserMenu / Login.
 * - Logged-in customer → luôn show, href `/account/orders`.
 * - Guest → chỉ show khi localStorage có guest orders (loadGuestOrders đã
 *   tự prune entries quá 3 ngày), href `/orders`.
 * - Server-side render an toàn: state khởi đầu `0` orders, sau mount mới
 *   đọc localStorage → không gây hydration mismatch.
 */
function OrderHistoryButton() {
  const t = useTranslations('header');
  const { isLoggedIn } = useAuth();
  const { currentBranch } = useBrand();
  const [guestCount, setGuestCount] = useState(0);

  useEffect(() => {
    if (isLoggedIn) {
      setGuestCount(0);
      return;
    }
    if (typeof window === "undefined") return;

    const syncCount = () => {
      setGuestCount(loadGuestOrders().length);
    };

    // Sync ngay lần đầu
    syncCount();

    // Lắng nghe sự kiện custom thay vì poll 2s một lần
    window.addEventListener("tempo:guest-orders-updated", syncCount);

    return () => {
      window.removeEventListener("tempo:guest-orders-updated", syncCount);
    };
  }, [isLoggedIn]);

  const show = isLoggedIn || guestCount > 0;
  if (!show) return null;

  const href = isLoggedIn ? accountHref(currentBranch.slug, 'orders') : '/orders';

  return (
    <Link href={href} aria-label={t('orderHistory')}>
      <Button
        variant="ghost"
        size="sm"
        className="h-7 gap-1 px-2 text-xs font-normal hover:bg-transparent md:h-8 md:gap-1.5 md:px-2.5 md:text-base"
      >
        <History className="h-3.5 w-3.5" />
        <span className="hidden sm:inline">{t('orderHistory')}</span>
      </Button>
    </Link>
  );
}

/**
 * Icon button "Chưa thanh toán" với badge số lượng đơn hàng pending.
 * Chỉ hiển thị khi có guest orders chưa thanh toán (counter payment).
 * Mobile: icon-only với badge. Desktop: icon + text.
 * Custom wallet icon (SVG) thay vì Receipt.
 */
function PendingOrdersButton() {
  const t = useTranslations('header');
  const { isLoggedIn } = useAuth();
  const [pendingCount, setPendingCount] = useState(0);

  useEffect(() => {
    if (isLoggedIn) {
      setPendingCount(0);
      return;
    }
    if (typeof window === "undefined") return;

    const syncCount = () => {
	      getGuestOrdersCountsForHeader()
	        .then(({ pending }) => {
	          setPendingCount(pending);
	        })
	        .catch((err) => {
	          console.error("[Header] Failed to sync pending orders badge:", err);
	        });
    };

    // Sync ngay lần đầu
    syncCount();

    // Lắng nghe sự kiện custom thay vì poll 2s một lần
    window.addEventListener("tempo:guest-orders-updated", syncCount);

    return () => {
      window.removeEventListener("tempo:guest-orders-updated", syncCount);
    };
  }, [isLoggedIn]);

  if (isLoggedIn || pendingCount === 0) return null;

  return (
    <Link href="/orders?tab=pending" aria-label={t('pendingOrders')}>
      <button className="relative flex items-center gap-1.5 rounded-lg p-2 transition-colors hover:bg-neutral-100">
        {/* Icon wrapper with badge - animated */}
        <div className="relative animate-bounce">
          {/* Custom pending-orders icon: document + clock (theo mẫu user gửi) */}
          <svg
            width="22"
            height="22"
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
          >
            {/* Document */}
            <rect
              x="3.25"
              y="3.25"
              width="11.5"
              height="17.5"
              rx="1.75"
              stroke="#D80027"
              strokeWidth="1.5"
            />
            <line
              x1="6"
              y1="8"
              x2="13"
              y2="8"
              stroke="#D80027"
              strokeWidth="1.5"
              strokeLinecap="round"
            />
            <line
              x1="6"
              y1="11.5"
              x2="11.5"
              y2="11.5"
              stroke="#D80027"
              strokeWidth="1.5"
              strokeLinecap="round"
            />
            <line
              x1="6"
              y1="15"
              x2="10.5"
              y2="15"
              stroke="#D80027"
              strokeWidth="1.5"
              strokeLinecap="round"
            />
            {/* Clock / pending badge */}
            <circle
              cx="18"
              cy="16"
              r="4.25"
              stroke="#D80027"
              strokeWidth="1.5"
            />
            <path
              d="M18 13.75V16l1.5 1.5"
              stroke="#D80027"
              strokeWidth="1.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
          {/* Badge at top-right corner - with pulse animation */}
          {pendingCount > 0 && (
            <span className="absolute -right-1 -top-1 flex h-4 min-w-[16px] items-center justify-center rounded-full bg-red-500 px-1 text-[9px] font-bold leading-none text-white animate-pulse">
              {pendingCount}
            </span>
          )}
        </div>
      </button>
    </Link>
  );
}

/**
 * Custom "world languages" icon — extract từ LanguageSwitcher để dùng
 * lại trong hamburger menu mobile. Path gốc thiết kế kiểu một cuốn sách
 * mở + chữ ngôn ngữ, brand-friendly hơn `Globe` của lucide. Bỏ clipPath
 * gốc vì rect cùng size viewBox → không clip gì.
 */
function LanguageIcon({ className }: { className?: string }) {
  return (
    <svg
      className={className}
      viewBox="0 0 32 32"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden="true"
    >
      <path
        d="M14.125 28.7431C13.3263 28.6519 12.6263 28.4956 11.7738 28.2175C9.76313 27.5613 7.9225 26.325 6.58563 24.7325C4.3375 22.0544 3.40375 18.6025 3.99688 15.1656C4.0675 14.7581 4.125 14.4013 4.125 14.3725C4.125 14.3438 3.38437 14.3112 2.47937 14.3006L0.834375 14.2812L0.58125 14.1138C0.441875 14.0213 0.26125 13.8388 0.179375 13.7075L0.03125 13.4688V8.75V4.03125L0.179375 3.7925C0.26125 3.66125 0.441875 3.47875 0.58125 3.38625L0.834375 3.21875H5.5625H10.2906L10.5437 3.38625C10.6831 3.47875 10.8638 3.66125 10.9456 3.7925C11.0856 4.01875 11.095 4.095 11.1144 5.23438C11.1256 5.89625 11.1444 6.4375 11.1556 6.4375C11.1675 6.4375 11.4756 6.3375 11.84 6.21562C15.345 5.04312 19.2787 5.67562 22.2687 7.8925C23.9412 9.1325 25.3712 10.9719 26.1287 12.8569L26.335 13.3694L28.75 13.3881L31.1656 13.4062L31.4188 13.5737C31.5581 13.6662 31.7388 13.8487 31.8206 13.98L31.9688 14.2188V18.9419V23.6656L31.8013 23.9188C31.7088 24.0581 31.5263 24.2388 31.395 24.3206C31.1606 24.4663 31.1237 24.4694 29.3862 24.4881L27.6169 24.5075L26.5425 25.3463C25.9519 25.8081 25.4181 26.1863 25.3563 26.1863C25.295 26.1869 25.175 26.1187 25.0906 26.0344C24.9463 25.89 24.9375 25.84 24.9375 25.1906V24.5H24.6525C24.4063 24.5 24.3444 24.5281 24.2006 24.7031C23.8575 25.1231 23.0781 25.9019 22.6562 26.2463C20.3219 28.1525 17.1331 29.0856 14.125 28.7431ZM15.9062 27.7894C17.0187 27.2675 18.0694 25.1037 18.6231 22.195L18.7344 21.6081L18.2581 21.6481C16.6294 21.785 13.3081 21.75 12.2406 21.5837L12.0125 21.5487L12.1038 22.04C12.6594 25.035 13.7544 27.2981 14.8975 27.8175C15.2431 27.9744 15.5294 27.9663 15.9062 27.7894ZM13.0887 27.3325C12.3125 26.1994 11.5919 24.1644 11.2188 22.0531L11.1125 21.4494L10.3894 21.315C8.30125 20.9256 6.41813 20.245 5.28625 19.47L4.86375 19.1806L4.90812 19.3869C5.76562 23.3719 8.78938 26.5587 12.6875 27.5875C13.0144 27.6737 13.3019 27.7456 13.3275 27.7469C13.3531 27.7488 13.2456 27.5625 13.0887 27.3325ZM18.0925 27.5925C19.9313 27.1144 21.5269 26.21 22.8563 24.8913L23.2188 24.5312L22.4688 24.4994C21.6069 24.4625 21.3681 24.3631 21.0737 23.9188C20.9087 23.67 20.9056 23.6469 20.8862 22.4675L20.8656 21.2687L20.2744 21.3762C19.9488 21.4356 19.67 21.4969 19.6537 21.5131C19.6381 21.5287 19.5688 21.8537 19.5 22.2356C19.1362 24.255 18.4325 26.2069 17.6612 27.3325C17.5044 27.5625 17.4006 27.75 17.4312 27.75C17.4612 27.75 17.7594 27.6794 18.0925 27.5925ZM26.6794 24.1338L27.3275 23.625H29.1012C30.7919 23.625 30.8806 23.6194 31 23.5C31.1225 23.3775 31.125 23.2919 31.125 18.9375C31.125 14.5694 31.1231 14.4981 30.9988 14.3737C30.8744 14.2494 30.815 14.2475 26.4 14.2644C22.055 14.2806 21.925 14.285 21.8388 14.4025C21.7644 14.5044 21.75 15.2263 21.75 18.9494C21.75 23.2919 21.7525 23.3775 21.875 23.5C21.9944 23.6194 22.0831 23.625 23.7619 23.625C25.4844 23.625 25.5263 23.6281 25.6681 23.7606C25.7938 23.8794 25.8125 23.9562 25.8125 24.3644C25.8125 24.8037 25.8188 24.8263 25.9219 24.7369C25.9819 24.685 26.3231 24.4131 26.6794 24.1338ZM18.3037 20.7537C18.5912 20.7212 18.835 20.6856 18.8456 20.6756C18.8562 20.665 18.9019 20.3044 18.9475 19.875C19.1069 18.3663 19.0181 13.9269 18.825 13.7388C18.6319 13.5513 14.4125 13.4606 13 13.6131C11.8225 13.7406 11.8975 13.71 11.8525 14.0837C11.6669 15.6262 11.65 18.3494 11.8162 19.9475L11.8925 20.6763L12.3369 20.7219C13.525 20.8425 14.175 20.8637 15.9062 20.8394C16.9375 20.8244 18.0162 20.7862 18.3037 20.7537ZM10.9731 20.2656C10.7725 18.71 10.7725 15.4006 10.9744 14.1094C11.02 13.8187 10.9644 13.8144 10.5906 14.0781L10.3025 14.2812L8.6825 14.3006L7.0625 14.32V15.0069C7.0625 15.6525 7.05313 15.7025 6.90938 15.8469C6.70438 16.0513 6.5825 16.0394 6.25875 15.7825L5.98625 15.5656L5.7275 15.7437C5.37437 15.9869 4.88688 16.5187 4.7775 16.7806C4.33625 17.8369 5.61313 19.0256 8.07563 19.8506C8.87188 20.1175 10.315 20.47 10.9587 20.5556C10.9881 20.5594 10.9944 20.4287 10.9731 20.2656ZM20.4275 20.4325C20.645 20.3906 20.8344 20.3256 20.8475 20.2875C20.8612 20.25 20.88 18.8356 20.8894 17.1456L20.9062 14.0719L20.3569 13.965C20.055 13.9069 19.7963 13.8706 19.7819 13.885C19.7675 13.8994 19.7819 14.1487 19.8137 14.44C19.9719 15.8769 19.9469 19.1962 19.7687 20.3375C19.7331 20.57 19.7381 20.5794 19.8813 20.545C19.9638 20.525 20.2094 20.475 20.4275 20.4325ZM5.12063 14.91C4.98 14.7637 4.96625 14.775 4.91 15.0863L4.86625 15.3294L5.05188 15.18C5.23375 15.0344 5.235 15.0287 5.12063 14.91ZM6.32625 13.5794C6.45813 13.4388 6.47125 13.4375 8.2275 13.4375C9.96562 13.4375 9.9975 13.435 10.1225 13.3006C10.2481 13.1656 10.25 13.1056 10.25 8.73812C10.25 4.39562 10.2475 4.31 10.125 4.1875C10.0025 4.065 9.91687 4.0625 5.5625 4.0625C1.20813 4.0625 1.1225 4.065 1 4.1875C0.8775 4.31 0.875 4.39563 0.875 8.75C0.875 13.1044 0.8775 13.19 1 13.3125C1.11875 13.4312 1.20812 13.4375 2.87562 13.4375H4.62625L5.39125 14.0556L6.15625 14.6744L6.175 14.1975C6.19 13.8131 6.21938 13.6931 6.32625 13.5794ZM25.3319 13.2656C25.3106 13.2056 25.1756 12.9031 25.0325 12.5938C23.9287 10.2113 21.8769 8.27437 19.4062 7.2825C18.9375 7.09375 17.5831 6.68812 17.4225 6.6875C17.3969 6.6875 17.5044 6.875 17.6612 7.105C18.4312 8.22938 19.1363 10.1825 19.4988 12.1956C19.5669 12.5738 19.6388 12.9094 19.6588 12.9412C19.6788 12.9737 19.7706 13.0019 19.8631 13.0044C19.9556 13.0063 20.3969 13.0875 20.8438 13.1838C21.6256 13.3531 21.7256 13.36 23.5138 13.3675C25.2575 13.3744 25.3687 13.3681 25.3319 13.2656ZM13.8125 12.68C14.8719 12.6019 16.9431 12.6381 17.9831 12.7531C18.335 12.7919 18.6481 12.8144 18.6794 12.8025C18.7519 12.775 18.4494 11.2925 18.1944 10.425C17.6231 8.47938 16.7525 7.04187 15.9062 6.64687C15.5112 6.46312 15.2388 6.4625 14.8438 6.64687C13.7437 7.16 12.6756 9.36063 12.1275 12.2425L12.0156 12.8287L12.43 12.7906C12.6575 12.7694 13.28 12.72 13.8125 12.68ZM11.6587 10.4156C12.0225 9.1475 12.5744 7.89437 13.1044 7.13312C13.2562 6.91437 13.3725 6.7275 13.3625 6.7175C13.325 6.68125 12.2044 6.99313 11.6719 7.1875L11.125 7.38688L11.1287 10.0525L11.1325 12.7188L11.3131 11.8531C11.4131 11.3769 11.5687 10.73 11.6587 10.4156ZM24.3169 21.9606C24.0769 21.655 24.1462 21.5106 24.705 21.1494C24.9475 20.9925 25.3062 20.7106 25.5031 20.5231L25.8606 20.1825L25.5769 19.7631C25.2731 19.3131 24.8737 18.51 24.7394 18.0781L24.6569 17.8125H24.1831C23.7687 17.8125 23.6925 17.7944 23.5731 17.6681C23.3969 17.48 23.4006 17.2438 23.5819 17.0731C23.72 16.9444 23.7825 16.9375 24.8631 16.9375H26V16.3544C26 15.8263 26.0137 15.7594 26.1444 15.6356C26.3325 15.4594 26.5687 15.4631 26.7394 15.6444C26.8581 15.7712 26.875 15.86 26.875 16.3631V16.9375H28.0206C29.1369 16.9375 29.1694 16.9412 29.3019 17.0819C29.4781 17.27 29.4744 17.5062 29.2931 17.6769C29.1706 17.7912 29.075 17.8125 28.6763 17.8125H28.2044L28.0625 18.2031C27.8719 18.7294 27.5369 19.3894 27.2313 19.8394L26.98 20.2106L27.2869 20.4819C27.4556 20.6319 27.8256 20.9106 28.1094 21.1019C28.6731 21.4819 28.7719 21.6431 28.6 21.9056C28.4413 22.1475 28.235 22.1644 27.8919 21.9644C27.5237 21.75 26.9931 21.3625 26.6769 21.0769L26.4387 20.8619L26.0788 21.1669C25.4013 21.7425 24.8013 22.125 24.5769 22.125C24.5056 22.125 24.3881 22.0506 24.3169 21.9606ZM26.7362 18.9844C27.0281 18.4731 27.2837 17.8881 27.2362 17.8406C27.2187 17.8231 26.8356 17.8169 26.385 17.8263L25.5656 17.8438L25.7763 18.3281C25.9944 18.8288 26.3544 19.4375 26.4331 19.4375C26.4575 19.4375 26.5944 19.2338 26.7362 18.9844ZM3.02813 11.8469C2.94375 11.7625 2.875 11.6569 2.875 11.6131C2.875 11.5319 3.60813 9.61625 3.815 9.15625C3.87688 9.01875 4.19875 8.2175 4.53 7.375C5.30688 5.3975 5.2325 5.535 5.53625 5.5125C5.71625 5.49937 5.81625 5.52562 5.88812 5.60625C6.03875 5.77437 8.25 11.3469 8.25 11.5581C8.25 11.6812 8.19625 11.7831 8.08562 11.8706C7.69812 12.175 7.53875 12.0169 7.09062 10.8806L6.71875 9.93812H5.59375L4.46875 9.93875L4.13375 10.7975C3.68562 11.9481 3.40063 12.2188 3.02813 11.8469ZM5.98688 8.1225C5.77875 7.60563 5.59812 7.19375 5.58437 7.20687C5.57125 7.22062 5.39437 7.64313 5.19125 8.14688L4.8225 9.0625H5.59312H6.36438L5.98688 8.1225Z"
        fill="currentColor"
      />
    </svg>
  );
}

/**
 * Mobile-only hamburger menu — consolidates LanguageSwitcher + auth +
 * order history vào 1 dropdown duy nhất để giảm "noise" ở mobile header.
 *
 * Items:
 *   - "Lịch sử đặt hàng" — chỉ render khi logged-in HOẶC guest có order
 *   - "Ngôn ngữ" — click expand inline list 3 ngôn ngữ (locale switch
 *     thực hiện qua next-intl router, không dùng LanguageSwitcher để
 *     tránh nested dropdown)
 *   - "Đăng nhập" (filled emerald) HOẶC "Đăng xuất" tuỳ auth state
 *
 * Tự đóng khi click outside (dùng `mousedown` listener trên document,
 * khớp pattern LanguageSwitcher).
 */
function MobileNavMenu({ hideAuth = false, hideOrderHistory = false }: { hideAuth?: boolean; hideOrderHistory?: boolean }) {
  const t = useTranslations('header');
  const pathname = usePathname();
  const locale = useLocale() as Locale;
  const { currentBranch } = useBrand();
  const { isLoggedIn, logout } = useAuth();
  // #1777 — CÙNG đường đổi ngôn ngữ với switcher desktop. Bản riêng ở đây
  // không ghi cookie và truyền `usePathname()` trần vào `router.replace`, nên
  // dưới 768px — nơi hamburger là cách đổi ngôn ngữ DUY NHẤT trên
  // `/order-success` — khách vừa trả tiền vẫn mất mã đơn như trước #1773.
  const { switchLocale: applyLocale } = useLocaleSwitch();

  const [open, setOpen] = useState(false);
  const [langOpen, setLangOpen] = useState(false);
  const [guestCount, setGuestCount] = useState(0);
  const [pendingCount, setPendingCount] = useState(0);
  const ref = useRef<HTMLDivElement>(null);
  const languageButtonRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (isLoggedIn) {
      setGuestCount(0);
      setPendingCount(0);
      return;
    }
    if (typeof window === "undefined") return;

    const syncCount = () => {
	      getGuestOrdersCountsForHeader()
	        .then(({ total, pending }) => {
	          setGuestCount(total);
	          setPendingCount(pending);
	        })
	        .catch((err) => {
	          console.error("[Header] Failed to sync guest counts in mobile menu:", err);
	        });
    };

    // Sync ngay lần đầu
    syncCount();

    // Lắng nghe sự kiện custom thay vì poll 2s một lần
    window.addEventListener("tempo:guest-orders-updated", syncCount);

    return () => {
      window.removeEventListener("tempo:guest-orders-updated", syncCount);
    };
  }, [isLoggedIn]);

  // Close on outside click.
  useEffect(() => {
    if (!open) return;
    function handleClick(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
        setLangOpen(false);
      }
    }
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [open]);

  useEffect(() => {
    if (!open) return;
    function handleEscape(event: KeyboardEvent) {
      if (event.key !== "Escape") return;
      if (langOpen) {
        setLangOpen(false);
        languageButtonRef.current?.focus();
      } else {
        setOpen(false);
      }
    }
    document.addEventListener("keydown", handleEscape);
    return () => document.removeEventListener("keydown", handleEscape);
  }, [langOpen, open]);

  const showHistory = !hideOrderHistory && (isLoggedIn || guestCount > 0);
  const historyHref = isLoggedIn ? accountHref(currentBranch.slug, 'orders') : '/orders';
  const loginTarget = loginHref(currentBranch.slug);
  // Mục Đăng nhập/Đăng xuất cuối menu — ẩn khi flow không cần auth (dine-in)
  // hoặc khi FEATURES.auth off (#47). Dùng chung cho cả border-b của mục
  // "Ngôn ngữ" ngay phía trên để không thừa gạch chân.
  //
  // #1717 chỉ siết nhánh KHÁCH VÃNG LAI: "Đăng nhập" trên URL không xác định
  // được cửa hàng thì ẩn, còn "Đăng xuất" của người đang đăng nhập phải luôn
  // còn — ẩn nó là nhốt họ trong phiên, không có lối ra. #1747 siết chip tài
  // khoản nhưng CỐ Ý không đụng mục này: chip là đường VÀO, Đăng xuất là lối
  // RA duy nhất còn lại trên mobile.
  //
  // `authEntryPoints` off chỉ cắt nhánh khách vãng lai, vì đúng lý lẽ đó:
  // `isLoggedIn` vẫn đi thẳng qua để "Đăng xuất" không biến mất.
  const showAuthItem =
    FEATURES.auth &&
    !hideAuth &&
    (isLoggedIn || (FEATURES.authEntryPoints && authEntryPointsAllowed(pathname)));

  const switchLocale = (newLocale: Locale) => {
    applyLocale(newLocale);
    setLangOpen(false);
    setOpen(false);
  };

  const closeMenu = () => {
    setOpen(false);
    setLangOpen(false);
  };

  return (
    <div className="relative md:hidden" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-label={t('menuAriaLabel')}
        aria-expanded={open}
        className="flex h-9 w-9 items-center justify-center rounded-lg text-neutral-700 transition-colors hover:bg-muted"
      >
        <Menu className="size-5" />
      </button>

      {open && (
        <div className="absolute right-0 top-full z-50 mt-2 w-44 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-lg animate-in fade-in slide-in-from-top-2 duration-150">
          {/* Lịch sử đặt hàng */}
          {showHistory && (
            <Link
              href={historyHref}
              onClick={closeMenu}
              className="flex items-center gap-2.5 border-b border-neutral-100 px-3 py-2 text-[13px] font-medium text-neutral-800 hover:bg-neutral-50"
            >
              <History className="size-[18px] text-neutral-600" />
              {t('orderHistory')}
            </Link>
          )}

          {/* Ngôn ngữ — click expand inline 3 ngôn ngữ */}
          <button
            ref={languageButtonRef}
            type="button"
            onClick={() => setLangOpen((v) => !v)}
            className={cn(
              "flex w-full items-center justify-between gap-2.5 px-3 py-2 text-[13px] font-medium text-neutral-800 hover:bg-neutral-50",
              showAuthItem && "border-b border-neutral-100"
            )}
            aria-expanded={langOpen}
            aria-haspopup="menu"
          >
            <span className="flex items-center gap-2.5">
              <LanguageIcon className="size-[18px] text-neutral-600" />
              {t('language')}
            </span>
            <ChevronDown
              className={cn(
                "size-3 text-neutral-500 transition-transform",
                langOpen && "rotate-180"
              )}
            />
          </button>
          {langOpen && (
            <div
              role="menu"
              aria-label={t('language')}
              className="border-b border-neutral-100 bg-neutral-50"
            >
              {locales.map((loc) => {
                const isActive = locale === loc;
                return (
                  <button
                    key={loc}
                    type="button"
                    role="menuitemradio"
                    aria-checked={isActive}
                    onClick={() => switchLocale(loc)}
                    className={cn(
                      "flex w-full items-center justify-between gap-2.5 px-3 py-1.5 text-[12px]",
                      isActive
                        ? "bg-emerald-50 font-semibold text-emerald-700"
                        : "text-neutral-700 hover:bg-white"
                    )}
                  >
	                    <span className="flex items-center gap-2.5">
	                      <span
	                        className="flex shrink-0 items-center justify-center overflow-hidden rounded border border-neutral-200 bg-white"
	                        style={{ width: "24px", height: "18px" }}
	                      >
	                        {/* eslint-disable-next-line @next/next/no-img-element -- static SVG in /public, no optimisation needed */}
	                        <img
	                          src={localeFlags[loc]}
	                          alt=""
	                          aria-hidden
	                          width={24}
	                          height={18}
	                          className="h-full w-full object-cover"
	                        />
	                      </span>
	                      <span className="text-left">{localeNames[loc]}</span>
	                    </span>
                    {isActive && <Check className="size-3" />}
                  </button>
                );
              })}
            </div>
          )}

          {/* Login (guest) hoặc Logout (đã login). Ẩn hoàn toàn khi hideAuth
              (vd: dine-in flow không cần login) hoặc FEATURES.auth off. */}
          {showAuthItem && (!isLoggedIn ? (
            <div className="p-2">
              <Link href={loginTarget} onClick={closeMenu}>
                <Button
                  className="h-8 w-full gap-1.5 px-3 text-xs"
                  style={{ backgroundColor: '#2D8336' }}
                >
                  <LogIn className="size-3.5" />
                  {t('login')}
                </Button>
              </Link>
            </div>
          ) : (
            <button
              type="button"
              onClick={() => {
                closeMenu();
                void logout();
              }}
              className="flex w-full items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-neutral-800 hover:bg-neutral-50"
            >
              <LogOut className="size-[18px] text-neutral-600" />
              {t('logout')}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
