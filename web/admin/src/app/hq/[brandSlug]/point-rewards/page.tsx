"use client";

/**
 * HQ Point Rewards — #1514. Catalog "đổi điểm" hiển thị ở customer-web
 * `/account/points`.
 *
 * Trước màn hình này bảng `point_rewards` không có đường nhập nào: không API
 * admin, không seeder, không lệnh artisan. Header của schema ghi thẳng "chưa
 * có UI — seed/tinker cho tới khi admin-web làm", nên thêm một phần thưởng
 * nghĩa là mở `php artisan tinker` trên production.
 *
 * Một phần thưởng là BẢN MẪU CỦA MỘT COUPON: khách bỏ điểm ra và hệ thống
 * mint một coupon cá nhân theo đúng thông số ở đây. Tên và ảnh do người vận
 * hành tự đặt, không link tới Product/SKU — "Bia" chỉ là nhãn của một coupon,
 * hệ thống không phát ra một ly bia.
 *
 * Danh sách này CÓ hiện cả phần thưởng đang tắt: tắt xong mà nó biến mất thì
 * không còn đường bật lại.
 */

import { useMemo, useState } from "react";
import { useParams } from "next/navigation";
import Image from "next/image";
import { EllipsisVertical, Eye, EyeOff, Gift, Pencil, Plus, Trash2 } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { Badge, Button, StatusBadge, Tabs, TabsContent, TabsList, TabsTrigger } from "@godxjp/ui";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@godxjp/ui";
import {
  useDeletePointReward,
  usePointRewards,
  useTogglePointRewardActive,
} from "@/hooks/api/use-point-rewards";
import type { PointReward } from "@/services/point-reward-service";
import { PointRewardFormDialog } from "./components/point-reward-form-dialog";
import { RedemptionLog } from "@/components/shared/redemption-log";
import { useTranslation } from "@/providers/app-provider";
import { SUPPORTED_LOCALES } from "@/types/models/payload-helpers";

