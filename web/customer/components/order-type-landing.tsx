"use client";

import { Link } from "@/i18n/routing";
import { useTranslations } from 'next-intl';
import { ShoppingBag, UtensilsCrossed, ArrowRight, AlertCircle, Loader2 } from "lucide-react";
import { useBrand } from "@/context/brand-context";
import { FEATURES } from "@/lib/feature-flags";
import { Button } from "@/components/ui/button";

export default function OrderTypeLanding() {
	  const { isLoadingBranches, branchesError, refetchBranches, branches, currentBranch } = useBrand();
	  const t = useTranslations('orderType');

	  const brand = currentBranch.brand;
	  // GHIM CỨNG logo dọc (tròn + chữ xếp dưới) cho khối giữa trang /order.
	  // Cùng lý do với header (xem `BrandBadge` trong components/Header.tsx):
	  // chuỗi fallback cũ `customer_order_logo_url || customer_header_logo_url
	  // || logo_url` đọc từ DB nên mỗi môi trường ra một logo, và khi brand
	  // chưa cấu hình thì trang này rơi về logo NGANG của header — sai bố cục
	  // vì khối này thiết kế cho logo dọc.
	  const orderLogo = "/images/brand-logo-stacked.png";

  if (isLoadingBranches) {
    return (
      <div className="flex flex-1 flex-col items-center justify-center gap-3 px-4 py-12 text-muted-foreground">
        <Loader2 className="h-6 w-6 animate-spin" aria-label={t('loading')} />
        <p className="text-sm">{t('loadingBranches')}</p>
      </div>
    );
  }

  if (branchesError) {
    return (
      <div className="flex flex-1 flex-col items-center justify-center gap-3 px-4 py-12 text-center">
        <AlertCircle className="h-8 w-8 text-destructive" />
        <div>
          <p className="text-sm font-semibold">{t('loadError')}</p>
          <p className="mt-1 text-xs text-muted-foreground">
            {t('checkConnection')}
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={refetchBranches}>
          {t('retry')}
        </Button>
      </div>
    );
  }

	  if (branches.length === 0) {
    return (
      <div className="flex flex-1 flex-col items-center justify-center gap-3 px-4 py-12 text-center">
        <p className="text-sm font-semibold">{t('noBranch')}</p>
        <p className="text-xs text-muted-foreground">
          {t('contactAdmin')}
        </p>
      </div>
    );
  }

  return (
    <div className="flex flex-1 flex-col items-center justify-center px-4 py-12">
      <div className="w-full max-w-sm anim-enter">
	        <div className="mb-8 text-center">
	          {/* eslint-disable-next-line @next/next/no-img-element */}
	          <img
	            src={orderLogo}
	            alt={brand.name}
	            className="mx-auto mb-4 h-20 w-auto object-contain md:h-24"
	          />
	          <p className="mt-1 text-sm text-muted-foreground">
	            {t("chooseOrderType")}
	          </p>
	        </div>

        <div className="flex flex-col gap-4 anim-stagger">
          {/* Card "Đặt bàn" — ẩn khi FEATURES.booking off (#47). Landing khi đó
              chỉ còn Takeaway; Dine-in vốn chỉ vào được qua QR tại bàn. */}
          {FEATURES.booking && (
            <Link
              href="/select-branch?next=booking"
              className="group flex items-center gap-5 rounded-2xl border-2 border-border bg-card p-5 shadow-sm transition-all hover:border-primary/50 hover:shadow-md"
            >
              <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary/10 transition-colors group-hover:bg-primary/20">
                <UtensilsCrossed className="h-7 w-7 text-primary" />
              </div>
              <div className="flex-1">
                <p className="text-base font-bold">{t('bookTable')}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  {t('bookTableDesc')}
                </p>
              </div>
              <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-1 group-hover:text-primary" />
            </Link>
          )}

          <Link
            href="/select-branch?next=takeaway"
            className="group flex items-center gap-5 rounded-2xl border-2 border-border bg-card p-5 shadow-sm transition-all hover:border-primary/50 hover:shadow-md"
          >
            <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-xl bg-primary/10 transition-colors group-hover:bg-primary/20">
              <ShoppingBag className="h-7 w-7 text-primary" />
            </div>
            <div className="flex-1">
              <p className="text-base font-bold">{t('takeaway')}</p>
              <p className="mt-0.5 text-xs text-muted-foreground">
                {t('takeawayDesc')}
              </p>
            </div>
            <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-1 group-hover:text-primary" />
          </Link>
        </div>
      </div>
    </div>
  );
}
