import { defineRouting } from 'next-intl/routing';
import { createNavigation } from 'next-intl/navigation';

export const routing = defineRouting({
  locales: ['ja', 'vi', 'en'],
  defaultLocale: 'ja',
  localePrefix: 'always',
  localeDetection: false,
});

// Export navigation helpers that preserve locale
export const { Link, redirect, usePathname, useRouter } = createNavigation(routing);
