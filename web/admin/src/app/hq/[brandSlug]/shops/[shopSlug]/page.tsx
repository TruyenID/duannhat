"use client";

import { useState } from "react";
import { notFound, useParams, useRouter } from "next/navigation";
import {
  Crown,
  ExternalLink,
  Hash,
  Package,
  Pencil,
  Store,
  Trash2,
  UtensilsCrossed,
} from "lucide-react";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { Button, StatusBadge } from "@godxjp/ui";
import { useShop, useDeleteShop } from "@/hooks/api/use-shops";
import { ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";

import { Spinner } from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import { EditShopDialog } from "./components/edit-shop-dialog";

const WEEKLY_DAYS = ["mon", "tue", "wed", "thu", "fri", "sat", "sun"] as const;

export default function ShopDetailPage() {
  const params = useParams<{ brandSlug: string; shopSlug: string }>();
  const brandSlug = params.brandSlug;
  const shopSlug = params.shopSlug;
  const { t } = useTranslation();
  const router = useRouter();
  const [editOpen, setEditOpen] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const deleteShop = useDeleteShop(brandSlug);

  const { data: shop, isLoading, isError, error } = useShop(shopSlug);

  // 404 / 403 from the resolver middleware → standard not-found UI.
  if (isError && error instanceof ApiError && (error.status === 404 || error.status === 403)) {
    notFound();
  }

  if (isLoading || !shop) {
    return (
      <>
        <PageHeader
          title={t("hq.shops.detail.title_fallback")}
          backHref={`/hq/${brandSlug}/shops`}
        />
        <PageContent>
          <div className="flex items-center justify-center py-16 text-sm text-muted-foreground">
            <Spinner className="mr-2 size-4" /> {t("hq.shops.detail.loading")}
          </div>
        </PageContent>
      </>
    );
  }

  return (
    <>
      <PageHeader title={shop.name} description={shop.slug} backHref={`/hq/${brandSlug}/shops`}>
        <Button size="sm" variant="outline" onClick={() => setEditOpen(true)}>
          <Pencil className="size-3" /> {t("common.edit")}
        </Button>
        {!shop.is_headquarters && (
          <Button
            size="sm"
            variant="outline"
            className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
            onClick={() => setConfirmDelete(true)}
            disabled={deleteShop.isPending}
          >
            <Trash2 className="size-3" /> {t("common.delete")}
          </Button>
        )}
        <a
          href={`/shop/${shop.slug}/dashboard`}
          target="_blank"
          rel="noopener noreferrer"
          className="inline-flex h-7 items-center gap-1 rounded-md border border-input bg-background px-2 text-xs font-medium hover:bg-accent"
        >
          <ExternalLink className="size-3" /> {t("hq.shops.detail.open_pos")}
        </a>
      </PageHeader>

      <PageContent>
        {isError && !(error instanceof ApiError) && (
          <div className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
            {t("hq.shops.detail.load_error", {
              message: error instanceof Error ? error.message : t("hq.shops.detail.unknown_error"),
            })}
          </div>
        )}

        {/* ===== Header card ===== */}
        <div className="mb-4 flex items-start gap-3 rounded-lg border bg-card p-4">
          <div className="flex size-12 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
            <Store className="size-5" />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-1.5">
              <h2 className="text-base font-semibold">{shop.name}</h2>
              <StatusBadge status="active" />
              {shop.is_headquarters && (
                <span
                  className="inline-flex h-5 items-center gap-0.5 rounded bg-purple-100 px-1.5 text-[10px] font-medium text-purple-700"
                  title={t("hq.shops.detail.hq_title")}
                >
                  <Crown className="size-2.5" /> {t("hq.shops.detail.hq_label")}
                </span>
              )}
            </div>
            <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
              <span className="inline-flex items-center gap-1">
                <Hash className="size-3" />
                {shop.slug}
              </span>
              {shop.code && (
                <span>
                  {t("hq.shops.detail.code_prefix")} <span className="font-mono">{shop.code}</span>
                </span>
              )}
            </div>
          </div>
        </div>

        {/* ===== Stat cards ===== */}
        <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard
            icon={<UtensilsCrossed className="size-4" />}
            label={t("hq.shops.detail.active_menu")}
            value="—"
            hint={t("hq.shops.detail.active_menu_hint")}
          />
          <StatCard
            icon={<Package className="size-4" />}
            label={t("hq.shops.detail.tables")}
            value="—"
            hint={t("hq.shops.detail.tables_hint")}
          />
          <StatCard
            icon={<Store className="size-4" />}
            label={t("hq.shops.detail.status")}
            value={t("hq.shops.detail.status_active")}
            hint={t("hq.shops.detail.status_hint")}
          />
          <StatCard
            icon={<Crown className="size-4" />}
            label={t("hq.shops.detail.branch_type")}
            value={
              shop.is_headquarters
                ? t("hq.shops.detail.headquarters")
                : t("hq.shops.detail.standard")
            }
          />
        </div>

        {/* ===== Info card ===== */}
        <div className="rounded-lg border bg-card">
          <div className="border-b px-4 py-2.5">
            <h3 className="text-sm font-semibold">{t("hq.shops.detail.info_heading")}</h3>
          </div>
          <dl className="divide-y text-sm">
            <Row label={t("common.name")} value={shop.name} />
            <Row label={t("common.slug")} value={shop.slug} mono />
            <Row label={t("hq.shops.form.timezone")} value={shop.timezone ?? "—"} />
            <Row label={t("common.code")} value={shop.code ?? "—"} mono />
            <Row label={t("common.address")} value={shop.address ?? "—"} />
            <Row label={t("common.phone")} value={shop.phone ?? "—"} />
            <Row
              label={t("hq.shops.form.seat_capacity")}
              value={shop.seat_capacity != null ? String(shop.seat_capacity) : "—"}
            />
            <Row label={t("hq.shops.form.business_hours")} value={shop.business_hours ?? "—"} />
            <Row
              label={t("hq.shops.form.weekly_hours")}
              value={formatWeekly(shop.weekly_hours, t)}
            />
            <Row label={t("hq.shops.detail.branch_id")} value={shop.id} mono small />
            <Row
              label={t("hq.shops.detail.console_brand_id")}
              value={shop.console_brand_id ?? "—"}
              mono
              small
            />
            <Row
              label={t("hq.shops.detail.is_hq")}
              value={shop.is_headquarters ? t("hq.shops.detail.yes") : t("hq.shops.detail.no")}
            />
          </dl>
        </div>

        {/* ===== Quick links ===== */}
        <div className="mt-4 rounded-lg border bg-card">
          <div className="border-b px-4 py-2.5">
            <h3 className="text-sm font-semibold">{t("hq.shops.detail.quick_links")}</h3>
          </div>
          <div className="grid grid-cols-1 divide-y sm:grid-cols-2 sm:divide-x sm:divide-y-0">
            <QuickLink
              href={`/shop/${shop.slug}/dashboard`}
              external
              icon={<Store className="size-4" />}
              title={t("hq.shops.detail.ql_pos_title")}
              description={t("hq.shops.detail.ql_pos_desc")}
            />
            <QuickLink
              href={`/shop/${shop.slug}/menu`}
              external
              icon={<UtensilsCrossed className="size-4" />}
              title={t("hq.shops.detail.ql_menu_title")}
              description={t("hq.shops.detail.ql_menu_desc")}
            />
          </div>
        </div>
      </PageContent>

      <EditShopDialog
        brandSlug={brandSlug}
        shop={shop}
        open={editOpen}
        onOpenChange={setEditOpen}
      />

      {/* Delete from detail — confirm, then navigate back to the list. */}
      <DeleteConfirmDialog
        open={confirmDelete}
        onOpenChange={setConfirmDelete}
        description={t("hq.shops.delete_confirm", { name: shop.name })}
        onConfirm={() => {
          deleteShop.mutate(shop.id, {
            onSuccess: () => {
              setConfirmDelete(false);
              router.push(`/hq/${brandSlug}/shops`);
            },
          });
        }}
        isPending={deleteShop.isPending}
      />
    </>
  );
}

// ---------------------------------------------------------------------------

function formatWeekly(
  wh: import("@/services/shop-service").WeeklyHoursMap | null,
  t: (key: string) => string
): string {
  if (!wh) return "—";
  const parts: string[] = [];
  for (const d of WEEKLY_DAYS) {
    const entry = wh[d];
    if (!entry) continue;
    const label = t(`common.day.${d}`);
    if (entry.closed) parts.push(`${label}: ${t("hq.shops.form.day_closed")}`);
    else if (entry.open || entry.close)
      parts.push(`${label}: ${entry.open ?? ""}–${entry.close ?? ""}`);
  }
  return parts.length > 0 ? parts.join(" / ") : "—";
}

// ---------------------------------------------------------------------------

function StatCard({
  icon,
  label,
  value,
  hint,
}: {
  icon: React.ReactNode;
  label: string;
  value: string;
  hint?: string;
}) {
  return (
    <div className="rounded-lg border bg-card p-3">
      <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
        {icon}
        {label}
      </div>
      <div className="mt-1 text-xl font-semibold">{value}</div>
      {hint && <div className="mt-0.5 text-[11px] text-muted-foreground">{hint}</div>}
    </div>
  );
}

function Row({
  label,
  value,
  mono = false,
  small = false,
}: {
  label: string;
  value: string;
  mono?: boolean;
  small?: boolean;
}) {
  return (
    <div className="flex items-start justify-between gap-4 px-4 py-2.5">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={`text-right ${mono ? "font-mono" : ""} ${small ? "text-xs" : "text-sm"}`}>
        {value}
      </dd>
    </div>
  );
}

function QuickLink({
  href,
  icon,
  title,
  description,
  external = false,
}: {
  href: string;
  icon: React.ReactNode;
  title: string;
  description: string;
  external?: boolean;
}) {
  return (
    <a
      href={href}
      target={external ? "_blank" : undefined}
      rel={external ? "noopener noreferrer" : undefined}
      className="group flex items-start gap-3 px-4 py-3 hover:bg-accent"
    >
      <div className="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
        {icon}
      </div>
      <div className="flex-1">
        <div className="flex items-center gap-1 text-sm font-medium">
          {title}
          {external && (
            <ExternalLink className="size-3 text-muted-foreground group-hover:text-foreground" />
          )}
        </div>
        <div className="text-xs text-muted-foreground">{description}</div>
      </div>
    </a>
  );
}
