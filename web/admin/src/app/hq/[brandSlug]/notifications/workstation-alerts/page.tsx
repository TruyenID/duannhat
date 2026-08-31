"use client";

/**
 * `/hq/[brandSlug]/notifications/workstation-alerts` — #1806 S3, phần còn lại.
 *
 * ## Đây là TRÌNH BÀY, không phải chức năng thiếu
 *
 * Cảnh báo máy trạm đã tới hộp thư HQ từ khi `AlertController` ship. Màn
 * `/notifications` sẵn có cũng hiện chúng — nhưng hiện theo dòng thời gian, xen
 * giữa mọi loại thông báo khác, mỗi dòng là một `type` + `params` JSON. Ở đó
 * "quán Shibuya mất máy in ba ngày rồi" tồn tại nhưng không ĐỌC RA được.
 *
 * Màn này không thêm dữ liệu nào; nó đổi grain sang thứ HQ thật sự hỏi: **quán
 * nào đang có chuyện, và đi cứu quán nào trước.**
 *
 * ## Ba thứ luôn hiện, theo ruling #1806
 *
 * | Trường | Vì sao bắt buộc |
 * |---|---|
 * | tên quán | HQ nhìn nhiều quán; một cảnh báo không có địa chỉ thì không hành động được |
 * | `count` | alert gộp theo `(kind, subject)` — một dòng có thể đại diện 200 lần |
 * | `first_seen` | "vừa xảy ra" và "kéo dài từ thứ Hai" là hai việc khác nhau |
 *
 * ## KHÔNG dịch `kind`
 *
 * `kind` là định danh của registry phía Go (`alert_kinds.go`). Dịch nó ở đây
 * tạo ra danh sách thứ hai cho cùng một tập, và danh sách đó sẽ lặng lẽ trôi
 * khỏi bản gốc khi Go thêm kind mới — người đọc nhận một khoá i18n thô đúng
 * lúc có sự cố. Nên `kind` hiện nguyên dạng mono, còn chữ cho người đọc là
 * `title` do chính máy trạm gửi kèm.
 *
 * ## Trống và lỗi KHÔNG được trông giống nhau
 *
 * Trên màn cảnh báo, "không có quán nào báo lỗi" và "không tải được" là hai
 * kết luận ngược nhau. Vẽ chung một bảng rỗng cho cả hai là mời người trực
 * đóng máy tính vì tin vào cái thứ hai.
 */

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Skeleton,
} from "@godxjp/ui";
import {
  AlertTriangle,
  Building2,
  Clock,
  Hash,
  Info,
  ShieldAlert,
  Store,
  TriangleAlert,
} from "lucide-react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { useMemo, useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useWorkstationAlertFeed } from "@/hooks/api/use-hq-workstation-alerts";
import { formatDateTime } from "@/lib/date";
import { useTimezone, useTranslation } from "@/providers/app-provider";
import type {
  AlertSeverity,
  BranchAlertGroup,
} from "@/services/hq-workstation-alert-service";

const WINDOW_OPTIONS = [1, 7, 30] as const;

const SEVERITY_TONE: Record<AlertSeverity, string> = {
  critical:
    "bg-red-100 text-red-700 border-red-200 dark:bg-red-950 dark:text-red-300 dark:border-red-900",
  warning:
    "bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900",
  info: "bg-muted text-muted-foreground",
};

const SEVERITY_ICON: Record<AlertSeverity, typeof Info> = {
  critical: ShieldAlert,
  warning: TriangleAlert,
  info: Info,
};

