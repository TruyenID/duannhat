"use client";

/**
 * Plan-023 M6 T6.11 — shop channel-routes override page.
 *
 * Shop admin sets per-(type × channel) routing for this shop. When
 * a notification of that type is dispatched inside the shop, the
 * shop row wins over brand/system rows.
 */

import {
  Alert,
  AlertDescription,
  Button,
  Card,
  CardContent,
  Input,
  Skeleton,
  Spinner,
  Switch,
} from "@godxjp/ui";
import { AlertCircle, Plus, Trash2 } from "lucide-react";
import { useParams } from "next/navigation";
import { useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import {
  useShopRouteDelete,
  useShopRouteUpsert,
  useShopRoutes,
} from "@/hooks/api/use-shop-notifications";
import { useTranslation } from "@/providers/app-provider";

const CHANNELS = ["in_app", "realtime", "email", "push"] as const;

export default function ShopRoutingPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const [newType, setNewType] = useState("");
  const [newChannels, setNewChannels] = useState<Record<string, boolean>>({
    in_app: true,
    realtime: false,
    email: false,
    push: false,
  });
  const [saveError, setSaveError] = useState<string | null>(null);

  const { data, isLoading, isError, refetch } = useShopRoutes(shopSlug);
  const upsertMutation = useShopRouteUpsert(shopSlug);
  const deleteMutation = useShopRouteDelete(shopSlug);

  const rows = data?.data ?? [];

  async function onAdd() {
    setSaveError(null);
    if (!newType.match(/^[a-z0-9._-]+$/)) {
      setSaveError(t("notifications.shop.routing.invalid_type"));
      return;
    }
    try {
      await upsertMutation.mutateAsync({ type: newType, channels: newChannels });
      setNewType("");
    } catch (e) {
      setSaveError((e as Error).message);
    }
  }

  async function onToggle(
    type: string,
    channel: string,
    next: boolean,
    current: Record<string, boolean>
  ) {
    await upsertMutation.mutateAsync({ type, channels: { ...current, [channel]: next } });
  }

  return (
    <>
      <PageHeader
        title={t("notifications.shop.routing.title")}
        description={t("notifications.shop.routing.subtitle")}
      >
        <HelpPanel
          title={t("notifications.shop.routing.title")}
          subtitle={t("help.panel.shop_notifications_routing.subtitle")}
          purpose={t("help.panel.shop_notifications_routing.purpose")}
          usage={[
            t("help.panel.shop_notifications_routing.usage.1"),
            t("help.panel.shop_notifications_routing.usage.2"),
          ]}
          checks={[
            t("help.panel.shop_notifications_routing.checks.1"),
            t("help.panel.shop_notifications_routing.checks.2"),
            t("help.panel.shop_notifications_routing.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_notifications_routing.glossary.channels.term"),
              description: t("help.panel.shop_notifications_routing.glossary.channels.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
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

        {/* Add new override row */}
        <Card className="mb-4">
          <CardContent className="space-y-3 p-4">
            <p className="text-sm font-medium">{t("notifications.shop.routing.add_title")}</p>
            <div className="flex flex-wrap items-center gap-2">
              <Input
                placeholder="stock.alert.low"
                value={newType}
                onChange={(e) => setNewType(e.target.value)}
                className="max-w-xs font-mono text-sm"
              />
              {CHANNELS.map((c) => (
                <label key={c} className="flex items-center gap-1 text-xs">
                  <Switch
                    checked={newChannels[c] ?? false}
                    onCheckedChange={(v) => setNewChannels({ ...newChannels, [c]: v })}
                  />
                  {c}
                </label>
              ))}
              <Button size="sm" onClick={onAdd} disabled={upsertMutation.isPending}>
                {upsertMutation.isPending ? (
                  <Spinner className="mr-2 size-3.5" />
                ) : (
                  <Plus className="mr-1 size-3.5" />
                )}
                {t("common.save")}
              </Button>
            </div>
            {saveError ? (
              <Alert variant="destructive">
                <AlertDescription>{saveError}</AlertDescription>
              </Alert>
            ) : null}
          </CardContent>
        </Card>

        {/* Existing rows */}
        {isLoading ? (
          <Skeleton className="h-16 w-full" />
        ) : rows.length === 0 ? (
          <p className="text-center text-sm text-muted-foreground">
            {t("notifications.shop.routing.empty")}
          </p>
        ) : (
          <div className="space-y-2">
            {rows.map((row) => (
              <Card key={row.id}>
                <CardContent className="flex flex-wrap items-center gap-3 p-3">
                  <p className="min-w-0 flex-1 truncate font-mono text-xs">{row.type}</p>
                  {CHANNELS.map((c) => (
                    <label key={c} className="flex items-center gap-1 text-xs">
                      <Switch
                        checked={!!row.channels?.[c]}
                        onCheckedChange={(v) => onToggle(row.type, c, v, row.channels ?? {})}
                      />
                      {c}
                    </label>
                  ))}
                  <Button
                    variant="ghost"
                    size="icon"
                    onClick={() => deleteMutation.mutate(row.type)}
                  >
                    <Trash2 className="size-4" />
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </PageContent>
    </>
  );
}
