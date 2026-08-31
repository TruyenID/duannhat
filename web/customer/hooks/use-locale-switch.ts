"use client";

import { useCallback, useTransition } from "react";
import { usePathname, useRouter } from "@/i18n/routing";
import type { Locale } from "@/i18n/config";
import { localeCookieHeader, localeSwitchTarget } from "@/lib/locale-switch-target";

/**
 * #1773 / #1777 — MỘT đường đổi ngôn ngữ cho cả ứng dụng.
 *
 * Có ba chỗ đổi locale: switcher desktop (`language-switcher.tsx`), switcher
 * mobile trong hamburger (`Header.tsx` → `MobileNavMenu`), và `LocaleGuard`
 * (cưỡng chế cookie sau khi bfcache khôi phục trang). #1773 sửa đúng MỘT chỗ,
 * nên trên điện thoại — nơi hamburger là cách đổi ngôn ngữ DUY NHẤT ở màn
 * `/order-success`, vì `LanguageSwitcher` bọc trong `md:block hidden` — lỗi
 * còn nguyên. Ba bản sao của cùng một thao tác thì bản vá chỉ đi được vào một
 * bản; gộp lại là cách duy nhất khiến lần sau không lặp lại.
 *
 * Hai điều bắt buộc, và cả hai đều từng bị bỏ sót:
 *
 * 1. GIỮ QUERY. `usePathname()` của next-intl chỉ trả về đường dẫn. Truyền
 *    thẳng nó vào `router.replace` là vứt sạch tham số — mà `/order-success`
 *    đọc TOÀN BỘ trạng thái từ query (`id`, `code`, `type`, `shop`,
 *    `stripe_return`). Mất `id` thì trang không fetch được đơn và rơi về nhánh
 *    chưa-thu-tiền: khách vừa trả tiền xong được báo "Đang chờ thanh toán" và
 *    mất luôn mã đơn phải mang ra quầy.
 *
 * 2. GHI COOKIE. Bản mobile trước đây không ghi, nên `LocaleGuard` thấy cookie
 *    ≠ locale trên URL và replace ngược lại — nháy về ngôn ngữ cũ, cắt query
 *    lần thứ hai. `LocaleGuard` thì ngược lại: nó ĐANG cưỡng chế cookie, ghi
 *    lại là thừa, nên nó gọi với `persist: false`.
 *
 * Đọc `window.location` chứ không dùng `useSearchParams()`: hook đó bắt
 * component phải nằm trong Suspense boundary, mà cả header lẫn `LocaleGuard`
 * đều mount trên mọi trang. Cả hai caller chỉ chạy ở client (click handler /
 * effect), nên `window.location` vừa đủ và không kéo theo ràng buộc render nào.
 */
export function useLocaleSwitch(): {
  switchLocale: (locale: Locale, options?: { persist?: boolean }) => void;
  isPending: boolean;
} {
  const router = useRouter();
  const pathname = usePathname();
  const [isPending, startTransition] = useTransition();

  const switchLocale = useCallback(
    (locale: Locale, options?: { persist?: boolean }) => {
      const persist = options?.persist ?? true;

      if (persist && typeof document !== "undefined") {
        document.cookie = localeCookieHeader(locale);
      }

      const target =
        typeof window === "undefined"
          ? pathname
          : localeSwitchTarget(pathname, window.location.search, window.location.hash);

      startTransition(() => {
        router.replace(target, { locale });
      });
    },
    [pathname, router],
  );

  return { switchLocale, isPending };
}
