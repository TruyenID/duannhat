"use client";

import {
  Badge,
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
  Skeleton,
  Spinner,
} from "@godxjp/ui";
import { Bell, BellRing, Check, CheckCheck, ChevronRight, Sparkles, Zap } from "lucide-react";
import Link from "next/link";
import { useState } from "react";

import {
  useBulkMarkRead,
  useMarkNotificationRead,
  useNotificationInbox,
  useNotificationSummary,
} from "@/hooks/api/use-notifications";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import type { LocaleCode } from "@/i18n";
import type { NotificationPriority, NotificationRow } from "@/services/notification-service";

import { resolveNotificationSubjectHref } from "./notification-subject-resolver";

/**
 * Top-bar bell icon with unread badge + rich dropdown preview of the 10
 * most recent unread notifications.
 *
 * plan-008 T8.1 (shipped) + plan-012 visual refresh.
 *   - summary polls every 30 s
 *   - inbox fetched on dropdown open
 *   - realtime hook (when mounted) invalidates these queries so the badge
 *     bumps in < 500 ms on HQ/Shop/inbox pages
 */
export function NotificationBell() {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);

  const summary = useNotificationSummary();
  const inbox = useNotificationInbox({ status: "unread", per_page: 10 }, { enabled: open });
  const markRead = useMarkNotificationRead();
  const bulkRead = useBulkMarkRead();

  const unread = summary.data?.unread_count ?? 0;
  const hasUnread = unread > 0;
  const badgeLabel = unread > 99 ? "99+" : String(unread);

  const handleRowClick = (id: string) => {
    markRead.mutate(id);
    setOpen(false);
  };

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          size="sm"
          className="relative size-8 p-0"
          aria-label={t("notifications.bell.aria")}
          data-slot="notification-bell"
        >
          {hasUnread ? <BellRing className="size-4" /> : <Bell className="size-4" />}
          {hasUnread ? (
            <Badge
              className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full border-2 border-background px-1 text-[9px] leading-none font-bold"
              variant="destructive"
            >
              {badgeLabel}
            </Badge>
          ) : null}
        </Button>
      </DropdownMenuTrigger>

      <DropdownMenuContent
        align="end"
        sideOffset={8}
        className="flex w-[380px] flex-col gap-0 overflow-hidden p-0"
      >
        {/* Header */}
        <div className="flex items-center justify-between border-b bg-muted/30 px-4 py-3">
          <div className="flex items-center gap-2">
            <Bell className="size-4 text-muted-foreground" />
            <h3 className="text-sm font-semibold">{t("notifications.bell.title")}</h3>
            {hasUnread ? (
              <Badge variant="secondary" className="h-5 text-[10px]">
                {unread}
              </Badge>
            ) : null}
          </div>
          {hasUnread ? (
            <button
              type="button"
              onClick={() => bulkRead.mutate({ all_unread: true })}
              disabled={bulkRead.isPending}
              className="inline-flex items-center gap-1 text-[11px] font-medium text-muted-foreground hover:text-foreground disabled:opacity-50"
            >
              <CheckCheck className="size-3" />
              {t("notifications.bell.mark_all_read")}
            </button>
          ) : null}
        </div>

        {/* Body */}
        <div className="max-h-[420px] overflow-y-auto">
          {inbox.isLoading ? (
            <BellSkeleton />
          ) : inbox.isError ? (
            <p className="px-4 py-8 text-center text-xs text-destructive">
              {t("notifications.bell.error")}
            </p>
          ) : inbox.data && inbox.data.data.length > 0 ? (
            <ul className="divide-y">
              {inbox.data.data.map((row) => (
                <BellRow key={row.id} row={row} onClick={handleRowClick} t={t} />
              ))}
            </ul>
          ) : (
            <BellEmpty t={t} />
          )}
        </div>

        {/* Footer */}
        <Link
          href="/inbox"
          onClick={() => setOpen(false)}
          className="flex items-center justify-center gap-1.5 border-t bg-muted/20 px-4 py-2.5 text-xs font-medium text-muted-foreground transition hover:bg-muted/40 hover:text-foreground"
        >
          {t("notifications.bell.view_all")}
          <ChevronRight className="size-3" />
        </Link>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function BellRow({
  row,
  onClick,
  t,
}: {
  row: NotificationRow;
  onClick: (id: string) => void;
  t: (k: string) => string;
}) {
  const { locale } = useTranslation();
  const { timezone } = useTimezone();
  const href = resolveNotificationSubjectHref(row);
  const title = row.title && row.title.trim() !== "" ? row.title : row.template_key;
  const relativeTime = row.created_at ? formatRelative(row.created_at, locale, timezone) : "";
  const dot = priorityDot(row.priority);
  const unread = row.recipient?.read_at === null;

  const inner = (
    <div className="flex items-start gap-3 p-3 hover:bg-muted/40">
      <div className={`mt-1.5 size-2 shrink-0 rounded-full ${dot}`} />
      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <p
            className={
              unread
                ? "line-clamp-2 text-xs leading-snug font-semibold"
                : "line-clamp-2 text-xs leading-snug text-muted-foreground"
            }
          >
            {title}
          </p>
          {row.priority === "urgent" ? (
            <Zap className="mt-0.5 size-3 shrink-0 text-red-500" />
          ) : null}
        </div>
        {row.body ? (
          <p className="mt-0.5 line-clamp-1 text-[11px] text-muted-foreground">{row.body}</p>
        ) : null}
        <div className="mt-1 flex items-center gap-1.5 text-[10px] text-muted-foreground">
          <span>{row.actor?.display_name ?? t("notifications.actor.system")}</span>
          <span>·</span>
          <span>{relativeTime}</span>
        </div>
      </div>
      {unread ? (
        <span className="mt-1 size-1.5 shrink-0 rounded-full bg-primary" aria-label="unread" />
      ) : null}
    </div>
  );

  return (
    <li>
      {href ? (
        <Link href={href} onClick={() => onClick(row.id)} className="block cursor-pointer">
          {inner}
        </Link>
      ) : (
        <button
          type="button"
          onClick={() => onClick(row.id)}
          className="block w-full cursor-pointer text-left"
        >
          {inner}
        </button>
      )}
    </li>
  );
}

