"use client";

import Link from "next/link";
import { Store } from "lucide-react";
import { Button } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";

export default function BrandNotFound() {
  const { t } = useTranslation();

  return (
    <div className="flex h-full flex-col items-center justify-center gap-6 px-4 text-center">
      <div className="flex size-14 items-center justify-center rounded-2xl bg-muted">
        <Store className="size-7 text-muted-foreground" />
      </div>
      <div className="flex flex-col gap-1.5">
        <p className="text-base font-medium text-foreground">{t("common.not_found.title")}</p>
        <p className="max-w-xs text-sm text-muted-foreground">
          {t("common.not_found.scope_brand")}
        </p>
      </div>
      <Button asChild variant="outline" size="sm">
        <Link href="/">{t("common.go_home")}</Link>
      </Button>
    </div>
  );
}
