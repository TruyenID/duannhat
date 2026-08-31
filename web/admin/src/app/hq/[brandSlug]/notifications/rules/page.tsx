"use client";

/**
 * Plan-023 M7 T7.7 — HQ Notification Rules page.
 *
 * v1 surface: list + create/edit Sheet + dry-run drawer + delete
 * confirm. Condition tree authored as JSON in a textarea — the
 * visual DnD tree builder defers to a follow-up FE polish session.
 * That choice makes the page ship-able now while preserving the
 * full backend contract (RuleDslValidator catches malformed JSON
 * at save time with a per-leaf error list).
 */

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  Skeleton,
  Spinner,
  Switch,
  Textarea,
} from "@godxjp/ui";
import { AlertCircle, FlaskConical, Pencil, Play, Plus, Trash2 } from "lucide-react";
import { useParams } from "next/navigation";
import { useMemo, useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import {
  useCreateRule,
  useDeleteRule,
  useDryRunRule,
  useRules,
  useUpdateRule,
} from "@/hooks/api/use-notification-rules";
import { useTranslation } from "@/providers/app-provider";
import type { RulePayload, RuleRow } from "@/services/notification-rule-service";

const TRIGGER_OPTIONS = ["model.created", "model.updated", "model.deleted"] as const;

interface EditorState {
  mode: "create" | "edit";
  id: string | null;
  name: string;
  description: string;
  trigger_event: string;
  trigger_model_type: string;
  conditions_json: string;
  action_json: string;
  cooldown_minutes: number;
  is_active: boolean;
}

function emptyEditor(): EditorState {
  return {
    mode: "create",
    id: null,
    name: "",
    description: "",
    trigger_event: "model.updated",
    trigger_model_type: "",
    conditions_json: JSON.stringify({ combinator: "and", children: [] }, null, 2),
    action_json: JSON.stringify(
      {
        template_key: "stock.alert.low",
        channels: ["in_app"],
        priority: "normal",
      },
      null,
      2
    ),
    cooldown_minutes: 0,
    is_active: false,
  };
}

export default function RulesPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  const [filterActive, setFilterActive] = useState<"all" | "active" | "inactive">("all");
  const [editor, setEditor] = useState<EditorState | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<RuleRow | null>(null);
  const [dryRunFor, setDryRunFor] = useState<RuleRow | null>(null);
  const [saveError, setSaveError] = useState<string | null>(null);

  const filters = useMemo(
    () => (filterActive === "all" ? {} : { is_active: filterActive === "active" }),
    [filterActive]
  );

  const { data, isLoading, isError, refetch } = useRules(brandSlug, filters);
  const createMutation = useCreateRule(brandSlug);
  const updateMutation = useUpdateRule(brandSlug);
  const deleteMutation = useDeleteRule(brandSlug);
  const dryRunMutation = useDryRunRule(brandSlug);

  const rows = data?.data ?? [];

  function openCreate() {
    setEditor(emptyEditor());
    setSaveError(null);
  }

  function openEdit(row: RuleRow) {
    setEditor({
      mode: "edit",
      id: row.id,
      name: row.name,
      description: row.description ?? "",
      trigger_event: row.trigger_event,
      trigger_model_type: row.trigger_model_type ?? "",
      conditions_json: JSON.stringify(row.conditions, null, 2),
      action_json: JSON.stringify(row.action, null, 2),
      cooldown_minutes: row.cooldown_minutes,
      is_active: row.is_active,
    });
    setSaveError(null);
  }

  async function onSave() {
    if (!editor) return;
    setSaveError(null);
    let conditions: unknown;
    let action: unknown;
    try {
      conditions = JSON.parse(editor.conditions_json);
      action = JSON.parse(editor.action_json);
    } catch (e) {
      setSaveError(`Invalid JSON: ${(e as Error).message}`);
      return;
    }

    const payload: RulePayload = {
      name: editor.name,
      description: editor.description || null,
      trigger_event: editor.trigger_event,
      trigger_model_type: editor.trigger_model_type || null,
      conditions: conditions as RulePayload["conditions"],
      action: action as RulePayload["action"],
      cooldown_minutes: editor.cooldown_minutes,
      is_active: editor.is_active,
    };

    try {
      if (editor.mode === "create") {
        await createMutation.mutateAsync(payload);
      } else if (editor.id) {
        await updateMutation.mutateAsync({ id: editor.id, payload });
      }
      setEditor(null);
    } catch (e) {
      setSaveError((e as Error).message ?? t("common.error_loading"));
    }
  }

  async function onDryRun(row: RuleRow) {
    setDryRunFor(row);
    await dryRunMutation.mutateAsync({ id: row.id });
  }

  async function onConfirmDelete() {
    if (!confirmDelete) return;
    await deleteMutation.mutateAsync(confirmDelete.id);
    setConfirmDelete(null);
  }

  return (
    <>
      <PageHeader
        title={t("notifications.rules.title")}
        description={t("notifications.rules.subtitle")}
      >
        <Button size="sm" onClick={openCreate}>
          <Plus className="mr-1 size-3.5" />
          {t("notifications.rules.create")}
        </Button>
      </PageHeader>

      <PageContent>
        <div className="mb-4 flex items-center gap-2">
          {(["all", "active", "inactive"] as const).map((f) => (
            <Button
              key={f}
              size="sm"
              variant={filterActive === f ? "default" : "outline"}
              onClick={() => setFilterActive(f)}
            >
              {t(`notifications.rules.filter_${f}`)}
            </Button>
          ))}
        </div>

        {isError ? (
          <Alert variant="destructive" className="mb-4">
            <AlertCircle className="size-4" />
            <AlertDescription className="flex items-center justify-between">
              {t("common.error_loading")}
              <Button variant="outline" size="sm" onClick={() => refetch()}>
                {t("common.retry")}
              </Button>
            </AlertDescription>
          </Alert>
        ) : null}

        {isLoading ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-20 w-full" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <Card>
            <CardContent className="p-8 text-center text-sm text-muted-foreground">
              <FlaskConical className="mx-auto mb-3 size-8 text-muted-foreground/60" />
              {t("notifications.rules.empty")}
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-2">
            {rows.map((row) => (
              <RuleCard
                key={row.id}
                row={row}
                onEdit={openEdit}
                onDryRun={onDryRun}
                onDelete={setConfirmDelete}
              />
            ))}
          </div>
        )}
      </PageContent>

      {/* Editor Sheet */}
      <Sheet open={editor !== null} onOpenChange={(o) => !o && setEditor(null)}>
        <SheetContent className="w-full sm:max-w-2xl" data-slot="rule-editor">
          <SheetHeader>
            <SheetTitle>
              {editor?.mode === "create"
                ? t("notifications.rules.create_title")
                : t("notifications.rules.edit_title")}
            </SheetTitle>
            <SheetDescription>{t("notifications.rules.editor_subtitle")}</SheetDescription>
          </SheetHeader>

          {editor ? <RuleEditorForm editor={editor} setEditor={setEditor} /> : null}

          {saveError ? (
            <Alert variant="destructive" className="mt-4">
              <AlertCircle className="size-4" />
              <AlertDescription>{saveError}</AlertDescription>
            </Alert>
          ) : null}

          <div className="mt-4 flex items-center justify-end gap-2">
            <Button variant="outline" onClick={() => setEditor(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              onClick={onSave}
              disabled={createMutation.isPending || updateMutation.isPending}
            >
              {createMutation.isPending || updateMutation.isPending ? (
                <Spinner className="mr-2 size-3.5" />
              ) : null}
              {t("common.save")}
            </Button>
          </div>
        </SheetContent>
      </Sheet>

      {/* Dry-run Sheet */}
      <Sheet open={dryRunFor !== null} onOpenChange={(o) => !o && setDryRunFor(null)}>
        <SheetContent className="w-full sm:max-w-xl" data-slot="rule-dry-run">
          <SheetHeader>
            <SheetTitle>{t("notifications.rules.dry_run.title")}</SheetTitle>
            <SheetDescription>{t("notifications.rules.dry_run.subtitle")}</SheetDescription>
          </SheetHeader>
          {dryRunMutation.isPending ? (
            <div className="mt-4 flex items-center gap-2 text-sm text-muted-foreground">
              <Spinner className="size-3.5" /> {t("common.loading")}
            </div>
          ) : dryRunMutation.data ? (
            <DryRunResult data={dryRunMutation.data.data} />
          ) : null}
        </SheetContent>
      </Sheet>

      {/* Delete confirm */}
      <Dialog open={confirmDelete !== null} onOpenChange={(o) => !o && setConfirmDelete(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("notifications.rules.delete.title")}</DialogTitle>
            <DialogDescription>
              {t("notifications.rules.delete.body", { name: confirmDelete?.name ?? "" })}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setConfirmDelete(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              onClick={onConfirmDelete}
              disabled={deleteMutation.isPending}
            >
              {deleteMutation.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
              {t("common.delete")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

function RuleCard({
  row,
  onEdit,
  onDryRun,
  onDelete,
}: {
  row: RuleRow;
  onEdit: (r: RuleRow) => void;
  onDryRun: (r: RuleRow) => void;
  onDelete: (r: RuleRow) => void;
}) {
  const { t } = useTranslation();
  return (
    <Card data-slot="rule-card">
      <CardContent className="flex items-center gap-3 p-4">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <p className="truncate font-medium">{row.name}</p>
            <Badge variant={row.is_active ? "default" : "secondary"}>
              {row.is_active
                ? t("notifications.rules.status.active")
                : t("notifications.rules.status.inactive")}
            </Badge>
          </div>
          <p className="text-xs text-muted-foreground">
            {row.trigger_event}
            {row.trigger_model_type ? ` · ${row.trigger_model_type}` : ""} ·
            {t("notifications.rules.fire_count", { count: row.fire_count })}
          </p>
        </div>
        <Button variant="ghost" size="icon" onClick={() => onDryRun(row)}>
          <Play className="size-4" />
        </Button>
        <Button variant="ghost" size="icon" onClick={() => onEdit(row)}>
          <Pencil className="size-4" />
        </Button>
        <Button variant="ghost" size="icon" onClick={() => onDelete(row)}>
          <Trash2 className="size-4" />
        </Button>
      </CardContent>
    </Card>
  );
}

function RuleEditorForm({
  editor,
  setEditor,
}: {
  editor: EditorState;
  setEditor: (e: EditorState) => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="mt-4 space-y-4">
      <div>
        <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
          {t("notifications.rules.field.name")}
        </p>
        <Input
          value={editor.name}
          onChange={(e) => setEditor({ ...editor, name: e.target.value })}
        />
      </div>

      <div>
        <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
          {t("notifications.rules.field.description")}
        </p>
        <Textarea
          rows={2}
          value={editor.description}
          onChange={(e) => setEditor({ ...editor, description: e.target.value })}
          maxLength={1000}
          className="field-sizing-fixed"
        />
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {t("notifications.rules.field.trigger_event")}
          </p>
          <div className="flex flex-wrap gap-1">
            {TRIGGER_OPTIONS.map((opt) => (
              <Button
                key={opt}
                size="sm"
                variant={editor.trigger_event === opt ? "default" : "outline"}
                onClick={() => setEditor({ ...editor, trigger_event: opt })}
              >
                {opt}
              </Button>
            ))}
          </div>
        </div>
        <div>
          <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {t("notifications.rules.field.trigger_model_type")}
          </p>
          <Input
            value={editor.trigger_model_type}
            placeholder="Recipe"
            onChange={(e) => setEditor({ ...editor, trigger_model_type: e.target.value })}
          />
        </div>
      </div>

      <div>
        <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
          {t("notifications.rules.field.conditions_json")}
        </p>
        <Textarea
          rows={8}
          className="font-mono text-xs"
          value={editor.conditions_json}
          onChange={(e) => setEditor({ ...editor, conditions_json: e.target.value })}
        />
        <p className="mt-1 text-[10px] text-muted-foreground">
          {t("notifications.rules.field.conditions_json_hint")}
        </p>
      </div>

      <div>
        <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
          {t("notifications.rules.field.action_json")}
        </p>
        <Textarea
          rows={6}
          className="font-mono text-xs"
          value={editor.action_json}
          onChange={(e) => setEditor({ ...editor, action_json: e.target.value })}
        />
      </div>

      <div className="grid grid-cols-2 gap-3">
        <div>
          <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {t("notifications.rules.field.cooldown")}
          </p>
          <Input
            type="number"
            min={0}
            value={String(editor.cooldown_minutes)}
            onChange={(e) =>
              setEditor({ ...editor, cooldown_minutes: Math.max(0, Number(e.target.value) || 0) })
            }
          />
        </div>
        <div>
          <p className="mb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {t("notifications.rules.field.is_active")}
          </p>
          <div className="flex items-center gap-2">
            <Switch
              checked={editor.is_active}
              onCheckedChange={(c) => setEditor({ ...editor, is_active: c })}
            />
            <span className="text-xs text-muted-foreground">
              {editor.is_active
                ? t("notifications.rules.status.active")
                : t("notifications.rules.status.inactive")}
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}

function DryRunResult({
  data,
}: {
  data: {
    considered: number;
    matched_count: number;
    sample: Array<{ id: string | number; updated_at: string | null; trace: Array<unknown> }>;
    window_since: string;
  };
}) {
  const { t } = useTranslation();
  return (
    <div className="mt-4 space-y-3 text-sm">
      <p>
        {t("notifications.rules.dry_run.summary", {
          considered: data.considered,
          matched: data.matched_count,
        })}
      </p>
      <p className="text-xs text-muted-foreground">
        {t("notifications.rules.dry_run.window", { since: data.window_since })}
      </p>
      {data.sample.length === 0 ? (
        <p className="text-xs text-muted-foreground">
          {t("notifications.rules.dry_run.no_matches")}
        </p>
      ) : (
        <ul className="space-y-2">
          {data.sample.map((s) => (
            <li key={String(s.id)} className="rounded-md border p-2 font-mono text-xs">
              <p>id: {String(s.id)}</p>
              <p className="text-muted-foreground">{s.updated_at ?? "-"}</p>
              <pre className="mt-1 max-h-32 overflow-auto text-[10px]">
                {JSON.stringify(s.trace, null, 2)}
              </pre>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
