import { NextResponse, type NextRequest } from 'next/server';
import createMiddleware from 'next-intl/middleware';
import { routing } from './i18n/routing';
import { FEATURES } from './lib/feature-flags';
import { flowForPrefix, missingShopSegment } from './lib/shop-routes';

const intlMiddleware = createMiddleware(routing);

const LOCALE_COOKIE = 'NEXT_LOCALE';
const LOCALES = new Set<string>(routing.locales);

/**
 * Returns the first path segment when it is one of the configured locales
 * (`/vi/menus` → `vi`), null otherwise. Used to detect URLs that arrived
 * without a locale prefix — the only case where the locale cookie should
 * win over `defaultLocale`.
 */
function pathLocale(pathname: string): string | null {
  const first = pathname.split('/')[1] ?? '';
  return LOCALES.has(first) ? first : null;
}

// Route prefixes tạm khoá theo feature flag (godx-tempo-customer-web#47).
// So khớp trên pathname ĐÃ bỏ locale prefix, theo kiểu prefix nên `/login`
// phủ luôn `/login/[shop]`, `/account` phủ `/account/[shop]/orders/[id]`, v.v.
// Không xoá thư mục route — chỉ chặn ở đây để bật lại là xong.
const DISABLED_ROUTE_PREFIXES: string[] = [
  ...(FEATURES.booking ? [] : ['/booking']),
  // `/reset-password` (#1783) đi cùng nhóm: nó là một nhánh của khu tài khoản,
  // chỉ khác ở chỗ khách tới từ link trong thư. Bỏ sót nó nghĩa là tắt auth mà
  // vẫn còn một trang đặt mật khẩu gọi được endpoint auth.
  ...(FEATURES.auth ? [] : ['/login', '/register', '/account', '/reset-password']),
];

function isDisabledRoute(pathnameWithoutLocale: string): boolean {
  return DISABLED_ROUTE_PREFIXES.some(
    (prefix) =>
      pathnameWithoutLocale === prefix || pathnameWithoutLocale.startsWith(`${prefix}/`),
  );
}

// Hostnames we DON'T force HTTPS on — bare-LAN test paths, dev loopback.
// Anything else (Cloudflare Tunnel, custom domain) gets a 308 to HTTPS so
// the browser stops flashing "Not Secure" and so any future cookie with
// `Secure` flag actually works.
const HTTP_OK_HOSTS = new Set([
  'localhost',
  '127.0.0.1',
  '[::1]',
]);
const HTTP_OK_TLD_SUFFIXES = ['.local'];

function shouldForceHttps(host: string): boolean {
  const hostname = host.split(':')[0].toLowerCase();
  if (HTTP_OK_HOSTS.has(hostname)) return false;
  if (HTTP_OK_TLD_SUFFIXES.some((suf) => hostname.endsWith(suf))) return false;
  return true;
}

export default function middleware(req: NextRequest) {
  // Cloudflare Tunnel forwards the client's original protocol via this header.
  // Next.js sees the proxied request as HTTP either way, so we read the header
  // to know if the BROWSER → CF leg was secure.
  const proto = req.headers.get('x-forwarded-proto') ?? req.nextUrl.protocol.replace(':', '');
  const host = req.headers.get('host') ?? '';

  if (proto === 'http' && shouldForceHttps(host)) {
    const url = req.nextUrl.clone();
    url.protocol = 'https:';
    // 308 Permanent Redirect preserves the request method (vs 301 which can
    // downgrade POST → GET in some clients). Cacheable + safe.
    return NextResponse.redirect(url, 308);
  }

  // Locale persistence — the `NEXT_LOCALE` cookie (set by the header
  // language-switcher) is the source of truth. Whenever the URL's
  // locale doesn't match the cookie, redirect to the cookie's variant
  // BEFORE next-intl runs. Two cases:
  //   1. Bare URL (`/`, `/menus`, `/checkout`) → prefix with cookie.
  //   2. Wrong-locale URL (`/ja/menus` while cookie is `vi`) → swap.
  // Behaviour matches the spec "language only changes via the header
  // switcher" — browser back / external links / refresh never silently
  // flip the visible language.
  const cookieLocale = req.cookies.get(LOCALE_COOKIE)?.value;
  if (cookieLocale && LOCALES.has(cookieLocale)) {
    const currentLocale = pathLocale(req.nextUrl.pathname);
    if (currentLocale && currentLocale !== cookieLocale) {
      // /ja/menus → /vi/menus
      const url = req.nextUrl.clone();
      const rest = req.nextUrl.pathname.slice(`/${currentLocale}`.length) || '/';
      url.pathname = `/${cookieLocale}${rest === '/' ? '' : rest}`;
      return NextResponse.redirect(url, 307);
    }
    if (!currentLocale && cookieLocale !== routing.defaultLocale) {
      // / → /vi/, /menus → /vi/menus
      const url = req.nextUrl.clone();
      url.pathname = `/${cookieLocale}${req.nextUrl.pathname === '/' ? '' : req.nextUrl.pathname}`;
      return NextResponse.redirect(url, 307);
    }
  }

  // Hai guard route dưới đây chạy SAU khối locale ở trên (để URL đã ổn định về
  // đúng locale) và TRƯỚC next-intl.
  const currentLocale = pathLocale(req.nextUrl.pathname);
  const bare = currentLocale
    ? req.nextUrl.pathname.slice(`/${currentLocale}`.length) || '/'
    : req.nextUrl.pathname;
  const locale = currentLocale ?? routing.defaultLocale;

  // 1. Feature-flag. Redirect về trang chủ cùng locale — không 404, không loop
  //    (`/` không bao giờ nằm trong danh sách chặn).
  if (DISABLED_ROUTE_PREFIXES.length > 0 && isDisabledRoute(bare)) {
    const url = req.nextUrl.clone();
    url.pathname = `/${locale}`;
    url.search = '';
    return NextResponse.redirect(url, 307);
  }

  // 2. Cửa hàng bắt buộc trong URL (#1505). Khu tài khoản chỉ tồn tại bên trong
  //    một cửa hàng, nên `/login`, `/register`, `/account` trần không được
  //    render — kể cả gõ tay hay bookmark cũ. Đá về /select-branch (KHÔNG phải
  //    `/`) và mang theo ý định ban đầu, để chọn cửa hàng xong là quay lại đúng
  //    khu vừa muốn vào.
  //
  //    Ở đây chỉ kiểm "có segment cửa hàng hay không". Slug đó có thật hay
  //    không thì middleware không trả lời được (edge runtime, không gọi API);
  //    việc đối chiếu với danh sách chi nhánh nằm ở `<RequireShop>`.
  const missingShop = FEATURES.auth ? missingShopSegment(bare) : null;
  if (missingShop) {
    const url = req.nextUrl.clone();
    url.pathname = `/${locale}/select-branch`;
    url.search = `?next=${flowForPrefix(missingShop)}`;
    return NextResponse.redirect(url, 307);
  }

  return intlMiddleware(req);
}

export const config = {
  matcher: ['/', '/(ja|vi|en)/:path*', '/((?!api|_next|_vercel|.*\\..*).*)'],
};
