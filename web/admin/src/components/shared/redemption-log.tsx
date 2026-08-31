"use client";

/**
 * Nhật ký đổi thưởng — #1700.
 *
 * Trả lời câu hỏi VẬN HÀNH: tháng này đổi bao nhiêu, phần thưởng nào chạy, bao
 * nhiêu mã phát ra rồi nằm im. Câu hỏi "khách NÀY thế nào" thì ở chỗ khác —
 * khối Điểm & đổi thưởng trong chi tiết khách.
 *
 * Là một TAB của màn phần thưởng chứ không phải một mục sidebar mới: nó nằm
 * cạnh đúng cái cấu hình sinh ra nó, và sidebar HQ đã hơn 30 mục.
 *
 * Ba thứ dễ đọc nhầm nếu không cẩn thận, nên chúng được nói thẳng trên màn:
 *   1. Bộ lọc ngày tính theo múi giờ VẬN HÀNH của brand, không theo máy người
 *      xem — nên `meta.timezone` được in ra dưới bảng.
 *   2. `points` trong sổ là số ÂM. Hiện đúng dấu, vì đây là một cuốn sổ.
 *   3. Khách có thể không mở được trang chi tiết (dữ liệu cũ chưa gắn tổ
 *      chức), nên tên khách ở đây là chữ thường, không phải link.
 */

