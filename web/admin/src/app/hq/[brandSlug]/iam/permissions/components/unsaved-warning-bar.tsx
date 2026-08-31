"use client";

import { Button } from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";

export interface UnsavedWarningBarProps {
  isDirty: boolean;
  isSaving: boolean;
  onSave: () => void;
  onDiscard: () => void;
}

export function UnsavedWarningBar({
  isDirty,
  isSaving,
  onSave,
  onDiscard,
}: UnsavedWarningBarProps) {
  const { t } = useTranslation();

  if (!isDirty) return null;

  return (
    <div
      data-slot="unsaved-warning-bar"
      // left-(--sidebar-width) offsets the bar from the sidebar so it only
      // spans the content area, not the full viewport width.
      className="fixed right-0 bottom-0 left-(--sidebar-width) z-50 flex items-center justify-between border-t bg-background px-6 py-3 shadow-lg"
    >
      <p className="text-sm font-medium text-muted-foreground">{t("iam.permissions.unsaved")}</p>
      <div className="flex items-center gap-2">
        <Button
          variant="outline"
          size="sm"
          className="h-8 text-xs"
          onClick={onDiscard}
          disabled={isSaving}
        >
          {t("iam.permissions.discard")}
        </Button>
        <Button size="sm" className="h-8 text-xs" onClick={onSave} disabled={isSaving}>
          {isSaving ? <Spinner className="mr-1 size-3.5" /> : null}
          {t("iam.permissions.save")}
        </Button>
      </div>
    </div>
  );
}
