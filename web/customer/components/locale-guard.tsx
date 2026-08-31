"use client";

import { useEffect } from "react";
import { useLocale } from "next-intl";
import { locales, type Locale } from "@/i18n/config";
import { useLocaleSwitch } from "@/hooks/use-locale-switch";
import { readLocaleCookie } from "@/lib/locale-switch-target";

/**
 * Cookie-vs-URL locale guard. Browser bfcache restores a previously
 * rendered page from memory without ever hitting the server, so the
 * middleware locale-rewrite never runs on back/forward navigation.
 * Result: tapping the back arrow on `/vi/orders` could surface the
 * cached `/ja/...` page even though the user had already switched.
 *
 * This component:
 *   1. Renders nothing.
 *   2. On every URL change AND on the `pageshow` event (the canonical
 *      bfcache-restore hook), checks whether the active URL locale
 *      matches the locale cookie set by the header switcher (name owned
 *      by `lib/locale-switch-target.ts` — #1777).
 *   3. If not, `router.replace`s into the cookie's locale — same path,
 *      different prefix — so the visible language reverts to whatever
 *      the user last chose from the switcher.
 *
 * Place once at the top of `app/[locale]/layout.tsx`.
 */
export function LocaleGuard() {
	const activeLocale = useLocale() as Locale;
	// #1777 — cùng đường điều hướng với hai switcher. Trước đây guard truyền
	// `usePathname()` trần vào `router.replace`, nên cú cưỡng chế cookie CẮT
	// QUERY y như switcher — và nó chạy cả trên `pageshow`, tức nút Back cũng
	// đủ để xoá trạng thái đơn khỏi URL dù khách chưa hề đụng nút đổi ngôn ngữ.
	// `persist: false` vì guard đang ĐỌC cookie, ghi lại là thừa.
	const { switchLocale } = useLocaleSwitch();

	useEffect(() => {
		const enforce = () => {
			if (typeof document === "undefined") return;
			const cookieLocale = readLocaleCookie(document.cookie) as Locale | null;
			if (!cookieLocale) return;
			if (!locales.includes(cookieLocale)) return;
			if (cookieLocale === activeLocale) return;
			switchLocale(cookieLocale, { persist: false });
		};

		// Initial check (covers first paint + every client-side nav).
		enforce();

		// pageshow fires every time the page becomes visible — including
		// when the browser restores it from bfcache, which is when
		// middleware redirects don't fire. event.persisted === true tells
		// us bfcache was the source.
		const onPageShow = (e: PageTransitionEvent) => {
			if (e.persisted) enforce();
		};
		window.addEventListener("pageshow", onPageShow);
		return () => window.removeEventListener("pageshow", onPageShow);
	}, [activeLocale, switchLocale]);

	return null;
}
