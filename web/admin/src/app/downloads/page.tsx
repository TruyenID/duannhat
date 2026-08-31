import type { Metadata } from "next";
import { cookies } from "next/headers";
import { DEFAULT_LOCALE, LOCALE_COOKIE, getTranslations, isLocaleCode } from "@/i18n";
import { backendOrigin, loadCatalog } from "./catalog.server";
import { WorkstationDownloads } from "./components/workstation-downloads";

/**
 * `GET /downloads` — the public workstation download page.
 *
 * PUBLIC ON PURPOSE, and public by default: admin-web has no `middleware.ts`
 * and nothing in the root layout gates on a session, so a new route is
 * reachable without signing in unless it asks for a session itself. This one
 * must not: the person opening it is usually installing the first workstation
 * at a shop, or reinstalling one that has stopped answering.
 *
 * Laravel still serves the BINARIES out of `public/downloads/workstation/` —
 * only the page moved here. `GET /downloads` on the backend now 301s to this
 * route so the links already circulating keep working.
 */

// The manifest changes whenever a release is published, and the backend origin
// is read from the live process environment. Neither may be frozen into a
// build artefact.
export const dynamic = "force-dynamic";

export async function generateMetadata(): Promise<Metadata> {
  const cookieStore = await cookies();
  const cookieLocale = cookieStore.get(LOCALE_COOKIE)?.value;
  const locale = isLocaleCode(cookieLocale) ? cookieLocale : DEFAULT_LOCALE;

  return { title: getTranslations(locale)["downloads.meta_title"] };
}

export default async function DownloadsPage() {
  const origin = backendOrigin();
  const catalog = await loadCatalog(origin);

  return <WorkstationDownloads origin={origin} catalog={catalog} />;
}
