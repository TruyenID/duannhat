"use client";

import { useCallback, useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Link, usePathname } from "@/i18n/routing";
import {
  BookOpen,
  ChevronLeft,
  ChevronRight,
  CreditCard,
  MapPin,
  Smartphone,
  TicketPercent,
} from "lucide-react";
import { FEATURES } from "@/lib/feature-flags";
import { useAuth } from "@/context/auth-context";
import { useBrand } from "@/context/brand-context";
import { accountHref, authEntryPointsAllowed } from "@/lib/shop-routes";

/**
 * Banner slides — placeholder. Thay `image` bằng URL thật khi có asset.
 * Nếu `image` rỗng → render gradient + caption để không vỡ layout.
 */
type Slide = {
  id: string;
  image?: string;
  alt: string;
  /** Màu nền fallback khi không có `image` */
  bg?: string;
  caption?: string;
};

export default function HomeHero() {
  const t = useTranslations("homeHero");
  const { isLoggedIn } = useAuth();
  const { currentBranch } = useBrand();
  const pathname = usePathname();
  const SLIDES: Slide[] = [
    { id: "spring-menu", image: "/images/banner-spring-menu.webp", alt: t("altSpringMenu") },
    { id: "family-day", image: "/images/banner-family-day.webp", alt: "Family Day" },
    { id: "trung-chan", image: "/images/banner-trung-chan.webp", alt: t("altPoachedEggs") },
  ];
  // 3 shortcut cuối trỏ khu tài khoản — ẩn khi FEATURES.auth off (#47) để
  // không còn link mồ côi tới route đã bị chặn.
  //
  // Ẩn thêm khi URL không xác định được cửa hàng (#1717). Trước đây chỗ này
  // dựng link từ `currentBranch` — tức localStorage — nên ở trang thương hiệu
  // nó lặng lẽ trỏ vào khu tài khoản của chi nhánh phiên trước. Còn "Tìm cửa
  // hàng" ngay bên cạnh mới là lối đi đúng cho khách chưa chọn cửa hàng.
  //
  // Và ẩn với KHÁCH VÃNG LAI khi `authEntryPoints` off. Ba shortcut này không
  // mang chữ "Đăng nhập", nhưng với người chưa đăng nhập chúng dẫn thẳng vào
  // `/account/{shop}` — tức tường đăng nhập. Để nguyên thì lệnh "ẩn nút đăng
  // nhập ở mọi trang" vẫn hở đúng một lối. Người ĐÃ đăng nhập vẫn thấy: với họ
  // đây là shortcut vào tài khoản thật, không phải lời mời.
  const accountShortcut = accountHref(currentBranch.slug);
  const accountShortcutsVisible =
    FEATURES.auth &&
    authEntryPointsAllowed(pathname) &&
    (isLoggedIn || FEATURES.authEntryPoints);
  const QUICK_LINKS = [
    { href: "/select-branch", label: t("shortcutFindStore"), icon: MapPin },
    { href: "/menus", label: t("shortcutMenu"), icon: BookOpen },
    ...(accountShortcutsVisible
      ? [
          { href: accountShortcut, label: t("shortcutGuide"), icon: Smartphone },
          { href: accountShortcut, label: t("shortcutCard"), icon: CreditCard },
          { href: accountShortcut, label: t("shortcutCoupon"), icon: TicketPercent },
        ]
      : []),
  ];
  const [index, setIndex] = useState(0);
  const total = SLIDES.length;

  const next = useCallback(() => setIndex((i) => (i + 1) % total), [total]);
  const prev = useCallback(() => setIndex((i) => (i - 1 + total) % total), [total]);

  useEffect(() => {
    const id = window.setInterval(next, 6000);
    return () => window.clearInterval(id);
  }, [next]);

  return (
    <section className="mx-auto w-full max-w-7xl px-4 pt-6 pb-2 md:px-6 md:pt-8 anim-enter">
      <div className="grid gap-5 md:grid-cols-[minmax(0,2fr)_minmax(240px,1fr)] md:items-center md:gap-6">
        {/* Carousel */}
        <div className="relative overflow-hidden rounded-2xl border bg-card shadow-sm">
          <div
            className="flex aspect-[16/9] w-full transition-transform duration-500 ease-out"
            style={{ transform: `translateX(-${index * 100}%)` }}
          >
            {SLIDES.map((slide) => (
              <div
                key={slide.id}
                className="relative flex h-full w-full shrink-0 items-center justify-center"
                style={{ background: slide.bg }}
              >
                {slide.image ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img
                    src={slide.image}
                    alt={slide.alt}
                    className="absolute inset-0 h-full w-full object-cover"
                  />
                ) : (
                  <p className="px-6 text-center text-lg font-bold tracking-tight text-foreground/80 md:text-2xl anim-float">
                    {slide.caption ?? slide.alt}
                  </p>
                )}
              </div>
            ))}
          </div>

          {/* Prev/Next */}
          <button
            type="button"
            onClick={prev}
            aria-label={t('previousBanner')}
            className="absolute left-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-md bg-primary text-primary-foreground shadow-md transition hover:bg-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          >
            <ChevronLeft className="h-5 w-5" />
          </button>
          <button
            type="button"
            onClick={next}
            aria-label={t("nextBanner")}
            className="absolute right-3 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-md bg-primary text-primary-foreground shadow-md transition hover:bg-primary/90 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          >
            <ChevronRight className="h-5 w-5" />
          </button>

          {/* Dots */}
          <div className="absolute inset-x-0 bottom-3 flex justify-center gap-1.5">
            {SLIDES.map((s, i) => (
              <button
                key={s.id}
                type="button"
                aria-label={t("bannerGoto", { index: i + 1 })}
                onClick={() => setIndex(i)}
                className={`h-1.5 rounded-full transition-all ${
                  i === index ? "w-6 bg-primary" : "w-1.5 bg-foreground/30 hover:bg-foreground/50"
                }`}
              />
            ))}
          </div>
        </div>

        {/* Quick links */}
        <ul className="flex flex-col gap-2.5 anim-stagger">
          {QUICK_LINKS.map(({ href, label, icon: Icon }) => (
            <li key={label}>
              <Link
                href={href}
                className="group flex items-center gap-3 rounded-xl bg-[#fff5d6] px-4 py-3 text-sm font-semibold text-foreground shadow-sm transition hover:bg-[#ffeeb8] hover:shadow-md"
              >
                <Icon className="h-4 w-4 shrink-0 text-foreground/70 transition-colors group-hover:text-primary" />
                <span className="min-w-0 flex-1 leading-snug">{label}</span>
              </Link>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
