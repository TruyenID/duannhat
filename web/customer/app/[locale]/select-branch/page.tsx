"use client";

import { Suspense, useMemo, useState } from "react";
import { useRouter } from "@/i18n/routing";
import { useSearchParams } from "next/navigation";
import { useTranslations } from 'next-intl';
import {
  AlertCircle,
  ArrowLeft,
  Clock,
  Loader2,
  MapPin,
  Search,
  SearchX,
  UtensilsCrossed,
  Star,
  X,
} from "lucide-react";
import Header from "@/components/Header";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { useBrand } from "@/context/brand-context";
import { useCart } from "@/context/cart-context";
import { FEATURES } from "@/lib/feature-flags";
import { shopScopedHref, type ShopScopedFlow } from "@/lib/shop-routes";
import type { Branch } from "@/data/brands";

type NextFlow = "takeaway" | "booking" | ShopScopedFlow;

// `?next=booking` chỉ được chấp nhận khi FEATURES.booking on (#47). Khi off,
// link booking đã bị gỡ khỏi UI nhưng URL cũ (bookmark, chia sẻ) vẫn có thể
// tới đây — coi như không có `next` và rơi về flow mặc định `/stores/[slug]`.
//
// login/register/account đến từ guard "URL phải có cửa hàng" (#1505): khách gõ
// `/login` trần thì middleware đá về đây kèm `?next=login`, chọn xong cửa hàng
// là quay lại đúng khu vừa muốn vào. Cũng gác theo FEATURES.auth để khi tắt
// auth thì không còn đường nào dẫn ngược vào khu đã ẩn.
function isNextFlow(v: string | null): v is NextFlow {
  if (v === "takeaway") return true;
  if (v === "booking") return FEATURES.booking;
  if (v === "login" || v === "register" || v === "account") return FEATURES.auth;
  return false;
}

function isShopScopedFlow(v: NextFlow | null): v is ShopScopedFlow {
  return v === "login" || v === "register" || v === "account";
}

function normalize(text: string): string {
  return text
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/đ/g, "d")
    .replace(/Đ/g, "d");
}


