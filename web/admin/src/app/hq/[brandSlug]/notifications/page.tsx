"use client";

/**
 * `/hq/[brandSlug]/notifications` — HQ audit of every notification in the
 * brand's organizations (plan-008 T8.5 + plan-012 visual refresh).
 *
 * Filter bar + paginated table + detail Sheet with full recipients fan-out.
 */

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  Input,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  Skeleton,
  Spinner,
} from "@godxjp/ui";
import {
  AlertCircle,
  Ban,
  Bell,
  BookOpenCheck,
  Building2,
  CalendarClock,
  Check,
  Clock,
  Eye,
  Filter,
  Key,
  Search,
  Send,
  Tag,
  Users,
  XCircle,
  Zap,
} from "lucide-react";
import { useParams, useSearchParams } from "next/navigation";
import { useEffect, useMemo, useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import {
  useAdminNotificationDetail,
  useAdminNotificationList,
  useAdminNotificationStats,
  useAdminNotificationTypes,
} from "@/hooks/api/use-notifications";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type {
  AdminActorTypeFilter,
  AdminNotificationFilters,
  AdminNotificationListRow,
  NotificationPriority,
} from "@/services/notification-service";

const PRIORITY_TONE: Record<NotificationPriority, string> = {
  low: "bg-muted text-muted-foreground",
  normal:
    "bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:border-blue-900",
  high: "bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900",
  urgent:
    "bg-red-100 text-red-700 border-red-200 dark:bg-red-950 dark:text-red-300 dark:border-red-900",
};

export default function HqNotificationsPage() {
  const { t } = useTranslation();
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;

  const searchParams = useSearchParams();
  const detailParam = searchParams.get("detail");

  const [filters, setFilters] = useState<AdminNotificationFilters>({ per_page: 25 });
  const [detailId, setDetailId] = useState<string | null>(detailParam);

  useEffect(() => {
    if (detailParam) setDetailId(detailParam);
  }, [detailParam]);

  const list = useAdminNotificationList(brandSlug, filters);
  const types = useAdminNotificationTypes(brandSlug);
  const stats = useAdminNotificationStats(brandSlug);
  const detail = useAdminNotificationDetail(brandSlug, detailId, {
    enabled: Boolean(detailId),
  });

  const patch = (next: Partial<AdminNotificationFilters>) =>
    setFilters((prev) => ({ ...prev, ...next, page: 1 }));

  const hasFilters = Boolean(
    filters.type?.length || filters.priority || filters.actor_type || filters.search
  );

  const rows = list.data?.data ?? [];
  const total = list.data?.meta?.total ?? 0;

  return (
    <>
      <PageHeader
        title={t("notifications.admin.title")}
        description={t("notifications.admin.subtitle")}
      >
        {/* Quick stats (TC-NOTIF-ROOT04) */}
        <div className="flex flex-wrap gap-2" data-slot="notifications-quick-stats">
          <QuickStat
            icon={<Send className="size-3.5" />}
            label={t("notifications.admin.stats.sent")}
            value={stats.data?.sent}
            loading={stats.isLoading}
            tone="primary"
          />
          <QuickStat
            icon={<CalendarClock className="size-3.5" />}
            label={t("notifications.admin.stats.scheduled")}
            value={stats.data?.scheduled}
            loading={stats.isLoading}
            tone="info"
          />
          <QuickStat
            icon={<XCircle className="size-3.5" />}
            label={t("notifications.admin.stats.failed")}
            value={stats.data?.failed}
            loading={stats.isLoading}
            tone="danger"
          />
        </div>
      </PageHeader>

      <PageContent>
        {/* Filter bar */}
        <Card className="mb-4">
          <CardContent className="p-4">
            <div className="mb-3 flex items-center gap-2">
              <Filter className="size-4 text-muted-foreground" />
              <span className="text-sm font-medium">{t("common.filter")}</span>
              {hasFilters ? (
                <Badge variant="secondary" className="text-[10px]">
                  {t("notifications.admin.filters.active")}
                </Badge>
              ) : null}
              {hasFilters ? (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => setFilters({ per_page: 25 })}
                  className="ml-auto h-7 text-xs"
                >
                  {t("notifications.admin.filters.reset")}
                </Button>
              ) : null}
            </div>

            <div className="grid grid-cols-1 gap-3 md:grid-cols-12">
              <div className="md:col-span-4">
                <label className="mb-1 block text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                  {t("notifications.admin.filters.type")}
                </label>
                <Select
                  value={filters.type?.[0] ?? "_all"}
                  onValueChange={(v) => patch({ type: v === "_all" ? undefined : [v] })}
                >
                  <SelectTrigger data-slot="filter-type" className="h-9">
                    <SelectValue placeholder={t("notifications.admin.filters.type")} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="_all">{t("common.all")}</SelectItem>
                    {types.data?.map((row) => (
                      <SelectItem key={row.type} value={row.type}>
                        <span className="font-mono text-xs">{row.type}</span>
                        <span className="ml-2 text-[10px] text-muted-foreground">
                          · {row.count}
                        </span>
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="md:col-span-2">
                <label className="mb-1 block text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                  {t("notifications.admin.filters.priority")}
                </label>
                <Select
                  value={filters.priority ?? "_all"}
                  onValueChange={(v) =>
                    patch({ priority: v === "_all" ? undefined : (v as NotificationPriority) })
                  }
                >
                  <SelectTrigger data-slot="filter-priority" className="h-9">
                    <SelectValue placeholder={t("notifications.admin.filters.priority")} />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="_all">{t("common.all")}</SelectItem>
                    <SelectItem value="low">{t("notifications.priority.low")}</SelectItem>
                    <SelectItem value="normal">{t("notifications.priority.normal")}</SelectItem>
                    <SelectItem value="high">{t("notifications.priority.high")}</SelectItem>
                    <SelectItem value="urgent">{t("notifications.priority.urgent")}</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="md:col-span-2">
                <label className="mb-1 block text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                  {t("notifications.admin.columns.actor")}
                </label>
                <Select
                  value={filters.actor_type ?? "_all"}
                  onValueChange={(v) =>
                    patch({ actor_type: v === "_all" ? undefined : (v as AdminActorTypeFilter) })
                  }
                >
                  <SelectTrigger data-slot="filter-actor-type" className="h-9">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="_all">{t("common.all")}</SelectItem>
                    <SelectItem value="null">
                      {t("notifications.admin.filters.actor_system")}
                    </SelectItem>
                    <SelectItem value="User">
                      {t("notifications.admin.filters.actor_user")}
                    </SelectItem>
                    <SelectItem value="Device">
                      {t("notifications.admin.filters.actor_device")}
                    </SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="md:col-span-4">
                <label className="mb-1 block text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                  {t("common.search")}
                </label>
                <div className="relative">
                  <Search className="absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    placeholder={t("notifications.admin.filters.search_placeholder")}
                    value={filters.search ?? ""}
                    onChange={(e) => patch({ search: e.target.value || undefined })}
                    data-slot="filter-search"
                    className="h-9 pl-8"
                  />
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        {/* Result count */}
        {!list.isLoading && !list.isError ? (
          <p className="mb-2 text-xs text-muted-foreground">
            {t("notifications.admin.total", { count: total })}
          </p>
        ) : null}

        {/* Table */}
        {list.isLoading ? (
          <TableSkeleton />
        ) : list.isError ? (
          <Alert
            variant="destructive"
            data-slot="admin-notifications-error"
            className="flex items-start gap-3"
          >
            <AlertCircle className="size-4" />
            <AlertDescription className="flex-1">{t("notifications.admin.error")}</AlertDescription>
            <Button
              variant="outline"
              size="sm"
              onClick={() => list.refetch()}
              disabled={list.isFetching}
              data-slot="admin-notifications-retry"
              className="h-7 shrink-0 text-xs"
            >
              {list.isFetching && <Spinner className="mr-1.5 size-3" />}
              {t("common.retry")}
            </Button>
          </Alert>
        ) : rows.length === 0 ? (
          <EmptyState filtered={hasFilters} t={t} />
        ) : (
          <Card className="overflow-hidden">
            <div className="overflow-x-auto">
              <table className="w-full text-sm" data-slot="admin-notifications-table">
                <thead className="bg-muted/30">
                  <tr>
                    <Th>{t("notifications.admin.columns.type")}</Th>
                    <Th>{t("notifications.admin.columns.actor")}</Th>
                    <Th>{t("notifications.admin.columns.priority")}</Th>
                    <Th className="text-center">{t("notifications.admin.columns.recipients")}</Th>
                    <Th>{t("notifications.admin.columns.created")}</Th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((row) => (
                    <TableRow key={row.id} row={row} onClick={() => setDetailId(row.id)} t={t} />
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        )}
      </PageContent>

      <Sheet open={Boolean(detailId)} onOpenChange={(open) => !open && setDetailId(null)}>
        <SheetContent className="flex w-full flex-col gap-4 p-6 sm:max-w-xl">
          <SheetHeader className="space-y-2 p-0">
            <SheetTitle className="flex items-center gap-2">
              <div className="rounded-md bg-primary/10 p-1.5">
                <Bell className="size-4 text-primary" />
              </div>
              {t("notifications.admin.detail.title")}
            </SheetTitle>
            {detail.data ? (
              <SheetDescription className="font-mono text-xs">{detail.data.type}</SheetDescription>
            ) : null}
          </SheetHeader>

          <div className="-mx-6 flex-1 overflow-y-auto px-6 pb-2">
            {detail.isLoading ? (
              <div className="flex h-40 items-center justify-center">
                <Spinner className="size-5" />
              </div>
            ) : detail.data ? (
              <DetailContent data={detail.data} t={t} />
            ) : null}
          </div>
        </SheetContent>
      </Sheet>
    </>
  );
}

function QuickStat({
  icon,
  label,
  value,
  loading,
  tone,
}: {
  icon: React.ReactNode;
  label: string;
  value: number | undefined;
  loading: boolean;
  tone: "primary" | "info" | "danger";
}) {
  const toneCls = {
    primary: "text-primary",
    info: "text-blue-600 dark:text-blue-400",
    danger: "text-red-600 dark:text-red-400",
  }[tone];
  return (
    <div className="flex items-center gap-2 rounded-md border bg-card px-3 py-1.5">
      <span className={toneCls}>{icon}</span>
      <span className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
        {label}
      </span>
      {loading ? (
        <Skeleton className="h-4 w-6" />
      ) : (
        <span className="text-sm font-semibold tabular-nums">{(value ?? 0).toLocaleString()}</span>
      )}
    </div>
  );
}

function Th({ children, className = "" }: { children: React.ReactNode; className?: string }) {
  return (
    <th
      className={`px-4 py-2.5 text-left text-[10px] font-medium tracking-wider text-muted-foreground uppercase ${className}`}
    >
      {children}
    </th>
  );
}

function TableRow({
  row,
  onClick,
  t,
}: {
  row: AdminNotificationListRow;
  onClick: () => void;
  t: (k: string, p?: Record<string, string | number>) => string;
}) {
  const { locale } = useTranslation();
  const { timezone } = useTimezone();
  const { total, read } = row.recipients_summary;
  const readPct = total > 0 ? Math.round((read / total) * 100) : 0;
  return (
    <tr
      className="cursor-pointer border-t transition hover:bg-muted/20"
      data-slot="admin-notifications-row"
      onClick={onClick}
    >
      <td className="px-4 py-3">
        <div className="flex items-center gap-2">
          <div
            className={`size-2 shrink-0 rounded-full ${
              row.priority === "urgent"
                ? "bg-red-500"
                : row.priority === "high"
                  ? "bg-amber-500"
                  : row.priority === "normal"
                    ? "bg-blue-500"
                    : "bg-muted-foreground/50"
            }`}
          />
          <code className="text-xs font-medium">{row.type}</code>
        </div>
      </td>
      <td className="px-4 py-3 text-sm">
        {row.actor?.display_name ?? (
          <span className="text-muted-foreground italic">{t("notifications.actor.system")}</span>
        )}
      </td>
      <td className="px-4 py-3">
        <Badge variant="outline" className={`text-[10px] ${PRIORITY_TONE[row.priority]}`}>
          {row.priority === "urgent" ? <Zap className="mr-1 size-3" /> : null}
          {t(`notifications.priority.${row.priority}`)}
        </Badge>
      </td>
      <td className="px-4 py-3">
        <div className="flex items-center justify-center gap-2">
          <span className="text-xs tabular-nums">
            <span className="font-semibold">{read}</span>
            <span className="text-muted-foreground">/{total}</span>
          </span>
          <div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted">
            <div className="h-full bg-primary transition-all" style={{ width: `${readPct}%` }} />
          </div>
        </div>
      </td>
      <td className="px-4 py-3 text-xs text-muted-foreground">
        {formatDateTime(row.created_at, locale, timezone)}
      </td>
    </tr>
  );
}

function TableSkeleton() {
  return (
    <Card className="overflow-hidden">
      <div className="divide-y">
        {[0, 1, 2, 3, 4].map((i) => (
          <div key={i} className="flex items-center gap-4 p-4">
            <Skeleton className="size-2 rounded-full" />
            <Skeleton className="h-4 w-32" />
            <Skeleton className="h-4 w-24" />
            <Skeleton className="h-4 w-16" />
            <Skeleton className="ml-auto h-4 w-28" />
          </div>
        ))}
      </div>
    </Card>
  );
}

function EmptyState({ filtered, t }: { filtered: boolean; t: (k: string) => string }) {
  return (
    <Card>
      <CardContent className="flex flex-col items-center justify-center gap-3 py-16 text-center">
        <div className="rounded-full bg-muted p-4">
          <Bell className="size-8 text-muted-foreground" />
        </div>
        <p className="text-base font-medium">
          {filtered
            ? t("notifications.admin.empty_filtered")
            : t("notifications.admin.empty_title")}
        </p>
        <p className="max-w-sm text-sm text-muted-foreground">
          {filtered
            ? t("notifications.admin.empty_filtered_hint")
            : t("notifications.admin.empty_hint")}
        </p>
      </CardContent>
    </Card>
  );
}

function DetailContent({
  data,
  t,
}: {
  data: NonNullable<ReturnType<typeof useAdminNotificationDetail>["data"]>;
  t: (k: string, p?: Record<string, string | number>) => string;
}) {
  const { locale } = useTranslation();
  const { timezone } = useTimezone();
  const { recipients } = data;
  const counts = useMemo(() => {
    let read = 0;
    let seen = 0;
    let dismissed = 0;
    let unread = 0;
    recipients.forEach((r) => {
      if (r.dismissed_at) dismissed++;
      else if (r.read_at) read++;
      else if (r.seen_at) seen++;
      else unread++;
    });
    return { read, seen, dismissed, unread, total: recipients.length };
  }, [recipients]);

  return (
    <div className="space-y-4 py-4 text-sm">
      {/* Meta */}
      <div className="space-y-2 rounded-md border bg-muted/20 p-3 text-xs">
        <MetaRow
          icon={<Building2 className="size-3.5" />}
          label={t("notifications.admin.columns.actor")}
        >
          {data.actor?.display_name ?? (
            <span className="text-muted-foreground italic">{t("notifications.actor.system")}</span>
          )}
        </MetaRow>
        {data.subject ? (
          <MetaRow
            icon={<Tag className="size-3.5" />}
            label={t("notifications.admin.columns.subject")}
          >
            <span>
              <span className="font-mono text-[11px] text-muted-foreground">
                {data.subject.type}
              </span>
              {": "}
              {data.subject.display_name ?? data.subject.id}
            </span>
          </MetaRow>
        ) : null}
        {data.idempotency_key ? (
          <MetaRow
            icon={<Key className="size-3.5" />}
            label={t("notifications.admin.detail.idempotency_key")}
          >
            <code className="font-mono text-[11px]">{data.idempotency_key}</code>
          </MetaRow>
        ) : null}
        <MetaRow
          icon={<Clock className="size-3.5" />}
          label={t("notifications.admin.columns.created")}
        >
          {formatDateTime(data.created_at, locale, timezone)}
        </MetaRow>
      </div>

      {/* Fan-out summary */}
      <div className="grid grid-cols-4 gap-2">
        <StatusTile
          icon={<BookOpenCheck className="size-3.5" />}
          label={t("notifications.admin.detail.recipient_state.read")}
          value={counts.read}
          tone="primary"
        />
        <StatusTile
          icon={<Eye className="size-3.5" />}
          label={t("notifications.admin.detail.recipient_state.seen")}
          value={counts.seen}
          tone="info"
        />
        <StatusTile
          icon={<Bell className="size-3.5" />}
          label={t("notifications.admin.detail.recipient_state.unread")}
          value={counts.unread}
          tone="neutral"
        />
        <StatusTile
          icon={<Ban className="size-3.5" />}
          label={t("notifications.admin.detail.recipient_state.dismissed")}
          value={counts.dismissed}
          tone="muted"
        />
      </div>

      {/* Recipient list */}
      <section>
        <div className="mb-2 flex items-center gap-2">
          <Users className="size-3.5 text-muted-foreground" />
          <h3 className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
            {t("notifications.admin.detail.recipients")}
          </h3>
          <Badge variant="outline" className="text-[10px]">
            {recipients.length}
          </Badge>
        </div>
        <Card className="overflow-hidden">
          <ul className="divide-y">
            {recipients.map((r) => (
              <RecipientRow key={r.id} row={r} t={t} />
            ))}
          </ul>
        </Card>
      </section>
    </div>
  );
}

function MetaRow({
  icon,
  label,
  children,
}: {
  icon: React.ReactNode;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-start gap-2">
      <div className="mt-0.5 flex shrink-0 items-center gap-1 text-muted-foreground">
        {icon}
        <span className="text-[10px] tracking-wider uppercase">{label}</span>
      </div>
      <div className="flex-1 break-all text-foreground">{children}</div>
    </div>
  );
}

function StatusTile({
  icon,
  label,
  value,
  tone,
}: {
  icon: React.ReactNode;
  label: string;
  value: number;
  tone: "primary" | "info" | "neutral" | "muted";
}) {
  const toneCls = {
    primary: "bg-primary/10 text-primary",
    info: "bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300",
    neutral: "bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300",
    muted: "bg-muted text-muted-foreground",
  }[tone];
  return (
    <div className="rounded-md border p-2 text-center">
      <div
        className={`mx-auto mb-1 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[9px] font-medium tracking-wider uppercase ${toneCls}`}
      >
        {icon}
        {label}
      </div>
      <p className="text-lg font-semibold tabular-nums">{value}</p>
    </div>
  );
}

function RecipientRow({
  row,
  t,
}: {
  row: NonNullable<ReturnType<typeof useAdminNotificationDetail>["data"]>["recipients"][number];
  t: (k: string) => string;
}) {
  const state = row.dismissed_at
    ? "dismissed"
    : row.read_at
      ? "read"
      : row.seen_at
        ? "seen"
        : "unread";
  const stateIcon = {
    dismissed: <Ban className="size-3" />,
    read: <BookOpenCheck className="size-3" />,
    seen: <Eye className="size-3" />,
    unread: <Bell className="size-3" />,
  }[state];
  const stateTone = {
    dismissed: "text-muted-foreground",
    read: "text-primary",
    seen: "text-blue-600 dark:text-blue-400",
    unread: "text-amber-600 dark:text-amber-400",
  }[state];

  return (
    <li className="flex items-center gap-3 px-3 py-2 text-xs">
      <div className="min-w-0 flex-1">
        <p className="truncate">
          {row.recipient?.display_name ?? row.recipient?.id.slice(0, 8) ?? "—"}
        </p>
        <p className="truncate text-[10px] text-muted-foreground">{row.recipient?.type}</p>
      </div>
      <div className={`flex shrink-0 items-center gap-1 ${stateTone}`}>
        {stateIcon}
        <span className="text-[10px] tracking-wider uppercase">
          {t(`notifications.admin.detail.recipient_state.${state}`)}
        </span>
      </div>
    </li>
  );
}
