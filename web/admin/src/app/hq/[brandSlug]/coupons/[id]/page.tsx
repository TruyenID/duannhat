"use client";

import { useState } from "react";
import Link from "next/link";
import { formatDateTime } from "@/lib/date";
import { notFound, useParams, useRouter } from "next/navigation";
import { EllipsisVertical, Pause, Pencil, Play, Trash2 } from "lucide-react";
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
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import type { ColumnDef } from "@tanstack/react-table";

import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { ApiError } from "@/lib/api";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { Pagination } from "@/components/shared/pagination";
import {
  useCoupon,
  useCouponRedemptions,
  useDeleteCoupon,
  usePauseCoupon,
  useResumeCoupon,
} from "@/hooks/api/use-coupons";
import type { CouponComputedStatus, RedemptionListItem } from "@/services/coupon-service";
import { useShops } from "@/hooks/api/use-shops";
import { useTranslation, useTimezone } from "@/providers/app-provider";

function statusBadgeVariant(
  s: CouponComputedStatus | undefined
): "default" | "secondary" | "destructive" | "outline" {
  switch (s) {
    case "active":
      return "default";
    case "scheduled":
      return "outline";
    case "expired":
    case "exhausted":
      return "destructive";
    default:
      return "secondary";
  }
}

export default function CouponDetailPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const { brandSlug, id } = useParams<{ brandSlug: string; id: string }>();
  const router = useRouter();
  const [tab, setTab] = useState("overview");
  const [redemptionPage, setRedemptionPage] = useState(1);
  const [deleting, setDeleting] = useState(false);

  const { data, isLoading, error } = useCoupon(brandSlug, id);
  const coupon = data?.data;

  const { data: redemptionData, isLoading: redLoading } = useCouponRedemptions(
    brandSlug,
    id,
    redemptionPage
  );
  const redemptions = redemptionData?.data ?? [];
  const redMeta = redemptionData?.meta;

  const pauseMutation = usePauseCoupon(brandSlug);
  const resumeMutation = useResumeCoupon(brandSlug);
  const deleteMutation = useDeleteCoupon(brandSlug);

  if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
    notFound();
  }

  if (error) {
    return (
      <>
        <PageHeader title={t("hq.coupons.title")} backHref={`/hq/${brandSlug}/coupons`} />
        <PageContent>
          <Card className="p-6 text-center text-sm text-red-500">{t("common.load_error")}</Card>
        </PageContent>
      </>
    );
  }

  if (isLoading || !coupon) {
    return (
      <>
        <PageHeader title={t("hq.coupons.title")} backHref={`/hq/${brandSlug}/coupons`} />
        <PageContent>
          <Skeleton className="h-48 w-full" />
        </PageContent>
      </>
    );
  }

  const computed = coupon.computed_status ?? coupon.status;
  const canDelete = coupon.times_used === 0;

  const redemptionColumns: ColumnDef<RedemptionListItem>[] = [
    {
      id: "redeemed_at",
      header: t("hq.coupons.col.redeemed_at"),
      cell: ({ row }) => (
        <span className="text-xs">
          {formatDateTime(row.original.redeemed_at, locale, timezone)}
        </span>
      ),
    },
    {
      id: "order",
      header: t("hq.coupons.col.order"),
      cell: ({ row }) => (
        <span className="font-mono text-xs">
          {row.original.order_code ?? row.original.customer_order_id.slice(0, 8)}
        </span>
      ),
    },
    {
      id: "customer",
      header: t("hq.coupons.col.customer"),
      cell: ({ row }) => (
        <span className="text-xs">{row.original.customer_name ?? t("common.guest")}</span>
      ),
    },
    {
      id: "discount",
      header: t("hq.coupons.col.discount_applied"),
      cell: ({ row }) => (
        <span className="text-xs">
          {row.original.discount_applied_amount.toLocaleString(locale)}
        </span>
      ),
    },
    {
      id: "status",
      header: t("hq.coupons.col.status"),
      cell: ({ row }) => (
        <Badge variant={row.original.released_at ? "secondary" : "default"}>
          {row.original.released_at
            ? t("hq.coupons.redemption.released")
            : t("hq.coupons.redemption.active")}
        </Badge>
      ),
    },
    {
      id: "via",
      header: t("hq.coupons.col.channel"),
      cell: ({ row }) => (
        <span className="text-xs text-muted-foreground">{row.original.redeemed_via}</span>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={coupon.code}
        description={coupon.name}
        backHref={`/hq/${brandSlug}/coupons`}
      >
        <Badge variant={statusBadgeVariant(computed as CouponComputedStatus)}>
          {t(`hq.coupons.status.${computed}`)}
        </Badge>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="size-8">
              <EllipsisVertical className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem asChild>
              <Link href={`/hq/${brandSlug}/coupons/${coupon.id}/edit`}>
                <Pencil className="mr-2 size-3.5" /> {t("common.edit")}
              </Link>
            </DropdownMenuItem>
            {computed !== "paused" ? (
              <DropdownMenuItem onSelect={() => pauseMutation.mutate(coupon.id)}>
                <Pause className="mr-2 size-3.5" /> {t("hq.coupons.action.pause")}
              </DropdownMenuItem>
            ) : (
              <DropdownMenuItem onSelect={() => resumeMutation.mutate(coupon.id)}>
                <Play className="mr-2 size-3.5" /> {t("hq.coupons.action.resume")}
              </DropdownMenuItem>
            )}
            <DropdownMenuItem
              disabled={!canDelete}
              onSelect={() => {
                if (!canDelete) {
                  toast.error(t("hq.coupons.error.cannot_delete_used"));
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
            <TabsTrigger value="overview">{t("hq.coupons.tabs.overview")}</TabsTrigger>
            <TabsTrigger value="redemptions">
              {t("hq.coupons.tabs.redemptions")} · {coupon.times_used}
            </TabsTrigger>
            <TabsTrigger value="branches">{t("hq.coupons.tabs.branches")}</TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="mt-4">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <Card className="p-4">
                <h3 className="mb-2 text-sm font-semibold">{t("hq.coupons.panel.discount")}</h3>
                <dl className="space-y-1 text-sm">
                  <Row
                    label={t("hq.coupons.field.discount_type")}
                    value={t(`hq.coupons.discount_type.${coupon.discount_type}`)}
                  />
                  <Row
                    label={t("hq.coupons.field.discount_value")}
                    value={
                      coupon.discount_type === "percent"
                        ? `${coupon.discount_value}%`
                        : coupon.discount_value.toLocaleString(locale)
                    }
                  />
                  {coupon.discount_type === "percent" && (
                    <Row
                      label={t("hq.coupons.field.max_discount_cap")}
                      value={
                        coupon.max_discount_cap == null
                          ? t("common.unlimited")
                          : coupon.max_discount_cap.toLocaleString(locale)
                      }
                    />
                  )}
                  <Row
                    label={t("hq.coupons.field.min_order_subtotal")}
                    value={coupon.min_order_subtotal.toLocaleString(locale)}
                  />
                </dl>
              </Card>
              <Card className="p-4">
                <h3 className="mb-2 text-sm font-semibold">{t("hq.coupons.panel.validity")}</h3>
                <dl className="space-y-1 text-sm">
                  <Row
                    label={t("hq.coupons.field.valid_from")}
                    value={formatDateTime(coupon.valid_from, locale, timezone)}
                  />
                  <Row
                    label={t("hq.coupons.field.valid_until")}
                    value={formatDateTime(coupon.valid_until, locale, timezone)}
                  />
                </dl>
              </Card>
              <Card className="p-4">
                <h3 className="mb-2 text-sm font-semibold">{t("hq.coupons.panel.limits")}</h3>
                <dl className="space-y-1 text-sm">
                  <Row
                    label={t("hq.coupons.field.usage_limit_total")}
                    value={
                      coupon.usage_limit_total == null
                        ? t("common.unlimited")
                        : `${coupon.times_used} / ${coupon.usage_limit_total}`
                    }
                  />
                  <Row
                    label={t("hq.coupons.field.usage_limit_per_customer")}
                    value={
                      coupon.usage_limit_per_customer === 0
                        ? t("common.unlimited")
                        : String(coupon.usage_limit_per_customer)
                    }
                  />
                </dl>
              </Card>
            </div>
            {coupon.description && (
              <Card className="mt-4 p-4">
                <h3 className="mb-2 text-sm font-semibold">{t("hq.coupons.field.description")}</h3>
                <p className="text-sm text-muted-foreground">{coupon.description}</p>
              </Card>
            )}
          </TabsContent>

          <TabsContent value="redemptions" className="mt-4">
            {redLoading && redemptions.length === 0 ? (
              <DataTableSkeleton columns={6} />
            ) : (
              <DataTable
                columns={redemptionColumns}
                data={redemptions}
                emptyMessage={t("hq.coupons.redemption.empty")}
              />
            )}
            {redMeta && redMeta.last_page > 1 && (
              <Pagination
                meta={redMeta}
                page={redemptionPage}
                onPageChange={setRedemptionPage}
                perPage={redMeta.per_page}
              />
            )}
          </TabsContent>

          <TabsContent value="branches" className="mt-4">
            <BranchesTab
              brandSlug={brandSlug}
              couponBranchIds={coupon.applicable_branch_ids ?? []}
            />
          </TabsContent>
        </Tabs>
      </PageContent>

      <DeleteConfirmDialog
        open={deleting}
        onOpenChange={(open) => !open && setDeleting(false)}
        title={t("hq.coupons.delete_dialog.title")}
        description={t("hq.coupons.delete_dialog.description", { code: coupon.code })}
        isPending={deleteMutation.isPending}
        onConfirm={() => {
          deleteMutation.mutate(coupon.id, {
            onSuccess: () => {
              setDeleting(false);
              router.push(`/hq/${brandSlug}/coupons`);
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
      <dd className="text-right">{value}</dd>
    </div>
  );
}

/**
 * Plan-019 branches tab.
 *
 * Two modes:
 *   - Brand-wide (couponBranchIds.length === 0): list every shop in the brand
 *     with an "All" badge so the user sees the actual coverage, not just a
 *     "applies to all" placeholder.
 *   - Whitelisted: filter the brand's shops down to the whitelisted ids and
 *     render only those, resolving id → name + slug instead of raw UUIDs.
 *
 * Either way the table looks the same shape, which keeps the user's mental
 * model simple: "this coupon is usable at these shops".
 */
function BranchesTab({
  brandSlug,
  couponBranchIds,
}: {
  brandSlug: string;
  couponBranchIds: string[];
}) {
  const { t } = useTranslation();
  const { data: shopsData, isLoading } = useShops(brandSlug, { per_page: 200 });
  const allShops = shopsData?.data ?? [];

  const brandWide = couponBranchIds.length === 0;
  const visibleShops = brandWide
    ? allShops
    : allShops.filter((s) => couponBranchIds.includes(s.id));
  // If the backend whitelist references shop IDs that aren't in the brand's
  // current shop list (rare — shop deleted/soft-deleted after coupon create),
  // surface them at the bottom as orphans so the user knows the whitelist
  // isn't a no-op.
  const knownIds = new Set(allShops.map((s) => s.id));
  const orphanIds = brandWide ? [] : couponBranchIds.filter((id) => !knownIds.has(id));

  return (
    <Card className="p-4">
      <div className="mb-3 flex items-center justify-between gap-2">
        <div className="text-sm font-semibold">
          {brandWide
            ? t("hq.coupons.branches.title_all")
            : t("hq.coupons.branches.title_whitelist", { count: couponBranchIds.length })}
        </div>
        <Badge variant={brandWide ? "default" : "outline"}>
          {brandWide
            ? t("hq.coupons.branches.badge_all")
            : t("hq.coupons.branches.badge_whitelist")}
        </Badge>
      </div>

      {isLoading && shopsData === undefined ? (
        <Skeleton className="h-12 w-full" />
      ) : visibleShops.length === 0 && orphanIds.length === 0 ? (
        <p className="text-sm text-muted-foreground">{t("hq.coupons.branches.empty")}</p>
      ) : (
        <ul className="divide-y rounded-md border">
          {visibleShops.map((s) => (
            <li key={s.id} className="flex items-center justify-between px-3 py-2 text-sm">
              <span className="font-medium">{s.name}</span>
              <span className="font-mono text-xs text-muted-foreground">{s.slug}</span>
            </li>
          ))}
          {orphanIds.map((id) => (
            <li
              key={id}
              className="flex items-center justify-between px-3 py-2 text-sm text-muted-foreground"
            >
              <span className="font-mono text-xs">{id.slice(0, 8)}…</span>
              <Badge variant="destructive" className="text-[10px]">
                {t("hq.coupons.branches.orphan")}
              </Badge>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
