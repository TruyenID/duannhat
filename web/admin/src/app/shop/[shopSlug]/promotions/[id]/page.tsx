"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { Calendar, CalendarDays, EllipsisVertical, Pencil, Power, Trash2 } from "lucide-react";
import { toast } from "sonner";
import {
  Badge,
  Button,
  Card,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  Skeleton,
  Switch,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import type { ColumnDef } from "@tanstack/react-table";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import {
  useDeleteShopPromotion,
  useShopPromotion,
  useShopPromotionRecentItems,
  useToggleShopPromotion,
} from "@/hooks/api/use-menu-promotions";
import type { OrderItemSummary } from "@/services/menu-promotion-service";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate, formatDateTime } from "@/lib/date";
import type { LocaleCode } from "@/i18n";

export default function ShopPromotionDetailPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const { shopSlug, id } = useParams<{ shopSlug: string; id: string }>();
  const router = useRouter();
  const [tab, setTab] = useState("overview");
  const [deleting, setDeleting] = useState(false);

  const { data, isLoading, error } = useShopPromotion(shopSlug, id);
  const promotion = data?.data;

  const { data: itemsData, isLoading: itemsLoading } = useShopPromotionRecentItems(shopSlug, id);
  const items = itemsData?.data ?? [];

  const toggleMutation = useToggleShopPromotion(shopSlug);
  const deleteMutation = useDeleteShopPromotion(shopSlug);

  if (error) {
    return (
      <>
        <PageHeader title={t("shop.promotions.title")} backHref={`/shop/${shopSlug}/promotions`} />
        <PageContent>
          <div className="py-12 text-center text-sm text-red-500">{t("common.load_error")}</div>
        </PageContent>
      </>
    );
  }
  if (isLoading || !promotion) {
    return (
      <>
        <PageHeader title={t("shop.promotions.title")} backHref={`/shop/${shopSlug}/promotions`} />
        <PageContent>
          <Skeleton className="h-48 w-full" />
        </PageContent>
      </>
    );
  }

  const report = promotion.report;
  const usedCount = report?.items_with_promotion_count ?? 0;
  const canDelete = usedCount === 0;

  const itemColumns: ColumnDef<OrderItemSummary>[] = [
    {
      id: "applied_at",
      header: t("shop.promotions.col.applied_at"),
      cell: ({ row }) => (
        <span className="text-xs">{formatDateTime(row.original.applied_at, locale, timezone)}</span>
      ),
    },
    {
      id: "order",
      header: t("shop.promotions.col.order"),
      cell: ({ row }) => (
        <Link
          href={`/shop/${shopSlug}/orders/${row.original.customer_order_id}`}
          className="font-mono text-xs text-primary hover:underline"
        >
          {row.original.order_code ?? row.original.customer_order_id.slice(0, 8)}
        </Link>
      ),
    },
    {
      id: "product",
      header: t("shop.promotions.col.product"),
      cell: ({ row }) => <span className="text-sm">{row.original.product_name}</span>,
    },
    {
      id: "before",
      header: t("shop.promotions.col.before"),
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground line-through">
          {row.original.original_unit_price?.toLocaleString(locale) ?? "—"}
        </span>
      ),
    },
    {
      id: "after",
      header: t("shop.promotions.col.after"),
      cell: ({ row }) => (
        <span className="text-sm font-medium">
          {row.original.unit_price.toLocaleString(locale)}
        </span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={promotion.name}
        description={`−${promotion.discount_percent}%`}
        backHref={`/shop/${shopSlug}/promotions`}
      >
        <Badge variant={promotion.is_active ? "default" : "secondary"}>
          {promotion.is_active
            ? t("shop.promotions.status.active")
            : t("shop.promotions.status.inactive")}
        </Badge>
        <Switch
          checked={promotion.is_active}
          onCheckedChange={() => toggleMutation.mutate(promotion.id)}
        />
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="size-8">
              <EllipsisVertical className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem asChild>
              <Link href={`/shop/${shopSlug}/promotions/${promotion.id}/edit`}>
                <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
              </Link>
            </DropdownMenuItem>
            <DropdownMenuItem onSelect={() => toggleMutation.mutate(promotion.id)}>
              <Power className="mr-2 size-3.5" />{" "}
              {promotion.is_active
                ? t("shop.promotions.action.deactivate")
                : t("shop.promotions.action.activate")}
            </DropdownMenuItem>
            <DropdownMenuItem
              disabled={!canDelete}
              onSelect={() => {
                if (!canDelete) {
                  toast.error(t("promotion.error.promotion_already_used_use_deactivate_instead"));
                  return;
                }
                setDeleting(true);
              }}
              className="text-destructive focus:text-destructive"
            >
              <Trash2 className="mr-2 size-3.5" /> {t("common.delete")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </PageHeader>

      <PageContent>
        <Tabs value={tab} onValueChange={setTab}>
          <TabsList>
            <TabsTrigger value="overview">{t("shop.promotions.tabs.overview")}</TabsTrigger>
            <TabsTrigger value="report">
              {t("shop.promotions.tabs.report")} · {usedCount}
            </TabsTrigger>
            <TabsTrigger value="scope">{t("shop.promotions.tabs.scope")}</TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="mt-4">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <Card className="p-4">
                <h3 className="mb-2 text-sm font-semibold">
                  {t("shop.promotions.panel.discount")}
                </h3>
                <Row
                  label={t("shop.promotions.field.discount_percent")}
                  value={`${promotion.discount_percent}%`}
                />
                <Row
                  label={t("shop.promotions.col.stacking")}
                  value={
                    promotion.stacking_mode === "stackable_with_coupons"
                      ? t("shop.promotions.stacking.stackable")
                      : t("shop.promotions.stacking.exclusive")
                  }
                />
              </Card>
              <Card className="p-4">
                <h3 className="mb-2 text-sm font-semibold">{t("shop.promotions.panel.window")}</h3>
                <ScheduleDescription
                  promotion={{
                    daily_time_from: promotion.daily_time_from ?? null,
                    daily_time_to: promotion.daily_time_to ?? null,
                    weekdays: Array.isArray(promotion.weekdays)
                      ? (promotion.weekdays as number[])
                      : null,
                    valid_from: promotion.valid_from,
                    valid_until: promotion.valid_until,
                  }}
                  t={t}
                  locale={locale}
                  timezone={timezone}
                />
              </Card>
              <Card className="p-4">
                <h3 className="mb-2 text-sm font-semibold">{t("shop.promotions.panel.report")}</h3>
                <Row label={t("shop.promotions.report.items_count")} value={String(usedCount)} />
                <Row
                  label={t("shop.promotions.report.total_discount")}
                  value={(report?.total_discount_applied ?? 0).toLocaleString(locale)}
                />
                <Row
                  label={t("shop.promotions.report.first_redeemed")}
                  value={
                    report?.first_redeemed_at
                      ? formatDateTime(report.first_redeemed_at, locale, timezone)
                      : "—"
                  }
                />
              </Card>
            </div>
            {promotion.description && (
              <Card className="mt-4 p-4">
                <h3 className="mb-2 text-sm font-semibold">
                  {t("shop.promotions.field.description")}
                </h3>
                <p className="text-sm text-muted-foreground">{promotion.description}</p>
              </Card>
            )}
          </TabsContent>

          <TabsContent value="report" className="mt-4">
            {itemsLoading && items.length === 0 ? (
              <DataTableSkeleton columns={5} />
            ) : (
              <DataTable
                columns={itemColumns}
                data={items}
                emptyMessage={t("shop.promotions.report.empty")}
              />
            )}
          </TabsContent>

          <TabsContent value="scope" className="mt-4">
            <Card className="p-4">
              <h3 className="mb-2 text-sm font-semibold">
                {t("shop.promotions.field.scope_summary")}
              </h3>
              <p className="mb-3 text-sm">
                <Badge variant="outline">
                  {t(`shop.promotions.scope.${promotion.applies_to}`)}
                </Badge>
              </p>
              {(promotion.applicable_category_ids?.length ?? 0) > 0 && (
                <div className="mb-3">
                  <p className="text-xs font-semibold">
                    {t("shop.promotions.field.applicable_categories")}
                  </p>
                  <ul className="ml-4 list-disc text-sm">
                    {(promotion.applicable_category_ids ?? []).map((cid) => (
                      <li key={cid} className="font-mono">
                        {cid}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              {(promotion.applicable_product_ids?.length ?? 0) > 0 && (
                <div>
                  <p className="text-xs font-semibold">
                    {t("shop.promotions.field.applicable_products")}
                  </p>
                  <ul className="ml-4 list-disc text-sm">
                    {(promotion.applicable_product_ids ?? []).map((pid) => (
                      <li key={pid} className="font-mono">
                        {pid}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </Card>
          </TabsContent>
        </Tabs>
      </PageContent>

      <DeleteConfirmDialog
        open={deleting}
        onOpenChange={(open) => !open && setDeleting(false)}
        title={t("shop.promotions.delete_dialog.title")}
        description={t("shop.promotions.delete_dialog.description", { name: promotion.name })}
        isPending={deleteMutation.isPending}
        onConfirm={() => {
          deleteMutation.mutate(promotion.id, {
            onSuccess: () => {
              setDeleting(false);
              router.push(`/shop/${shopSlug}/promotions`);
            },
          });
        }}
      />
    </>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-sm">{value}</dd>
    </div>
  );
}

const WEEKDAY_KEYS_DETAIL = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"] as const;

/**
 * Human-readable schedule used on the Shop promotion detail page —
 * mirrors the form's live summary, so what a manager sees while editing
 * matches what they see after saving.
 */
function ScheduleDescription({
  promotion,
  t,
  locale,
  timezone,
}: {
  promotion: {
    daily_time_from?: string | null;
    daily_time_to?: string | null;
    weekdays?: number[] | null;
    valid_from: string;
    valid_until: string;
  };
  t: (k: string, p?: Record<string, string | number>) => string;
  locale: LocaleCode;
  timezone?: string;
}) {
  const from =
    typeof promotion.daily_time_from === "string" ? promotion.daily_time_from.slice(0, 5) : null;
  const to =
    typeof promotion.daily_time_to === "string" ? promotion.daily_time_to.slice(0, 5) : null;

  let timeLine: string;
  if (!from || !to) {
    timeLine = t("shop.promotions.summary.time_all_day");
  } else if (from < to) {
    timeLine = t("shop.promotions.summary.time_range", { from, to });
  } else {
    timeLine = t("shop.promotions.summary.time_cross_midnight", { from, to });
  }

  const weekdayArr = Array.isArray(promotion.weekdays) ? promotion.weekdays : [];
  let daysLine: string;
  if (weekdayArr.length === 0 || weekdayArr.length === 7) {
    daysLine = t("shop.promotions.summary.weekdays_all");
  } else {
    const labels = [...weekdayArr]
      .sort((a, b) => a - b)
      .map((d) => t(`common.weekday.${WEEKDAY_KEYS_DETAIL[d - 1]}`));
    daysLine = t("shop.promotions.summary.weekdays_list", { days: labels.join(", ") });
  }

  const dateLine = t("shop.promotions.summary.date_range", {
    from: formatDate(promotion.valid_from, locale, timezone),
    to: formatDate(promotion.valid_until, locale, timezone),
  });

  return (
    <ul className="space-y-1.5 text-sm">
      <li className="flex gap-2">
        <Calendar className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
        <span>{dateLine}</span>
      </li>
      <li className="flex gap-2">
        <CalendarDays className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
        <span>{daysLine}</span>
      </li>
      <li className="flex gap-2">
        <span className="text-muted-foreground">⏰</span>
        <span>{timeLine}</span>
      </li>
    </ul>
  );
}
