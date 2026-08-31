"use client";

/**
 * Shop Point Rewards — #1514. Catalog đổi điểm nhìn từ MỘT cửa hàng.
 *
 * CHỈ ĐỌC + MỘT CÔNG TẮC, và đó là chủ ý. Phần thưởng thuộc brand, coupon nó
 * mint ra tiêu được ở mọi chi nhánh — để một cửa hàng đặt giá điểm là để cửa
 * hàng đó phát hành giá trị cho cả chuỗi. Muốn sửa điểm/ảnh/thông số thì vào
 * màn HQ.
 *
 * Cái cửa hàng ĐƯỢC quyết là "hôm nay ở đây có phục vụ món này không". Tắt ở
 * đây chỉ ghi pivot của đúng chi nhánh này: brand không đổi, chi nhánh khác
 * không đổi.
 *
 * Hai trạng thái tắt KHÁC NHAU và màn hình phải phân biệt được:
 *   - HQ tắt (`is_active = false`) — khách không thấy ở đâu cả, cửa hàng bật
 *     lại cũng vô ích (BR-PRB02). Hàng bị làm mờ + có nhãn giải thích.
 *   - Cửa hàng tự tắt — công tắc bên phải.
 */

import { useMemo, useState } from "react";
import { useParams } from "next/navigation";
import Image from "next/image";
import { Gift } from "lucide-react";
import type { ColumnDef } from "@tanstack/react-table";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { Badge, Switch, Tabs, TabsContent, TabsList, TabsTrigger } from "@godxjp/ui";
import {
  useSetPointRewardBranchAvailability,
  useShopPointRewards,
} from "@/hooks/api/use-point-rewards";
import type { ShopPointReward } from "@/services/point-reward-service";
import { RedemptionLog } from "@/components/shared/redemption-log";
import { useTranslation } from "@/providers/app-provider";

export default function ShopPointRewardsPage() {
  const params = useParams<{ shopSlug: string }>();
  const shopSlug = params.shopSlug;
  const [tab, setTab] = useState("catalog");
  const { t } = useTranslation();

  const { data: response, isLoading, refetch, isFetching } = useShopPointRewards(shopSlug);
  const items = useMemo<ShopPointReward[]>(() => response?.data ?? [], [response]);

  const setAvailability = useSetPointRewardBranchAvailability(shopSlug);

  const columns: ColumnDef<ShopPointReward>[] = useMemo(
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
        header: t("shop.point_rewards.col.name"),
        size: 280,
        cell: ({ row }) => (
          <div className={row.original.is_active ? "" : "opacity-50"}>
            <p className="font-medium">{row.original.name}</p>
            {!row.original.is_active && (
              <p className="text-[11px] text-muted-foreground">
                {t("shop.point_rewards.disabled_by_hq")}
              </p>
            )}
          </div>
        ),
      },
      {
        id: "cost_points",
        header: t("shop.point_rewards.col.cost_points"),
        size: 100,
        cell: ({ row }) => (
          <span className="text-sm font-semibold tabular-nums">
            {row.original.cost_points.toLocaleString()}
          </span>
        ),
      },
      {
        id: "service_condition",
        header: t("shop.point_rewards.col.service_condition"),
        size: 160,
        cell: ({ row }) => (
          <span className="text-xs text-muted-foreground">
            {t(`hq.point_rewards.condition.${row.original.service_condition}`)}
          </span>
        ),
      },
      {
        id: "stock",
        header: t("shop.point_rewards.col.stock"),
        size: 120,
        cell: ({ row }) => {
          const r = row.original;
          if (r.remaining_stock === null) {
            return (
              <span className="text-xs text-muted-foreground">
                {t("hq.point_rewards.stock.unlimited")}
              </span>
            );
          }
          return r.is_out_of_stock ? (
            <Badge
              variant="outline"
              className="h-5 border-transparent bg-red-50 text-[11px] text-red-700"
            >
              {t("hq.point_rewards.stock.out")}
            </Badge>
          ) : (
            <span className="text-xs tabular-nums">{r.remaining_stock}</span>
          );
        },
      },
      {
        id: "availability",
        header: t("shop.point_rewards.col.available"),
        size: 120,
        cell: ({ row }) => {
          const r = row.original;
          return (
            <Switch
              checked={r.is_available_at_branch}
              // HQ đã tắt thì công tắc cửa hàng không còn nghĩa (BR-PRB02) —
              // khoá lại thay vì để người ta bật rồi tự hỏi sao khách vẫn
              // không thấy.
              disabled={!r.is_active || setAvailability.isPending}
              onCheckedChange={(next) => setAvailability.mutate({ id: r.id, isAvailable: next })}
              aria-label={t("shop.point_rewards.col.available")}
            />
          );
        },
      },
    ],
    [setAvailability, t]
  );

  return (
    <>
      <PageHeader
        title={t("shop.point_rewards.title")}
        description={t("shop.point_rewards.description")}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <HelpPanel
          title={t("shop.point_rewards.title")}
          subtitle={t("help.panel.shop_point_rewards.subtitle")}
          purpose={t("help.panel.shop_point_rewards.purpose")}
          usage={[
            t("help.panel.shop_point_rewards.usage.1"),
            t("help.panel.shop_point_rewards.usage.2"),
          ]}
          checks={[
            t("help.panel.shop_point_rewards.checks.1"),
            t("help.panel.shop_point_rewards.checks.2"),
            t("help.panel.shop_point_rewards.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_point_rewards.glossary.stock.term"),
              description: t("help.panel.shop_point_rewards.glossary.stock.desc"),
            },
            {
              term: t("help.panel.shop_point_rewards.glossary.availability.term"),
              description: t("help.panel.shop_point_rewards.glossary.availability.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        {/* #1718 — cùng hai tab như màn HQ. Nhật ký là dữ liệu cấp BRAND (lượt
            đổi không gắn chi nhánh nào), nên component tự ghi nhãn khi đứng ở
            phạm vi cửa hàng. */}
        <Tabs value={tab} onValueChange={setTab}>
          <TabsList className="mb-4">
            <TabsTrigger value="catalog">{t("hq.point_rewards.tab.catalog")}</TabsTrigger>
            <TabsTrigger value="redemptions">{t("hq.point_rewards.tab.redemptions")}</TabsTrigger>
          </TabsList>

          <TabsContent value="catalog">
            {isLoading && response === undefined ? (
              <DataTableSkeleton columns={6} />
            ) : (
              <DataTable
                columns={columns}
                data={items}
                emptyMessage={t("shop.point_rewards.empty")}
              />
            )}
          </TabsContent>

          <TabsContent value="redemptions">
            <RedemptionLog scope={{ kind: "shop", shopSlug }} />
          </TabsContent>
        </Tabs>
      </PageContent>
    </>
  );
}
