"use client";

/**
 * Version history + diff + rollback — plan-053 M4 (#1171), TR-31 / TR-38.
 *
 * History only ever moves FORWARDS: rollback republishes an old definition as
 * a NEW version, it never un-publishes one (TR-38). So this list is an audit
 * trail — "what were we printing on the 3rd" stays answerable after any number
 * of mistakes — and the destructive-looking button is not destructive at all.
 */

import { useState } from "react";
import {
  Badge,
  Button,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";
import { Archive, Undo2 } from "lucide-react";
import { DefinitionDiffView } from "@/components/shared/print-template/definition-diff";
import { useTranslation } from "@/providers/app-provider";
import {
  useBrandPrintTemplateDiff,
  useBrandPrintTemplateHistory,
} from "@/hooks/api/use-print-templates";
import type { PrintTemplateVersion } from "@/types/models/PrintTemplate";

export interface VersionHistoryProps {
  brandSlug: string;
  kind: string;
  onRollback: (version: PrintTemplateVersion) => void;
  onRetire: (version: PrintTemplateVersion) => void;
  isMutating: boolean;
}

const STATUS_COLOR: Record<string, "success" | "warning" | "destructive"> = {
  published: "success",
  draft: "warning",
  retired: "destructive",
};

export function VersionHistory({
  brandSlug,
  kind,
  onRollback,
  onRetire,
  isMutating,
}: VersionHistoryProps) {
  const { t } = useTranslation();
  const { data, isLoading } = useBrandPrintTemplateHistory(brandSlug, kind);
  const versions = data?.data ?? [];
  const published = versions.filter((version) => version.status !== "draft");

  // Default comparison: the newest published version against the one before
  // it, falling back to "against the system default" (version 0) for a brand's
  // very first publish.
  const [from, setFrom] = useState<string>("");
  const [to, setTo] = useState<string>("");

  const defaultTo = published[0]?.version;
  const defaultFrom = published[1]?.version ?? 0;
  const effectiveTo = to === "" ? defaultTo : Number(to);
  const effectiveFrom = from === "" ? defaultFrom : Number(from);

  const { data: diff, isFetching: diffLoading } = useBrandPrintTemplateDiff(
    brandSlug,
    kind,
    effectiveFrom ?? 0,
    effectiveTo,
    effectiveTo !== undefined
  );

  if (isLoading) {
    return (
      <div className="flex items-center gap-2 p-6 text-xs text-muted-foreground">
        <Spinner className="size-3.5" />
        {t("common.loading")}
      </div>
    );
  }

  if (versions.length === 0) {
    return (
      <p className="rounded-md border border-dashed px-3 py-8 text-center text-xs text-muted-foreground">
        {t("print_templates.history.empty")}
      </p>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <ul className="divide-y rounded-md border" data-testid="version-history">
        {versions.map((version) => (
          <li key={version.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
            <span className="font-mono text-xs font-semibold">v{version.version}</span>
            <Badge
              color={STATUS_COLOR[version.status] ?? "info"}
              variant="soft"
              className="text-[10px]"
            >
              {t(`print_templates.status.${version.status}`)}
            </Badge>
            <span className="text-[11px] text-muted-foreground">
              {version.published_at
                ? new Date(version.published_at).toLocaleString()
                : t("print_templates.history.not_published")}
            </span>
            {version.published_by && (
              <span className="text-[11px] text-muted-foreground">· {version.published_by}</span>
            )}
            {version.effective_from && (
              <span className="font-mono text-[11px] text-muted-foreground">
                · {t("print_templates.effective_from")}: {version.effective_from}
              </span>
            )}
            {version.notes && <span className="text-[11px]">— {version.notes}</span>}

            <div className="ml-auto flex items-center gap-1">
              {version.status === "published" && (
                <>
                  <Button
                    size="sm"
                    variant="outline"
                    className="h-7 gap-1 text-xs"
                    disabled={isMutating}
                    data-testid={`rollback-v${version.version}`}
                    onClick={() => onRollback(version)}
                  >
                    <Undo2 className="size-3.5" />
                    {t("print_templates.action.rollback")}
                  </Button>
                  <Button
                    size="sm"
                    variant="ghost"
                    className="h-7 gap-1 text-xs text-muted-foreground"
                    disabled={isMutating}
                    onClick={() => onRetire(version)}
                  >
                    <Archive className="size-3.5" />
                    {t("print_templates.action.retire")}
                  </Button>
                </>
              )}
            </div>
          </li>
        ))}
      </ul>

      <section className="flex flex-col gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-xs font-medium">{t("print_templates.diff.title")}</span>
          <Select value={from || String(defaultFrom)} onValueChange={setFrom}>
            <SelectTrigger className="h-8 w-44 text-xs" data-testid="diff-from">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="0">{t("print_templates.system_default")}</SelectItem>
              {published.map((version) => (
                <SelectItem key={version.id} value={String(version.version)}>
                  v{version.version}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <span className="text-xs text-muted-foreground">→</span>
          <Select value={to || String(defaultTo ?? "")} onValueChange={setTo}>
            <SelectTrigger className="h-8 w-44 text-xs" data-testid="diff-to">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {published.map((version) => (
                <SelectItem key={version.id} value={String(version.version)}>
                  v{version.version}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {diffLoading && <Spinner className="size-3.5" />}
        </div>

        {diff && (
          <DefinitionDiffView
            changes={diff.data.changes}
            fromVersion={diff.data.from_version}
            toVersion={diff.data.to_version}
          />
        )}
      </section>
    </div>
  );
}