import { useMemo, useState } from "react";
import { format } from "date-fns";
import type { ColumnDef } from "@tanstack/react-table";
import {
  Badge,
  DatePicker,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import { Pagination, type PaginationMeta } from "@/components/shared/pagination";
import { useDebounce } from "@/hooks/use-debounce";
import { useRedemptions } from "@/hooks/api/use-customer-points";
import { usePointRewards, useShopPointRewards } from "@/hooks/api/use-point-rewards";
import type {
  PersonalCouponStatus,
  PointScope,
  RedemptionRow,
} from "@/services/customer-point-service";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";

const ALL = "all";

/** Màu theo mức độ "cần để ý": đã tiêu là kết cục tốt, hết hạn là tiền rơi vãi. */
const STATUS_VARIANT: Record<PersonalCouponStatus, "default" | "secondary" | "outline"> = {
  used: "secondary",
  unused: "default",
  expired: "outline",
};

export interface RedemptionLogProps {
  /** HQ hay cửa hàng. Dữ liệu như nhau; cửa hàng thêm nhãn "toàn brand". */
  scope: PointScope;
}

export function RedemptionLog({ scope }: RedemptionLogProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  const [search, setSearch] = useState("");
  const [rewardId, setRewardId] = useState(ALL);
  const [couponStatus, setCouponStatus] = useState<PersonalCouponStatus | typeof ALL>(ALL);
  const [dateFrom, setDateFrom] = useState<Date | undefined>();
  const [dateTo, setDateTo] = useState<Date | undefined>();
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const debouncedSearch = useDebounce(search, 300);

  const filters = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      point_reward_id: rewardId === ALL ? undefined : rewardId,
      coupon_status: couponStatus === ALL ? undefined : couponStatus,
      date_from: dateFrom ? format(dateFrom, "yyyy-MM-dd") : undefined,
      date_to: dateTo ? format(dateTo, "yyyy-MM-dd") : undefined,
      page,
      per_page: perPage,
    }),
    [debouncedSearch, rewardId, couponStatus, dateFrom, dateTo, page, perPage]
  );

  const { data, isLoading, isFetching } = useRedemptions(scope, filters);
  // Danh sách phần thưởng để đổ vào bộ lọc — lấy từ đúng endpoint của phạm vi
  // đang đứng, nên dùng lại được cache của tab bên cạnh. Hook nào cũng tự tắt
  // khi slug rỗng, nên gọi cả hai là an toàn và giữ thứ tự hook cố định.
  const hqRewards = usePointRewards(scope.kind === "hq" ? scope.brandSlug : "");
  const shopRewards = useShopPointRewards(scope.kind === "shop" ? scope.shopSlug : "");
  const rewardOptions = (scope.kind === "hq" ? hqRewards.data?.data : shopRewards.data?.data) ?? [];

  const rows = data?.data ?? [];
  const hasActiveFilters =
    !!search || rewardId !== ALL || couponStatus !== ALL || !!dateFrom || !!dateTo;

  function clearFilters() {
    setSearch("");
    setRewardId(ALL);
    setCouponStatus(ALL);
    setDateFrom(undefined);
    setDateTo(undefined);
    setPage(1);
  }

  /** Mọi thay đổi bộ lọc phải kéo về trang 1 — trang 4 của kết quả cũ không tồn tại trong kết quả mới. */
  function withReset<T>(setter: (value: T) => void) {
    return (value: T) => {
      setter(value);
      setPage(1);
    };
  }

  const columns: ColumnDef<RedemptionRow>[] = useMemo(
    () => [
      {
        id: "created_at",
        header: t("hq.redemptions.col.when"),
        cell: ({ row }) => (
          <span className="text-xs whitespace-nowrap">
            {formatDateTime(row.original.created_at, locale, timezone)}
          </span>
        ),
      },
      {
        id: "customer",
        header: t("hq.redemptions.col.customer"),
        cell: ({ row }) => {
          const customer = row.original.customer;
          if (!customer) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          return (
            <div className="leading-tight">
              <div className="text-sm">{customer.name}</div>
              <div className="text-xs text-muted-foreground">
                {customer.phone ?? customer.email ?? "—"}
              </div>
            </div>
          );
        },
      },
      {
        id: "reward",
        header: t("hq.redemptions.col.reward"),
        cell: ({ row }) => <span className="text-sm">{row.original.reward?.name ?? "—"}</span>,
      },
      {
        id: "points",
        header: t("hq.redemptions.col.points"),
        cell: ({ row }) => (
          // Giữ nguyên dấu âm: đây là một dòng sổ, không phải một con số đẹp.
          <span className="text-sm tabular-nums">{row.original.points.toLocaleString()}</span>
        ),
      },
      {
        id: "coupon",
        header: t("hq.redemptions.col.coupon"),
        cell: ({ row }) => {
          const coupon = row.original.coupon;
          if (!coupon) {
            return <span className="text-xs text-muted-foreground">—</span>;
          }
          return (
            <div className="flex items-center gap-2">
              <span className="font-mono text-xs">{coupon.code}</span>
              <Badge variant={STATUS_VARIANT[coupon.status]} className="text-[10px]">
                {t(`hq.redemptions.status.${coupon.status}`)}
              </Badge>
            </div>
          );
        },
      },
      {
        id: "valid_until",
        header: t("hq.redemptions.col.valid_until"),
        cell: ({ row }) => (
          <span className="text-xs whitespace-nowrap text-muted-foreground">
            {row.original.coupon?.valid_until
              ? formatDateTime(row.original.coupon.valid_until, locale, timezone)
              : "—"}
          </span>
        ),
      },
    ],
    [t, locale, timezone]
  );

  const meta: PaginationMeta = {
    current_page: data?.meta.current_page ?? 1,
    last_page: data?.meta.last_page ?? 1,
    total: data?.meta.total ?? 0,
    per_page: perPage,
  };

  return (
    <div className="space-y-3">
      <ListPageToolbar
        search={search}
        onSearchChange={withReset(setSearch)}
        searchPlaceholder={t("hq.redemptions.search_placeholder")}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={clearFilters}
      >
        <Select value={rewardId} onValueChange={withReset(setRewardId)}>
          <SelectTrigger className="h-8 w-44 text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>{t("hq.redemptions.filter.all_rewards")}</SelectItem>
            {rewardOptions.map((reward) => (
              <SelectItem key={reward.id} value={reward.id}>
                {reward.name ?? reward.id.slice(0, 8)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          value={couponStatus}
          onValueChange={withReset((v: string) =>
            setCouponStatus(v as PersonalCouponStatus | typeof ALL)
          )}
        >
          <SelectTrigger className="h-8 w-36 text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>{t("hq.redemptions.filter.all_statuses")}</SelectItem>
            <SelectItem value="unused">{t("hq.redemptions.status.unused")}</SelectItem>
            <SelectItem value="used">{t("hq.redemptions.status.used")}</SelectItem>
            <SelectItem value="expired">{t("hq.redemptions.status.expired")}</SelectItem>
          </SelectContent>
        </Select>

        <DatePicker
          value={dateFrom}
          onChange={withReset(setDateFrom)}
          placeholder={t("hq.redemptions.filter.date_from")}
          className="h-8 w-35 text-xs"
        />
        <DatePicker
          value={dateTo}
          onChange={withReset(setDateTo)}
          placeholder={t("hq.redemptions.filter.date_to")}
          className="h-8 w-35 text-xs"
        />
      </ListPageToolbar>

      {isLoading && data === undefined ? (
        <DataTableSkeleton columns={6} />
      ) : (
        <>
          <DataTable columns={columns} data={rows} emptyMessage={t("hq.redemptions.empty")} />
          <Pagination
            meta={meta}
            page={page}
            onPageChange={setPage}
            perPage={perPage}
            onPerPageChange={withReset(setPerPage)}
          />
          {/* Không im lặng đoán hộ: nói rõ bộ lọc ngày đang tính theo đồng hồ
              nào. Brand VN và brand Nhật lệch nhau hai tiếng, và một lượt đổi
              lúc gần nửa đêm sẽ rơi vào hai ngày khác nhau tuỳ đồng hồ. */}
          {data?.meta.timezone && (
            <p className="text-xs text-muted-foreground">
              {scope.kind === "shop" && (
                <span className="mr-1">{t("shop.redemptions.brand_scope_note")}</span>
              )}
              {t("hq.redemptions.timezone_note")} {data.meta.timezone}
              {isFetching ? " · …" : ""}
            </p>
          )}
        </>
      )}
    </div>
  );
}
