"use client";

/**
 * Plan-023 M6 T6.11 — shop broadcast composer.
 *
 * Single-screen form (no wizard for v1). Picks an audience + template
 * + channels + priority. Backend
 * (ShopNotificationBroadcastController) enforces:
 *   - audience must be shop-scoped or brand-default
 *   - template must be shop-scoped or brand/system visible
 *   - recipients intersected with shop members via
 *     ShopScopedAudienceResolver
 */

import {
  Alert,
  AlertDescription,
  Button,
  Card,
  CardContent,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Skeleton,
  Spinner,
} from "@godxjp/ui";
import { AlertCircle, Send } from "lucide-react";
import { useParams } from "next/navigation";
import { useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import {
  useShopAudiences,
  useShopBroadcast,
  useShopTemplates,
} from "@/hooks/api/use-shop-notifications";
import { useTranslation } from "@/providers/app-provider";

const CHANNELS = ["in_app", "realtime", "email", "push"] as const;
const PRIORITIES = ["low", "normal", "high", "urgent"] as const;

export default function ShopComposePage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t } = useTranslation();

  const audiences = useShopAudiences(shopSlug);
  const templates = useShopTemplates(shopSlug);
  const broadcastMutation = useShopBroadcast(shopSlug);

  const [audienceId, setAudienceId] = useState<string>("");
  const [templateId, setTemplateId] = useState<string>("");
  const [priority, setPriority] = useState<(typeof PRIORITIES)[number]>("normal");
  const [chosen, setChosen] = useState<Record<string, boolean>>({
    in_app: true,
    realtime: false,
    email: false,
    push: false,
  });
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [sentId, setSentId] = useState<string | null>(null);

  async function onSubmit() {
    setSubmitError(null);
    setSentId(null);
    if (!audienceId || !templateId) {
      setSubmitError(t("notifications.shop.compose.missing_choice"));
      return;
    }
    const channels = CHANNELS.filter((c) => chosen[c]);
    if (channels.length === 0) {
      setSubmitError(t("notifications.shop.compose.no_channels"));
      return;
    }
    try {
      const res = await broadcastMutation.mutateAsync({
        audience_id: audienceId,
        template_id: templateId,
        channels,
        priority,
      });
      setSentId(res.data.id);
    } catch (e) {
      setSubmitError((e as Error).message);
    }
  }

  return (
    <>
      <PageHeader
        title={t("notifications.shop.compose.title")}
        description={t("notifications.shop.compose.subtitle")}
      >
        <HelpPanel
          title={t("notifications.shop.compose.title")}
          subtitle={t("help.panel.shop_notifications_compose.subtitle")}
          purpose={t("help.panel.shop_notifications_compose.purpose")}
          usage={[
            t("help.panel.shop_notifications_compose.usage.1"),
            t("help.panel.shop_notifications_compose.usage.2"),
            t("help.panel.shop_notifications_compose.usage.3"),
          ]}
          checks={[
            t("help.panel.shop_notifications_compose.checks.1"),
            t("help.panel.shop_notifications_compose.checks.2"),
            t("help.panel.shop_notifications_compose.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_notifications_compose.glossary.priority.term"),
              description: t("help.panel.shop_notifications_compose.glossary.priority.desc"),
            },
            {
              term: t("help.panel.shop_notifications_compose.glossary.in_app.term"),
              description: t("help.panel.shop_notifications_compose.glossary.in_app.desc"),
            },
          ]}
        />
      </PageHeader>
      <PageContent>
        <Card>
          <CardContent className="space-y-4 p-5">
            {/* Audience picker */}
            <div>
              <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {t("notifications.shop.compose.audience")}
              </p>
              {audiences.isLoading ? (
                <Skeleton className="h-9 w-full" />
              ) : (
                <Select value={audienceId} onValueChange={setAudienceId}>
                  <SelectTrigger>
                    <SelectValue placeholder={t("notifications.shop.compose.pick_audience")} />
                  </SelectTrigger>
                  <SelectContent>
                    {(audiences.data?.data ?? []).map((a) => (
                      <SelectItem key={a.id} value={a.id}>
                        {a.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </div>

            {/* Template picker */}
            <div>
              <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {t("notifications.shop.compose.template")}
              </p>
              {templates.isLoading ? (
                <Skeleton className="h-9 w-full" />
              ) : (
                <Select value={templateId} onValueChange={setTemplateId}>
                  <SelectTrigger>
                    <SelectValue placeholder={t("notifications.shop.compose.pick_template")} />
                  </SelectTrigger>
                  <SelectContent>
                    {(templates.data?.data ?? []).map((tpl) => (
                      <SelectItem key={tpl.id} value={tpl.id}>
                        {tpl.key}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </div>

            {/* Channels */}
            <div>
              <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {t("notifications.shop.compose.channels")}
              </p>
              <div className="flex flex-wrap gap-2">
                {CHANNELS.map((c) => (
                  <Button
                    key={c}
                    size="sm"
                    variant={chosen[c] ? "default" : "outline"}
                    onClick={() => setChosen({ ...chosen, [c]: !chosen[c] })}
                  >
                    {c}
                  </Button>
                ))}
              </div>
            </div>

            {/* Priority */}
            <div>
              <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {t("notifications.shop.compose.priority")}
              </p>
              <div className="flex flex-wrap gap-2">
                {PRIORITIES.map((p) => (
                  <Button
                    key={p}
                    size="sm"
                    variant={priority === p ? "default" : "outline"}
                    onClick={() => setPriority(p)}
                  >
                    {p}
                  </Button>
                ))}
              </div>
            </div>

            {submitError ? (
              <Alert variant="destructive">
                <AlertCircle className="size-4" />
                <AlertDescription>{submitError}</AlertDescription>
              </Alert>
            ) : null}

            {sentId ? (
              <Alert>
                <AlertDescription>
                  {t("notifications.shop.compose.sent", { id: sentId })}
                </AlertDescription>
              </Alert>
            ) : null}

            <div className="flex justify-end">
              <Button onClick={onSubmit} disabled={broadcastMutation.isPending} size="lg">
                {broadcastMutation.isPending ? (
                  <Spinner className="mr-2 size-4" />
                ) : (
                  <Send className="mr-2 size-4" />
                )}
                {t("notifications.shop.compose.submit")}
              </Button>
            </div>
          </CardContent>
        </Card>
      </PageContent>
    </>
  );
}
