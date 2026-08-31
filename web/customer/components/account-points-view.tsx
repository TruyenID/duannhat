"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "@/i18n/routing";
import { useParams } from "next/navigation";
import { useTranslations, useLocale } from "next-intl";
import { useAuth } from "@/context/auth-context";
import { apiFetch, ApiError } from "@/lib/api";
import { accountHref } from "@/lib/shop-routes";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { proxyImageUrl } from "@/lib/image-url";
import { toast } from "sonner";
import { Gift, Lock } from "lucide-react";

// ---------------------------------------------------------------------------
// Kiểu dữ liệu — khớp GET /api/v1/customer/me/points{,/rewards}
// ---------------------------------------------------------------------------

/**
 * `/points` trả cả hạng, tiến độ lên hạng và lịch sử bút toán, nhưng trang này
 * chỉ đọc `balance` — và đọc để TÍNH chứ không để vẽ: số dư quyết định thẻ nào
 * đủ điểm để bấm. Số dư và hạng hiển thị ở tab "Khách hàng thành viên", còn
 * lịch sử bút toán không nằm trong thiết kế của trang đổi điểm.
 */
interface PointsPayload {
  balance: number;
}

type ServiceCondition = "dine_in" | "takeaway" | "both";

interface Reward {
  id: string;
  name: string | null;
  description: string | null;
  cost_points: number;
  discount_type: "fixed" | "percent";
  discount_value: string | number;
  valid_days: number;
  image_url: string | null;
  service_condition: ServiceCondition;
  /** `null` = không giới hạn — KHÁC hẳn `0` (đã hết). */
  remaining_stock: number | null;
  is_out_of_stock: boolean;
}

