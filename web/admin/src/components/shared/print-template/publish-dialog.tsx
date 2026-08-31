"use client";

/**
 * Publish dialog — plan-053 M4 (#1171), DESIGN §4.
 *
 * `effective_from` is a BRANCH-LOCAL wall clock, not an instant (#1091): HQ
 * schedules "2026-08-01 00:00" and a Tokyo branch flips two hours before a
 * Hanoi one. The field is therefore a plain `datetime-local`-shaped string and
 * the copy says which clock it means — a timezone picker here would be a lie.
 */

import { useState } from "react";
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Spinner,
  Textarea,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";

export interface PublishDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  isPending: boolean;
  onConfirm: (input: { effective_from: string | null; notes: string | null }) => void;
}

export function PublishDialog({ open, onOpenChange, isPending, onConfirm }: PublishDialogProps) {
  const { t } = useTranslation();
  const [effectiveFrom, setEffectiveFrom] = useState("");
  const [notes, setNotes] = useState("");

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t("print_templates.publish.title")}</DialogTitle>
          <DialogDescription>{t("print_templates.publish.description")}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-3">
          <div className="flex flex-col gap-1">
            <Label className="text-xs">{t("print_templates.publish.notes")}</Label>
            <Textarea
              rows={3}
              className="text-xs"
              data-testid="publish-notes"
              placeholder={t("print_templates.publish.notes_placeholder")}
              value={notes}
              onChange={(event) => setNotes(event.target.value)}
            />
          </div>

          <div className="flex flex-col gap-1">
            <Label className="text-xs">{t("print_templates.publish.effective_from")}</Label>
            <Input
              type="datetime-local"
              className="h-8 text-xs"
              data-testid="publish-effective-from"
              value={effectiveFrom}
              onChange={(event) => setEffectiveFrom(event.target.value)}
            />
            <p className="text-[11px] text-muted-foreground">
              {t("print_templates.publish.effective_from_hint")}
            </p>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" size="sm" onClick={() => onOpenChange(false)}>
            {t("common.cancel")}
          </Button>
          <Button
            size="sm"
            disabled={isPending}
            data-testid="publish-confirm"
            onClick={() =>
              onConfirm({
                // `datetime-local` gives 'YYYY-MM-DDTHH:mm'; the backend wants
                // a wall clock string, so only the separator changes.
                effective_from: effectiveFrom ? effectiveFrom.replace("T", " ") : null,
                notes: notes.trim() || null,
              })
            }
          >
            {isPending && <Spinner className="mr-2 size-3.5" />}
            {t("print_templates.action.publish")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
