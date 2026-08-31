import type { Metadata } from "next";
import { NextIntlClientProvider } from 'next-intl';
import { getMessages } from 'next-intl/server';
import { notFound } from 'next/navigation';
import { locales } from '@/i18n/config';
import { CartProvider } from "@/context/cart-context";
import { BrandProvider } from "@/context/brand-context";
import { LocaleGuard } from "@/components/locale-guard";
import { AuthProvider } from "@/context/auth-context";
import { LoadingProvider } from "@/context/loading-context";
import { Toaster } from "@/components/ui/sonner";
import "../fonts.css";
import "../globals.css";

export const metadata: Metadata = {
  title: "Betoya Menu",
  description: "Betoya online menu",
};

export function generateStaticParams() {
  return locales.map((locale) => ({ locale }));
}

// Force all pages under [locale]/ to be dynamic. Customer-web is heavily
// client-state-driven (BrandProvider, CartProvider, AuthProvider) so static
// prerender adds no value — every page re-renders client-side anyway. This
// avoids the "useX must be used inside Provider" prerender errors that hit
// orphan pages / pages with deep client component trees.
export const dynamic = "force-dynamic";

export default async function LocaleLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  // Validate that the incoming `locale` parameter is valid
  if (!locales.includes(locale as (typeof locales)[number])) {
    notFound();
  }

  // Providing all messages to the client
  // side is the easiest way to get started
  const messages = await getMessages();

  return (
    <html
      lang={locale}
      className="font-vars antialiased mdl-js"
      suppressHydrationWarning
    >
      <body>
        <NextIntlClientProvider locale={locale} messages={messages}>
          <LoadingProvider>
            <AuthProvider>
              <BrandProvider>
                <CartProvider>
                  <LocaleGuard />
                  {children}
                  <Toaster position="top-center" richColors />
                </CartProvider>
              </BrandProvider>
            </AuthProvider>
          </LoadingProvider>
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
