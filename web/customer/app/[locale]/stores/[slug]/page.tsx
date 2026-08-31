"use client";

import { useEffect, useMemo } from "react";
import { Link, useRouter } from "@/i18n/routing";
import { useParams } from "next/navigation";
import { useTranslations } from 'next-intl';
import {
  ChevronDown,
  ArrowLeft,
  Clock,
  Footprints,
  Loader2,
  MapPin,
  Navigation,
  Phone,
  ShoppingBag,
  Star,
  Store,
  UtensilsCrossed,
  Users,
} from "lucide-react";
import Header from "@/components/Header";
import StoreBanner from "@/components/store-banner";
import { Button } from "@/components/ui/button";
import { useBrand } from "@/context/brand-context";
import { useBranchOpenState } from "@/hooks/use-branch-open-state";
import { FEATURES } from "@/lib/feature-flags";
import type { WeeklyHoursMap } from "@/data/brands";

const DAYS: (keyof WeeklyHoursMap)[] = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"];
const DAY_KEY_BY_INDEX: readonly (keyof WeeklyHoursMap)[] = [
  "sun", "mon", "tue", "wed", "thu", "fri", "sat",
] as const;

const SAMPLE_REVIEWS = [
  { name: "Name", city: "Tokyo", rating: 5, comment: "The positive aspect was undoubtedly the efficiency of the service. The queue moved quickly, the staff was friendly, and the food was delicious." },
  { name: "Name", city: "Tokyo", rating: 5, comment: "The positive aspect was undoubtedly the efficiency of the service. The queue moved quickly, the staff was friendly." },
  { name: "Name", city: "Tokyo", rating: 4, comment: "Great atmosphere and tasty Vietnamese coffee. Will come back again next time." },
];

type DayEntry = { open?: string | null; close?: string | null; closed?: boolean };
type ScheduleGroup = { startDay: keyof WeeklyHoursMap; endDay: keyof WeeklyHoursMap; entry?: DayEntry; hasToday: boolean };

/** "16000" → "16K", "1234" → "1.2K", "950" → "950" */
function formatCount(n: number): string {
  if (n >= 1000) {
    const k = n / 1000;
    return `${k % 1 === 0 ? k.toFixed(0) : k.toFixed(1)}K`;
  }
  return String(n);
}

/** Gộp các ngày liên tiếp cùng giờ thành 1 dòng; ngày hôm nay tách riêng để highlight. */
function groupSchedule(weekly: WeeklyHoursMap, todayKey: keyof WeeklyHoursMap): ScheduleGroup[] {
  const groups: ScheduleGroup[] = [];
  let cur: (ScheduleGroup & { key: string }) | null = null;
  for (const d of DAYS) {
    const e = weekly[d] as DayEntry | undefined;
    const key = e?.closed ? "closed" : `${e?.open ?? ""}-${e?.close ?? ""}`;
    const isToday = d === todayKey;
    const startNew = !cur || cur.key !== key || isToday || cur.hasToday;
    if (startNew) {
      cur = { startDay: d, endDay: d, entry: e, hasToday: isToday, key };
      groups.push(cur);
    } else {
      cur!.endDay = d;
    }
  }
  return groups;
}

