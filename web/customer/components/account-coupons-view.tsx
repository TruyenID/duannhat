"use client";

import { useEffect, useState } from "react";
import { useRouter } from "@/i18n/routing";
import { useTranslations, useLocale } from "next-intl";
import { useAuth } from "@/context/auth-context";
import { apiFetch } from "@/lib/api";
import { formatGuestDate } from "@/lib/date-format";
import Header from "@/components/Header";
import { Card } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { Copy, TicketPercent } from "lucide-react";

// ---------------------------------------------------------------------------
// Khớp GET /api/v1/customer/me/coupons
// ---------------------------------------------------------------------------

interface WalletCoupon {
  id: string;
  code: string;
  name: string | null;
  description: string | null;
  discount_type: "fixed" | "percent";
  discount_value: string | number;
  min_order_subtotal: string | number | null;
  valid_until: string | null;
  from_point_reward: boolean;
}

interface UsedCoupon {
  id: string;
  code: string | null;
  name: string | null;
  discount_applied_amount: string | number;
  redeemed_at: string | null;
  order_code: string | null;
}

interface Wallet {
  available: WalletCoupon[];
  expired: WalletCoupon[];
  used: UsedCoupon[];
}

type TabKey = "available" | "used" | "expired";

export default function AccountCouponsView() {
  const { isLoggedIn, isLoading } = useAuth();
  const router = useRouter();
  const t = useTranslations("coupons");
  const locale = useLocale();

  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [fetching, setFetching] = useState(true);
  const [tab, setTab] = useState<TabKey>("available");

  useEffect(() => {
    if (!isLoggedIn) return;
    apiFetch<{ data: Wallet }>("/api/v1/customer/me/coupons", {
      silent401: true,
    })
      .then(({ data }) => setWallet(data))
      .catch(() => setWallet(null))
      .finally(() => setFetching(false));
  }, [isLoggedIn]);

  useEffect(() => {
    if (!isLoading && !isLoggedIn) {
      router.replace("/login?redirect=/account/coupons");
    }
  }, [isLoading, isLoggedIn, router]);

  async function copyCode(code: string) {
    try {
      await navigator.clipboard?.writeText(code);
      toast.success(t("copied"));
    } catch {
      // clipboard không dùng được (insecure context) — bỏ qua
    }
  }

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

  const lists: Record<TabKey, number> = {
    available: wallet?.available.length ?? 0,
    used: wallet?.used.length ?? 0,
    expired: wallet?.expired.length ?? 0,
  };

  return (
    <>
      <Header showBack hideSwitcher showLogo />

      <main className="flex flex-1 flex-col items-center bg-muted/30 px-4 py-6">
        <div className="w-full max-w-[480px] space-y-3">
          {/* Chuyển nhóm */}
          <div className="flex gap-1 rounded-xl bg-muted p-1">
            {(["available", "used", "expired"] as TabKey[]).map((key) => (
              <button
                key={key}
                type="button"
                onClick={() => setTab(key)}
                className={`flex-1 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors ${
                  tab === key
                    ? "bg-background shadow-sm"
                    : "text-muted-foreground hover:text-foreground"
                }`}
              >
                {t(`tab.${key}`)} ({lists[key]})
              </button>
            ))}
          </div>

          {tab === "used" ? (
            wallet && wallet.used.length > 0 ? (
              wallet.used.map((row) => (
                <Card key={row.id} className="rounded-xl p-4">
                  <div className="flex items-center gap-2">
                    <TicketPercent className="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span className="truncate text-sm font-medium">
                      {row.name ?? row.code ?? "—"}
                    </span>
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {row.redeemed_at
                      ? formatGuestDate(row.redeemed_at, locale)
                      : ""}
                    {row.order_code ? ` · ${row.order_code}` : ""}
                  </p>
                </Card>
              ))
            ) : (
              <EmptyState text={t("emptyUsed")} />
            )
          ) : null}

          {tab !== "used"
            ? (() => {
                const items =
                  tab === "available"
                    ? (wallet?.available ?? [])
                    : (wallet?.expired ?? []);

                if (items.length === 0) {
                  return (
                    <EmptyState
                      text={
                        tab === "available" ? t("emptyAvailable") : t("emptyExpired")
                      }
                    />
                  );
                }

                return items.map((coupon) => (
                  <Card
                    key={coupon.id}
                    className={`rounded-xl p-4 ${tab === "expired" ? "opacity-60" : ""}`}
                  >
                    <div className="flex items-start gap-2">
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold">
                          {coupon.name ?? coupon.code}
                        </p>
                        {coupon.description && (
                          <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                            {coupon.description}
                          </p>
                        )}
                      </div>
                      {coupon.from_point_reward && (
                        <Badge variant="secondary" className="shrink-0 text-[10px]">
                          {t("fromPoints")}
                        </Badge>
                      )}
                    </div>

                    <div className="mt-3 flex items-center gap-2 rounded-lg bg-muted px-3 py-2">
                      <code className="min-w-0 flex-1 truncate font-mono text-sm font-semibold tracking-wider">
                        {coupon.code}
                      </code>
                      {tab === "available" && (
                        <Button
                          aria-label={t('copyCode')}
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7 shrink-0"
                          onClick={() => copyCode(coupon.code)}
                        >
                          <Copy className="h-3.5 w-3.5" />
                        </Button>
                      )}
                    </div>

                    {coupon.valid_until && (
                      <p className="mt-2 text-xs text-muted-foreground">
                        {t("validUntil", {
                          date: formatGuestDate(coupon.valid_until, locale),
                        })}
                      </p>
                    )}
                  </Card>
                ));
              })()
            : null}

          {/* Nói rõ vì sao ví không có mã khuyến mãi công khai — nếu không,
              khách tưởng app hỏng khi không thấy mã đọc được trên poster. */}
          <p className="px-1 text-center text-xs text-muted-foreground">
            {t("publicCodeHint")}
          </p>
        </div>
      </main>
    </>
  );
}

function EmptyState({ text }: { text: string }) {
  return (
    <Card className="rounded-xl p-8 text-center text-sm text-muted-foreground">
      {text}
    </Card>
  );
}
