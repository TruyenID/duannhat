"use client";

import { useParams, useRouter } from "next/navigation";
import { useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import {
  useCreateMaterialUnit,
  useDeleteMaterialUnit,
  useMaterialUnits,
  useUpdateMaterialUnit,
} from "@/hooks/api/use-material-units";
import { useTranslation } from "@/providers/app-provider";
import type { MaterialUnit } from "@/services/material-unit-service";
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
  Spinner,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";

interface UnitFormState {
  unit: string;
  ratio: string;
  is_base: boolean;
}

const EMPTY: UnitFormState = { unit: "", ratio: "1", is_base: false };

// Ratio is stored as decimal(10,4) — at most 4 decimal places (TC-UNIT-104).
const MAX_RATIO_DECIMALS = 4;

function ratioDecimalCount(value: string): number {
  const dot = value.trim().indexOf(".");
  return dot === -1 ? 0 : value.trim().length - dot - 1;
}

export default function HqMaterialUnitsPage() {
  const { t } = useTranslation();
  const params = useParams<{ brandSlug: string; id: string }>();
  const router = useRouter();

  const { data, isLoading } = useMaterialUnits(params.brandSlug, params.id);
  const create = useCreateMaterialUnit(params.brandSlug, params.id);
  const update = useUpdateMaterialUnit(params.brandSlug, params.id);
  const remove = useDeleteMaterialUnit(params.brandSlug, params.id);

  const [editing, setEditing] = useState<MaterialUnit | null>(null);
  const [showDialog, setShowDialog] = useState(false);
  const [form, setForm] = useState<UnitFormState>(EMPTY);
  // Snapshot of the form when the dialog opened — baseline for the
  // unsaved-changes guard (TC-UNIT-110).
  const [initialForm, setInitialForm] = useState<UnitFormState>(EMPTY);
  const [confirmExit, setConfirmExit] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState<MaterialUnit | null>(null);

  const openCreate = () => {
    setEditing(null);
    setForm(EMPTY);
    setInitialForm(EMPTY);
    setShowDialog(true);
  };

  const openEdit = (unit: MaterialUnit) => {
    setEditing(unit);
    const next: UnitFormState = {
      unit: unit.unit,
      ratio: String(unit.ratio),
      is_base: !!unit.is_base,
    };
    setForm(next);
    setInitialForm(next);
    setShowDialog(true);
  };

  const isDirty = JSON.stringify(form) !== JSON.stringify(initialForm);

  // Guard dialog close (Cancel, Escape, click-outside): confirm before
  // discarding a dirty form, otherwise close straight away (TC-UNIT-110).
  const requestClose = () => {
    if (isDirty) {
      setConfirmExit(true);
    } else {
      setShowDialog(false);
    }
  };

  const handleSubmit = () => {
    const payload = {
      unit: form.unit.trim(),
      ratio: Number(form.ratio),
      is_base: form.is_base,
    };
    if (editing) {
      update.mutate({ id: editing.id, input: payload }, { onSuccess: () => setShowDialog(false) });
    } else {
      create.mutate(payload, { onSuccess: () => setShowDialog(false) });
    }
  };

  const isPending = create.isPending || update.isPending;
  const ratioTooPrecise =
    form.ratio.trim() !== "" && ratioDecimalCount(form.ratio) > MAX_RATIO_DECIMALS;
  const canSubmit = form.unit.trim() !== "" && Number(form.ratio) > 0 && !ratioTooPrecise;

  return (
    <>
      <PageHeader
        title={t("material_unit.page_title")}
        description={t("material_unit.page_subtitle")}
      >
        <div className="flex gap-2">
          <Button variant="outline" onClick={() => router.back()}>
            {t("common.back")}
          </Button>
          <Button onClick={openCreate}>
            <Plus className="mr-2 size-4" />
            {t("material_unit.add_button")}
          </Button>
        </div>
      </PageHeader>

      <PageContent>
        <Card>
          <CardHeader>
            <CardTitle>{t("material_unit.list_title")}</CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Spinner className="mx-auto" />
            ) : (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>{t("material_unit.col_unit")}</TableHead>
                    <TableHead className="text-right">{t("material_unit.col_ratio")}</TableHead>
                    <TableHead>{t("material_unit.col_is_base")}</TableHead>
                    <TableHead className="w-32 text-right">{t("common.actions")}</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(data?.data ?? []).map((u) => (
                    <TableRow key={u.id}>
                      <TableCell className="font-medium">{u.unit}</TableCell>
                      <TableCell className="text-right tabular-nums">
                        {Number(u.ratio).toLocaleString()}
                      </TableCell>
                      <TableCell>
                        {u.is_base ? <Badge>{t("material_unit.base_badge")}</Badge> : "—"}
                      </TableCell>
                      <TableCell className="text-right">
                        <Button variant="ghost" size="sm" onClick={() => openEdit(u)}>
                          <Pencil className="size-4" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setConfirmDelete(u)}
                          disabled={u.is_base}
                        >
                          <Trash2 className="size-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                  {(data?.data ?? []).length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={4} className="text-center text-muted-foreground">
                        {t("material_unit.empty")}
                      </TableCell>
                    </TableRow>
                  ) : null}
                </TableBody>
              </Table>
            )}
          </CardContent>
        </Card>
      </PageContent>

      <Dialog
        open={showDialog}
        onOpenChange={(open) => (open ? setShowDialog(true) : requestClose())}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editing ? t("material_unit.edit_title") : t("material_unit.add_title")}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            <div className="space-y-1">
              <Label htmlFor="unit">{t("material_unit.col_unit")}</Label>
              <Input
                id="unit"
                value={form.unit}
                onChange={(e) => setForm((prev) => ({ ...prev, unit: e.target.value }))}
                placeholder="kg"
              />
            </div>
            <div className="space-y-1">
              <Label htmlFor="ratio">{t("material_unit.col_ratio")}</Label>
              <Input
                id="ratio"
                type="number"
                step="0.0001"
                value={form.ratio}
                aria-invalid={ratioTooPrecise || undefined}
                onChange={(e) => setForm((prev) => ({ ...prev, ratio: e.target.value }))}
              />
              {ratioTooPrecise ? (
                <p className="text-xs text-destructive">
                  {t("material_unit.ratio_precision_error")}
                </p>
              ) : (
                <p className="text-xs text-muted-foreground">{t("material_unit.ratio_help")}</p>
              )}
            </div>
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <Switch
                  id="is_base"
                  checked={form.is_base}
                  onCheckedChange={(checked) => setForm((prev) => ({ ...prev, is_base: checked }))}
                />
                <Label htmlFor="is_base">{t("material_unit.col_is_base")}</Label>
              </div>
              {form.is_base ? (
                <p className="text-xs text-muted-foreground">
                  {t("material_unit.base_recalc_hint")}
                </p>
              ) : null}
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={requestClose}>
              {t("common.cancel")}
            </Button>
            <Button onClick={handleSubmit} disabled={!canSubmit || isPending}>
              {isPending ? <Spinner className="mr-2" /> : null}
              {t("common.save")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Unsaved-changes guard — confirm before discarding edits (TC-UNIT-110). */}
      <AlertDialog open={confirmExit} onOpenChange={setConfirmExit}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("hq.products.unsaved.title")}</AlertDialogTitle>
            <AlertDialogDescription>{t("hq.products.unsaved.desc")}</AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("hq.products.unsaved.continue_editing")}</AlertDialogCancel>
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                setConfirmExit(false);
                setShowDialog(false);
              }}
            >
              {t("hq.products.unsaved.exit_without_saving")}
            </Button>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      <DeleteConfirmDialog
        open={!!confirmDelete}
        onOpenChange={(open) => !open && setConfirmDelete(null)}
        description={t("material_unit.delete_confirm", {
          unit: confirmDelete?.unit ?? "",
        })}
        onConfirm={async () => {
          if (confirmDelete) {
            await remove.mutateAsync(confirmDelete.id);
            setConfirmDelete(null);
          }
        }}
        isPending={remove.isPending}
      />
    </>
  );
}
