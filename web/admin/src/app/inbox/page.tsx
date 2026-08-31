"use client";

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  Spinner,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import {
  ArrowLeft,
  Bell,
  BellOff,
  Check,
  CheckCheck,
  ChevronRight,
  Inbox as InboxIcon,
  Sparkles,
  X,
  Zap,
} from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState } from "react";

import { RealtimeMount } from "@/components/notifications/realtime-mount";
import {
  useBulkDismiss,
  useBulkMarkRead,
  useDismissNotification,
  useMarkNotificationRead,
  useNotificationInbox,
} from "@/hooks/api/use-notifications";
import { formatDateTime } from "@/lib/date";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import type {
  NotificationPriority,
  NotificationRow,
  NotificationStatus,
} from "@/services/notification-service";

const PRIORITY_TONE: Record<NotificationPriority, string> = {
  low: "bg-muted text-muted-foreground border-muted",
  normal:
    "bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:border-blue-900",
  high: "bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900",
  urgent:
    "bg-red-100 text-red-700 border-red-200 dark:bg-red-950 dark:text-red-300 dark:border-red-900",
};

export default function InboxPage() {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const router = useRouter();
  const [status, setStatus] = useState<NotificationStatus>("unread");

  const handleBack = () => {
    if (typeof window !== "undefined" && window.history.length > 1) {
      router.back();
    } else {
      router.push("/");
    }
  };

  const inbox = useNotificationInbox({ status, per_page: 25 });
  const markRead = useMarkNotificationRead();
  const dismiss = useDismissNotification();
  const bulkRead = useBulkMarkRead();
  const bulkDismiss = useBulkDismiss();

  const rows = inbox.data?.data ?? [];

  return (
    <div className="min-h-screen bg-muted/10">
      <RealtimeMount />
      <div className="mx-auto max-w-4xl p-4 sm:p-6">
        {/* Header */}
        <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-center gap-3">
            <Button variant="ghost" size="icon" onClick={handleBack} aria-label={t("common.back")}>
              <ArrowLeft className="size-4" />
            </Button>
            <div className="rounded-lg bg-primary/10 p-2.5">
              <InboxIcon className="size-5 text-primary" />
            </div>
            <div>
              <h1 className="text-xl font-semibold" data-slot="inbox-title">
                {t("notifications.inbox.title")}
              </h1>
              <p className="text-xs text-muted-foreground">
                {inbox.data?.meta?.total !== undefined
                  ? t("notifications.inbox.total_count", { count: inbox.data.meta.total })
                  : ""}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => bulkRead.mutate({ all_unread: true })}
              disabled={bulkRead.isPending}
            >
              <CheckCheck className="mr-1 size-3.5" />
              {t("notifications.inbox.mark_all_read")}
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => bulkDismiss.mutate({ all_read: true })}
              disabled={bulkDismiss.isPending}
            >
              <X className="mr-1 size-3.5" />
              {t("notifications.inbox.dismiss_all_read")}
            </Button>
          </div>
        </div>

        {/* Tabs + list */}
        <Tabs value={status} onValueChange={(v) => setStatus(v as NotificationStatus)}>
          <TabsList className="mb-4">
            <TabsTrigger value="unread" className="gap-1.5">
              <Bell className="size-3.5" />
              {t("notifications.inbox.tabs.unread")}
            </TabsTrigger>
            <TabsTrigger value="read" className="gap-1.5">
              <Check className="size-3.5" />
              {t("notifications.inbox.tabs.read")}
            </TabsTrigger>
            <TabsTrigger value="all">{t("notifications.inbox.tabs.all")}</TabsTrigger>
          </TabsList>

          <TabsContent value={status} className="mt-0">
            {inbox.isLoading ? (
              <Card>
                <CardContent className="flex h-48 items-center justify-center">
                  <Spinner className="size-5" />
                </CardContent>
              </Card>
            ) : inbox.isError ? (
              <Alert variant="destructive">
                <AlertDescription>{t("notifications.inbox.error")}</AlertDescription>
              </Alert>
            ) : rows.length === 0 ? (
              <EmptyState status={status} t={t} />
            ) : (
              <div className="space-y-2">
                {rows.map((row) => (
                  <InboxRow
                    key={row.id}
                    row={row}
                    onMarkRead={() => markRead.mutate(row.id)}
                    onDismiss={() => dismiss.mutate(row.id)}
                    t={t}
                    locale={locale}
                    timezone={timezone}
                  />
                ))}
              </div>
            )}
          </TabsContent>
        </Tabs>
      </div>
    </div>
  );
}