export default function AccountPointsView() {
  const { isLoggedIn, isLoading } = useAuth();
  const router = useRouter();
  const t = useTranslations("points");
  const locale = useLocale();
  // Khu tài khoản nằm dưới `/account/[shop]` (#1505) — liên kết trong panel
  // phải giữ nguyên cửa hàng đang xem, nếu không bấm sang là rơi ra khỏi cửa
  // hàng và bị guard đá về /select-branch.
  const { shop } = useParams<{ shop?: string }>();

  const [points, setPoints] = useState<PointsPayload | null>(null);
  const [rewards, setRewards] = useState<Reward[]>([]);
  const [fetching, setFetching] = useState(true);
  const [redeeming, setRedeeming] = useState<string | null>(null);
  // Cả tấm thẻ là vùng bấm, nên phải có bước xác nhận: trước đây nút "Đổi"
  // nhỏ và cố ý khó chạm nhầm, giờ chạm nhầm là tiêu điểm thật.
  const [confirming, setConfirming] = useState<Reward | null>(null);

  // Loader THUẦN: chỉ lấy dữ liệu, không đụng state. Việc setState nằm ở nơi
  // gọi, nên effect không bao giờ có một lời gọi mà trong ruột nó là setState
  // đồng bộ (react-hooks/set-state-in-effect).
  const fetchAll = useCallback(
    () =>
      Promise.all([
        apiFetch<{ data: PointsPayload }>("/api/v1/customer/me/points", {
          silent401: true,
        }),
        apiFetch<{ data: Reward[] }>("/api/v1/customer/me/points/rewards", {
          silent401: true,
        }),
      ]),
    [],
  );

  useEffect(() => {
    if (!isLoggedIn) return;
    fetchAll()
      .then(([pointsRes, rewardsRes]) => {
        setPoints(pointsRes.data);
        setRewards(rewardsRes.data);
      })
      .catch(() => setPoints(null))
      .finally(() => setFetching(false));
  }, [isLoggedIn, fetchAll]);

  useEffect(() => {
    if (!isLoading && !isLoggedIn) {
      router.replace("/login?redirect=/account/points");
    }
  }, [isLoading, isLoggedIn, router]);

  async function handleRedeem(reward: Reward) {
    setConfirming(null);
    setRedeeming(reward.id);
    try {
      const res = await apiFetch<{ data: { coupon: { code: string } } }>(
        "/api/v1/customer/me/points/redeem",
        { method: "POST", body: JSON.stringify({ reward_id: reward.id }) },
      );
      toast.success(t("redeemed", { code: res.data.coupon.code }));
      const [pointsRes, rewardsRes] = await fetchAll();
      setPoints(pointsRes.data);
      setRewards(rewardsRes.data);
    } catch (err) {
      // Backend phân biệt "không đủ điểm" với "phần thưởng đã ngừng" bằng
      // `error` riêng — khách đang cầm điện thoại cần biết mình gặp cái nào.
      const code =
        err instanceof ApiError ? (err.body.error as string) : undefined;
      toast.error(
        code === "INSUFFICIENT_POINTS"
          ? t("errorInsufficient")
          : code === "REWARD_UNAVAILABLE"
            ? t("errorUnavailable")
            : // "Hết hàng" tách khỏi "đã ngừng": cái đầu đáng quay lại xem,
              // cái sau thì không (#1514).
              code === "REWARD_OUT_OF_STOCK"
              ? t("errorOutOfStock")
              : t("errorGeneric"),
      );
    } finally {
      setRedeeming(null);
    }
  }

  if (!isLoading && !isLoggedIn) return null;

  // Vỏ 2 cột dựng NGAY cả khi đang tải: tiêu đề và sidebar không phụ thuộc dữ
  // liệu, nên bọc chúng trong nhánh loading riêng chỉ làm sidebar chớp tắt mỗi
  // lần đổi tab.
  return (
    <>
      <div className="flex items-center gap-2">
        <h2 className="text-xl font-bold text-primary">{t("rewardsTitle")}</h2>
        <Button
          size="sm"
          className="ml-auto h-8 shrink-0 text-xs"
          onClick={() => router.push(accountHref(shop, "coupons"))}
        >
          {t("viewMyCoupons")}
        </Button>
      </div>

      {isLoading || fetching ? (
        <div className="flex items-center justify-center py-20">
          <span className="size-5 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>
      ) : !points ? (
        // `points === null` = endpoint trả 404 vì tính năng đang tắt
        // (`LOYALTY_POINTS_ENABLED=false`), không phải lỗi mạng.
        <p className="py-20 text-center text-sm text-neutral-500">
          {t("unavailable")}
        </p>
      ) : rewards.length === 0 ? (
        <p className="py-20 text-center text-sm text-neutral-500">
          {t("noRewards")}
        </p>
      ) : (
        <ul className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
          {rewards.map((reward) => (
            <RewardCard
              key={reward.id}
              reward={reward}
              affordable={points.balance >= reward.cost_points}
              busy={redeeming !== null}
              onSelect={() => setConfirming(reward)}
            />
          ))}
        </ul>
      )}

      {/* Xác nhận trước khi tiêu điểm — không hoàn lại được. */}
      <Dialog
        open={confirming !== null}
        onOpenChange={(open) => !open && setConfirming(null)}
      >
        <DialogContent className="max-w-[340px]">
          <DialogHeader>
            <DialogTitle className="text-base">
              {t("confirmTitle", { name: confirming?.name ?? "" })}
            </DialogTitle>
            <DialogDescription>
              {t("confirmBody", {
                points: (confirming?.cost_points ?? 0).toLocaleString(locale),
                days: confirming?.valid_days ?? 0,
              })}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2 sm:gap-2">
            <Button variant="secondary" onClick={() => setConfirming(null)}>
              {t("confirmCancel")}
            </Button>
            <Button
              disabled={redeeming !== null}
              onClick={() => confirming && handleRedeem(confirming)}
            >
              {redeeming !== null ? t("redeeming") : t("redeem")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

// ---------------------------------------------------------------------------
// Thẻ phần thưởng
// ---------------------------------------------------------------------------

function RewardCard({
  reward,
  affordable,
  busy,
  onSelect,
}: {
  reward: Reward;
  affordable: boolean;
  busy: boolean;
  onSelect: () => void;
}) {
  const t = useTranslations("points");
  const locale = useLocale();

  const disabled = reward.is_out_of_stock || !affordable || busy;
  const image = proxyImageUrl(reward.image_url);

  return (
    // `h-full` cả ô lẫn nút: tên phần thưởng dài 2 dòng làm thẻ đó cao hơn hàng
    // xóm, dòng điểm mỗi thẻ một cao độ và cả hàng trông lởm chởm.
    <li className="h-full">
      <button
        type="button"
        disabled={disabled}
        onClick={onSelect}
        className="group flex h-full w-full flex-col overflow-hidden rounded-lg border border-neutral-200 bg-white text-left transition enabled:hover:border-primary/40 enabled:hover:shadow-sm disabled:cursor-not-allowed"
      >
        <div className="relative aspect-[4/3] w-full bg-muted">
          {image ? (
            // Ảnh đến từ storage của tenant (MinIO/S3), không qua loader của
            // next/image — cùng lý do với ItemImageGallery.
            // eslint-disable-next-line @next/next/no-img-element
            <img
              src={image}
              alt={reward.name ?? ""}
              className={`h-full w-full object-cover ${
                reward.is_out_of_stock ? "opacity-40 grayscale" : ""
              }`}
            />
          ) : (
            <div className="flex h-full w-full items-center justify-center">
              <Gift className="h-8 w-8 text-muted-foreground/40" />
            </div>
          )}
        </div>

        <div className="flex flex-1 flex-col gap-0.5 p-2.5">
          <p className="line-clamp-2 text-sm font-medium leading-snug">
            {reward.name}
          </p>

          {/* BR-PR07 — chỉ hiển thị. Không tầng nào cưỡng chế điều kiện này. */}
          <p className="text-[11px] leading-tight text-muted-foreground">
            {t(`conditions.${reward.service_condition}`)}
          </p>

          <div className="mt-auto pt-1.5">
            {reward.is_out_of_stock ? (
              <span className="flex items-center gap-1 text-xs font-semibold text-muted-foreground">
                <Lock className="h-3 w-3 shrink-0" />
                {t("outOfStock")}
              </span>
            ) : (
              <span
                className={`text-sm font-bold ${
                  affordable ? "text-destructive" : "text-muted-foreground"
                }`}
              >
                {t("costPoints", {
                  points: reward.cost_points.toLocaleString(locale),
                })}
              </span>
            )}
          </div>
        </div>
      </button>
    </li>
  );
}
