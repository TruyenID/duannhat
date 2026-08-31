"use client";

import { useEffect, useState } from "react";
import { useRouter } from "@/i18n/routing";
import { useTranslations, useLocale } from "next-intl";
import { useAuth } from "@/context/auth-context";
import { apiFetch } from "@/lib/api";
import Header from "@/components/Header";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Check, Crown, Lock } from "lucide-react";

interface Tier {
  key: string;
  min_lifetime_points: number;
  benefits: string[];
}

interface MembershipPayload {
  lifetime_points: number;
  balance: number;
  current_tier: Tier | null;
  next_tier: Tier | null;
  points_to_next_tier: number | null;
  tiers: Tier[];
}

export default function AccountMembershipView() {
  const { isLoggedIn, isLoading } = useAuth();
  const router = useRouter();
  const t = useTranslations("membership");
  const locale = useLocale();

  const [data, setData] = useState<MembershipPayload | null>(null);
  const [fetching, setFetching] = useState(true);

  useEffect(() => {
    if (!isLoggedIn) return;
    apiFetch<{ data: MembershipPayload }>("/api/v1/customer/me/membership", {
      silent401: true,
    })
      .then(({ data }) => setData(data))
      .catch(() => setData(null))
      .finally(() => setFetching(false));
  }, [isLoggedIn]);

  useEffect(() => {
    if (!isLoading && !isLoggedIn) {
      router.replace("/login?redirect=/account/membership");
    }
  }, [isLoading, isLoggedIn, router]);

  if (isLoading || fetching) {
    return (
      <>
        <Header showBack hideSwitcher showLogo />
        <div className="flex flex-1 items-center justify-center py-20">
          <span className="h-5 w-5 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>
      </>
    );
  }

  if (!isLoggedIn) return null;

  if (!data) {
    return (
      <>
        <Header showBack hideSwitcher showLogo />
        <main className="flex flex-1 flex-col items-center bg-muted/30 px-4 py-6">
          <Card className="w-full max-w-[480px] p-6 text-center text-sm text-muted-foreground">
            {t("unavailable")}
          </Card>
        </main>
      </>
    );
  }

  const currentKey = data.current_tier?.key;

  return (
    <>
      <Header showBack hideSwitcher showLogo />

      <main className="flex flex-1 flex-col items-center bg-muted/30 px-4 py-6">
        <div className="w-full max-w-[480px] space-y-3">
          {/* Hạng hiện tại */}
          <Card className="rounded-xl p-4">
            <div className="flex items-center gap-2">
              <Crown className="h-5 w-5 shrink-0 text-primary" />
              <div className="min-w-0 flex-1">
                <p className="text-xs text-muted-foreground">
                  {t("currentTier")}
                </p>
                <p className="truncate text-lg font-bold leading-tight">
                  {currentKey ? t(`tiers.${currentKey}`) : "—"}
                </p>
              </div>
            </div>

            <p className="mt-3 text-xs text-muted-foreground">
              {t("lifetime", {
                points: data.lifetime_points.toLocaleString(locale),
              })}
            </p>

            {data.next_tier && data.points_to_next_tier !== null && (
              <p className="mt-1 text-xs text-muted-foreground">
                {t("toNextTier", {
                  points: data.points_to_next_tier.toLocaleString(locale),
                  tier: t(`tiers.${data.next_tier.key}`),
                })}
              </p>
            )}
          </Card>

          {/* Thang hạng — cả đường đi, không chỉ chỗ đang đứng */}
          {data.tiers.map((tier) => {
            const reached = data.lifetime_points >= tier.min_lifetime_points;
            const isCurrent = tier.key === currentKey;

            return (
              <Card
                key={tier.key}
                className={`rounded-xl p-4 ${isCurrent ? "border-primary" : ""} ${
                  reached ? "" : "opacity-70"
                }`}
              >
                <div className="flex items-center gap-2">
                  <span className="flex-1 text-sm font-semibold">
                    {t(`tiers.${tier.key}`)}
                  </span>
                  {isCurrent ? (
                    <Badge className="text-[10px]">{t("badgeCurrent")}</Badge>
                  ) : reached ? (
                    <Badge variant="secondary" className="text-[10px]">
                      {t("badgeReached")}
                    </Badge>
                  ) : (
                    <Badge variant="secondary" className="text-[10px]">
                      {t("requires", {
                        points: tier.min_lifetime_points.toLocaleString(locale),
                      })}
                    </Badge>
                  )}
                </div>

                <ul className="mt-3 space-y-1.5">
                  {tier.benefits.map((benefit) => (
                    <li
                      key={benefit}
                      className="flex items-start gap-2 text-sm text-muted-foreground"
                    >
                      {reached ? (
                        <Check className="mt-0.5 h-3.5 w-3.5 shrink-0 text-primary" />
                      ) : (
                        <Lock className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                      )}
                      <span>{t(`benefits.${benefit}`)}</span>
                    </li>
                  ))}
                </ul>
              </Card>
            );
          })}
        </div>
      </main>
    </>
  );
}