function BellSkeleton() {
  return (
    <div className="divide-y">
      {[0, 1, 2].map((i) => (
        <div key={i} className="flex items-start gap-3 p-3">
          <Skeleton className="mt-1.5 size-2 shrink-0 rounded-full" />
          <div className="flex-1 space-y-2">
            <Skeleton className="h-3 w-3/4" />
            <Skeleton className="h-2.5 w-1/2" />
          </div>
        </div>
      ))}
    </div>
  );
}

function BellEmpty({ t }: { t: (k: string) => string }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center">
      <div className="rounded-full bg-primary/10 p-3">
        <Sparkles className="size-5 text-primary" />
      </div>
      <p className="text-sm font-medium">{t("notifications.bell.empty_title")}</p>
      <p className="text-[11px] text-muted-foreground">{t("notifications.bell.empty_hint")}</p>
    </div>
  );
}

function priorityDot(p: NotificationPriority): string {
  switch (p) {
    case "urgent":
      return "bg-red-500";
    case "high":
      return "bg-amber-500";
    case "normal":
      return "bg-blue-500";
    default:
      return "bg-muted-foreground/50";
  }
}

function formatRelative(iso: string, locale: LocaleCode, timezone?: string): string {
  const then = new Date(iso).getTime();
  const now = Date.now();
  const diffSec = Math.floor((now - then) / 1000);
  if (diffSec < 60) return `${diffSec}s`;
  const diffMin = Math.floor(diffSec / 60);
  if (diffMin < 60) return `${diffMin}m`;
  const diffHr = Math.floor(diffMin / 60);
  if (diffHr < 24) return `${diffHr}h`;
  const diffDay = Math.floor(diffHr / 24);
  if (diffDay < 7) return `${diffDay}d`;
  return formatDate(iso, locale, timezone);
}
