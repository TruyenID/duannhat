"use client";

/**
 * #1153 — Cấu hình hoá đơn điện tử VN (brand default).
 *
 * Chỉ có nghĩa với tổ chức hoạt động tại VN (operating_country=VN, mirror từ
 * Platform): org khác thấy thông báo "không áp dụng" thay vì form. Credentials
 * là WRITE-ONLY — server chỉ trả `has_credentials`, form gửi bộ mới khi người
 * dùng nhập đủ cả 3 trường (base_url / username / password).
 *
 * Việc BẬT truyền thật còn cần cờ hệ thống `VN_EINVOICE_ENABLED` phía server
 * (fail-closed chờ hợp đồng provider + đăng ký dải ký hiệu CQT) — form hiển
 * thị trạng thái cờ đó read-only.
 */

import { useState } from "react";
import { useQueryClient, useQuery, useMutation } from "@tanstack/react-query";
import { toast } from "sonner";

import { apiFetch, ApiError } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";

import {
  Badge,
  Button,
  Card,
  CardHeader,
  CardTitle,
  CardDescription,
  CardContent,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
} from "@godxjp/ui";

interface VnEinvoiceSettingsData {
  applicable: boolean;
  globally_enabled: boolean;
  providers: string[];
  enabled: boolean;
  provider: string | null;
  template_code: string | null;
  invoice_series: string | null;
  has_credentials: boolean;
}

interface VnEinvoiceSettingsResponse {
  data: VnEinvoiceSettingsData;
}

const vnEinvoiceKeys = {
  get: (brandSlug: string) => ["hq", brandSlug, "settings", "vn-einvoice"] as const,
};

export interface BrandVnEinvoiceTabProps {
  brandSlug: string;
}

