"use client";

import { useEffect } from "react";
import { useRouter } from "@/i18n/routing";
import { useTranslations } from 'next-intl';
import { Loader2 } from "lucide-react";

export default function TakeawayRedirectPage() {
  const router = useRouter();
  const t = useTranslations('takeaway');

  useEffect(() => {
    router.replace("/select-branch?next=takeaway");
  }, [router]);

  return (
    <div className="flex min-h-screen items-center justify-center gap-2 bg-[#FAFAFA] text-muted-foreground">
      <Loader2 className="h-5 w-5 animate-spin" />
      <span className="text-sm">{t('redirecting')}</span>
    </div>
  );
}