export default function PointRewardsPage() {
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;
  const { t } = useTranslation();

  const [tab, setTab] = useState("catalog");
  const [createOpen, setCreateOpen] = useState(false);
  const [editing, setEditing] = useState<PointReward | null>(null);
  const [deleting, setDeleting] = useState<PointReward | null>(null);

  const { data: response, isLoading, refetch, isFetching } = usePointRewards(brandSlug);
  const items = useMemo<PointReward[]>(() => response?.data ?? [], [response]);

  const toggleActive = useTogglePointRewardActive(brandSlug);
  const deleteMutation = useDeletePointReward(brandSlug);

  const columns: ColumnDef<PointReward>[] = useMemo(
    () => [
      {
        id: "image",
        header: "",
        size: 56,
        cell: ({ row }) => (
          <div className="relative aspect-[4/3] w-12 overflow-hidden rounded border bg-muted">
            {row.original.image_url ? (
              <Image
                src={row.original.image_url}
                alt=""
                fill
                sizes="48px"
                className="object-cover"
                unoptimized
              />
            ) : (
              <div className="flex h-full w-full items-center justify-center">
                <Gift className="size-4 text-muted-foreground/40" />
              </div>
            )}
          </div>
        ),
      },
      {
        id: "name",
        header: t("hq.point_rewards.col.name"),
        size: 260,
        cell: ({ row }) => (
          <button
            type="button"
            className="text-left font-medium text-primary hover:underline"
            onClick={() => setEditing(row.original)}
          >
            {row.original.name}
          </button>
        ),
      },
      {
        id: "cost_points",
        header: t("hq.point_rewards.col.cost_points"),
        size: 100,
        cell: ({ row }) => (
          <span className="text-sm font-semibold tabular-nums">
            {row.original.cost_points.toLocaleString()}
          </span>
        ),
      },
      {
        id: "discount",
        header: t("hq.point_rewards.col.discount"),
        size: 140,
        cell: ({ row }) => {
          const r = row.original;
          return (
            <span className="text-xs text-muted-foreground">
              {r.discount_type === "percent"
                ? `${r.discount_value}%${r.max_discount_cap ? ` (≤ ${r.max_discount_cap})` : ""}`
                : `${r.discount_value}`}
            </span>
          );
        },
      },
      {
        id: "stock",
        header: t("hq.point_rewards.col.stock"),
        size: 130,
        cell: ({ row }) => {
          const r = row.original;
          // `null` = không giới hạn. Hiện "∞" chứ đừng hiện 0 — nhìn nhầm
          // thành hết hàng là gọi ngay cho kỹ thuật.
          if (r.stock_quantity === null) {
            return (
              <span className="text-xs text-muted-foreground">
                {t("hq.point_rewards.stock.unlimited")}
              </span>
            );
          }
          return (
            <div className="flex items-center gap-1.5">
              <span className="text-xs tabular-nums">
                {r.remaining_stock ?? 0}/{r.stock_quantity}
              </span>
              {r.is_out_of_stock && (
                <Badge variant="outline" className="h-5 border-transparent bg-red-50 text-[11px] text-red-700">
                  {t("hq.point_rewards.stock.out")}
                </Badge>
              )}
            </div>
          );
        },
      },
      {
        id: "service_condition",
        header: t("hq.point_rewards.col.service_condition"),
        size: 150,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {t(`hq.point_rewards.condition.${row.original.service_condition}`)}
          </span>
        ),
      },
      {
        id: "languages",
        header: t("hq.point_rewards.col.languages"),
        size: 120,
        cell: ({ row }) => {
          // Thiếu một thứ tiếng không phải lỗi — customer-web lui về ngôn ngữ
          // khác — nhưng người vận hành cần thấy, vì khách xem tiếng đó sẽ đọc
          // tên phần thưởng bằng ngôn ngữ khác.
          const filled = SUPPORTED_LOCALES.filter((l) =>
            row.original.translations?.[l]?.name?.trim(),
          );

          return (
            <div className="flex flex-wrap gap-1">
              {SUPPORTED_LOCALES.map((l) => (
                <Badge
                  key={l}
                  variant="outline"
                  className={`h-5 px-1.5 text-[11px] font-medium uppercase ${
                    filled.includes(l)
                      ? "border-transparent bg-emerald-50 text-emerald-700"
                      : "border-dashed text-muted-foreground"
                  }`}
                >
                  {l}
                </Badge>
              ))}
            </div>
          );
        },
      },
      {
        id: "status",
        header: t("common.status"),
        size: 100,
        cell: ({ row }) => <StatusBadge status={row.original.is_active ? "active" : "inactive"} />,
      },
      {
        id: "actions",
        size: 50,
        header: t("common.action"),
        cell: ({ row }) => {
          const reward = row.original;
          return (
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7">
                  <EllipsisVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={() => setEditing(reward)}>
                  <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
                </DropdownMenuItem>
                <DropdownMenuItem
                  onClick={() =>
                    toggleActive.mutate({ id: reward.id, currentIsActive: reward.is_active })
                  }
                >
                  {reward.is_active ? (
                    <EyeOff className="mr-2 size-3.5" />
                  ) : (
                    <Eye className="mr-2 size-3.5" />
                  )}
                  {reward.is_active
                    ? t("hq.point_rewards.action.deactivate")
                    : t("hq.point_rewards.action.activate")}
                </DropdownMenuItem>
                <DropdownMenuItem variant="destructive" onClick={() => setDeleting(reward)}>
                  <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          );
        },
      },
    ],
    [toggleActive, t],
  );

  return (
    <>
      <PageHeader
        title={t("hq.point_rewards.title")}
        description={t("hq.point_rewards.description")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <Button size="sm" className="h-7 gap-1 text-xs" onClick={() => setCreateOpen(true)}>
          <Plus className="size-3.5" />
          {t("common.new")}
        </Button>
      </PageHeader>

      <PageContent>
        {/* Hai tab, hai câu hỏi: "phát cái gì" và "ai đã đổi". Nhật ký nằm
            cạnh chính cấu hình sinh ra nó thay vì thành một mục sidebar mới —
            sidebar HQ đã hơn 30 mục (#1700). */}
        <Tabs value={tab} onValueChange={setTab}>
          <TabsList className="mb-4">
            <TabsTrigger value="catalog">{t("hq.point_rewards.tab.catalog")}</TabsTrigger>
            <TabsTrigger value="redemptions">{t("hq.point_rewards.tab.redemptions")}</TabsTrigger>
          </TabsList>

          <TabsContent value="catalog">
            {isLoading && response === undefined ? (
              <DataTableSkeleton columns={9} />
            ) : (
              <DataTable
                columns={columns}
                data={items}
                emptyMessage={t("hq.point_rewards.empty")}
              />
            )}
          </TabsContent>

          <TabsContent value="redemptions">
            <RedemptionLog scope={{ kind: "hq", brandSlug }} />
          </TabsContent>
        </Tabs>
      </PageContent>

      <PointRewardFormDialog
        brandSlug={brandSlug}
        open={createOpen}
        onOpenChange={setCreateOpen}
        reward={null}
      />

      <PointRewardFormDialog
        brandSlug={brandSlug}
        open={!!editing}
        onOpenChange={(o) => !o && setEditing(null)}
        reward={editing}
      />

      {/* Xoá — hỏi lại vì khác "tắt": tắt thì bật lại được, xoá thì biến khỏi
          màn hình này. Coupon đã mint vẫn dùng được, lịch sử điểm vẫn nguyên. */}
      <AlertDialog open={!!deleting} onOpenChange={(o) => !o && setDeleting(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.point_rewards.delete.title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("hq.point_rewards.delete.desc")}
              {deleting?.name ? ` — “${deleting.name}”` : ""}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteMutation.isPending}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              disabled={deleteMutation.isPending}
              onClick={() => {
                if (!deleting) return;
                deleteMutation.mutate(deleting.id, { onSuccess: () => setDeleting(null) });
              }}
            >
              {t("common.delete")}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