export function BrandVnEinvoiceTab({ brandSlug }: BrandVnEinvoiceTabProps) {
  const { t } = useTranslation();
  const qc = useQueryClient();

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: vnEinvoiceKeys.get(brandSlug),
    queryFn: () =>
      apiFetch<VnEinvoiceSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/vn-einvoice`),
    staleTime: 60 * 1000,
    retry: false,
  });

  const settings = data?.data;

  const [enabled, setEnabled] = useState<boolean | null>(null);
  const [provider, setProvider] = useState<string | null>(null);
  const [templateCode, setTemplateCode] = useState<string | null>(null);
  const [invoiceSeries, setInvoiceSeries] = useState<string | null>(null);
  const [credBaseUrl, setCredBaseUrl] = useState("");
  const [credUsername, setCredUsername] = useState("");
  const [credPassword, setCredPassword] = useState("");

  const effEnabled = enabled ?? settings?.enabled ?? false;
  const effProvider = provider ?? settings?.provider ?? "";
  const effTemplateCode = templateCode ?? settings?.template_code ?? "";
  const effInvoiceSeries = invoiceSeries ?? settings?.invoice_series ?? "";

  const credsTouched =
    credBaseUrl.trim() !== "" || credUsername.trim() !== "" || credPassword.trim() !== "";
  const credsComplete =
    credBaseUrl.trim() !== "" && credUsername.trim() !== "" && credPassword.trim() !== "";

  const saveMutation = useMutation({
    mutationFn: () => {
      const body: Record<string, unknown> = {
        enabled: effEnabled,
        provider: effProvider === "" ? null : effProvider,
        template_code: effTemplateCode.trim() === "" ? null : effTemplateCode.trim(),
        invoice_series: effInvoiceSeries.trim() === "" ? null : effInvoiceSeries.trim(),
      };
      if (credsComplete) {
        body.credentials = {
          base_url: credBaseUrl.trim(),
          username: credUsername.trim(),
          password: credPassword.trim(),
        };
      }
      return apiFetch<VnEinvoiceSettingsResponse>(`/api/v1/hq/${brandSlug}/settings/vn-einvoice`, {
        method: "PATCH",
        body: JSON.stringify(body),
      });
    },
    onSuccess: () => {
      setEnabled(null);
      setProvider(null);
      setTemplateCode(null);
      setInvoiceSeries(null);
      setCredBaseUrl("");
      setCredUsername("");
      setCredPassword("");
      qc.invalidateQueries({ queryKey: vnEinvoiceKeys.get(brandSlug) });
      toast.success(t("hq.brand.settings.vn_einvoice.toast_saved"));
    },
    onError: (err) => {
      toast.error(
        err instanceof ApiError && err.body
          ? String((err.body as { message?: string }).message ?? err.message)
          : err instanceof Error
            ? err.message
            : t("hq.brand.settings.vn_einvoice.toast_error")
      );
    },
  });

  if (error) {
    return (
      <div className="flex h-full flex-col items-center justify-center gap-3 text-sm text-muted-foreground">
        <p>{t("common.error_loading")}</p>
        <Button variant="outline" size="sm" onClick={() => refetch()}>
          {t("common.retry")}
        </Button>
      </div>
    );
  }

  if (isLoading || !settings) {
    return (
      <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
        <Spinner className="size-3.5" />
        {t("common.loading")}
      </div>
    );
  }

  if (!settings.applicable) {
    return (
      <div data-slot="brand-vn-einvoice-tab" className="max-w-xl">
        <Card>
          <CardHeader>
            <CardTitle className="text-base">
              {t("hq.brand.settings.vn_einvoice.section_title")}
            </CardTitle>
            <CardDescription>
              {t("hq.brand.settings.vn_einvoice.not_applicable")}
            </CardDescription>
          </CardHeader>
        </Card>
      </div>
    );
  }

  const dirty =
    enabled !== null ||
    provider !== null ||
    templateCode !== null ||
    invoiceSeries !== null ||
    credsTouched;

  return (
    <div data-slot="brand-vn-einvoice-tab" className="max-w-xl space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            {t("hq.brand.settings.vn_einvoice.section_title")}
            {settings.globally_enabled ? (
              <Badge variant="secondary">
                {t("hq.brand.settings.vn_einvoice.global_on")}
              </Badge>
            ) : (
              <Badge variant="outline">
                {t("hq.brand.settings.vn_einvoice.global_off")}
              </Badge>
            )}
          </CardTitle>
          <CardDescription>
            {t("hq.brand.settings.vn_einvoice.description")}
          </CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          <div className="flex items-center justify-between gap-3">
            <Label htmlFor="vn-einvoice-enabled" className="cursor-pointer">
              {t("hq.brand.settings.vn_einvoice.enabled_label")}
            </Label>
            <Switch
              id="vn-einvoice-enabled"
              checked={effEnabled}
              onCheckedChange={(v: boolean) => setEnabled(v)}
            />
          </div>

          <div className="space-y-1.5">
            <Label>{t("hq.brand.settings.vn_einvoice.provider_label")}</Label>
            <Select value={effProvider} onValueChange={(v: string) => setProvider(v)}>
              <SelectTrigger id="vn-einvoice-provider">
                <SelectValue
                  placeholder={t("hq.brand.settings.vn_einvoice.provider_placeholder")}
                />
              </SelectTrigger>
              <SelectContent>
                {settings.providers.map((p) => (
                  <SelectItem key={p} value={p}>
                    {t(`hq.brand.settings.vn_einvoice.provider_${p}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="vn-einvoice-template">
                {t("hq.brand.settings.vn_einvoice.template_code_label")}
              </Label>
              <Input
                id="vn-einvoice-template"
                value={effTemplateCode}
                onChange={(e) => setTemplateCode(e.target.value)}
                placeholder="1/001"
                className="font-mono"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="vn-einvoice-series">
                {t("hq.brand.settings.vn_einvoice.invoice_series_label")}
              </Label>
              <Input
                id="vn-einvoice-series"
                value={effInvoiceSeries}
                onChange={(e) => setInvoiceSeries(e.target.value)}
                placeholder="C25TAA"
                className="font-mono"
              />
            </div>
          </div>

          <div className="space-y-3 rounded-md border p-3">
            <div className="flex items-center justify-between">
              <p className="text-sm font-medium">
                {t("hq.brand.settings.vn_einvoice.credentials_label")}
              </p>
              {settings.has_credentials && !credsTouched && (
                <Badge variant="secondary">
                  {t("hq.brand.settings.vn_einvoice.credentials_saved")}
                </Badge>
              )}
            </div>
            <p className="text-xs text-muted-foreground">
              {t("hq.brand.settings.vn_einvoice.credentials_hint")}
            </p>
            <Input
              value={credBaseUrl}
              onChange={(e) => setCredBaseUrl(e.target.value)}
              placeholder={t("hq.brand.settings.vn_einvoice.credentials_base_url")}
              className="font-mono"
            />
            <div className="grid grid-cols-2 gap-3">
              <Input
                value={credUsername}
                onChange={(e) => setCredUsername(e.target.value)}
                placeholder={t("hq.brand.settings.vn_einvoice.credentials_username")}
                autoComplete="off"
              />
              <Input
                type="password"
                value={credPassword}
                onChange={(e) => setCredPassword(e.target.value)}
                placeholder={t("hq.brand.settings.vn_einvoice.credentials_password")}
                autoComplete="new-password"
              />
            </div>
            {credsTouched && !credsComplete && (
              <p className="text-xs text-red-600">
                {t("hq.brand.settings.vn_einvoice.credentials_incomplete")}
              </p>
            )}
          </div>

          <div className="flex items-center gap-2 border-t pt-4">
            <Button
              onClick={() => saveMutation.mutate()}
              disabled={saveMutation.isPending || !dirty || (credsTouched && !credsComplete)}
              className="gap-2"
            >
              {saveMutation.isPending && <Spinner className="size-3.5" />}
              {t("common.save")}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
