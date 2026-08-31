"use client";

/**
 * Plan-023 M6 T6.11 — shop notification audit list.
 *
 * Mirrors the HQ audit page in a slim shop-scoped form. Lists
 * notifications whose aggregation_key contains the current shop's
 * branch_id (per the backend filter in ShopNotificationAdminController).
 */

import { Alert, AlertDescription, Badge, Button, Card, CardContent, Skeleton } from "@godxjp/ui";
import { AlertCircle, Bell } from "lucide-react";
import { useParams } from "next/navigation";
import { useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { useShopAudit } from "@/hooks/api/use-shop-notifications";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";

const PRIORITIES = ["all", "urgent", "high", "normal", "low"] as const;

export default function ShopNotificationsAuditPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const [priority, setPriority] = useState<(typeof PRIORITIES)[number]>("all");

  const { data, isLoading, isError, refetch } = useShopAudit(shopSlug, {
    priority: priority === "all" ? undefined : priority,
  });

  const rows = data?.data ?? [];

  return (
    <>
      <PageHeader
        title={t("notifications.shop.audit.title")}
        description={t("notifications.shop.audit.subtitle")}
      >
        <HelpPanel
          title={t("notifications.shop.audit.title")}
          subtitle={t("help.panel.shop_notifications_audit.subtitle")}
          purpose={t("help.panel.shop_notifications_audit.purpose")}
          usage={[
            t("help.panel.shop_notifications_audit.usage.1"),
            t("help.panel.shop_notifications_audit.usage.2"),
          ]}
          checks={[
            t("help.panel.shop_notifications_audit.checks.1"),
            t("help.panel.shop_notifications_audit.checks.2"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_notifications_audit.glossary.event_type.term"),
              description: t("help.panel.shop_notifications_audit.glossary.event_type.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <div className="mb-4 flex flex-wrap items-center gap-1">
          {PRIORITIES.map((p) => (
            <Button
              key={p}
              size="sm"
              variant={priority === p ? "default" : "outline"}
              onClick={() => setPriority(p)}
            >
              {t(`notifications.shop.audit.priority_${p}`)}
            </Button>
          ))}
        </div>

        {isError ? (
          <Alert variant="destructive" className="mb-4">
            <AlertCircle className="size-4" />
            <AlertDescription className="flex items-center justify-between">
              {t("common.error_loading")}
              <Button variant="outline" size="sm" onClick={() => refetch()}>
                {t("common.retry")}
              </Button>
            </AlertDescription>
          </Alert>
        ) : null}

        {isLoading ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-14 w-full" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <Card>
            <CardContent className="p-8 text-center text-sm text-muted-foreground">
              <Bell className="mx-auto mb-3 size-8 text-muted-foreground/60" />
              {t("notifications.shop.audit.empty")}
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-2">
            {rows.map((row) => (
              <Card key={row.id} data-slot="shop-notif-row">
                <CardContent className="flex items-center gap-3 p-3">
                  <Badge variant="secondary">{row.priority}</Badge>
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-mono text-xs">{row.type}</p>
                    <p className="text-[10px] text-muted-foreground">
                      {formatDateTime(row.created_at, locale, timezone)}
                    </p>
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </PageContent>
    </>
  );
}
