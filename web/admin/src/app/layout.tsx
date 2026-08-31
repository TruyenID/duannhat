import type { Metadata } from "next";
import { cookies } from "next/headers";
import { AppProvider } from "@/providers/app-provider";
import { QueryProvider } from "@/providers/query-provider";
import { RuntimeConfigProvider } from "@/providers/runtime-config-provider";
import { Toaster } from "@godxjp/ui";
import { DEFAULT_LOCALE, LOCALE_COOKIE, isLocaleCode } from "@/i18n";
import "./fonts.css";
import "./globals.css";


export const metadata: Metadata = {
  title: "DXS Product",
  description: "Product, inventory, and menu management system",
};

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  // Read the persisted locale from the cookie so SSR and the very first
  // client paint use the same language. Without this, every navigation
  // briefly rendered the default (ja) before useEffect synced the user's
  // real preference from localStorage.
  const cookieStore = await cookies();
  const cookieLocale = cookieStore.get(LOCALE_COOKIE)?.value;
  const locale = isLocaleCode(cookieLocale) ? cookieLocale : DEFAULT_LOCALE;

  // Deployment-scoped config, read per request. The `cookies()` call above
  // already opts this layout into dynamic rendering, so this is the live
  // process environment — not a value frozen into the bundle at build time.
  // Deliberately NOT a `NEXT_PUBLIC_*` name: those are inlined at build time
  // and so cannot follow a domain change without a rebuild.
  const runtimeConfig = {
    customerWebUrl: process.env.CUSTOMER_WEB_URL ?? "",
  };

  return (
    <html
      lang={locale}
      className="font-vars h-full antialiased"
      suppressHydrationWarning
    >
      <body className="flex min-h-full flex-col font-sans">
        <RuntimeConfigProvider value={runtimeConfig}>
          <QueryProvider>
            <AppProvider defaultLocale={locale}>
              {children}
              <Toaster position="top-right" richColors closeButton duration={3000} />
            </AppProvider>
          </QueryProvider>
        </RuntimeConfigProvider>
      </body>
    </html>
  );
}
