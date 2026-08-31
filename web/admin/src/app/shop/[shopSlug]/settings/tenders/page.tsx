"use client";

import { useMemo } from "react";
import { useParams } from "next/navigation";
import {
  Badge,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@godxjp/ui";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { DataTableSkeleton } from "@/components/shared/data-table-skeleton";
import { HelpPanel } from "@/components/shared/help-panel";
import { useTranslation } from "@/providers/app-provider";
import {
  useTillTenderActivation,
  useToggleTillTenderActivation,
} from "@/hooks/api/use-till-tender-activation";
import type { TillTenderType } from "@/services/till-tender-type-service";

/**
 * #1156 — per-branch tender activation.
 *
 * Lists the branch's EFFECTIVE tender vocabulary (org-wide seed + branch
 * overrides, GET /shops/{slug}/till/tender-types) with one activation switch
 * per row (PATCH /shops/{slug}/till/tender-types/{tenderKey}). Flipping a
 * tender only affects THIS branch — the first flip materializes a
 * branch-scoped override row; the org vocabulary itself is managed in
 * Settings → tender types.
 */
export default function ShopTenderActivationPage() {
  const { shopSlug } = useParams<{ shopSlug: string }>();
  const { t, locale } = useTranslation();

  const { data, isLoading, isFetching, refetch } = useTillTenderActivation(shopSlug);
  const toggleMutation = useToggleTillTenderActivation(shopSlug);

  const rows = useMemo(() => data?.data ?? [], [data]);
  const activeCount = rows.filter((row) => row.is_active).length;

  const displayName = (row: TillTenderType): string => {
    const name = row.name as unknown;
    if (typeof name === "string") return name;
    if (name && typeof name === "object") {
      const map = name as Record<string, string>;
      return map[locale] ?? map.ja ?? map.en ?? Object.values(map)[0] ?? row.tender_key;
    }
    return row.tender_key;
  };

  return (
    <>
      <PageHeader
        title={t("shop.tenders.title")}
        description={t("shop.tenders.count", { active: activeCount, total: rows.length })}
        onRefresh={refetch}
        isRefreshing={isFetching}
      >
        <HelpPanel
          title={t("shop.tenders.title")}
          subtitle={t("help.panel.shop_tenders.subtitle")}
          purpose={t("help.panel.shop_tenders.purpose")}
          usage={[t("help.panel.shop_tenders.usage.1"), t("help.panel.shop_tenders.usage.2")]}
          checks={[
            t("help.panel.shop_tenders.checks.1"),
            t("help.panel.shop_tenders.checks.2"),
            t("help.panel.shop_tenders.checks.3"),
          ]}
          glossary={[
            {
              term: t("help.panel.shop_tenders.glossary.tender_key.term"),
              description: t("help.panel.shop_tenders.glossary.tender_key.desc"),
            },
            {
              term: t("help.panel.shop_tenders.glossary.parent.term"),
              description: t("help.panel.shop_tenders.glossary.parent.desc"),
            },
          ]}
        />
      </PageHeader>

      <PageContent>
        <p className="mb-4 max-w-prose text-sm text-muted-foreground">
          {t("shop.tenders.description")}
        </p>

        {isLoading && data === undefined ? (
          <DataTableSkeleton columns={5} />
        ) : (
          <div className="overflow-x-auto rounded-lg border">
            <Table data-slot="tender-activation-table">
              <TableHeader>
                <TableRow>
                  <TableHead>{t("shop.tenders.col.name")}</TableHead>
                  <TableHead>{t("shop.tenders.col.key")}</TableHead>
                  <TableHead>{t("shop.tenders.col.category")}</TableHead>
                  <TableHead className="text-right">{t("shop.tenders.col.active")}</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((row) => (
                  <TableRow key={row.tender_key} data-state={row.is_active ? "active" : undefined}>
                    <TableCell className="font-medium">
                      {displayName(row)}
                      {row.parent_tender_key && (
                        <span className="ml-2 text-xs text-muted-foreground">
                          ← {row.parent_tender_key}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="font-mono text-xs">{row.tender_key}</TableCell>
                    <TableCell>
                      <Badge variant="outline" className="h-5 text-[10px]">
                        {t(`shop.tenders.category.${row.category}`) !==
                        `shop.tenders.category.${row.category}`
                          ? t(`shop.tenders.category.${row.category}`)
                          : row.category}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <Switch
                        checked={row.is_active}
                        onCheckedChange={(checked) =>
                          toggleMutation.mutate({ tenderKey: row.tender_key, isActive: checked })
                        }
                        aria-label={t("shop.tenders.toggle_aria", { name: displayName(row) })}
                      />
                    </TableCell>
                  </TableRow>
                ))}
                {rows.length === 0 && (
                  <TableRow>
                    <TableCell
                      colSpan={4}
                      className="py-10 text-center text-sm text-muted-foreground"
                    >
                      {t("shop.tenders.empty")}
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
        )}
      </PageContent>
    </>
  );
}
