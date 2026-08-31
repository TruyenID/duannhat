"use client";

import { useEffect, useState } from "react";
import { Link, useRouter } from "@/i18n/routing";
import { useParams } from "next/navigation";
import { accountHref } from "@/lib/shop-routes";
import { useTranslations, useLocale } from "next-intl";
import { useAuth } from "@/context/auth-context";
import { apiFetch } from "@/lib/api";
import Header from "@/components/Header";
import { Crown, Gem, Medal, Sparkles } from "lucide-react";

// ---------------------------------------------------------------------------
// Membership payload — cùng shape với /account/membership (#1441).
// `balance` là ĐIỂM KHẢ DỤNG, không phải tiền: xem CustomerPointService::balance().
// ---------------------------------------------------------------------------
interface Tier {
  key: string;
  min_lifetime_points: number;
  benefits: string[];
  /** #1772 — ảnh nền thẻ do HQ đặt cho hạng này. null = chưa cấu hình. */
  background_image_url?: string | null;
}

interface MembershipPayload {
  lifetime_points: number;
  balance: number;
  current_tier: Tier | null;
  next_tier: Tier | null;
  points_to_next_tier: number | null;
  tiers: Tier[];
}

/** Icon cho từng hạng. Khoá hạng do `config/loyalty.php` định nghĩa; hạng lạ
    rơi về `Sparkles` thay vì vỡ layout. */
const TIER_ICONS: Record<string, React.ElementType> = {
  bronze: Sparkles,
  silver: Medal,
  gold: Crown,
  platinum: Gem,
};

