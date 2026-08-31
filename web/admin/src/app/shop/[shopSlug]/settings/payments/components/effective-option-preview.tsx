"use client";

import { Badge, StatusBadge } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type {
  EffectivePaymentOptionRow,
  PaymentPolicyTraceEntry,
} from "@/services/shop-payment-settings-service";

export interface EffectiveOptionPreviewProps {
  preference: string;
  effective: boolean;
  source: string;
  reasonCode: string;
  errorCode?: string | null;
  providerCode?: string;
  rail?: string;
  trace?: PaymentPolicyTraceEntry[];
  compact?: boolean;
}

/**
 * Read-only projection of one resolved payment option: preference, effective
 * verdict, denying layer (source), reason code + the resolver trace so an
 * operator can see WHICH policy layer said no.
 */
export function EffectiveOptionPreview({
  preference,
  effective,
  source,
  reasonCode,
  errorCode,
  providerCode,
  rail,
  trace,
  compact = false,
}: EffectiveOptionPreviewProps) {
  const { t } = useTranslation();

  return (
    <div
      data-slot="effective-option-preview"
      className={compact ? "space-y-2 text-xs" : "space-y-3 text-sm"}
      aria-label={t("shop.payments.preview.aria_label")}
    >
      <div className="flex flex-wrap items-center gap-2">
        <StatusBadge status={effective ? "active" : "inactive"} />
        {providerCode && (
          <Badge variant="secondary" className="h-5 text-[10px]">
            {providerCode}
          </Badge>
        )}
        {rail && (
          <Badge variant="outline" className="h-5 text-[10px]">
            {rail}
          </Badge>
        )}
      </div>

      <dl className="grid gap-1.5 sm:grid-cols-2">
        <PreviewRow label={t("shop.payments.preview.preference")} value={preference} />
        <PreviewRow
          label={t("shop.payments.preview.effective")}
          value={effective ? t("shop.payments.preview.enabled") : t("shop.payments.preview.disabled")}
        />
        <PreviewRow label={t("shop.payments.preview.source")} value={source} />
        <PreviewRow label={t("shop.payments.preview.reason")} value={reasonCode} />
        {errorCode && (
          <PreviewRow
            label={t("shop.payments.preview.error_code")}
            value={errorCode}
            className="sm:col-span-2"
          />
        )}
      </dl>

      {trace && trace.length > 0 && (
        <div className="flex flex-wrap items-center gap-1.5" role="note">
          <span className="text-[11px] text-muted-foreground">
            {t("shop.payments.preview.trace")}:
          </span>
          {trace.map((entry, index) => (
            <Badge
              key={`${entry.layer}-${index}`}
              variant={entry.decision === "allowed" ? "outline" : "secondary"}
              className="h-5 font-mono text-[10px]"
              title={entry.reason}
            >
              {entry.layer}: {entry.decision}
            </Badge>
          ))}
        </div>
      )}
    </div>
  );
}

function PreviewRow({
  label,
  value,
  className,
}: {
  label: string;
  value: string;
  className?: string;
}) {
  return (
    <div className={className}>
      <dt className="text-[11px] text-muted-foreground">{label}</dt>
      <dd className="font-medium text-foreground">{value}</dd>
    </div>
  );
}

export function effectivePreviewFromShopOption(
  row: EffectivePaymentOptionRow
): EffectiveOptionPreviewProps {
  return {
    preference: row.shop_preference,
    effective: row.effective,
    source: row.source,
    reasonCode: row.reason,
    errorCode: row.error_code,
    providerCode: row.provider,
    rail: row.rail,
    trace: row.trace,
  };
}

export function effectivePreviewFromDeviceOption(
  row: EffectivePaymentOptionRow
): EffectiveOptionPreviewProps {
  return {
    preference: row.device_preference,
    effective: row.effective,
    source: row.source,
    reasonCode: row.reason,
    errorCode: row.error_code,
    providerCode: row.provider,
    rail: row.rail,
    trace: row.trace,
  };
}
