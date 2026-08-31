"use client";

/**
 * Reverb connection config panel (TC-SET-REV01 / TC-SET-REV02).
 *
 * Read-only view of the brand's Reverb connection settings (app_key + the
 * shared host/port/scheme infra config) plus a "Test connection" action.
 * app_secret is never fetched here — it is only revealed once on rotation
 * (TC-SET-REV04), handled by ReverbRotationTab.
 */

import { Alert, AlertDescription, Button, Card, CardContent, Spinner } from "@godxjp/ui";
import { useMutation, useQuery } from "@tanstack/react-query";
import { AlertCircle, CheckCircle2, Plug, Wifi } from "lucide-react";
import { toast } from "sonner";

import { apiFetch } from "@/lib/api";
import { useTranslation } from "@/providers/app-provider";

interface ReverbConfig {
  app_id: string | null;
  app_key: string | null;
  allowed_origins: string[];
  provisioned_at: string | null;
  host: string;
  port: number;
  scheme: string;
  cluster: string;
}

interface TestResult {
  ok: boolean;
  host: string;
  port: number;
  latency_ms?: number;
  message: string;
}

interface ReverbConfigPanelProps {
  brandSlug: string;
}

export function ReverbConfigPanel({ brandSlug }: ReverbConfigPanelProps) {
  const { t } = useTranslation();

  const config = useQuery({
    queryKey: ["hq", brandSlug, "settings", "reverb", "config"],
    queryFn: () =>
      apiFetch<{ data: ReverbConfig }>(`/api/v1/hq/${brandSlug}/settings/reverb`).then(
        (r) => r.data
      ),
    enabled: !!brandSlug,
  });

  const test = useMutation({
    mutationFn: () =>
      apiFetch<{ data: TestResult }>(`/api/v1/hq/${brandSlug}/settings/reverb/test`, {
        method: "POST",
      }).then((r) => r.data),
    onSuccess: (result) => {
      if (result.ok) {
        toast.success(
          t("hq.reverb.test_ok", {
            host: result.host,
            port: result.port,
            latency: result.latency_ms ?? 0,
          })
        );
      } else {
        toast.error(t("hq.reverb.test_fail", { message: result.message }));
      }
    },
    onError: () => toast.error(t("hq.reverb.test_fail", { message: "" })),
  });

  const rows: Array<{ label: string; value: string }> = config.data
    ? [
        { label: t("hq.reverb.field_app_key"), value: config.data.app_key ?? "—" },
        { label: t("hq.reverb.field_host"), value: config.data.host },
        { label: t("hq.reverb.field_port"), value: String(config.data.port) },
        { label: t("hq.reverb.field_scheme"), value: config.data.scheme },
        { label: t("hq.reverb.field_cluster"), value: config.data.cluster },
        {
          label: t("hq.reverb.field_allowed_origins"),
          value: config.data.allowed_origins.join(", ") || "*",
        },
      ]
    : [];

  return (
    <Card data-slot="reverb-config-panel">
      <CardContent className="space-y-4 p-4">
        <div className="flex items-start gap-3">
          <div className="rounded-full bg-muted p-2">
            <Wifi className="size-4" />
          </div>
          <div className="flex-1">
            <p className="text-sm font-medium">{t("hq.reverb.config_title")}</p>
            <p className="text-xs text-muted-foreground">{t("hq.reverb.config_desc")}</p>
          </div>
          <Button
            variant="outline"
            size="sm"
            onClick={() => test.mutate()}
            disabled={test.isPending || !config.data}
          >
            {test.isPending ? (
              <Spinner className="mr-2 size-3.5" />
            ) : test.data?.ok ? (
              <CheckCircle2 className="mr-2 size-3.5 text-emerald-600" />
            ) : (
              <Plug className="mr-2 size-3.5" />
            )}
            {t("hq.reverb.test_button")}
          </Button>
        </div>

        {config.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5" />
          </div>
        ) : config.isError ? (
          <Alert variant="destructive">
            <AlertCircle className="size-4" />
            <AlertDescription className="flex items-center justify-between">
              {t("common.error_loading")}
              <Button variant="outline" size="sm" onClick={() => config.refetch()}>
                {t("common.retry")}
              </Button>
            </AlertDescription>
          </Alert>
        ) : (
          <dl className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            {rows.map((row) => (
              <div key={row.label} className="rounded-md border bg-background p-2.5">
                <dt className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                  {row.label}
                </dt>
                <dd className="mt-0.5 truncate font-mono text-xs" title={row.value}>
                  {row.value}
                </dd>
              </div>
            ))}
          </dl>
        )}
      </CardContent>
    </Card>
  );
}
