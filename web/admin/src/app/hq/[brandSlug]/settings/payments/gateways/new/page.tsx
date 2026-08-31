"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { SettingsTabsNav } from "../../../components/settings-tabs-nav";
import { PaymentsSettingsShell } from "../../components/payments-settings-nav";
import { useCreatePaymentGatewayConnection } from "@/hooks/api/use-payment-gateways";
import { useTranslation } from "@/providers/app-provider";

/**
 * Create an HQ-owned gateway connection.
 *
 * This form used to have two controls and post `{provider_code, environment}`.
 * `PaymentGatewayConnectionStoreRequest` requires six more fields, so submitting
 * always came back 422 — "The merchant account id field is required. (and 5 more
 * errors)" — and no gateway connection could be created through the HQ UI at all
 * (#F2). The very same payload sent by hand returns 201, which is what made this
 * a form defect rather than a backend one.
 *
 * The three ownership ids are typed rather than picked: they are issued by Godx
 * Identity, whose projection adapter is still fail-closed here (plan-047 T2.5),
 * so there is no list to select from yet. They stay required because they decide
 * which legal entity the money belongs to — defaulting them would be guessing at
 * that answer.
 */
export default function HqPaymentGatewayNewPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const router = useRouter();
  const { t } = useTranslation();

  const create = useCreatePaymentGatewayConnection(brandSlug);

  const [providerCode, setProviderCode] = useState("stripe");
  const [environment, setEnvironment] = useState("sandbox");
  const [merchantAccountId, setMerchantAccountId] = useState("");
  const [merchantDisplayName, setMerchantDisplayName] = useState("");
  const [chargeModel, setChargeModel] = useState("direct");
  const [identityBrandId, setIdentityBrandId] = useState("");
  const [brandOwnerOrgUnitId, setBrandOwnerOrgUnitId] = useState("");
  const [operatorOrgUnitId, setOperatorOrgUnitId] = useState("");
  const [ownershipRevision, setOwnershipRevision] = useState("");

  const requiredFilled =
    merchantAccountId.trim() !== "" &&
    identityBrandId.trim() !== "" &&
    brandOwnerOrgUnitId.trim() !== "" &&
    operatorOrgUnitId.trim() !== "" &&
    ownershipRevision.trim() !== "";

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    const result = await create.mutateAsync({
      provider_code: providerCode,
      environment,
      merchant_account_id: merchantAccountId.trim(),
      charge_model: chargeModel,
      identity_brand_id: identityBrandId.trim(),
      brand_owner_org_unit_id: brandOwnerOrgUnitId.trim(),
      operator_org_unit_id: operatorOrgUnitId.trim(),
      ownership_revision: ownershipRevision.trim(),
      ...(merchantDisplayName.trim() ? { merchant_display_name: merchantDisplayName.trim() } : {}),
    });
    if (result.onboarding_url) {
      window.location.href = result.onboarding_url;
      return;
    }
    router.push(`/hq/${brandSlug}/settings/payments/gateways/${result.data.id}`);
  }

  return (
    <>
      <PageHeader
        title={t("hq.payments.gateways.new_title")}
        description={t("hq.payments.gateways.new_description")}
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
          <form onSubmit={handleSubmit} className="max-w-2xl space-y-4">
            <Card>
              <CardHeader>
                <CardTitle className="text-base">{t("hq.payments.gateways.new_form")}</CardTitle>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label>{t("hq.payments.field.provider")}</Label>
                  <Select value={providerCode} onValueChange={setProviderCode}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="stripe">Stripe</SelectItem>
                      <SelectItem value="paypay">PayPay</SelectItem>
                      <SelectItem value="sbps">SBPS</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-2">
                  <Label>{t("hq.payments.field.environment")}</Label>
                  <Select value={environment} onValueChange={setEnvironment}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="sandbox">{t("hq.payments.env.sandbox")}</SelectItem>
                      <SelectItem value="test">test</SelectItem>
                      <SelectItem value="live">{t("hq.payments.env.live")}</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("hq.payments.gateways.merchant_section")}
                </CardTitle>
              </CardHeader>
              <CardContent className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="merchant_account_id">
                    {t("hq.payments.field.merchant_account")} *
                  </Label>
                  <Input
                    id="merchant_account_id"
                    value={merchantAccountId}
                    onChange={(e) => setMerchantAccountId(e.target.value)}
                    placeholder="acct_1234567890"
                    required
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="merchant_display_name">
                    {t("hq.payments.field.display_name")}
                  </Label>
                  <Input
                    id="merchant_display_name"
                    value={merchantDisplayName}
                    onChange={(e) => setMerchantDisplayName(e.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <Label>{t("hq.payments.field.charge_model")} *</Label>
                  <Select value={chargeModel} onValueChange={setChargeModel}>
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="direct">direct</SelectItem>
                      <SelectItem value="destination">destination</SelectItem>
                      <SelectItem value="separate_charges_and_transfers">
                        separate_charges_and_transfers
                      </SelectItem>
                      <SelectItem value="provider_native">provider_native</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  {t("hq.payments.gateways.identity_section")}
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <p className="text-xs text-muted-foreground">
                  {t("hq.payments.gateways.identity_hint")}
                </p>
                <div className="grid gap-4 sm:grid-cols-2">
                  <div className="space-y-2">
                    <Label htmlFor="identity_brand_id">
                      {t("hq.payments.field.identity_brand")} *
                    </Label>
                    <Input
                      id="identity_brand_id"
                      value={identityBrandId}
                      onChange={(e) => setIdentityBrandId(e.target.value)}
                      placeholder="00000000-0000-0000-0000-000000000000"
                      required
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="ownership_revision">
                      {t("hq.payments.field.ownership_revision")} *
                    </Label>
                    <Input
                      id="ownership_revision"
                      value={ownershipRevision}
                      onChange={(e) => setOwnershipRevision(e.target.value)}
                      placeholder="10001"
                      required
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="brand_owner_org_unit_id">
                      {t("hq.payments.field.brand_owner_org_unit")} *
                    </Label>
                    <Input
                      id="brand_owner_org_unit_id"
                      value={brandOwnerOrgUnitId}
                      onChange={(e) => setBrandOwnerOrgUnitId(e.target.value)}
                      placeholder="00000000-0000-0000-0000-000000000000"
                      required
                    />
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="operator_org_unit_id">
                      {t("hq.payments.field.operator_org_unit")} *
                    </Label>
                    <Input
                      id="operator_org_unit_id"
                      value={operatorOrgUnitId}
                      onChange={(e) => setOperatorOrgUnitId(e.target.value)}
                      placeholder="00000000-0000-0000-0000-000000000000"
                      required
                    />
                  </div>
                </div>
              </CardContent>
            </Card>

            <Button type="submit" disabled={create.isPending || !requiredFilled} className="h-8">
              {create.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
              {t("hq.payments.gateways.start_onboarding")}
            </Button>
          </form>
        </PaymentsSettingsShell>
      </PageContent>
    </>
  );
}