function SelectBranchBody() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const rawNext = searchParams.get("next");
  const next: NextFlow | null = isNextFlow(rawNext) ? rawNext : null;
  const t = useTranslations('selectBranch');
  const tc = useTranslations('common');
  const ts = useTranslations('switcher');

  const { branches, currentBranch, isLoadingBranches, branchesError, refetchBranches, switchBranch } = useBrand();
  const { totalItems, clearCart } = useCart();
  const [search, setSearch] = useState("");
  const [pendingBranch, setPendingBranch] = useState<Branch | null>(null);

  const filtered: Branch[] = useMemo(() => {
    const q = normalize(search.trim());
    if (!q) return branches;
    return branches.filter((b) =>
      [b.name, b.code ?? "", b.brand.name, b.address ?? ""].some((s) =>
        normalize(s).includes(q),
      ),
    );
  }, [branches, search]);

  function navigateToBranch(slug: string) {
    switchBranch(slug);
    if (next === "takeaway") {
      router.push(`/takeaway/${slug}`);
    } else if (next === "booking") {
      router.push("/booking");
    } else if (isShopScopedFlow(next)) {
      router.push(shopScopedHref(next, slug));
    } else {
      router.push(`/stores/${slug}`);
    }
  }

  function handlePick(branch: Branch) {
    if (branch.slug !== currentBranch.slug && totalItems > 0) {
      setPendingBranch(branch);
      return;
    }
    navigateToBranch(branch.slug);
  }

  function confirmSwitch() {
    if (!pendingBranch) return;
    const slug = pendingBranch.slug;
    clearCart();
    setPendingBranch(null);
    navigateToBranch(slug);
  }

  // Always use MapPin icon with emerald color
  const flowIcon = <MapPin className="h-6 w-6 text-emerald-600" />;

  const flowLabel =
    next === "takeaway"
      ? t('orderTakeaway')
      : next === "booking"
        ? t('bookTable')
        : t('selectStore');

  return (
    <>
      {/* Header — mobile: style takeaway checkout (back + logo + lang + login).
          `hideShadow` để bỏ gạch ngang giữa header chính và sub-header trên mobile.
          Desktop: giữ nguyên markup cũ.

          Quan trọng: wrapper dùng `contents` (display: contents) thay vì `block`
          để wrapper không tạo block layout — sticky bên trong Header
          (position: sticky top-0) sẽ pin theo viewport chứ không bị wrapper giới hạn
          (block wrapper chỉ cao = Header → cuộn xuống là Header trôi theo). */}
      <div className="contents md:hidden">
        <Header hideSwitcher showLogo hideOrderCta hideShadow hideRegister />
      </div>
      <div className="hidden md:contents">
        <Header showLogo hideSwitcher hideBranchInfo hideRegister />
      </div>

      {/* Sub-header "← Chọn cửa hàng". Mobile: sticky ngay dưới global header
          (top-12 = 48px) + border-b — match pattern checkout-page-mobile.
          Desktop: static, no border (giữ nguyên theo yêu cầu chỉ-mobile). */}
      <div className="sticky top-12 z-30 border-b border-neutral-200 bg-white md:static md:top-auto md:z-auto md:border-b-0 md:bg-transparent">
        <div className="mx-auto flex max-w-7xl items-center gap-2 px-4 py-3 md:px-6 md:py-4">
          <button
            aria-label={tc('back')}
            onClick={() => router.back()}
            className="-ml-1 flex size-8 items-center justify-center rounded-lg text-neutral-700 transition-colors hover:bg-muted"
          >
            <ArrowLeft className="size-5" />
          </button>
          <h1 className="text-lg font-bold text-neutral-900">{t('selectStore')}</h1>
        </div>
      </div>

      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 md:px-6 md:pt-0">
        {isLoadingBranches && (
          <div className="flex items-center justify-center gap-2 py-12 text-muted-foreground">
            <Loader2 className="h-5 w-5 animate-spin" />
            <span className="text-sm">{t('loading')}</span>
          </div>
        )}

        {!isLoadingBranches && branchesError && (
          <div className="flex flex-col items-center gap-3 rounded-lg border border-red-200 bg-red-50 p-6 text-center text-sm text-red-700">
            <AlertCircle className="h-5 w-5" />
            <p>{t('loadError')}</p>
            <Button variant="outline" size="sm" onClick={refetchBranches}>
              {tc('retry')}
            </Button>
          </div>
        )}

        {!isLoadingBranches && !branchesError && (
          <div className="grid gap-5 md:grid-cols-2 anim-enter">
            {/* Left column: Title + Map */}
            <div className="order-2 md:order-1 flex flex-col gap-4">
              {/* Title with icon — ẩn hoàn toàn theo yêu cầu (cả mobile lẫn
                  desktop). Title đã có ở sub-header `← Chọn cửa hàng` rồi. */}
              <div className="hidden items-center gap-3">
                <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-emerald-50">
                  {flowIcon}
                </div>
                <div>
                  <h1 className="text-xl font-bold leading-tight">{t('title')}</h1>
                  <p className="text-sm text-muted-foreground">{flowLabel}</p>
                </div>
              </div>

              {/* Map */}
              <MapPlaceholder />
            </div>

            {/* Right column: Search + List */}
            <div className="order-1 md:order-2 flex flex-col gap-4">
              {/* Search box — always rendered so the user can edit/clear their query */}
              <div className="relative">
                <Search className="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  placeholder={t('searchPlaceholder')}
                  className="h-11 pl-11 pr-12 rounded-xl border-border/60 bg-muted/30 focus:bg-white transition-colors"
                  aria-label={t('searchPlaceholder')}
                />
                {search && (
                  <button
                    type="button"
                    onClick={() => setSearch("")}
                    aria-label={tc('clear')}
                    className="absolute right-3 top-1/2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                  >
                    <X className="h-4 w-4" />
                  </button>
                )}
              </div>

              {/* List or empty state */}
              {filtered.length > 0 ? (
                <ul className="flex flex-col gap-3 anim-stagger">
                  {filtered.map((branch) => (
                    <li key={branch.id}>
                      <BranchCard branch={branch} onPick={() => handlePick(branch)} />
                    </li>
                  ))}
                </ul>
              ) : (
                <div className="flex min-h-[280px] flex-col items-center justify-center gap-4 rounded-2xl border border-dashed border-border/70 bg-muted/20 px-6 py-12 text-center anim-enter">
                  <div className="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50">
                    <SearchX className="h-8 w-8 text-emerald-600/70" />
                  </div>
                  <div className="flex flex-col gap-1.5">
                    <p className="text-base font-semibold text-foreground">
                      {search ? t('noMatch') : t('noBranch')}
                    </p>
                    {search && (
                      <p className="max-w-[280px] truncate text-sm text-muted-foreground">
                        {t('searchedFor')} &ldquo;{search}&rdquo;
                      </p>
                    )}
                  </div>
                  {search && (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setSearch("")}
                      className="mt-1 gap-1.5 rounded-full"
                    >
                      <X className="h-4 w-4" />
                      {tc('clear')}
                    </Button>
                  )}
                </div>
              )}
            </div>
          </div>
        )}
      </main>

      <Dialog open={pendingBranch !== null} onOpenChange={(open) => !open && setPendingBranch(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{ts('switchTitle')}</DialogTitle>
            <DialogDescription>{ts('switchDesc', { count: totalItems })}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setPendingBranch(null)}>
              {tc('cancel')}
            </Button>
            <Button variant="destructive" onClick={confirmSwitch}>
              {ts('confirmSwitch')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function MapPlaceholder() {
  const t = useTranslations('selectBranch');
  return (
    <div className="relative h-[280px] overflow-hidden rounded-xl border bg-muted md:sticky md:top-20 md:h-[560px]">
      <iframe
        title={t('storeMap')}
        src="https://www.openstreetmap.org/export/embed.html?bbox=139.55%2C35.55%2C139.88%2C35.78&layer=mapnik"
        className="h-full w-full"
        loading="lazy"
      />
      <div className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/10 to-transparent" />
      <div className="absolute bottom-3 left-3 right-3 rounded-md bg-white/95 px-3 py-2 text-xs text-muted-foreground shadow">
        <p className="font-medium text-foreground">{t('storeMap')}</p>
        <p className="mt-0.5">{t('mapHint')}</p>
      </div>
    </div>
  );
}

function BranchCard({ branch, onPick }: { branch: Branch; onPick: () => void }) {
  const t = useTranslations('selectBranch');
  const hours = (branch as unknown as { opening_hours?: string | null }).opening_hours ?? "11:00 - 22:00";

  // Mock data (replace with real data from API)
  const rating = 4.6;
  const reviewCount = 15000;
  const seats = 36;

  return (
    <button
      type="button"
      onClick={onPick}
      className="group relative flex w-full items-center justify-between gap-4 rounded-2xl border border-border/60 bg-white p-5 text-left shadow-sm transition-all hover:border-emerald-500/40 hover:shadow-md"
    >
      {/* Left: Info */}
      <div className="flex-1 flex flex-col gap-3 min-w-0">
        {/* Store name + subtitle */}
        <div>
          <h3 className="text-base font-bold leading-tight text-foreground">
            {branch.name}
          </h3>
          <p className="text-xs text-muted-foreground mt-1">{branch.brand.name}</p>
        </div>

        {/* Stats row: Rating + Hours + Seats */}
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs">
          {/* Rating */}
          <div className="flex items-center gap-1.5">
            <Star className="h-4 w-4 fill-yellow-400 text-yellow-400" />
            <span className="font-semibold text-foreground">{rating}</span>
            <span className="text-muted-foreground">({reviewCount.toLocaleString()}+)</span>
          </div>

          {/* Hours - emerald green */}
          <div className="flex items-center gap-1.5 text-emerald-600">
            <Clock className="h-4 w-4" />
            <span className="font-medium">{t('open')} {hours}</span>
          </div>

          {/* Seats */}
          <div className="flex items-center gap-1.5 text-muted-foreground">
            <UtensilsCrossed className="h-4 w-4" />
            <span>{t('seats')}: {seats} {t('seatUnit')}</span>
          </div>
        </div>

        {/* Address */}
        {branch.address && (
          <div className="flex items-start gap-2 text-muted-foreground">
            <MapPin className="h-4 w-4 mt-0.5 shrink-0" />
            <span className="text-xs leading-relaxed">{branch.address}</span>
          </div>
        )}
      </div>

      {/* Right: Banner image with distance below */}
      <div className="flex flex-col items-center gap-2 flex-shrink-0">
        <div className="h-28 w-28 overflow-hidden rounded-xl bg-muted">
          {branch.img_branches ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={branch.img_branches}
              alt={branch.name}
              className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
            />
          ) : (
            <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-emerald-100 to-emerald-50">
              <span className="text-[28px] font-bold text-emerald-600/80">
                {branch.name.trim().charAt(0).toUpperCase()}
              </span>
            </div>
          )}
        </div>
       </div>
    </button>
  );
}

export default function SelectBranchPage() {
  return (
    <Suspense
      fallback={
        <div className="flex min-h-screen items-center justify-center text-muted-foreground">
          <Loader2 className="h-5 w-5 animate-spin" />
        </div>
      }
    >
      <SelectBranchBody />
    </Suspense>
  );
}
