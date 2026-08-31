"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { AlertCircle, ArrowRight, CheckCircle2, Plug, Store } from "lucide-react";
import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Skeleton,
} from "@godxjp/ui";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { HelpPanel } from "@/components/shared/help-panel";
import { SettingsTabsNav } from "../components/settings-tabs-nav";
import { usePaymentReadiness } from "@/hooks/api/use-payment-gateways";
import { useTranslation } from "@/providers/app-provider";
import { PaymentsSettingsShell } from "./components/payments-settings-nav";

export default function HqPaymentsOverviewPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();
  const { data, isLoading, isError, refetch } = usePaymentReadiness(brandSlug);

  const overallVariant =
    data?.overall_status === "ready"
      ? "default"
      : data?.overall_status === "action_required"
        ? "outline"
        : "secondary";

  return (
    <>
      <PageHeader
        title={t("hq.payments.title")}
        description={t("hq.payments.overview.description")}
        onRefresh={refetch}
      >
        <HelpPanel
          title={t("hq.payments.title")}
          subtitle={t("help.panel.hq_payments.subtitle")}
          purpose={t("help.panel.hq_payments.purpose")}
          usage={[
            t("help.panel.hq_payments.usage.1"),
            t("help.panel.hq_payments.usage.2"),
            t("help.panel.hq_payments.usage.3"),
            t("help.panel.hq_payments.usage.4"),
          ]}
          checks={[
            t("help.panel.hq_payments.checks.1"),
            t("help.panel.hq_payments.checks.2"),
            t("help.panel.hq_payments.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.hq_payments.glossary.connections.term"),
              description: t("help.panel.hq_payments.glossary.connections.desc"),
            },
            {
              term: t("help.panel.hq_payments.glossary.shops.term"),
              description: t("help.panel.hq_payments.glossary.shops.desc"),
            },
            {
              term: t("help.panel.hq_payments.glossary.options.term"),
              description: t("help.panel.hq_payments.glossary.options.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        <SettingsTabsNav brandSlug={brandSlug} />
        <PaymentsSettingsShell brandSlug={brandSlug}>
          {isError ? (
            <Alert variant="destructive">
              <AlertCircle className="size-4" />
              <AlertDescription>{t("common.error_loading")}</AlertDescription>
            </Alert>
          ) : isLoading ? (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {Array.from({ length: 4 }).map((_, i) => (
                <Skeleton key={i} className="h-24 rounded-lg" />
              ))}
            </div>
          ) : data ? (
            <div className="space-y-4">
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="flex items-center justify-between text-base">
                    {t("hq.payments.overview.status_title")}
                    {data ? (
                      <Badge variant={overallVariant}>
                        {t(`hq.payments.overview.status.${data.overall_status}`)}
                      </Badge>
                    ) : null}
                  </CardTitle>
                </CardHeader>
                <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                  <MetricCard
                    icon={Plug}
                    label={t("hq.payments.overview.connections")}
                    value={`${data.connections_ready}/${data.connections_total}`}
                    href={`/hq/${brandSlug}/settings/payments/gateways`}
                  />
                  <MetricCard
                    icon={Store}
                    label={t("hq.payments.overview.shops")}
                    value={`${data.shops_ready}/${data.shops_total}`}
                    href={`/hq/${brandSlug}/settings/payments/shops`}
                  />
                  <MetricCard
                    icon={CheckCircle2}
                    label={t("hq.payments.overview.options")}
                    value={`${data.options_enabled}/${data.options_total}`}
                    href={`/hq/${brandSlug}/settings/payments/methods`}
                  />
                </CardContent>
              </Card>

              {data.blockers.length > 0 ? (
                <Card>
                  <CardHeader className="pb-2">
                    <CardTitle className="text-base">
                      {t("hq.payments.overview.blockers")}
                    </CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-2">
                    {data.blockers.map((blocker) => (
                      <Alert key={blocker.code}>
                        <AlertCircle className="size-4" />
                        <AlertDescription className="flex flex-wrap items-center justify-between gap-2">
                          <span>{blocker.message}</span>
                          {blocker.href ? (
                            <Button variant="outline" size="sm" className="h-7 text-xs" asChild>
                              <Link href={`/hq/${brandSlug}/settings/payments/${blocker.href}`}>
                                {t("hq.payments.overview.resolve")}
                                <ArrowRight className="ml-1 size-3" />
                              </Link>
                            </Button>
                          ) : null}
                        </AlertDescription>
                      </Alert>
                    ))}
                  </CardContent>
                </Card>
              ) : null}
            </div>
          ) : null}
        </PaymentsSettingsShell>
      </PageContent>
    </>
  );
}

function MetricCard({
  icon: Icon,
  label,
  value,
  href,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: string;
  href: string;
}) {
  return (
    <Link
      href={href}
      className="flex items-start gap-3 rounded-lg border bg-card p-3 transition-colors hover:bg-muted/40"
    >
      <div className="rounded-md bg-muted p-2">
        <Icon className="size-4 text-muted-foreground" aria-hidden />
      </div>
      <div>
        <p className="text-xs text-muted-foreground">{label}</p>
        <p className="text-lg font-semibold tabular-nums">{value}</p>
      </div>
    </Link>
  );
}
