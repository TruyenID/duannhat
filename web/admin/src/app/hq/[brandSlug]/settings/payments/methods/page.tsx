"use client";

import { useMemo } from "react";
import { useParams } from "next/navigation";
import type { ColumnDef } from "@tanstack/react-table";
import {
  Alert,
  AlertDescription,
  Badge,
  RadioGroup,
  RadioGroupItem,
  Label,
  Spinner,
} from "@godxjp/ui";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { DataTable } from "@/components/shared/data-table";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { SettingsTabsNav } from "../../components/settings-tabs-nav";
import { PaymentsSettingsShell } from "../components/payments-settings-nav";
import {
  useHqPaymentOptionPolicies,
  useUpdateHqPaymentOptionPolicy,
} from "@/hooks/api/use-payment-gateways";
import {
  isPolicyEffective,
  type HqPaymentOptionPolicyRow,
} from "@/services/payment-gateway-service";
import { useTranslation } from "@/providers/app-provider";
import {
  PaymentPolicyPreference,
  getPaymentPolicyPreferenceLabel,
} from "@/types/models/enum/PaymentPolicyPreference";
import { getPaymentOptionRailLabel } from "@/types/models/enum/PaymentOptionRail";
import type { PaymentOptionRail } from "@/types/models/enum/PaymentOptionRail";

/** Provider code for grouping/labels — nested under `option` in the API payload. */
function providerCodeOf(row: HqPaymentOptionPolicyRow): string {
  return row.option.provider?.code ?? "—";
}

/** G6 — HQ default on / default off / blocked with effective preview. */
export default function HqPaymentMethodsPolicyPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t, locale } = useTranslation();
  const { data, isLoading, refetch } = useHqPaymentOptionPolicies(brandSlug);
  const updatePolicy = useUpdateHqPaymentOptionPolicy(brandSlug);

  const rows = useMemo(() => data ?? [], [data]);

  const columns: ColumnDef<HqPaymentOptionPolicyRow>[] = useMemo(
    () => [
      {
        id: "option",
        header: t("hq.payments.methods.col.option"),
        cell: ({ row }) => (
          <div>
            <p className="font-medium">
              {row.original.option.name ?? row.original.option.code}
            </p>
            <code className="text-[10px] text-muted-foreground">{row.original.option.code}</code>
          </div>
        ),
      },
      {
        id: "rail",
        header: t("hq.payments.methods.col.rail"),
        cell: ({ row }) => (
          <Badge variant="outline" className="text-[10px]">
            {getPaymentOptionRailLabel(row.original.option.rail as PaymentOptionRail, locale)}
          </Badge>
        ),
      },
      {
        id: "provider",
        header: t("hq.payments.field.provider"),
        cell: ({ row }) => row.original.option.provider?.name ?? providerCodeOf(row.original),
      },
      {
        id: "hq_policy",
        header: t("hq.payments.methods.col.hq_policy"),
        cell: ({ row }) => (
          <HqPolicyControl
            row={row.original}
            disabled={updatePolicy.isPending}
            onChange={(preference) =>
              updatePolicy.mutate({ option_id: row.original.option_id, preference })
            }
            locale={locale}
          />
        ),
      },
      {
        id: "effective",
        header: t("hq.payments.methods.col.effective"),
        cell: ({ row }) => (
          <div className="space-y-1">
            <Badge variant={isPolicyEffective(row.original.effective_preview) ? "default" : "secondary"}>
              {isPolicyEffective(row.original.effective_preview)
                ? t("hq.payments.methods.effective_yes")
                : t("hq.payments.methods.effective_no")}
            </Badge>
            <p className="text-[10px] text-muted-foreground">{row.original.effective_preview}</p>
          </div>
        ),
      },
    ],
    [t, locale, updatePolicy]
  );

  const grouped = useMemo(() => {
    const map = new Map<string, HqPaymentOptionPolicyRow[]>();
    for (const row of rows) {
      const key = providerCodeOf(row);
      if (!map.has(key)) map.set(key, []);
      map.get(key)!.push(row);
    }
    return map;
  }, [rows]);

  return (
    <>
      <PageHeader
        title={t("hq.payments.methods.title")}
        description={t("hq.payments.methods.description")}
        onRefresh={refetch}
      />

      <PageContent>
        <SettingsTabsNav brandSlug={brandSlug} />
        <PaymentsSettingsShell brandSlug={brandSlug}>
          <Alert className="mb-4">
            <AlertDescription>{t("hq.payments.methods.legacy_hint")}</AlertDescription>
          </Alert>

          {isLoading ? (
            <DataTableSkeleton columns={5} />
          ) : grouped.size > 1 ? (
            <div className="space-y-6">
              {Array.from(grouped.entries()).map(([provider, providerRows]) => (
                <section key={provider}>
                  <h3 className="mb-2 text-sm font-semibold capitalize">{provider}</h3>
                  <DataTable
                    columns={columns}
                    data={providerRows}
                    emptyMessage={t("hq.payments.methods.empty")}
                  />
                </section>
              ))}
            </div>
          ) : (
            <DataTable columns={columns} data={rows} emptyMessage={t("hq.payments.methods.empty")} />
          )}
        </PaymentsSettingsShell>
      </PageContent>
    </>
  );
}

function HqPolicyControl({
  row,
  disabled,
  onChange,
  locale,
}: {
  row: HqPaymentOptionPolicyRow;
  disabled: boolean;
  onChange: (preference: PaymentPolicyPreference) => void;
  locale: string;
}) {
  const { t } = useTranslation();
  const value = row.preference as PaymentPolicyPreference;

  const options = [
    PaymentPolicyPreference.Enabled,
    PaymentPolicyPreference.Disabled,
    PaymentPolicyPreference.Blocked,
  ];

  return (
    <RadioGroup
      value={value}
      onValueChange={(v) => onChange(v as PaymentPolicyPreference)}
      className="flex flex-col gap-1"
      disabled={disabled}
      aria-label={t("hq.payments.methods.col.hq_policy")}
    >
      {options.map((opt) => (
        <div key={opt} className="flex items-center gap-2">
          <RadioGroupItem value={opt} id={`${row.option_id}-${opt}`} />
          <Label htmlFor={`${row.option_id}-${opt}`} className="text-xs font-normal">
            {getPaymentPolicyPreferenceLabel(opt, locale)}
          </Label>
        </div>
      ))}
      {disabled ? <Spinner className="size-3" /> : null}
    </RadioGroup>
  );
}
