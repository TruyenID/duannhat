"use client";

import { X, MapPin, Clock, Phone, Star, Users } from "lucide-react";
import { useMemo } from "react";
import { useTranslations } from 'next-intl';
import type { Branch } from "@/data/brands";
import { useBranchOpenState, useNextOpeningLabel } from "@/hooks/use-branch-open-state";

interface ShopInfoModalProps {
  branch: Branch;
  onClose: () => void;
}

export function ShopInfoModal({ branch, onClose }: ShopInfoModalProps) {
  const t = useTranslations('shop');
  const tCommon = useTranslations('common');

  // #1167 — same live, weekly_hours-driven verdict as the banner (see
  // use-branch-open-state); `business_hours` is display copy, never a vote.
  const { isOpen, nextOpening } = useBranchOpenState(branch);
  const reopenLabel = useNextOpeningLabel(nextOpening);

  // PHẢI khớp logic với shop-info-banner để banner + modal hiển thị cùng
  // thông tin. Trước đây modal hardcode `isOpen = true` + giờ "11:00-22:00"
  // → user click "Thông tin" thấy giờ khác với banner.
  const currentDayStatus = useMemo(() => {
    if (!isOpen) {
      return reopenLabel ? t('closedNowUntil', { when: reopenLabel }) : t('closedNow');
    }
    // Ưu tiên `business_hours` (free-text từ DB) — wrap với i18n
    // `openHoursRaw` để có prefix "Mở cửa".
    if (branch.business_hours && branch.business_hours.trim()) {
      return t('openHoursRaw', { hours: branch.business_hours.trim() });
    }
    // Fallback `weekly_hours` JSON.
    if (!branch.weekly_hours) {
      return t('contactForHours');
    }
    const days = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"] as const;
    const todayKey = days[new Date().getDay()];
    const todayHours = branch.weekly_hours[todayKey];
    if (!todayHours || todayHours.closed) {
      return t('closedToday');
    }
    if (todayHours.open && todayHours.close) {
      return t('openHours', { open: todayHours.open, close: todayHours.close });
    }
    return t('openNow');
  }, [branch.business_hours, branch.weekly_hours, isOpen, reopenLabel, t]);

  // Hiển thị weekly_hours table (nếu có) — nguồn dữ liệu chính thức cho
  // giờ mở cửa theo tuần. Map sang label i18n + giữ thứ tự Mon-Sun.
  const weeklyHoursRows = useMemo(() => {
    if (!branch.weekly_hours) return null;
    const order = [
      { key: "mon", labelKey: "dayMon" },
      { key: "tue", labelKey: "dayTue" },
      { key: "wed", labelKey: "dayWed" },
      { key: "thu", labelKey: "dayThu" },
      { key: "fri", labelKey: "dayFri" },
      { key: "sat", labelKey: "daySat" },
      { key: "sun", labelKey: "daySun" },
    ] as const;
    return order.map(({ key, labelKey }) => {
      const day = branch.weekly_hours?.[key];
      const dayLabel = t(labelKey);
      const hours = !day || day.closed
        ? t('closedToday')
        : day.open && day.close
          ? `${day.open} - ${day.close}`
          : t('contactForHours');
      return { key, dayLabel, hours };
    });
  }, [branch.weekly_hours, t]);

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
      onClick={onClose}
    >
      <div
        className="relative w-full max-w-lg rounded-2xl bg-card shadow-2xl"
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="relative overflow-hidden rounded-t-2xl bg-gradient-to-br from-primary/20 to-accent/20 p-6">
          <button
            aria-label={tCommon('close')}
            onClick={onClose}
            className="absolute right-4 top-4 rounded-full bg-white/90 p-2 shadow-lg transition-transform hover:scale-110"
          >
            <X className="h-4 w-4" />
          </button>

          {branch.logo && (
            <div className="mb-4 flex">
              <div className="h-16 w-16 overflow-hidden rounded-full bg-white shadow-lg ring-4 ring-background">
                <img
                  src={branch.logo}
                  alt={`${branch.name} logo`}
                  className="h-full w-full object-cover"
                />
              </div>
            </div>
          )}

          {branch.brand?.name && (
            <p className="text-xs font-medium text-muted-foreground">
              {branch.brand.name}
            </p>
          )}
          <h2 className="text-2xl font-bold">{branch.name}</h2>
        </div>

        {/* Content */}
        <div className="max-h-[60vh] overflow-y-auto p-6">
          {/* Rating & Reviews */}
          {branch.review_avg_rating != null && branch.review_total_count ? (
            <div className="mb-6 flex items-center gap-4 rounded-lg bg-muted/50 p-4">
              <div className="flex items-center gap-1">
                <Star className="h-5 w-5 fill-yellow-400 text-yellow-400" />
                <span className="text-lg font-bold">{branch.review_avg_rating.toFixed(1)}</span>
              </div>
              <span className="text-sm text-muted-foreground">({branch.review_total_count} {t('reviews')})</span>
            </div>
          ) : null}

          {/* Opening Hours — đồng nhất với banner. Status string ưu tiên
              business_hours; bảng tuần (nếu có) hiển thị weekly_hours. */}
          <div className="mb-6 space-y-3">
            <h3 className="font-semibold">{t('openingHours')}</h3>
            <div className="flex items-start gap-3 rounded-lg border p-3">
              <Clock className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
              <div className="flex-1">
                <p className={isOpen ? "font-medium text-green-600" : "font-medium text-destructive"}>
                  {currentDayStatus}
                </p>
                {weeklyHoursRows && (
                  <div className="mt-2 space-y-1 text-sm text-muted-foreground">
                    {weeklyHoursRows.map((row) => (
                      <div key={row.key} className="flex items-center justify-between gap-3">
                        <span>{row.dayLabel}</span>
                        <span className="tabular-nums">{row.hours}</span>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* Address */}
          {branch.address && (
            <div className="mb-6 space-y-3">
              <h3 className="font-semibold">{t('address')}</h3>
              <div className="flex items-start gap-3 rounded-lg border p-3">
                <MapPin className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" />
                <p className="flex-1 text-sm">{branch.address}</p>
              </div>
            </div>
          )}

          {/* Phone */}
          {branch.phone && (
            <div className="mb-6 space-y-3">
              <h3 className="font-semibold">{t('phone')}</h3>
              <div className="flex items-center gap-3 rounded-lg border p-3">
                <Phone className="h-5 w-5 shrink-0 text-muted-foreground" />
                <a
                  href={`tel:${branch.phone}`}
                  className="flex-1 text-sm font-medium text-primary hover:underline"
                >
                  {branch.phone}
                </a>
              </div>
            </div>
          )}

          {/* Seat Capacity */}
          {branch.seat_capacity && (
            <div className="mb-6 space-y-3">
              <h3 className="font-semibold">{t('capacity')}</h3>
              <div className="flex items-center gap-3 rounded-lg border p-3">
                <Users className="h-5 w-5 shrink-0 text-muted-foreground" />
                <p className="flex-1 text-sm">{t('seatCount', { count: branch.seat_capacity })}</p>
              </div>
            </div>
          )}

        </div>
      </div>
    </div>
  );
}
