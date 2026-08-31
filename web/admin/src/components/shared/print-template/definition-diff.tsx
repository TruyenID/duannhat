"use client";

/**
 * Version diff — plan-053 M4 (#1171), TR-31.
 *
 * "What is different between June's receipt and July's" has to be answerable
 * from the history screen, not by diffing two JSON blobs by eye. The backend
 * returns a flat, path-addressed change list (`DefinitionDiff`); this renders
 * it, with `from = 0` meaning "compared against the system default".
 */

import { Badge } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type { PrintTemplateDiffChange } from "@/types/models/PrintTemplate";

export interface DefinitionDiffViewProps {
  changes: PrintTemplateDiffChange[];
  fromVersion: number;
  toVersion: number | null;
}

function format(value: unknown): string {
  if (value === undefined || value === null) return "—";
  if (typeof value === "string") return value === "" ? '""' : value;
  if (typeof value === "boolean" || typeof value === "number") return String(value);
  return JSON.stringify(value);
}

const OP_COLOR: Record<string, "success" | "destructive" | "info"> = {
  added: "success",
  removed: "destructive",
  changed: "info",
};

export function DefinitionDiffView({ changes, fromVersion, toVersion }: DefinitionDiffViewProps) {
  const { t } = useTranslation();

  if (changes.length === 0) {
    return (
      <p className="rounded-md border border-dashed px-3 py-6 text-center text-xs text-muted-foreground">
        {t("print_templates.diff.identical")}
      </p>
    );
  }

  return (
    <div data-slot="definition-diff" className="flex flex-col gap-2">
      <p className="text-xs text-muted-foreground">
        {t("print_templates.diff.header", {
          from: fromVersion === 0 ? t("print_templates.system_default") : `v${fromVersion}`,
          to: toVersion === null ? t("print_templates.system_default") : `v${toVersion}`,
          count: changes.length,
        })}
      </p>
      <ul className="divide-y rounded-md border">
        {changes.map((change, index) => (
          <li key={`${change.path}-${index}`} className="flex flex-col gap-1 px-3 py-2">
            <div className="flex items-center gap-2">
              <Badge color={OP_COLOR[change.op] ?? "info"} variant="soft" className="text-[10px]">
                {t(`print_templates.diff.op.${change.op}`)}
              </Badge>
              <span className="font-mono text-xs">{change.path}</span>
            </div>
            <div className="grid gap-1 sm:grid-cols-2">
              <div className="rounded bg-destructive/5 px-2 py-1 font-mono text-[11px] break-all">
                <span className="mr-1 text-muted-foreground">−</span>
                {format(change.from)}
              </div>
              <div className="rounded bg-emerald-500/10 px-2 py-1 font-mono text-[11px] break-all">
                <span className="mr-1 text-muted-foreground">+</span>
                {format(change.to)}
              </div>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