export default function BranchDetailPage() {
  const router = useRouter();
  const { slug } = useParams<{ slug: string }>();
  const { branches, isLoadingBranches, switchBranch } = useBrand();
  const t = useTranslations('storeDetail');
  const tCommon = useTranslations('common');
  const branch = branches.find((b) => b.slug === slug);
  const todayKey = useMemo(() => DAY_KEY_BY_INDEX[new Date().getDay()], []);
  // #1167 — "Đang mở cửa" used to mean "today isn't ticked as a day off", so a
  // shop that closed at 11:00 still read as open at 23:00. Now it is the real
  // verdict on the branch's clock, refreshed as the clock moves. Must sit
  // above the early returns below — hook order is not negotiable.
  const { isOpen: isOpenNow } = useBranchOpenState(branch);

  useEffect(() => {
    if (!isLoadingBranches && branch) switchBranch(branch.slug);
  }, [branch, isLoadingBranches, switchBranch]);

  if (isLoadingBranches) {
    return (
      <div className="flex min-h-screen flex-col">
        <Header showLogo hideSwitcher hideBranchInfo />
        <div className="flex flex-1 items-center justify-center gap-2 text-muted-foreground">
          <Loader2 className="h-5 w-5 animate-spin" />
          <span className="text-sm">{t('loading')}</span>
        </div>
      </div>
    );
  }

  if (!branch) {
    return (
      <div className="flex min-h-screen flex-col">
        <Header showLogo hideSwitcher hideBranchInfo />
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 text-center">
          <p className="font-semibold">{t('notFound')}</p>
          <p className="text-sm text-muted-foreground">{t('notFoundDesc', { slug })}</p>
          <Button variant="outline" size="sm" onClick={() => router.push("/select-branch")}>
            {t('backToList')}
          </Button>
        </div>
      </div>
    );
  }

  const todayEntry = branch.weekly_hours?.[todayKey] as DayEntry | undefined;
  const closeTime = todayEntry?.closed ? null : todayEntry?.close ?? null;
  const todayHours = todayEntry?.closed
    ? null
    : todayEntry?.open
      ? `${todayEntry.open}${todayEntry.close ? `-${todayEntry.close}` : ""}`
      : branch.business_hours ?? null;

  const dayLabel = (d: keyof WeeklyHoursMap) => t(`days.${d}`);
  const groups = branch.weekly_hours ? groupSchedule(branch.weekly_hours, todayKey) : [];

  const orderButtons = (vertical: boolean) => {
    const w = vertical ? "w-full" : "flex-1";
    return (
      <>
        <Button
          render={<Link href={`/takeaway/${branch.slug}`} />}
          nativeButton={false}
          className={`h-12 ${w} justify-center gap-2 bg-[#2D8336] text-sm font-semibold hover:bg-[#25692C]`}
        >
          <ShoppingBag className="h-4 w-4" />
          {t('takeawayMenuShort')}
        </Button>
        {/* Nút "Đặt bàn / menu tại chỗ" → /booking. Ẩn khi FEATURES.booking
            off (#47) để không còn link mồ côi tới route đã bị chặn. */}
        {FEATURES.booking && (
          <Button
            render={<Link href={`/booking?shop=${branch.slug}`} />}
            nativeButton={false}
            variant="outline"
            className={`h-12 ${w} justify-center gap-2 border-neutral-300 text-sm font-semibold`}
          >
            <UtensilsCrossed className="h-4 w-4" />
            {t('dineInMenu')}
          </Button>
        )}
      </>
    );
  };

  const mapEmbed = (
    <iframe
      title={t('storeMapTitle')}
      src="https://www.openstreetmap.org/export/embed.html?bbox=139.55%2C35.55%2C139.88%2C35.78&layer=mapnik"
      className="h-full w-full"
      loading="lazy"
    />
  );

  return (
    <div className="flex min-h-screen flex-col bg-white md:bg-neutral-50">
      <Header showLogo hideSwitcher hideBranchInfo />

      {/* Sub-header: ← tên cửa hàng */}
      <div className="border-b bg-white">
        <div className="mx-auto flex max-w-7xl items-center gap-2 px-4 py-3 md:px-6">
          <button
            aria-label={tCommon('back')}
            onClick={() => router.push("/select-branch")}
            className="-ml-1 flex size-8 items-center justify-center rounded-lg text-neutral-700 transition-colors hover:bg-muted"
          >
            <ArrowLeft className="size-5" />
          </button>
          {/* godx-tempo#1752 — bỏ tiền tố `t('store')` ở bản desktop. Tên chi
              nhánh từ API đã tự mang từ đó, nên nó ra "Cửa hàng Cửa hàng
              Ningyocho"; và với 10/17 chi nhánh không phải cửa hàng (xe bán đồ
              ăn, trụ sở, nhà máy, bếp) thì tiền tố còn sai loại hình.
              Bản mobile ngay trên đã luôn đúng — giờ hai bản dùng chung một
              cách hiển thị. */}
          <span className="truncate text-base font-bold text-neutral-900">
            {branch.name}
          </span>
        </div>
      </div>

      {/* ============================ MOBILE ============================ */}
      <div className="md:hidden">
        <main className="flex-1 pb-28">
          {/* Hero with overlay */}
          {/* #936 — banner mobile: khung co theo đúng tỉ lệ ảnh, không ép
              3:2 (ảnh mobile thật 780×384 ≈ 2:1, rộng hơn 3:2 → bị xén mép). */}
          <StoreBanner
            branch={branch}
            priority
            fallback={
              <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-primary/15 to-accent/15">
                <Store className="h-12 w-12 text-muted-foreground/30" />
              </div>
            }
          >
            <div className="absolute inset-0 bg-gradient-to-t from-black/75 via-black/20 to-transparent" />
            <div className="absolute inset-x-0 bottom-0 p-4 text-white">
              {branch.logo && (
                <div className="mb-2 size-14 overflow-hidden rounded-lg border-2 border-white/80 bg-white shadow">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={branch.logo} alt={branch.name} className="h-full w-full object-cover" />
                </div>
              )}
              <p className="text-xs font-medium opacity-90">{branch.brand.name}</p>
              <h1 className="text-2xl font-bold leading-tight">{branch.name}</h1>
              {branch.phone && (
                <p className="mt-1.5 flex items-center gap-1.5 text-xs opacity-95">
                  <Phone className="h-3.5 w-3.5 shrink-0" /> {branch.phone}
                </p>
              )}
              {branch.address && (
                <p className="mt-1 flex items-start gap-1.5 text-xs opacity-95">
                  <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0" /> {branch.address}
                </p>
              )}
            </div>
          </StoreBanner>

          {/* Stats row */}
          <div className="grid grid-cols-3 divide-x divide-neutral-200 border-b border-neutral-200">
            <Stat
              value={
                <span className="flex items-center gap-1">
                  <Star className="h-4 w-4 fill-yellow-400 text-yellow-400" />
                  {branch.review_avg_rating != null ? branch.review_avg_rating.toFixed(1) : "—"}
                </span>
              }
              label={branch.review_total_count ? `${formatCount(branch.review_total_count)} ${t('reviewsLabel')}` : t('reviewsLabel')}
            />
            <Stat
              value={<span className={isOpenNow ? "text-primary" : ""}>{isOpenNow ? t('openNow') : t('closedNow')}</span>}
              label={closeTime ? t('untilTime', { time: closeTime }) : ""}
            />
            <Stat value={branch.seat_capacity ?? "—"} label={t('tableCount')} />
          </div>

          <div className="space-y-6 px-4 py-5">
            <section>
              <h2 className="mb-2 text-lg font-bold text-neutral-900">{t('storeInfo')}</h2>
              {branch.description ? (
                <p className="text-sm leading-relaxed text-neutral-600">{branch.description}</p>
              ) : (
                <p className="text-sm text-muted-foreground">{t('noInfo')}</p>
              )}

              {groups.length > 0 && (
                <div className="mt-4 rounded-xl border border-neutral-200 p-4">
                  <div className="mb-2 flex items-center justify-between gap-2">
                    <h3 className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{t('activityScheduleShort')}</h3>
                    {todayHours && <span className="text-xs font-semibold text-primary">{t('todayInline', { hours: todayHours })}</span>}
                  </div>
                  <ul className="space-y-2 text-sm">
                    {groups.map((g, i) => (
                      <li key={i} className="flex items-center justify-between">
                        <span className={g.hasToday ? "font-bold text-primary" : "font-medium text-neutral-700"}>
                          {g.startDay === g.endDay ? dayLabel(g.startDay) : `${dayLabel(g.startDay)} - ${dayLabel(g.endDay)}`}
                          {g.hasToday && <span className="ml-1 font-normal">({t('today')})</span>}
                        </span>
                        <span className={`tabular-nums ${g.hasToday ? "font-semibold text-primary" : "text-neutral-700"}`}>
                          {scheduleValue(g.entry, t)}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </section>

            <section>
              <h2 className="mb-2 text-lg font-bold text-neutral-900">{t('location')}</h2>
              <div className="relative aspect-[16/10] w-full overflow-hidden rounded-xl border border-neutral-200 bg-muted">
                {mapEmbed}
                <div className="pointer-events-none absolute right-3 top-3 flex size-9 items-center justify-center rounded-full bg-white shadow-md">
                  <Navigation className="h-4 w-4 text-primary" />
                </div>
              </div>
              <p className="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                <Footprints className="h-3.5 w-3.5" /> {t('travelTimeHint')}
              </p>
            </section>

            <section>
              <h2 className="mb-3 text-lg font-bold text-neutral-900">{t('customerReviews')}</h2>
              <div className="-mx-4 flex gap-3 overflow-x-auto px-4 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                {SAMPLE_REVIEWS.map((r, i) => (
                  <div key={i} className="w-[260px] shrink-0">
                    <ReviewCard {...r} />
                  </div>
                ))}
              </div>
            </section>
          </div>
        </main>

        {/* Fixed bottom bar */}
        <div
          className="fixed inset-x-0 bottom-0 z-40 flex gap-3 border-t border-neutral-200 bg-white px-4 py-3 safe-area-bottom"
          style={{ boxShadow: '0px -6px 16px 0px #0000000D' }}
        >
          {orderButtons(false)}
        </div>
      </div>

      {/* ============================ DESKTOP ============================ */}
      <div className="hidden md:block">
        {/* Hero */}
        {/* #936 — banner tablet/desktop. Không ép tỉ lệ: khung cao theo đúng
            file admin upload nên ảnh hiện y như lúc preview. */}
        <StoreBanner
          branch={branch}
          priority
          fallback={
            <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-primary/15 to-accent/15">
              <Store className="h-14 w-14 text-muted-foreground/30" />
            </div>
          }
        />

        <main className="mx-auto w-full max-w-7xl px-6 pb-12">
          {/* Overlapping summary card */}
          <div className="relative -mt-14 rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm">
            {branch.logo && (
              <div className="relative -mt-16 mb-3 size-20 overflow-hidden rounded-xl border-4 border-white bg-white shadow-sm">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={branch.logo} alt={branch.name} className="h-full w-full object-cover" />
              </div>
            )}
            <p className="text-xs font-medium text-muted-foreground">{branch.brand.name}</p>
            <h1 className="mt-0.5 text-3xl font-bold leading-tight text-neutral-900">{branch.name}</h1>
            <div className="mt-3 flex flex-wrap items-center gap-x-6 gap-y-1.5 text-sm text-neutral-700">
              {branch.review_avg_rating != null && branch.review_total_count ? (
                <span className="inline-flex items-center gap-1 font-medium">
                  <Star className="h-4 w-4 fill-yellow-400 text-yellow-400" />
                  {branch.review_avg_rating.toFixed(1)}
                  <span className="text-muted-foreground">({branch.review_total_count.toLocaleString()}+)</span>
                </span>
              ) : null}
              {todayHours && (
                <span className="inline-flex items-center gap-1.5">
                  <Clock className="h-4 w-4 text-primary" /> {t('openHours', { hours: todayHours })}
                </span>
              )}
              {branch.seat_capacity != null && (
                <span className="inline-flex items-center gap-1.5">
                  <Users className="h-4 w-4 text-primary" /> {t('seatCapacityShort', { count: branch.seat_capacity })}
                </span>
              )}
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-x-6 gap-y-1.5 text-sm text-muted-foreground">
              {branch.address && (
                <span className="inline-flex items-center gap-1.5"><MapPin className="h-4 w-4 shrink-0" /> {branch.address}</span>
              )}
              {branch.phone && (
                <a href={`tel:${branch.phone}`} className="inline-flex items-center gap-1.5 hover:text-foreground"><Phone className="h-4 w-4 shrink-0" /> {branch.phone}</a>
              )}
            </div>
          </div>

          <div className="mt-6 grid grid-cols-3 gap-8">
            {/* Left column */}
            <div className="col-span-2 flex flex-col gap-6">
              {/* Thông tin cửa hàng */}
              <section className="rounded-xl border border-neutral-200 bg-white p-5">
                <h2 className="mb-2 text-base font-bold text-neutral-900">{t('storeInfo')}</h2>
                {branch.description && (
                  <p className="text-sm leading-relaxed text-muted-foreground">{branch.description}</p>
                )}
                {branch.img_branches && (
                  <div className="mt-3 grid grid-cols-3 gap-2">
                    {[0, 1, 2].map((i) => (
                      <div key={i} className="relative aspect-[4/3] overflow-hidden rounded-lg bg-muted">
                        {/* eslint-disable-next-line @next/next/no-img-element */}
                        <img src={branch.img_branches!} alt={branch.name} className="h-full w-full object-cover" />
                      </div>
                    ))}
                  </div>
                )}
                <div className="mt-4 grid grid-cols-2 gap-3">
                  {branch.address && <InfoTile icon={<MapPin className="h-4 w-4" />} label={t('addressLabel')} value={branch.address} />}
                  {branch.phone && <InfoTile icon={<Phone className="h-4 w-4" />} label={t('phoneLabel')} value={<a href={`tel:${branch.phone}`} className="hover:underline">{branch.phone}</a>} />}
                  {branch.seat_capacity != null && <InfoTile icon={<Users className="h-4 w-4" />} label={t('seatsLabel')} value={`${branch.seat_capacity} ${t('seats')}`} />}
                  {todayHours && (
                    <InfoTile
                      icon={<Clock className="h-4 w-4" />}
                      label={t('nextActivity')}
                      value={t('todayInline', { hours: todayHours })}
                      trailing={<ChevronDown className="h-4 w-4 text-muted-foreground" />}
                    />
                  )}
                </div>
              </section>

              {/* Lịch hoạt động hàng tuần (đầy đủ từng ngày) */}
              {branch.weekly_hours && (
                <section className="rounded-xl border border-neutral-200 bg-white p-5">
                  <h2 className="mb-3 text-base font-bold text-neutral-900">{t('weeklySchedule')}</h2>
                  <ul className="divide-y divide-neutral-100 text-sm">
                    {DAYS.map((d) => {
                      const entry = branch.weekly_hours?.[d] as DayEntry | undefined;
                      const isToday = d === todayKey;
                      return (
                        <li key={d} className="flex items-center justify-between py-2.5">
                          <span className={isToday ? "font-bold text-primary" : "font-medium text-neutral-700"}>
                            {dayLabel(d)}
                            {isToday && <span className="ml-2 text-xs font-normal">({t('today')})</span>}
                          </span>
                          <span className={`tabular-nums ${isToday ? "font-semibold text-primary" : "text-neutral-700"}`}>
                            {scheduleValue(entry, t)}
                          </span>
                        </li>
                      );
                    })}
                  </ul>
                </section>
              )}

              {/* Bản đồ cửa hàng */}
              <section className="overflow-hidden rounded-xl border border-neutral-200 bg-white">
                <div className="relative aspect-[16/9] w-full bg-muted">{mapEmbed}</div>
                <div className="border-t border-neutral-100 px-4 py-3">
                  <p className="text-sm font-semibold text-neutral-900">{t('storeMapTitle')}</p>
                  {branch.address && <p className="mt-0.5 text-xs text-muted-foreground">{branch.address}</p>}
                </div>
              </section>

              {/* Đánh giá của khách hàng */}
              <section>
                <h2 className="mb-3 text-base font-bold text-neutral-900">{t('customerReviews')}</h2>
                <div className="grid grid-cols-2 gap-3">
                  {SAMPLE_REVIEWS.slice(0, 2).map((r, i) => (
                    <ReviewCard key={i} {...r} />
                  ))}
                </div>
              </section>
            </div>

            {/* Right column — Đặt món ngay (sticky) */}
            <aside className="sticky top-20 h-fit">
              <div className="rounded-xl border border-neutral-200 bg-white p-5">
                <h2 className="mb-3 text-base font-bold text-neutral-900">{t('orderNow')}</h2>
                <div className="flex flex-col gap-3">{orderButtons(true)}</div>
              </div>
            </aside>
          </div>
        </main>
      </div>
    </div>
  );
}

function scheduleValue(entry: DayEntry | undefined, t: ReturnType<typeof useTranslations>): string {
  if (entry?.closed) return t('todayOff');
  if (entry?.open) return `${entry.open}${entry.close ? ` – ${entry.close}` : ""}`;
  return "—";
}

function Stat({ value, label }: { value: React.ReactNode; label: string }) {
  return (
    <div className="flex flex-col items-center justify-center gap-0.5 px-2 py-3 text-center">
      <span className="text-base font-bold text-neutral-900">{value}</span>
      {label && <span className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{label}</span>}
    </div>
  );
}

function InfoTile({
  icon,
  label,
  value,
  trailing,
}: {
  icon: React.ReactNode;
  label: string;
  value: React.ReactNode;
  trailing?: React.ReactNode;
}) {
  return (
    <div className="flex items-start gap-3 rounded-lg border border-neutral-200 bg-white p-3">
      <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">{icon}</div>
      <div className="min-w-0 flex-1">
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className="mt-0.5 text-sm font-medium text-neutral-900">{value}</p>
      </div>
      {trailing}
    </div>
  );
}

function ReviewCard({ name, city, rating, comment }: { name: string; city?: string; rating: number; comment: string }) {
  return (
    <div className="h-full rounded-xl border border-neutral-200 bg-white p-4">
      <div className="flex items-center gap-3">
        <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
          <span className="text-xs font-semibold">{name.slice(0, 1)}</span>
        </div>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold text-neutral-900">{name}</p>
          {city && <p className="text-xs text-primary">{city}</p>}
        </div>
        <div className="flex shrink-0 items-center gap-0.5">
          {Array.from({ length: 5 }).map((_, i) => (
            <Star key={i} className={`h-3.5 w-3.5 ${i < rating ? "fill-yellow-400 text-yellow-400" : "fill-neutral-200 text-neutral-200"}`} />
          ))}
        </div>
      </div>
      <p className="mt-2.5 text-xs leading-relaxed text-muted-foreground line-clamp-3">{comment}</p>
    </div>
  );
}
