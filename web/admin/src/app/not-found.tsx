import Link from "next/link";
import { cookies } from "next/headers";
import { FileQuestion } from "lucide-react";
import { Button } from "@godxjp/ui";
import { DEFAULT_LOCALE, LOCALE_COOKIE, isLocaleCode, getTranslations } from "@/i18n";

export default async function NotFound() {
  const cookieStore = await cookies();
  const cookieLocale = cookieStore.get(LOCALE_COOKIE)?.value;
  const locale = isLocaleCode(cookieLocale) ? cookieLocale : DEFAULT_LOCALE;
  const t = getTranslations(locale);

  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-6 bg-background px-4 text-center">
      <div className="flex size-16 items-center justify-center rounded-2xl bg-muted">
        <FileQuestion className="size-8 text-muted-foreground" />
      </div>
      <div className="flex flex-col gap-2">
        <h1 className="text-2xl font-semibold tracking-tight">404</h1>
        <p className="text-base font-medium text-foreground">{t["common.not_found.title"]}</p>
        <p className="max-w-sm text-sm text-muted-foreground">
          {t["common.not_found.description"]}
        </p>
      </div>
      <Button asChild variant="outline" size="sm">
        <Link href="/">{t["common.go_home"]}</Link>
      </Button>
    </div>
  );
}
