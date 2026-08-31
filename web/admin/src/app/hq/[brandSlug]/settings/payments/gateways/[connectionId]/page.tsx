"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import {
  AlertTriangle,
  ArrowLeft,
  ExternalLink,
  RefreshCw,
  ShieldCheck,
  Trash2,
} from "lucide-react";
import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Input,
  Label,
  Spinner,
} from "@godxjp/ui";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { SettingsTabsNav } from "../../../components/settings-tabs-nav";
import { ConnectionHealthBadge } from "../../components/connection-health-badge";
import { DisconnectImpactDialog } from "../../components/disconnect-impact-dialog";
import { PaymentsSettingsShell } from "../../components/payments-settings-nav";
import { SafeMerchantIdentity } from "../../components/safe-merchant-identity";
import { WebhookEndpointCard } from "../../components/webhook-endpoint-card";
import {
  useDisconnectPaymentGateway,
  usePaymentDisconnectImpact,
  usePaymentGatewayConnection,
  useRotatePaymentGatewaySecret,
  useValidatePaymentGatewayConnection,
} from "@/hooks/api/use-payment-gateways";
import { useTranslation } from "@/providers/app-provider";

export default function HqPaymentGatewayDetailPage() {
  const { brandSlug, connectionId } = useParams<{ brandSlug: string; connectionId: string }>();
  const router = useRouter();
  const { t } = useTranslation();

  const { data: connection, isLoading, isError, refetch } = usePaymentGatewayConnection(
    brandSlug,
    connectionId
  );

  const validate = useValidatePaymentGatewayConnection(brandSlug, connectionId);
  const rotate = useRotatePaymentGatewaySecret(brandSlug, connectionId);
  const disconnect = useDisconnectPaymentGateway(brandSlug, connectionId);

  const [disconnectOpen, setDisconnectOpen] = useState(false);
  const [rotateSecret, setRotateSecret] = useState("");
  const [showRotateForm, setShowRotateForm] = useState(false);

  const impact = usePaymentDisconnectImpact(brandSlug, connectionId, disconnectOpen);

  async function handleDisconnect() {
    await disconnect.mutateAsync();
    setDisconnectOpen(false);
    router.push(`/hq/${brandSlug}/settings/payments/gateways`);
  }

  async function handleRotate() {
    if (!rotateSecret.trim()) return;
    // `api_secret`, not `secret` — the rotate request declares the former, so
    // the old key was dropped by validation and every rotation answered
    // "The api secret field is required." (#F8).
    await rotate.mutateAsync({ api_secret: rotateSecret });
    setRotateSecret("");
    setShowRotateForm(false);
  }

  const onboardingStatus = connection?.onboarding_status;
  const needsOnboarding =
    onboardingStatus === "not_started" ||
    onboardingStatus === "pending" ||
    onboardingStatus === "action_required";

  return (
    <>
      <PageHeader
        title={connection?.provider.name ?? t("hq.payments.gateway_detail.title")}
        description={t("hq.payments.gateway_detail.description")}
        onRefresh={refetch}
      >
        <Button variant="outline" size="sm" className="h-7 gap-1.5 text-xs" asChild>
          <Link href={`/hq/${brandSlug}/settings/payments/gateways`}>
            <ArrowLeft className="size-3.5" />
            {t("common.back")}
          </Link>
        </Button>
      </PageHeader>

      <PageContent>
        <SettingsTabsNav brandSlug={brandSlug} />
        <PaymentsSettingsShell brandSlug={brandSlug}>
          {isLoading ? (
            <div className="flex justify-center py-12">
              <Spinner className="size-6" />
            </div>
          ) : isError || !connection ? (
            <Alert variant="destructive">
              <AlertDescription>{t("common.error_loading")}</AlertDescription>
            </Alert>
          ) : (
            <div className="space-y-4">
              <Card>
                <CardHeader className="flex flex-row items-center justify-between pb-2">
                  <CardTitle className="text-base">{t("hq.payments.gateway_detail.identity")}</CardTitle>
                  <ConnectionHealthBadge health={connection.health} />
                </CardHeader>
                <CardContent>
                  <SafeMerchantIdentity connection={connection} />
                </CardContent>
              </Card>

              <WebhookEndpointCard webhookUrl={connection.webhook_url} />

              {needsOnboarding ? (
                <Card>
                  <CardHeader className="pb-2">
                    <CardTitle className="text-base">{t("hq.payments.onboarding.title")}</CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-3">
                    <Badge variant="secondary">
                      {t(`hq.payments.onboarding.status.${onboardingStatus}`)}
                    </Badge>
                    {connection.onboarding_next_action ? (
                      <p className="text-sm text-muted-foreground">
                        {t("hq.payments.onboarding.next_action", {
                          action: connection.onboarding_next_action,
                        })}
                      </p>
                    ) : null}
                    {connection.health_reason_code ? (
                      <Alert>
                        <AlertTriangle className="size-4" />
                        <AlertDescription>{connection.health_reason_code}</AlertDescription>
                      </Alert>
                    ) : null}
                    <Button variant="outline" size="sm" className="h-7 gap-1.5 text-xs">
                      <ExternalLink className="size-3.5" />
                      {t("hq.payments.onboarding.continue")}
                    </Button>
                  </CardContent>
                </Card>
              ) : null}

              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="text-base">{t("hq.payments.gateway_detail.actions")}</CardTitle>
                </CardHeader>
                <CardContent className="flex flex-wrap gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7 gap-1.5 text-xs"
                    onClick={() => validate.mutate()}
                    disabled={validate.isPending}
                  >
                    {validate.isPending ? (
                      <Spinner className="size-3.5" />
                    ) : (
                      <RefreshCw className="size-3.5" />
                    )}
                    {t("hq.payments.actions.validate")}
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-7 gap-1.5 text-xs"
                    onClick={() => setShowRotateForm((v) => !v)}
                  >
                    <ShieldCheck className="size-3.5" />
                    {t("hq.payments.actions.rotate")}
                  </Button>
                  <Button
                    variant="destructive"
                    size="sm"
                    className="h-7 gap-1.5 text-xs"
                    onClick={() => setDisconnectOpen(true)}
                  >
                    <Trash2 className="size-3.5" />
                    {t("hq.payments.actions.disconnect")}
                  </Button>
                </CardContent>
              </Card>

              {showRotateForm ? (
                <Card>
                  <CardHeader className="pb-2">
                    <CardTitle className="text-base">{t("hq.payments.rotate.title")}</CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-3">
                    <Alert>
                      <AlertDescription>{t("hq.payments.rotate.warning")}</AlertDescription>
                    </Alert>
                    <div className="space-y-2">
                      <Label htmlFor="rotate-secret">{t("hq.payments.rotate.field_label")}</Label>
                      <Input
                        id="rotate-secret"
                        type="password"
                        autoComplete="off"
                        value={rotateSecret}
                        onChange={(e) => setRotateSecret(e.target.value)}
                        placeholder={t("hq.payments.rotate.field_placeholder")}
                      />
                      <p className="text-xs text-muted-foreground">
                        {t("hq.payments.rotate.never_displayed")}
                      </p>
                    </div>
                    <div className="flex gap-2">
                      <Button
                        size="sm"
                        className="h-7 text-xs"
                        onClick={handleRotate}
                        disabled={rotate.isPending || !rotateSecret.trim()}
                      >
                        {rotate.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
                        {t("hq.payments.rotate.submit")}
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-7 text-xs"
                        onClick={() => {
                          setShowRotateForm(false);
                          setRotateSecret("");
                        }}
                      >
                        {t("common.cancel")}
                      </Button>
                    </div>
                  </CardContent>
                </Card>
              ) : null}
            </div>
          )}
        </PaymentsSettingsShell>
      </PageContent>

      <DisconnectImpactDialog
        open={disconnectOpen}
        onOpenChange={setDisconnectOpen}
        impact={impact.data}
        isLoading={impact.isLoading}
        isPending={disconnect.isPending}
        onConfirm={handleDisconnect}
      />
    </>
  );
}
