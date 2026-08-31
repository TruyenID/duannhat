"use client";

/**
 * Khối "Điểm & đổi thưởng" trong chi tiết khách — #1700 (HQ), #1718 (cửa hàng).
 *
 * Trước #1700 admin không có đường nào thấy điểm của khách: dữ liệu nằm trong
 * `customer_point_entries` nhưng endpoint duy nhất đọc nó lấy khách từ token
 * của chính khách, nên admin không gọi thay được.
 *
 * Dùng chung cho hai phạm vi qua prop `scope`. Ở cửa hàng có thêm MỘT dòng
 * nhãn, và nó không phải trang trí: số dư là ví toàn brand (BR-PT04), còn
 * người trực quầy thì đang đứng ở một chi nhánh cụ thể — đây đúng là chỗ dễ
 * đọc nhầm "đây là điểm khách tích ở quán tôi" nhất.
 *
 * Hai con số ở đầu KHÔNG phải một: `balance` là cái khách tiêu được ngay,
 * `lifetime_points` là tổng đã tích trong đời và dùng để xét hạng — tiêu điểm
 * không làm tụt hạng, nên hai số này lệch nhau đúng bằng phần đã tiêu.
 *
 * 403 được xử lý riêng, không gộp vào lỗi chung: khách chưa gắn tổ chức (dữ
 * liệu cũ trước #1505) sẽ luôn 403 ở đây, và câu đúng phải nói là "không xem
 * được ở màn này", chứ hiện "không tải được" thì người dùng sẽ bấm lại mãi.
 */

import { useState } from "react";
import Link from "next/link";
import { ApiError } from "@/lib/api";
import { Badge, Card, Spinner } from "@godxjp/ui";
import { Pagination, type PaginationMeta } from "@/components/shared/pagination";
import { useCustomerPoints } from "@/hooks/api/use-customer-points";
import type {
  CustomerPointEntry,
  PersonalCouponStatus,
  PointScope,
} from "@/services/customer-point-service";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import type { LocaleCode } from "@/i18n";
import { formatDateTime } from "@/lib/date";

export interface CustomerPointsCardProps {
  /** HQ hay cửa hàng — quyết định endpoint, khoá cache, và có hiện nhãn phạm vi không. */
  scope: PointScope;
  customerId: string;
  /** Đường dẫn gốc tới màn đơn hàng, để bút toán tích điểm link về đơn. */
  ordersBasePath: string;
}

const STATUS_VARIANT: Record<PersonalCouponStatus, "default" | "secondary" | "outline"> = {
  used: "secondary",
  unused: "default",
  expired: "outline",
};

export function CustomerPointsCard({ scope, customerId, ordersBasePath }: CustomerPointsCardProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const [page, setPage] = useState(1);

  const { data, isLoading, error } = useCustomerPoints(scope, customerId, page);

  const isForbidden = error instanceof ApiError && (error.status === 403 || error.status === 404);

  const meta: PaginationMeta = {
    current_page: data?.meta.current_page ?? 1,
    last_page: data?.meta.last_page ?? 1,
    total: data?.meta.total ?? 0,
    per_page: 20,
  };

  return (
    <Card data-slot="customer-points-card" className="p-4">
      <h2
        className={scope.kind === "shop" ? "text-sm font-semibold" : "mb-3 text-sm font-semibold"}
      >
        {t("hq.customers.points.title")}
      </h2>

      {/* #1718 — ở màn cửa hàng PHẢI nói ra: đây là ví toàn brand của khách,
          không phải điểm tích tại chi nhánh này. Đúng chỗ người trực quầy dễ
          đọc nhầm nhất, và cách xử lý là nói thẳng chứ không giấu màn đi. */}
      {scope.kind === "shop" && (
        <p className="mt-1 mb-3 text-xs text-muted-foreground">
          {t("shop.customers.points.brand_scope_note")}
        </p>
      )}

      {isLoading && (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Spinner className="size-3.5" /> {t("common.loading")}
        </div>
      )}

      {!isLoading && isForbidden && (
        <p className="text-sm text-muted-foreground">{t("hq.customers.points.not_available")}</p>
      )}

      {!isLoading && error && !isForbidden && (
        <p className="text-sm text-red-500">{t("hq.customers.points.load_error")}</p>
      )}

      {!isLoading && !error && data && (
        <>
          <dl className="mb-4 grid grid-cols-2 gap-4">
            <div>
              <dt className="text-xs text-muted-foreground">{t("hq.customers.points.balance")}</dt>
              <dd className="text-lg font-semibold tabular-nums">
                {data.data.balance.toLocaleString()}
              </dd>
            </div>
            <div>
              <dt className="text-xs text-muted-foreground">{t("hq.customers.points.lifetime")}</dt>
              <dd className="text-lg font-semibold tabular-nums">
                {data.data.lifetime_points.toLocaleString()}
              </dd>
            </div>
          </dl>

          {data.data.entries.length === 0 ? (
            <p className="text-sm text-muted-foreground">{t("hq.customers.points.empty")}</p>
          ) : (
            <div className="space-y-1">
              {data.data.entries.map((entry) => (
                <PointEntryRow
                  key={entry.id}
                  entry={entry}
                  ordersBasePath={ordersBasePath}
                  locale={locale}
                  timezone={timezone}
                  t={t}
                />
              ))}
            </div>
          )}

          <Pagination meta={meta} page={page} onPageChange={setPage} />
        </>
      )}
    </Card>
  );
}

function PointEntryRow({
  entry,
  ordersBasePath,
  locale,
  timezone,
  t,
}: {
  entry: CustomerPointEntry;
  ordersBasePath: string;
  locale: LocaleCode;
  timezone: string;
  t: (key: string) => string;
}) {
  // Dương/âm quyết định màu — và giữ nguyên dấu khi hiển thị: đây là một cuốn
  // sổ, số dư là tổng cộng của chính những dòng này.
  const isCredit = entry.points > 0;

  return (
    <div className="flex items-center justify-between gap-3 rounded px-2 py-1.5 text-sm hover:bg-muted">
      <span className="w-32 shrink-0 text-xs text-muted-foreground">
        {formatDateTime(entry.created_at, locale, timezone)}
      </span>

      <span className="w-20 shrink-0 text-xs">{t(`hq.customers.points.kind.${entry.kind}`)}</span>

      <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
        {entry.coupon ? (
          <span className="flex items-center gap-2">
            <span className="font-mono">{entry.coupon.code}</span>
            <Badge variant={STATUS_VARIANT[entry.coupon.status]} className="text-[10px]">
              {t(`hq.redemptions.status.${entry.coupon.status}`)}
            </Badge>
            {entry.reward_name && <span className="truncate">{entry.reward_name}</span>}
          </span>
        ) : entry.order_id && entry.order_code ? (
          <Link href={`${ordersBasePath}/${entry.order_id}`} className="font-mono hover:underline">
            {entry.order_code}
          </Link>
        ) : (
          (entry.note ?? "—")
        )}
      </span>

      <span
        className={`w-20 shrink-0 text-right tabular-nums ${isCredit ? "text-emerald-600" : "text-muted-foreground"}`}
      >
        {isCredit ? "+" : ""}
        {entry.points.toLocaleString()}
      </span>
    </div>
  );
}
