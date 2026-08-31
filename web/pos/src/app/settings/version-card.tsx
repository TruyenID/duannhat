import { InfoIcon, RefreshCwIcon } from "lucide-react";
import { Button, Card, CardContent, CardHeader, CardTitle } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useWorkstation } from "@/providers/workstation-provider";

/**
 * Which builds this terminal is actually running (#2632).
 *
 * Lives on the settings screen, not on the sales screen: it is a number read
 * out during a support call, never something a cashier acts on mid-order.
 *
 * TWO rows, never one. The bundle is embedded in the binary, so they usually
 * match — but the browser can hold a cached older bundle, and a shop reporting
 * "the POS does X" while the binary is current is exactly the case a single
 * merged number cannot express. They are also NOT compared here: the binary
 * version and the bundle stamp come from two different build pipelines with
 * two different formats, so "equal" is not a question with an answer.
 *
 * #2633 added ONE read-only line under the rows when HQ expects a newer
 * workstation build. Still no button: the project owner ruled that updating from
 * the POS is not on offer, because whoever applies an update has to be at the
 * machine that is about to restart. The line names the version and points at the
 * workstation; it is a note for the person who will walk over there.
 */
export function VersionCard() {
  const { t } = useTranslation();
  const { versions, mode, testConnection, status, updateHint } =
    useWorkstation();

  const checking = status === "checking";

  return (
    /* `gap-0 p-0` + explicit padding — `@godxjp/ui`'s own `px-card` / `pt-card`
       are built on `--spacing-card` from a theme.css pos-web does not import,
       so they generate nothing and the card renders flush against its border.
       Same shape as the two cards above; see the note on the first one. */
    <Card className="mt-6 gap-0 p-0" data-slot="version-info">
      <CardHeader className="border-b px-5 py-4">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0 space-y-1">
            <CardTitle className="text-[15px] font-semibold">
              {t("settings.version.title")}
            </CardTitle>
            <p className="max-w-prose text-[12px] leading-relaxed text-muted-foreground">
              {t("settings.version.description")}
            </p>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 shrink-0 gap-1.5 text-[12px]"
            onClick={() => void testConnection()}
            disabled={mode === "cloud"}
          >
            <RefreshCwIcon className="size-3.5" />
            {t("settings.version.refresh")}
          </Button>
        </div>
      </CardHeader>
      <CardContent className="divide-y p-0">
        <VersionRow
          label={t("settings.version.workstation")}
          hint={t("settings.version.workstation_desc")}
          value={versions.workstation}
          unknownLabel={
            checking
              ? t("settings.version.checking")
              : t("settings.version.unknown")
          }
        />
        <VersionRow
          label={t("settings.version.pos_bundle")}
          hint={t("settings.version.pos_bundle_desc")}
          value={versions.posBundle}
          unknownLabel={
            checking
              ? t("settings.version.checking")
              : t("settings.version.unknown")
          }
        />
      </CardContent>
      {updateHint.available && (
        /* Muted, not a warning colour, and carrying no control. Nothing here is
           wrong yet and there is nothing for the cashier to do about it during
           service — an alarm would only teach people to ignore this card. */
        <p
          className="flex items-start gap-2 border-t px-5 py-3 text-[12px] leading-relaxed text-muted-foreground"
          data-slot="workstation-update-hint"
        >
          <InfoIcon className="mt-px size-3.5 shrink-0" aria-hidden="true" />
          <span>
            {updateHint.expectedVersion
              ? t("settings.version.update_available", {
                  version: updateHint.expectedVersion,
                })
              : t("settings.version.update_available_unknown_version")}
          </span>
        </p>
      )}
      {mode === "cloud" && (
        <p className="border-t px-5 py-3 text-[12px] leading-relaxed text-muted-foreground">
          {t("settings.version.cloud_mode")}
        </p>
      )}
    </Card>
  );
}

function VersionRow({
  label,
  hint,
  value,
  unknownLabel,
}: {
  label: string;
  hint: string;
  /** null = unknown. Rendered as words, never as a remembered older number. */
  value: string | null;
  unknownLabel: string;
}) {
  return (
    <div className="flex items-center justify-between gap-4 px-5 py-4">
      <div className="min-w-0">
        <div className="text-[14px] font-medium text-foreground">{label}</div>
        <div className="mt-0.5 text-[12px] leading-snug text-muted-foreground">
          {hint}
        </div>
      </div>
      {value ? (
        <span className="shrink-0 font-mono text-[13px] tabular-nums text-foreground">
          {value}
        </span>
      ) : (
        <span className="shrink-0 text-[13px] italic text-muted-foreground">
          {unknownLabel}
        </span>
      )}
    </div>
  );
}