function EmptyState({ status, t }: { status: NotificationStatus; t: (k: string) => string }) {
  return (
    <Card>
      <CardContent className="flex flex-col items-center justify-center gap-3 py-20 text-center">
        <div className="rounded-full bg-primary/10 p-4">
          {status === "unread" ? (
            <Sparkles className="size-8 text-primary" />
          ) : (
            <BellOff className="size-8 text-muted-foreground" />
          )}
        </div>
        <p className="text-base font-medium">
          {status === "unread"
            ? t("notifications.inbox.all_caught_up")
            : t("notifications.inbox.empty")}
        </p>
        {status === "unread" ? (
          <p className="max-w-sm text-sm text-muted-foreground">
            {t("notifications.inbox.all_caught_up_hint")}
          </p>
        ) : null}
      </CardContent>
    </Card>
  );
}

function InboxRow({
  row,
  onMarkRead,
  onDismiss,
  t,
  locale,
  timezone,
}: {
  row: NotificationRow;
  onMarkRead: () => void;
  onDismiss: () => void;
  t: (k: string) => string;
  locale: Parameters<typeof formatDateTime>[1];
  timezone?: string;
}) {
  const unread = row.recipient?.read_at === null;
  const title = row.title && row.title.trim() !== "" ? row.title : row.template_key;
  const relativeTime = row.created_at ? formatDateTime(row.created_at, locale, timezone) : "";

  return (
    <Card
      className={
        unread
          ? "border-l-2 border-l-primary transition hover:shadow-sm"
          : "transition hover:shadow-sm"
      }
    >
      <CardContent className="flex items-start gap-3 p-4">
        {/* Priority accent */}
        <div
          className={`mt-1 size-2 shrink-0 rounded-full ${
            row.priority === "urgent"
              ? "bg-red-500"
              : row.priority === "high"
                ? "bg-amber-500"
                : row.priority === "normal"
                  ? "bg-blue-500"
                  : "bg-muted-foreground"
          }`}
        />

        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <p className={unread ? "truncate text-sm font-semibold" : "truncate text-sm"}>
              {title}
            </p>
            {row.priority === "urgent" ? <Zap className="size-3 text-red-500" /> : null}
            <Badge
              variant="outline"
              className={`shrink-0 text-[10px] ${PRIORITY_TONE[row.priority]}`}
            >
              {t(`notifications.priority.${row.priority}`)}
            </Badge>
          </div>
          {row.body ? (
            <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{row.body}</p>
          ) : null}
          <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[11px] text-muted-foreground">
            <span>{row.actor?.display_name ?? t("notifications.actor.system")}</span>
            {relativeTime ? (
              <>
                <ChevronRight className="size-3" />
                <span>{relativeTime}</span>
              </>
            ) : null}
            <Badge variant="outline" className="font-mono text-[10px]">
              {row.type}
            </Badge>
          </div>
        </div>

        <div className="flex shrink-0 items-center gap-1">
          {unread ? (
            <Button variant="ghost" size="icon" className="size-8" onClick={onMarkRead}>
              <Check className="size-4" />
            </Button>
          ) : null}
          <Button variant="ghost" size="icon" className="size-8" onClick={onDismiss}>
            <X className="size-4" />
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}
