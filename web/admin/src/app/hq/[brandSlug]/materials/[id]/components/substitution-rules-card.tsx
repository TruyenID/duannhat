"use client";

import { useMemo, useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";

import {
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
  Switch,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
  Textarea,
} from "@godxjp/ui";
import { DeleteConfirmDialog } from "@/components/shared/delete-confirm-dialog";
import {
  useCreateSubstitutionRule,
  useDeleteSubstitutionRule,
  useSubstitutionRules,
  useUpdateSubstitutionRule,
} from "@/hooks/api/use-substitution-rules";
import { useMaterialLookup } from "@/hooks/api/use-materials";
import { useTranslation } from "@/providers/app-provider";
import type { SubstitutionRule } from "@/services/substitution-rule-service";

// =========================================================================
//  Props
// =========================================================================

export interface SubstitutionRulesCardProps {
  brandSlug: string;
  materialId: string;
  "data-slot"?: string;
}

// =========================================================================
//  Form state
// =========================================================================

interface RuleFormState {
  substitute_material_id: string;
  conversion_factor: string;
  priority: string;
  is_active: boolean;
  notes: string;
}

const EMPTY_FORM: RuleFormState = {
  substitute_material_id: "",
  conversion_factor: "1.0000",
  priority: "0",
  is_active: true,
  notes: "",
};

// =========================================================================
//  Component
// =========================================================================

export function SubstitutionRulesCard({
  brandSlug,
  materialId,
  ...rest
}: SubstitutionRulesCardProps) {
  const { t } = useTranslation();

  // Data
  const { data, isLoading } = useSubstitutionRules(brandSlug, materialId);
  const rules = useMemo(() => data?.data ?? [], [data]);

  const materialLookup = useMaterialLookup(brandSlug, true);
  const allMaterials = useMemo(() => materialLookup.data?.data ?? [], [materialLookup.data]);

  // Build a map for looking up material names
  const materialById = useMemo(() => {
    const m = new Map<string, string>();
    for (const mat of allMaterials) m.set(mat.id, mat.name);
    return m;
  }, [allMaterials]);

  // Exclude the current material and already-used substitutes from the dropdown
  const availableMaterials = useMemo(() => {
    const usedIds = new Set(rules.map((r) => r.substitute_material_id));
    return allMaterials.filter((m) => m.id !== materialId && !usedIds.has(m.id));
  }, [allMaterials, materialId, rules]);

  // Mutations
  const createMutation = useCreateSubstitutionRule(brandSlug);
  const updateMutation = useUpdateSubstitutionRule(brandSlug);
  const deleteMutation = useDeleteSubstitutionRule(brandSlug);

  // Dialog state
  const [editing, setEditing] = useState<SubstitutionRule | null>(null);
  const [showDialog, setShowDialog] = useState(false);
  const [form, setForm] = useState<RuleFormState>(EMPTY_FORM);
  const [confirmDelete, setConfirmDelete] = useState<SubstitutionRule | null>(null);

  const openCreate = () => {
    setEditing(null);
    setForm(EMPTY_FORM);
    setShowDialog(true);
  };

  const openEdit = (rule: SubstitutionRule) => {
    setEditing(rule);
    setForm({
      substitute_material_id: rule.substitute_material_id,
      conversion_factor: String(rule.conversion_factor),
      priority: String(rule.priority),
      is_active: rule.is_active,
      notes: rule.notes ?? "",
    });
    setShowDialog(true);
  };

  const handleSubmit = () => {
    if (editing) {
      updateMutation.mutate(
        {
          id: editing.id,
          input: {
            substitute_material_id: form.substitute_material_id,
            conversion_factor: Number(form.conversion_factor),
            priority: Number(form.priority),
            is_active: form.is_active,
            notes: form.notes.trim() || null,
          },
        },
        { onSuccess: () => setShowDialog(false) }
      );
    } else {
      createMutation.mutate(
        {
          primary_material_id: materialId,
          substitute_material_id: form.substitute_material_id,
          conversion_factor: Number(form.conversion_factor),
          priority: Number(form.priority),
          is_active: form.is_active,
          notes: form.notes.trim() || null,
        },
        { onSuccess: () => setShowDialog(false) }
      );
    }
  };

  const isPending = createMutation.isPending || updateMutation.isPending;
  const canSubmit = form.substitute_material_id !== "" && Number(form.conversion_factor) > 0;

  // For edit dialog, include the currently-editing material in available list
  const dialogMaterials = useMemo(() => {
    if (!editing) return availableMaterials;
    // Allow the currently selected substitute to remain in the list
    const has = availableMaterials.some((m) => m.id === editing.substitute_material_id);
    if (has) return availableMaterials;
    const current = allMaterials.find((m) => m.id === editing.substitute_material_id);
    return current ? [current, ...availableMaterials] : availableMaterials;
  }, [availableMaterials, editing, allMaterials]);

  return (
    <Card data-slot="substitution-rules-card" {...rest}>
      <CardHeader className="flex flex-row items-center justify-between">
        <div>
          <CardTitle className="text-sm">{t("material.substitution_rules")}</CardTitle>
          <p className="text-xs text-muted-foreground">
            {t("material.substitution_rules_description")}
          </p>
        </div>
        <Button variant="outline" size="sm" className="h-8 gap-1.5" onClick={openCreate}>
          <Plus className="size-3.5" />
          {t("material.substitution_add")}
        </Button>
      </CardHeader>

      <CardContent>
        {isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner className="size-5 text-muted-foreground" />
          </div>
        ) : rules.length === 0 ? (
          <div className="rounded-md border border-dashed py-8 text-center">
            <p className="text-xs text-muted-foreground">{t("material.substitution_empty")}</p>
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>{t("material.substitution_substitute")}</TableHead>
                <TableHead className="text-right">
                  {t("material.substitution_conversion")}
                </TableHead>
                <TableHead className="text-right">{t("material.substitution_priority")}</TableHead>
                <TableHead>{t("material.substitution_active")}</TableHead>
                <TableHead className="w-24 text-right">{t("common.actions")}</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {rules.map((rule) => {
                const subName =
                  rule.substitute_material?.name ??
                  materialById.get(rule.substitute_material_id) ??
                  rule.substitute_material_id;
                return (
                  <TableRow key={rule.id}>
                    <TableCell className="font-medium">{subName}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {Number(rule.conversion_factor).toFixed(4)}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{rule.priority}</TableCell>
                    <TableCell>
                      {rule.is_active ? (
                        <Badge variant="soft" color="success">
                          {t("material.substitution_active")}
                        </Badge>
                      ) : (
                        <Badge variant="outline">{t("common.inactive")}</Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-right">
                      <Button variant="ghost" size="sm" onClick={() => openEdit(rule)}>
                        <Pencil className="size-3.5" />
                      </Button>
                      <Button variant="ghost" size="sm" onClick={() => setConfirmDelete(rule)}>
                        <Trash2 className="size-3.5" />
                      </Button>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        )}
      </CardContent>

      {/* Create / Edit dialog */}
      <Dialog open={showDialog} onOpenChange={setShowDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>
              {editing ? t("material.substitution_edit") : t("material.substitution_add")}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-3">
            {/* Substitute material select */}
            <div className="space-y-1">
              <Label htmlFor="substitute_material">{t("material.substitution_substitute")}</Label>
              <Select
                value={form.substitute_material_id || undefined}
                onValueChange={(v) => setForm((prev) => ({ ...prev, substitute_material_id: v }))}
              >
                <SelectTrigger className="h-9 w-full text-xs">
                  <SelectValue placeholder={t("material.substitution_substitute")} />
                </SelectTrigger>
                <SelectContent>
                  {dialogMaterials.map((m) => (
                    <SelectItem key={m.id} value={m.id}>
                      {m.name}
                      {m.sku ? ` (${m.sku})` : ""}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* Conversion factor */}
            <div className="space-y-1">
              <Label htmlFor="conversion_factor">{t("material.substitution_conversion")}</Label>
              <Input
                id="conversion_factor"
                type="number"
                min="0.0001"
                step="0.0001"
                value={form.conversion_factor}
                onChange={(e) =>
                  setForm((prev) => ({ ...prev, conversion_factor: e.target.value }))
                }
              />
              <p className="text-xs text-muted-foreground">
                {t("material.substitution_conversion_help")}
              </p>
            </div>

            {/* Priority */}
            <div className="space-y-1">
              <Label htmlFor="priority">{t("material.substitution_priority")}</Label>
              <Input
                id="priority"
                type="number"
                min="0"
                step="1"
                value={form.priority}
                onChange={(e) => setForm((prev) => ({ ...prev, priority: e.target.value }))}
              />
            </div>

            {/* Active */}
            <div className="flex items-center gap-2">
              <Switch
                id="is_active"
                checked={form.is_active}
                onCheckedChange={(checked) => setForm((prev) => ({ ...prev, is_active: checked }))}
              />
              <Label htmlFor="is_active">{t("material.substitution_active")}</Label>
            </div>

            {/* Notes */}
            <div className="space-y-1">
              <Label htmlFor="notes">{t("material.substitution_notes")}</Label>
              <Textarea
                id="notes"
                value={form.notes}
                onChange={(e) => setForm((prev) => ({ ...prev, notes: e.target.value }))}
                rows={2}
                maxLength={1000}
                className="field-sizing-fixed"
              />
              <p className="text-right text-xs text-muted-foreground">
                {(form.notes ?? "").length}/1000
              </p>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setShowDialog(false)}>
              {t("common.cancel")}
            </Button>
            <Button onClick={handleSubmit} disabled={!canSubmit || isPending}>
              {isPending ? <Spinner className="mr-2 size-3.5" /> : null}
              {t("common.save")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete confirmation */}
      <DeleteConfirmDialog
        open={!!confirmDelete}
        onOpenChange={(open) => !open && setConfirmDelete(null)}
        description={t("material.substitution_delete_confirm")}
        onConfirm={async () => {
          if (confirmDelete) {
            await deleteMutation.mutateAsync(confirmDelete.id);
            setConfirmDelete(null);
          }
        }}
        isPending={deleteMutation.isPending}
      />
    </Card>
  );
}