export default function HqWorkstationAlertsPage() {
  const { t } = useTranslation();
  const { timezone } = useTimezone();
  const { brandSlug } = useParams<{ brandSlug: string }>();

  const [windowDays, setWindowDays] = useState<number>(1);
  const [severity, setSeverity] = useState<AlertSeverity | undefined>(undefined);

  const query = useMemo(() => ({ windowDays, severity }), [windowDays, severity]);
  const feed = useWorkstationAlertFeed(brandSlug, query);

  const groups = feed.data?.groups ?? [];
  const incidentCount = groups.reduce((sum, g) => sum + g.incidents.length, 0);
  const criticalCount = groups.reduce((sum, g) => sum + g.severityCounts.critical, 0);

  return (
    <>
      <PageHeader
        title={t("hq.workstation_alerts.title")}
        description={t("hq.workstation_alerts.subtitle")}
        onRefresh={() => void feed.refetch()}
        isRefreshing={feed.isFetching}
      >
        <div className="flex flex-wrap gap-2" data-slot="workstation-alerts-stats">
          <Stat
            icon={<Store className="size-3.5" />}
            label={t("hq.workstation_alerts.stats.branches")}
            value={groups.length}
            loading={feed.isLoading}
          />
          <Stat
            icon={<ShieldAlert className="size-3.5" />}
            label={t("hq.workstation_alerts.stats.critical")}
            value={criticalCount}
            loading={feed.isLoading}
            tone={criticalCount > 0 ? "danger" : undefined}
          />
          <Stat
            icon={<Hash className="size-3.5" />}
            label={t("hq.workstation_alerts.stats.incidents")}
            value={incidentCount}
            loading={feed.isLoading}
          />
        </div>
      </PageHeader>

      <PageContent>
        <div className="mb-4 flex flex-wrap items-end gap-3">
          <div className="w-44">
            <label className="mb-1 block text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
              {t("hq.workstation_alerts.filter.window")}
            </label>
            <Select value={String(windowDays)} onValueChange={(v) => setWindowDays(Number(v))}>
              <SelectTrigger data-slot="filter-window" className="h-9">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {WINDOW_OPTIONS.map((days) => (
                  <SelectItem key={days} value={String(days)}>
                    {t(`hq.workstation_alerts.window.${days}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="w-44">
            <label className="mb-1 block text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
              {t("hq.workstation_alerts.filter.severity")}
            </label>
            <Select
              value={severity ?? "_all"}
              onValueChange={(v) => setSeverity(v === "_all" ? undefined : (v as AlertSeverity))}
            >
              <SelectTrigger data-slot="filter-severity" className="h-9">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="_all">{t("hq.workstation_alerts.severity.all")}</SelectItem>
                <SelectItem value="critical">
                  {t("hq.workstation_alerts.severity.critical")}
                </SelectItem>
                <SelectItem value="warning">
                  {t("hq.workstation_alerts.severity.warning")}
                </SelectItem>
                <SelectItem value="info">{t("hq.workstation_alerts.severity.info")}</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        {/* Cắt bớt phải nói ra — xem docblock `truncated` trong service. */}
        {feed.data?.truncated ? (
          <Alert className="mb-4" data-slot="workstation-alerts-truncated">
            <AlertTriangle className="size-4" />
            <AlertDescription>
              {t("hq.workstation_alerts.truncated", {
                loaded: feed.data.loaded,
                total: feed.data.total,
              })}
            </AlertDescription>
          </Alert>
        ) : null}

        {feed.isError ? (
          <Card data-slot="workstation-alerts-error">
            <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
              <TriangleAlert className="size-6 text-destructive" />
              <p className="text-sm font-medium">{t("hq.workstation_alerts.error.title")}</p>
              <p className="text-xs text-muted-foreground">
                {t("hq.workstation_alerts.error.hint")}
              </p>
              <Button variant="outline" size="sm" onClick={() => void feed.refetch()}>
                {t("common.retry")}
              </Button>
            </CardContent>
          </Card>
        ) : feed.isLoading ? (
          <div className="space-y-3" data-slot="workstation-alerts-loading">
            <Skeleton className="h-28 w-full" />
            <Skeleton className="h-28 w-full" />
          </div>
        ) : groups.length === 0 ? (
          <Card data-slot="workstation-alerts-empty">
            <CardContent className="flex flex-col items-center gap-2 py-10 text-center">
              <Building2 className="size-6 text-muted-foreground" />
              <p className="text-sm font-medium">{t("hq.workstation_alerts.empty.title")}</p>
              <p className="text-xs text-muted-foreground">
                {t("hq.workstation_alerts.empty.hint")}
              </p>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-3" data-slot="workstation-alerts-groups">
            {groups.map((group) => (
              <BranchCard
                key={group.branchId}
                group={group}
                brandSlug={brandSlug}
                timezone={timezone}
              />
            ))}
          </div>
        )}
      </PageContent>
    </>
  );
}

function BranchCard({
  group,
  brandSlug,
  timezone,
}: {
  group: BranchAlertGroup;
  brandSlug: string;
  /**
   * Múi giờ HIỂN THỊ của người đang đăng nhập, không phải của quán (#1091: giờ
   * hiển thị được phép theo người dùng, chỉ giờ NGHIỆP VỤ mới bắt buộc theo
   * quán). Ở đây không có giờ nghiệp vụ nào — `first_seen` là mốc sự cố, và
   * một người trực ở HQ so nó với đồng hồ trên tường của chính họ.
   */
  timezone: string;
}) {
  const { t, locale } = useTranslation();

  return (
    <Card data-slot="workstation-alert-branch" data-branch-id={group.branchId}>
      <CardContent className="p-4">
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <Store className="size-4 text-muted-foreground" />
          <span className="text-sm font-semibold">{group.branchName}</span>
          {(["critical", "warning", "info"] as const).map((severity) =>
            group.severityCounts[severity] > 0 ? (
              <Badge
                key={severity}
                variant="outline"
                className={`text-[10px] ${SEVERITY_TONE[severity]}`}
              >
                {t(`hq.workstation_alerts.severity.${severity}`)} ·{" "}
                {group.severityCounts[severity]}
              </Badge>
            ) : null
          )}
          <span className="ml-auto text-[11px] text-muted-foreground">
            {t("hq.workstation_alerts.col.last_reported")}:{" "}
            {formatDateTime(group.lastReportedAt, locale, timezone)}
          </span>
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b text-[10px] tracking-wider text-muted-foreground uppercase">
                <th className="px-2 py-1.5 text-left">{t("hq.workstation_alerts.col.alert")}</th>
                <th className="px-2 py-1.5 text-left">{t("hq.workstation_alerts.col.subject")}</th>
                <th className="px-2 py-1.5 text-right">{t("hq.workstation_alerts.col.count")}</th>
                <th className="px-2 py-1.5 text-left">
                  {t("hq.workstation_alerts.col.first_seen")}
                </th>
                <th className="px-2 py-1.5 text-right">{t("hq.workstation_alerts.col.days")}</th>
                <th className="px-2 py-1.5" />
              </tr>
            </thead>
            <tbody>
              {group.incidents.map((incident) => {
                const Icon = SEVERITY_ICON[incident.severity];
                return (
                  <tr
                    key={incident.key}
                    className="border-b last:border-0"
                    data-slot="workstation-alert-incident"
                  >
                    <td className="px-2 py-2">
                      <div className="flex items-center gap-1.5">
                        <Icon
                          className={
                            incident.severity === "critical"
                              ? "size-3.5 text-red-600 dark:text-red-400"
                              : incident.severity === "warning"
                                ? "size-3.5 text-amber-600 dark:text-amber-400"
                                : "size-3.5 text-muted-foreground"
                          }
                        />
                        <span className="font-medium">{incident.title}</span>
                      </div>
                      {/* `kind` nguyên dạng — xem docblock đầu file. */}
                      <span className="ml-5 font-mono text-[10px] text-muted-foreground">
                        {incident.kind}
                      </span>
                    </td>
                    <td className="px-2 py-2 font-mono text-[11px] break-all">
                      {incident.subject || "—"}
                    </td>
                    <td className="px-2 py-2 text-right font-medium tabular-nums">
                      {incident.count}
                    </td>
                    <td className="px-2 py-2 whitespace-nowrap">
                      <span className="inline-flex items-center gap-1 text-muted-foreground">
                        <Clock className="size-3" />
                        {formatDateTime(incident.firstSeenAt, locale, timezone)}
                      </span>
                    </td>
                    <td className="px-2 py-2 text-right tabular-nums">{incident.days}</td>
                    <td className="px-2 py-2 text-right">
                      <Link
                        href={`/hq/${brandSlug}/notifications?detail=${incident.latestNotificationId}`}
                        className="text-[11px] text-primary hover:underline"
                      >
                        {t("hq.workstation_alerts.detail_link")}
                      </Link>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  );
}

function Stat({
  icon,
  label,
  value,
  loading,
  tone,
}: {
  icon: React.ReactNode;
  label: string;
  value: number | undefined;
  loading?: boolean;
  tone?: "danger";
}) {
  return (
    <div
      className={`flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs ${
        tone === "danger"
          ? "border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300"
          : "bg-muted/40"
      }`}
    >
      {icon}
      <span className="text-muted-foreground">{label}</span>
      {loading ? (
        <Skeleton className="h-3 w-6" />
      ) : (
        <span className="font-semibold tabular-nums">{value ?? 0}</span>
      )}
    </div>
  );
}