// ---------------------------------------------------------------------------
// Thanh tiến độ hạng — vẽ CẢ thang từ `data.tiers`, không hardcode mốc nào.
// Track chỉ chạy từ tâm cột đầu tới tâm cột cuối nên nhãn hai đầu không tràn.
// ---------------------------------------------------------------------------
function TierProgress({
  tiers,
  lifetimePoints,
  currentKey,
  locale,
  tierName,
}: {
  tiers: Tier[];
  lifetimePoints: number;
  currentKey?: string;
  locale: string;
  tierName: (key: string) => string;
}) {
  const n = tiers.length;
  if (n === 0) return null;

  const seg = n > 1 ? 100 / (n - 1) : 100;
  const idx = tiers.findIndex((tier) => tier.key === currentKey);

  // Phần đã đi được: tới mốc hạng hiện tại, cộng phần lẻ đang tiến về mốc kế.
  let pct = 0;
  if (idx >= 0) {
    pct = idx * seg;
    const next = tiers[idx + 1];
    if (next) {
      const span = next.min_lifetime_points - tiers[idx].min_lifetime_points;
      if (span > 0) {
        const into = Math.min(1, Math.max(0, (lifetimePoints - tiers[idx].min_lifetime_points) / span));
        pct += into * seg;
      }
    }
  }

  const columns = { gridTemplateColumns: `repeat(${n}, minmax(0, 1fr))` };
  // Tâm cột đầu = 50/n%, tâm cột cuối = 100 - 50/n% ⇒ bề rộng track = 100 - 100/n%.
  const trackWidth = `calc(100% - ${100 / n}%)`;

  return (
    <div>
      <div className="grid" style={columns}>
        {tiers.map((tier) => {
          const Icon = TIER_ICONS[tier.key] ?? Sparkles;
          const isCurrent = tier.key === currentKey;
          return (
            <div key={tier.key} className="flex flex-col items-center gap-1 px-1">
              <Icon className={`size-4 ${isCurrent ? "text-primary" : "text-neutral-400"}`} />
              <span
                className={`truncate text-[11px] ${
                  isCurrent ? "font-bold text-neutral-900" : "text-neutral-500"
                }`}
              >
                {tierName(tier.key)}
              </span>
            </div>
          );
        })}
      </div>

      <div className="relative mx-auto my-2 h-2 rounded-full bg-neutral-200" style={{ width: trackWidth }}>
        <div
          className="absolute inset-y-0 left-0 rounded-full bg-primary transition-[width]"
          style={{ width: `${pct}%` }}
        />
        {tiers.map((tier, i) => {
          const reached = lifetimePoints >= tier.min_lifetime_points;
          return (
            <span
              key={tier.key}
              aria-hidden="true"
              className={`absolute top-1/2 size-2 -translate-x-1/2 -translate-y-1/2 rounded-full ${
                reached ? "bg-white ring-2 ring-primary" : "bg-neutral-300"
              }`}
              style={{ left: `${i * seg}%` }}
            />
          );
        })}
      </div>

      <div className="grid" style={columns}>
        {tiers.map((tier) => (
          <span key={tier.key} className="text-center text-[11px] tabular-nums text-neutral-500">
            {tier.min_lifetime_points.toLocaleString(locale)}
          </span>
        ))}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// AccountView — vỏ 2 cột: sidebar điều hướng + panel "Khách hàng thành viên".
// Các mục sidebar trỏ sang route sẵn có, deep-link giữ nguyên (#1482).
// ---------------------------------------------------------------------------
export default function AccountView() {
  const { isLoggedIn, isLoading, user } = useAuth();
  // Cửa hàng đang xem, từ segment `[shop]` của URL (#1505).
  const { shop } = useParams<{ shop?: string }>();
  const router = useRouter();
  const t = useTranslations("account");
  const tm = useTranslations("membership");
  const locale = useLocale();

  const [data, setData] = useState<MembershipPayload | null>(null);
  const [fetching, setFetching] = useState(true);

  // Auth guard
  useEffect(() => {
    if (!isLoading && !isLoggedIn) {
      router.replace("/login?redirect=/account");
    }
  }, [isLoading, isLoggedIn, router]);

  useEffect(() => {
    if (!isLoggedIn) return;
    apiFetch<{ data: MembershipPayload }>("/api/v1/customer/me/membership", { silent401: true })
      .then(({ data }) => setData(data))
      .catch(() => setData(null))
      .finally(() => setFetching(false));
  }, [isLoggedIn]);

  if (isLoading) {
    return (
      <div className="flex min-h-screen flex-col bg-[#FAFAFA]">
        <Header showLogo hideSwitcher hideOrderCta hideShadow hideRegister />
        <div className="flex flex-1 items-center justify-center">
          <span className="size-5 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>
      </div>
    );
  }

  if (!isLoggedIn || !user) return null;

  const currentKey = data?.current_tier?.key;
  const tierName = (key: string) => tm(`tiers.${key}`);

  // #1772 — ảnh nền thẻ do HQ đặt cho hạng hiện tại. Không có thì giữ nguyên
  // gradient vàng: brand chưa cấu hình vẫn phải ra một tấm thẻ tử tế.
  //
  // Có ảnh thì đảo luôn cả bảng màu chữ sang trắng-trên-scrim-tối. Giữ chữ đen
  // và chỉ đắp ảnh vào là canh bạc với từng tấm ảnh HQ upload — một tấm tối màu
  // là tên khách và số điểm biến mất, mà lỗi đó chỉ lộ ra ở máy của khách.
  const cardBackground = data?.current_tier?.background_image_url ?? null;

  return (
    <>
      <h2 className="text-xl font-bold text-primary">{t("navMembership")}</h2>

            {fetching ? (
              <div className="flex items-center justify-center py-20">
                <span className="size-5 animate-spin rounded-full border-2 border-primary border-t-transparent" />
              </div>
            ) : !data ? (
              <p className="py-20 text-center text-sm text-neutral-500">{tm("unavailable")}</p>
            ) : (
              <div className="mx-auto mt-6 max-w-md space-y-6">
                {/* Thẻ thành viên */}
                <div
                  className="relative overflow-hidden rounded-xl bg-gradient-to-br from-[#F0DCA9] via-[#DFC084] to-[#C9A45E] bg-cover bg-center p-5 shadow-sm"
                  style={
                    cardBackground ? { backgroundImage: `url(${cardBackground})` } : undefined
                  }
                >
                  {cardBackground ? (
                    /* Scrim — bảo đảm tương phản bất kể HQ upload ảnh nào. */
                    <span
                      aria-hidden="true"
                      className="pointer-events-none absolute inset-0 bg-gradient-to-tr from-black/65 via-black/35 to-black/10"
                    />
                  ) : (
                    <>
                      {/* Vân sóng trang trí — thuần CSS, không cần asset. Chỉ vẽ
                          khi dùng nền mặc định; đè lên ảnh thật thì thành rác. */}
                      <span
                        aria-hidden="true"
                        className="pointer-events-none absolute -bottom-16 -right-8 size-56 rounded-full border-[16px] border-white/20"
                      />
                      <span
                        aria-hidden="true"
                        className="pointer-events-none absolute -bottom-24 right-10 size-56 rounded-full border-[16px] border-white/10"
                      />
                    </>
                  )}
                  <div className="relative">
                    {currentKey && (
                      <span
                        className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide ${
                          cardBackground ? "bg-black/45 text-white" : "bg-white/45 text-neutral-800"
                        }`}
                      >
                        {tm("cardBadge", { tier: tierName(currentKey) })}
                        <Crown className="size-3" />
                      </span>
                    )}
                    <p
                      className={`mt-3 truncate text-xl font-bold ${
                        cardBackground ? "text-white drop-shadow" : "text-neutral-900"
                      }`}
                    >
                      {user.name}
                    </p>
                    <p
                      className={`text-xl font-bold ${
                        cardBackground ? "text-white drop-shadow" : "text-neutral-900"
                      }`}
                    >
                      {data.lifetime_points.toLocaleString(locale)}{" "}
                      <span className={cardBackground ? "text-red-300" : "text-red-600"}>
                        {tm("pointUnit")}
                      </span>
                    </p>
                  </div>
                </div>

                {/* ORIGIN Points */}
                <div>
                  <h3 className="text-sm font-bold text-neutral-900">{tm("pointsBrand")}</h3>
                  <p className="mt-1 text-xs text-neutral-500">{tm("pointsNote")}</p>

                  <p className="mt-4 text-sm text-neutral-700">
                    {tm.rich("progressLabel", {
                      points: data.lifetime_points.toLocaleString(locale),
                      b: (chunks) => <span className="font-bold text-neutral-900">{chunks}</span>,
                    })}
                  </p>

                  <div className="mt-3">
                    <TierProgress
                      tiers={data.tiers}
                      lifetimePoints={data.lifetime_points}
                      currentKey={currentKey}
                      locale={locale}
                      tierName={tierName}
                    />
                  </div>

                  {data.next_tier && data.points_to_next_tier !== null && (
                    <p className="mt-4 text-center text-xs text-neutral-600">
                      {tm("toNextTier", {
                        points: data.points_to_next_tier.toLocaleString(locale),
                        tier: tierName(data.next_tier.key),
                      })}
                    </p>
                  )}
                  {currentKey && (
                    <p className="mt-1 text-center text-xs">
                      <Link href={accountHref(shop, "membership")} className="text-primary underline-offset-2 hover:underline">
                        {tm("benefitsLink", { tier: tierName(currentKey) })}
                      </Link>
                    </p>
                  )}
                </div>

                {/* CTA */}
                <div className="space-y-3">
                  <Link
                    href={accountHref(shop, "coupons")}
                    className="flex h-12 w-full items-center justify-center rounded-lg bg-[#EFB914] text-base font-bold text-white transition-colors hover:bg-[#DCA90E]"
                  >
                    {t("coupons")}
                  </Link>
                  <Link
                    href={accountHref(shop, "points")}
                    className="flex h-12 w-full items-center justify-center rounded-lg bg-primary text-base font-bold text-primary-foreground transition-colors hover:opacity-90"
                  >
                    {tm("redeemCta")}
                  </Link>
                </div>

                {/* Điểm khả dụng — `balance` là ĐIỂM, không phải tiền (#1482). */}
                <div className="border-t border-neutral-100 pt-5">
                  <h3 className="text-sm font-bold text-neutral-900">{tm("availableTitle")}</h3>
                  <p className="mt-1 text-2xl font-bold text-neutral-900 tabular-nums">
                    {data.balance.toLocaleString(locale)}{" "}
                    <span className="text-base font-medium text-neutral-500">{tm("pointUnitPlain")}</span>
                  </p>
                  <p className="mt-1 text-xs text-neutral-500">{tm("availableNote")}</p>
                </div>
              </div>
            )}
    </>
  );
}
