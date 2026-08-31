"use client";

import { MapPin, Phone, Clock, Star, Armchair } from "lucide-react";
import { useTranslations } from 'next-intl';
import type { Branch } from "@/data/brands";
import { useMemo, useState } from "react";
import { ShopInfoModal } from "@/components/shop-info-modal";
import StoreBanner from "@/components/store-banner";
import { useBranchOpenState, useNextOpeningLabel } from "@/hooks/use-branch-open-state";

interface ShopInfoBannerProps {
  branch: Branch;
}

export default function ShopInfoBanner({ branch }: ShopInfoBannerProps) {
  const [showModal, setShowModal] = useState(false);
  const t = useTranslations('shop');

  // #1167 — the verdict comes from `weekly_hours` on the BRANCH's clock, live.
  // It used to be "is today ticked as a day off?", so a shop that closed at
  // 11:00 still read as open at 23:00 — and any `business_hours` free-text at
  // all short-circuited the whole thing to open.
  const { isOpen, nextOpening } = useBranchOpenState(branch);
  const reopenLabel = useNextOpeningLabel(nextOpening);

  const currentDayStatus = useMemo(() => {
    // Closed wins over every display string below: quoting "Mở cửa 06:00 -
    // 11:00" at 11:30 is exactly the bug being fixed.
    if (!isOpen) {
      return reopenLabel ? t('closedNowUntil', { when: reopenLabel }) : t('closedNow');
    }

    // `business_hours` is free-text the shop typed for display ("11:00 - 22:00,
    // nghỉ trưa 14-16") — it has no structure to enforce, only to show.
    if (branch.business_hours && branch.business_hours.trim()) {
      return t('openHoursRaw', { hours: branch.business_hours.trim() });
    }

    // Fallback `weekly_hours` JSON nếu không có business_hours
    if (!branch.weekly_hours) {
      return t('contactForHours');
    }

    const days = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"] as const;
    const today = new Date().getDay();
    const todayKey = days[today];
    const todayHours = branch.weekly_hours[todayKey];

    if (!todayHours || todayHours.closed) {
      return t('closedToday');
    }

    if (todayHours.open && todayHours.close) {
      return t('openHours', { open: todayHours.open, close: todayHours.close });
    }

    return t('openNow');
  }, [branch.weekly_hours, branch.business_hours, isOpen, reopenLabel, t]);

  return (
    <>
      {/* Banner ảnh: full-width trên desktop (theo yêu cầu) — outer wrapper
          KHÔNG dùng max-w-7xl, KHÔNG dùng md:rounded-lg, để ảnh chạm cạnh
          trái/phải màn hình. Logo + info section bên dưới vẫn được wrap
          trong max-w-7xl để khớp container của Header/MenuPage. */}
      <div className="w-full overflow-hidden bg-white">
        {/* #936 — banner theo breakpoint. Khung KHÔNG ép tỉ lệ nữa: ảnh
            admin upload (desktop 1440×300 = 4.8:1) rộng hơn khung 4:1 cũ nên
            bị phóng to rồi xén hai mép trái phải. Nay khung co theo đúng tỉ
            lệ file → thấy trọn ảnh như lúc preview bên admin. */}
        <StoreBanner
          branch={branch}
          priority
          className="bg-gradient-to-br from-primary/20 to-accent/20"
          fallback={
            <div className="flex h-full w-full items-center justify-center">
              <div className="text-6xl font-bold text-muted-foreground/20 md:text-8xl">
                {branch.brand?.name?.[0] || branch.name[0]}
              </div>
            </div>
          }
        >
        {/* Mobile overlay text */}
        <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent md:hidden" />
        <div className="absolute bottom-0 left-0 right-0 p-4 text-white md:hidden">
          {branch.brand?.name && (
            <p className="text-xs font-medium opacity-90">{branch.brand.name}</p>
          )}
          <h1 className="text-xl font-bold">{branch.name}</h1>
          <div className="mt-1.5 flex flex-col gap-1 text-xs">
            {branch.phone && (
              <a href={`tel:${branch.phone}`} className="flex items-center gap-1.5 opacity-90">
                <Phone className="h-3.5 w-3.5" />
                {branch.phone}
              </a>
            )}
            {branch.address && (
              <div className="flex items-start gap-1.5 opacity-90">
                <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span className="line-clamp-2">{branch.address}</span>
              </div>
            )}
          </div>
        </div>
        </StoreBanner>
      </div>

      {/* Desktop: logo overlap + info — inside max-w-7xl để khớp container */}
      <div className="mx-auto w-full max-w-7xl md:px-6">
        {(branch.logo || branch.brand?.logo_url) && (
          <div className="relative -mt-12 ml-4 hidden md:-mt-14 md:ml-0 md:flex">
            <div className="h-20 w-20 overflow-hidden rounded-full bg-white shadow-lg ring-4 ring-background md:h-24 md:w-24">
              <img
                src={branch.logo || branch.brand?.logo_url || ''}
                alt={`${branch.brand?.name || branch.name} logo`}
                className="h-full w-full object-cover"
              />
            </div>
          </div>
        )}

      <div className="hidden pb-4 pt-3 md:block">
        <div className="mb-2">
          {branch.brand?.name && (
            <p className="text-xs font-medium text-muted-foreground">
              {branch.brand.name}
            </p>
          )}
          <h1 className="text-xl font-bold md:text-[30px] md:leading-tight">{branch.name}</h1>
        </div>

        {/* Stats row — match Figma: KHÔNG vertical dividers, KHÔNG nút "Thông tin".
            Order: rating → opening hours (green) → seat capacity. */}
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
          {branch.review_avg_rating != null && branch.review_total_count ? (
            <div className="flex items-center gap-1">
              <Star className="h-4 w-4 fill-yellow-400 text-yellow-400" />
              <span className="font-semibold">{branch.review_avg_rating.toFixed(1)}</span>
              <span className="text-muted-foreground">
                ({branch.review_total_count >= 1000
                  ? `${Math.floor(branch.review_total_count / 1000).toLocaleString()},000+`
                  : branch.review_total_count.toLocaleString()})
              </span>
            </div>
          ) : null}

          <div className="flex items-center gap-1.5">
            <Clock className="h-4 w-4 text-muted-foreground" />
            <span className={isOpen ? "text-green-600 no-underline" : "text-destructive no-underline"}>
              {currentDayStatus}
            </span>
          </div>

          {branch.seat_capacity && (
            <div className="flex items-center gap-1.5 text-muted-foreground">
              <Armchair className="h-4 w-4 shrink-0" />
              <span>{t('capacityCount', { count: branch.seat_capacity })}</span>
            </div>
          )}
        </div>

        <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
          {branch.address && (
            <div className="flex items-center gap-1.5">
              <MapPin className="h-4 w-4 shrink-0" />
              <span>{branch.address}</span>
            </div>
          )}

          {branch.phone && (
            <div className="flex items-center gap-1.5">
              <Phone className="h-4 w-4 shrink-0" />
              <a
                href={`tel:${branch.phone}`}
                className="text-primary hover:underline"
              >
                {branch.phone}
              </a>
            </div>
          )}
        </div>
      </div>
      </div>

      {showModal && (
        <ShopInfoModal branch={branch} onClose={() => setShowModal(false)} />
      )}
    </>
  );
}
