"use client";

/**
 * HQ Notification Audiences page (plan-012 T1.5).
 *
 * Lists saved audiences + opens a Sheet editor with the visual rule
 * builder. Live-preview count on the editor so admins see the resolved
 * recipient count as they edit.
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
  Textarea,
} from "@godxjp/ui";
import {
  AlertCircle,
  Building2,
  Eye,
  Filter,
  Info,
  MonitorSmartphone,
  Pencil,
  Plus,
  Send,
  ShieldCheck,
  Store,
  Tag,
  Trash2,
  UserCircle2,
  Users,
  UsersRound,
} from "lucide-react";
import { useParams } from "next/navigation";
import { useMemo, useState } from "react";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useDebounce } from "@/hooks/use-debounce";
import {
  useAudiencePreview,
  useAudiences,
  useCreateAudience,
  useDeleteAudience,
  useUpdateAudience,
} from "@/hooks/api/use-audiences";
import { ApiError } from "@/lib/api";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type { AudienceRow, AudienceRule } from "@/services/notification-audience-service";

import { RuleBuilder } from "./components/rule-builder";

const DEFAULT_RULE: AudienceRule = { version: 1, combinator: "or", rules: [] };

interface EditorState {
  mode: "create" | "edit";
  id: string | null;
  name: string;
  description: string;
  rule: AudienceRule;
}

function emptyEditor(): EditorState {
  return { mode: "create", id: null, name: "", description: "", rule: DEFAULT_RULE };
}

export default function AudiencesPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  const { data, isLoading, isError, refetch } = useAudiences(brandSlug);

  const [editor, setEditor] = useState<EditorState | null>(null);
  const [viewing, setViewing] = useState<AudienceRow | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<AudienceRow | null>(null);
  const [deleteError, setDeleteError] = useState<{
    code: "in_use" | "generic";
    count?: number;
  } | null>(null);

  const createMutation = useCreateAudience(brandSlug);
  const updateMutation = useUpdateAudience(brandSlug, editor?.id ?? "");
  const deleteMutation = useDeleteAudience(brandSlug);

  function openCreate() {
    setEditor(emptyEditor());
  }

  function openEdit(row: AudienceRow) {
    setEditor({
      mode: "edit",
      id: row.id,
      name: row.name,
      description: row.description ?? "",
      rule: row.rule ?? DEFAULT_RULE,
    });
  }

  function closeEditor() {
    setEditor(null);
  }

  async function save() {
    if (!editor) return;
    const payload = {
      name: editor.name,
      description: editor.description || null,
      rule: editor.rule,
    };
    if (editor.mode === "create") {
      await createMutation.mutateAsync(payload);
    } else {
      await updateMutation.mutateAsync(payload);
    }
    closeEditor();
  }

  async function doDelete() {
    if (!confirmDelete) return;
    setDeleteError(null);
    try {
      await deleteMutation.mutateAsync(confirmDelete.id);
      setConfirmDelete(null);
    } catch (e) {
      if (e instanceof ApiError && e.body?.error === "audience_in_use") {
        // Backend returns a 422 with the count of referencing schedules.
        // Surface it inline in the confirm dialog so the admin can navigate
        // to cancel them before retrying.
        const match = /referenced by (\d+)/.exec(String(e.body?.message ?? ""));
        const count = match ? Number(match[1]) : undefined;
        setDeleteError({ code: "in_use", count });
      } else {
        setDeleteError({ code: "generic" });
      }
    }
  }

  const rows = data?.data ?? [];
  const systemCount = rows.filter((r) => r.is_system).length;

  return (
    <>
      <PageHeader
        title={t("notifications.audiences.title")}
        description={t("notifications.audiences.subtitle")}
      >
        <Button onClick={openCreate} size="sm">
          <Plus className="mr-1 size-3.5" />
          {t("notifications.audiences.create")}
        </Button>
      </PageHeader>

      <PageContent>
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

        {/* Summary strip */}
        {!isLoading && rows.length > 0 ? (
          <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatTile
              icon={<UsersRound className="size-4" />}
              label={t("notifications.audiences.stat_total")}
              value={rows.length}
              tone="primary"
            />
            <StatTile
              icon={<ShieldCheck className="size-4" />}
              label={t("notifications.audiences.stat_system")}
              value={systemCount}
              tone="neutral"
            />
            <StatTile
              icon={<Pencil className="size-4" />}
              label={t("notifications.audiences.stat_custom")}
              value={rows.length - systemCount}
              tone="info"
            />
          </div>
        ) : null}

        {isLoading ? (
          <AudienceListSkeleton />
        ) : rows.length === 0 ? (
          <EmptyState onCreate={openCreate} />
        ) : (
          <AudienceList
            rows={rows}
            onView={setViewing}
            onEdit={openEdit}
            onDelete={setConfirmDelete}
          />
        )}

        {editor && (
          <AudienceEditor
            brandSlug={brandSlug}
            editor={editor}
            setEditor={setEditor}
            onCancel={closeEditor}
            onSave={save}
            isSaving={createMutation.isPending || updateMutation.isPending}
          />
        )}

        {viewing ? (
          <AudienceDetailSheet
            brandSlug={brandSlug}
            row={viewing}
            onClose={() => setViewing(null)}
            onEdit={() => {
              openEdit(viewing);
              setViewing(null);
            }}
          />
        ) : null}

        <Dialog
          open={confirmDelete !== null}
          onOpenChange={(v) => {
            if (!v) {
              setConfirmDelete(null);
              setDeleteError(null);
            }
          }}
        >
          <DialogContent>
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <Trash2 className="size-4 text-destructive" />
                {t("notifications.audiences.delete_title")}
              </DialogTitle>
              <DialogDescription>
                {t("notifications.audiences.delete_description", {
                  name: confirmDelete?.name ?? "",
                })}
              </DialogDescription>
            </DialogHeader>
            {deleteError ? (
              <Alert variant="destructive">
                <AlertCircle className="size-4" />
                <AlertDescription>
                  {deleteError.code === "in_use"
                    ? t("notifications.audiences.delete_in_use", {
                        count: deleteError.count ?? 0,
                      })
                    : t("common.error_loading")}
                </AlertDescription>
              </Alert>
            ) : null}
            <DialogFooter>
              <Button
                variant="outline"
                onClick={() => {
                  setConfirmDelete(null);
                  setDeleteError(null);
                }}
              >
                {t("common.cancel")}
              </Button>
              <Button
                variant="destructive"
                onClick={doDelete}
                disabled={deleteMutation.isPending || deleteError?.code === "in_use"}
              >
                {deleteMutation.isPending ? <Spinner className="mr-2 size-3.5" /> : null}
                {t("common.delete")}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </PageContent>
    </>
  );
}

function StatTile({
  icon,
  label,
  value,
  tone,
}: {
  icon: React.ReactNode;
  label: string;
  value: number;
  tone: "primary" | "info" | "neutral";
}) {
  const toneCls = {
    primary: "bg-primary/10 text-primary",
    info: "bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300",
    neutral: "bg-muted text-muted-foreground",
  }[tone];
  return (
    <Card>
      <CardContent className="flex items-center gap-3 p-4">
        <div className={`rounded-md p-2 ${toneCls}`}>{icon}</div>
        <div className="min-w-0 flex-1">
          <p className="truncate text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
            {label}
          </p>
          <p className="text-2xl leading-tight font-semibold">{value}</p>
        </div>
      </CardContent>
    </Card>
  );
}

function EmptyState({ onCreate }: { onCreate: () => void }) {
  const { t } = useTranslation();
  return (
    <Card>
      <CardContent className="flex flex-col items-center justify-center gap-3 py-16 text-center">
        <div className="rounded-full bg-primary/10 p-4">
          <UsersRound className="size-8 text-primary" />
        </div>
        <div className="space-y-1">
          <p className="text-base font-semibold">{t("notifications.audiences.empty_title")}</p>
          <p className="max-w-sm text-sm text-muted-foreground">
            {t("notifications.audiences.empty_description")}
          </p>
        </div>
        <Button onClick={onCreate} size="sm">
          <Plus className="mr-1 size-3.5" />
          {t("notifications.audiences.create")}
        </Button>
      </CardContent>
    </Card>
  );
}

function AudienceListSkeleton() {
  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2" data-slot="audience-list-skeleton">
      {[0, 1, 2, 3].map((i) => (
        <Skeleton key={i} className="h-28 w-full" />
      ))}
    </div>
  );
}

function AudienceList({
  rows,
  onView,
  onEdit,
  onDelete,
}: {
  rows: AudienceRow[];
  onView: (row: AudienceRow) => void;
  onEdit: (row: AudienceRow) => void;
  onDelete: (row: AudienceRow) => void;
}) {
  const { t } = useTranslation();

  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2" data-slot="audience-list">
      {rows.map((row) => {
        const ruleCount = row.rule?.rules?.length ?? 0;
        const excludeCount = row.rule?.exclude?.length ?? 0;
        const combinator = row.rule?.combinator?.toUpperCase() ?? "OR";
        return (
          <Card
            key={row.id}
            className="group cursor-pointer transition hover:border-primary/40 hover:shadow-sm"
            onClick={() => onView(row)}
          >
            <CardContent className="space-y-3 p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <Users className="size-4 shrink-0 text-muted-foreground" />
                    <h3 className="truncate text-sm font-semibold">{row.name}</h3>
                    {row.is_system ? (
                      <Badge variant="secondary" className="text-[10px]">
                        <ShieldCheck className="mr-1 size-3" />
                        {t("notifications.audiences.system_badge")}
                      </Badge>
                    ) : null}
                  </div>
                  {row.description ? (
                    <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                      {row.description}
                    </p>
                  ) : null}
                </div>
              </div>

              <div className="flex flex-wrap items-center gap-1.5 text-[10px]">
                <Badge variant="outline">{combinator}</Badge>
                <Badge variant="outline">
                  <Filter className="mr-1 size-3" />
                  {t("notifications.audiences.rule_count", { count: ruleCount })}
                </Badge>
                {excludeCount > 0 ? (
                  <Badge variant="outline" className="text-destructive">
                    −{excludeCount}
                  </Badge>
                ) : null}
              </div>

              <div className="flex justify-end gap-1 border-t pt-3">
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={(e) => {
                    e.stopPropagation();
                    onView(row);
                  }}
                >
                  <Eye className="mr-1 size-3.5" />
                  {t("common.view")}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={(e) => {
                    e.stopPropagation();
                    onEdit(row);
                  }}
                >
                  <Pencil className="mr-1 size-3.5" />
                  {t("common.edit")}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={(e) => {
                    e.stopPropagation();
                    onDelete(row);
                  }}
                  disabled={row.is_system}
                  className="text-destructive hover:text-destructive"
                >
                  <Trash2 className="mr-1 size-3.5" />
                  {t("common.delete")}
                </Button>
              </div>
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}

function AudienceEditor({
  brandSlug,
  editor,
  setEditor,
  onCancel,
  onSave,
  isSaving,
}: {
  brandSlug: string;
  editor: EditorState;
  setEditor: (state: EditorState) => void;
  onCancel: () => void;
  onSave: () => void;
  isSaving: boolean;
}) {
  const { t } = useTranslation();

  const debouncedRule = useDebounce(editor.rule, 400);
  const previewRule = useMemo<AudienceRule | null>(() => {
    if (!debouncedRule || (debouncedRule.rules?.length ?? 0) === 0) return null;
    return debouncedRule;
  }, [debouncedRule]);

  const preview = useAudiencePreview(brandSlug, previewRule);

  return (
    <Sheet open onOpenChange={(v) => !v && onCancel()}>
      <SheetContent side="right" className="flex w-full flex-col gap-0 p-0 sm:max-w-2xl">
        {/* Header — spans full width with colored bg */}
        <div className="flex items-start gap-3 border-b bg-muted/20 px-6 py-4">
          <div className="rounded-lg bg-primary/10 p-2.5">
            <Users className="size-5 text-primary" />
          </div>
          <div className="flex-1">
            <SheetHeader className="space-y-1 p-0 text-left">
              <SheetTitle className="text-base font-semibold">
                {editor.mode === "create"
                  ? t("notifications.audiences.create_title")
                  : t("notifications.audiences.edit_title")}
              </SheetTitle>
              <SheetDescription className="text-xs">
                {t("notifications.audiences.editor_description")}
              </SheetDescription>
            </SheetHeader>
          </div>
        </div>

        {/* Body — scrollable, sectioned */}
        <div className="flex-1 overflow-y-auto">
          <SheetSection
            title={t("notifications.audiences.section_identity")}
            description={t("notifications.audiences.section_identity_hint")}
          >
            <div className="space-y-4">
              <FormField label={t("notifications.audiences.name_label")} required>
                <Input
                  value={editor.name}
                  onChange={(e) => setEditor({ ...editor, name: e.target.value })}
                  placeholder={t("notifications.audiences.name_placeholder")}
                  className="h-10"
                />
              </FormField>

              <FormField label={t("notifications.audiences.description_label")}>
                <Textarea
                  value={editor.description}
                  onChange={(e) => setEditor({ ...editor, description: e.target.value })}
                  placeholder={t("notifications.audiences.description_placeholder")}
                  rows={2}
                  maxLength={1000}
                  className="field-sizing-fixed"
                />
              </FormField>
            </div>
          </SheetSection>

          <SheetSection
            title={t("notifications.audiences.section_rules")}
            description={t("notifications.audiences.section_rules_hint")}
            action={<PreviewBadge preview={preview} t={t} />}
          >
            <RuleBuilder value={editor.rule} onChange={(rule) => setEditor({ ...editor, rule })} />
          </SheetSection>
        </div>

        {/* Sticky footer */}
        <div className="flex items-center justify-end gap-2 border-t bg-background px-6 py-3">
          <Button variant="outline" size="sm" onClick={onCancel}>
            {t("common.cancel")}
          </Button>
          <Button size="sm" onClick={onSave} disabled={isSaving || editor.name.trim() === ""}>
            {isSaving ? <Spinner className="mr-2 size-3.5" /> : null}
            {t("common.save")}
          </Button>
        </div>
      </SheetContent>
    </Sheet>
  );
}

function SheetSection({
  title,
  description,
  action,
  children,
}: {
  title: string;
  description?: string;
  action?: React.ReactNode;
  children: React.ReactNode;
}) {
  return (
    <section className="border-b px-6 py-5 last:border-b-0">
      <div className="mb-4 flex items-start justify-between gap-4">
        <div>
          <h3 className="text-sm font-semibold">{title}</h3>
          {description ? (
            <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>
          ) : null}
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}

function FormField({
  label,
  required,
  children,
  hint,
}: {
  label: string;
  required?: boolean;
  children: React.ReactNode;
  hint?: string;
}) {
  return (
    <div className="space-y-1.5">
      <label className="flex items-center gap-1 text-xs font-medium">
        {label}
        {required ? <span className="text-destructive">*</span> : null}
      </label>
      {children}
      {hint ? <p className="text-[11px] text-muted-foreground">{hint}</p> : null}
    </div>
  );
}

function PreviewBadge({
  preview,
  t,
}: {
  preview: ReturnType<typeof useAudiencePreview>;
  t: (k: string, p?: Record<string, string | number>) => string;
}) {
  if (preview.isFetching) {
    return (
      <div className="inline-flex items-center gap-1.5 rounded-full border bg-muted/40 px-2.5 py-1 text-[11px]">
        <Spinner className="size-3" />
        <span className="text-muted-foreground">{t("common.loading")}</span>
      </div>
    );
  }
  if (preview.isError) {
    return (
      <Badge variant="destructive" className="text-[10px]">
        {t("common.error")}
      </Badge>
    );
  }
  if (preview.data) {
    const zero = preview.data.count === 0;
    return (
      <div
        className={
          zero
            ? "inline-flex items-center gap-1.5 rounded-full border border-destructive/50 bg-destructive/10 px-2.5 py-1 text-[11px] text-destructive"
            : "inline-flex items-center gap-1.5 rounded-full border border-primary/40 bg-primary/10 px-2.5 py-1 text-[11px] text-primary"
        }
      >
        <Users className="size-3" />
        <span className="font-medium">
          {t("notifications.audiences.preview_count", { count: preview.data.count })}
        </span>
      </div>
    );
  }
  return (
    <span className="text-[11px] text-muted-foreground italic">
      {t("notifications.audiences.preview_hint")}
    </span>
  );
}

/**
 * Read-only detail view — explains what the audience targets in plain
 * language (rule breakdown with icons) and shows the live resolved list
 * so admins understand exactly who will receive a broadcast that picks it.
 */
function AudienceDetailSheet({
  brandSlug,
  row,
  onClose,
  onEdit,
}: {
  brandSlug: string;
  row: AudienceRow;
  onClose: () => void;
  onEdit: () => void;
}) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();
  const preview = useAudiencePreview(brandSlug, row.rule ?? null);

  const rules = row.rule?.rules ?? [];
  const exclude = row.rule?.exclude ?? [];
  const combinator = (row.rule?.combinator ?? "or").toUpperCase();

  return (
    <Sheet open onOpenChange={(v) => !v && onClose()}>
      <SheetContent side="right" className="flex w-full flex-col gap-0 p-0 sm:max-w-xl">
        {/* Header */}
        <div className="flex items-start gap-3 border-b bg-muted/20 px-6 py-4">
          <div className="rounded-lg bg-primary/10 p-2.5">
            <Eye className="size-5 text-primary" />
          </div>
          <div className="flex-1">
            <SheetHeader className="space-y-1 p-0 text-left">
              <SheetTitle className="flex items-center gap-2 text-base font-semibold">
                {row.name}
                {row.is_system ? (
                  <Badge variant="secondary" className="text-[10px]">
                    <ShieldCheck className="mr-1 size-3" />
                    {t("notifications.audiences.system_badge")}
                  </Badge>
                ) : null}
              </SheetTitle>
              {row.description ? (
                <SheetDescription className="text-xs">{row.description}</SheetDescription>
              ) : null}
            </SheetHeader>
          </div>
        </div>

        {/* Body */}
        <div className="flex-1 space-y-4 overflow-y-auto p-6">
          {/* Usage hint */}
          <div className="flex items-start gap-2 rounded-md border border-primary/20 bg-primary/5 p-3 text-xs">
            <Info className="mt-0.5 size-3.5 shrink-0 text-primary" />
            <div>
              <p className="font-medium">{t("notifications.audiences.view.usage_title")}</p>
              <p className="mt-1 text-muted-foreground">
                {t("notifications.audiences.view.usage_hint")}
              </p>
            </div>
          </div>

          {/* Live preview */}
          <div className="rounded-lg border">
            <div className="flex items-center justify-between border-b bg-muted/30 px-3 py-2">
              <div className="flex items-center gap-2">
                <Users className="size-3.5 text-muted-foreground" />
                <span className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                  {t("notifications.audiences.view.resolved_recipients")}
                </span>
              </div>
              {preview.data ? (
                <Badge variant={preview.data.count === 0 ? "destructive" : "default"}>
                  {preview.data.count}
                </Badge>
              ) : null}
            </div>
            <div className="p-3">
              {preview.isLoading ? (
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                  <Spinner className="size-3" />
                  {t("common.loading")}
                </div>
              ) : preview.data?.sample.length ? (
                <ul className="space-y-1">
                  {preview.data.sample.map((s) => (
                    <li
                      key={s.id}
                      className="flex items-center gap-2 rounded px-2 py-1.5 text-xs hover:bg-muted/30"
                    >
                      <UserCircle2 className="size-3.5 text-muted-foreground" />
                      <span className="flex-1 truncate">{s.label}</span>
                      <Badge variant="outline" className="font-mono text-[10px]">
                        {s.type}
                      </Badge>
                    </li>
                  ))}
                  {preview.data.count > preview.data.sample.length ? (
                    <li className="pt-1 text-center text-[11px] text-muted-foreground italic">
                      {t("notifications.audiences.view.plus_more", {
                        count: preview.data.count - preview.data.sample.length,
                      })}
                    </li>
                  ) : null}
                </ul>
              ) : (
                <p className="text-xs text-muted-foreground italic">
                  {t("notifications.audiences.view.no_recipients")}
                </p>
              )}
            </div>
          </div>

          {/* Rule breakdown */}
          <div className="rounded-lg border">
            <div className="border-b bg-muted/30 px-3 py-2">
              <div className="flex items-center gap-2">
                <Filter className="size-3.5 text-muted-foreground" />
                <span className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                  {t("notifications.audiences.view.rules")}
                </span>
                <Badge variant="outline" className="text-[10px]">
                  {combinator}
                </Badge>
              </div>
            </div>
            <div className="divide-y">
              {rules.length === 0 ? (
                <p className="p-3 text-xs text-muted-foreground italic">
                  {t("notifications.audiences.view.no_rules")}
                </p>
              ) : (
                rules.map((rule, idx) => <RuleRow key={idx} rule={rule} t={t} />)
              )}
            </div>
          </div>

          {/* Exclude breakdown */}
          {exclude.length > 0 ? (
            <div className="rounded-lg border border-destructive/20">
              <div className="border-b bg-destructive/5 px-3 py-2">
                <div className="flex items-center gap-2">
                  <Trash2 className="size-3.5 text-destructive" />
                  <span className="text-[10px] font-medium tracking-wider text-destructive uppercase">
                    {t("notifications.audiences.view.exclude")}
                  </span>
                </div>
              </div>
              <div className="divide-y">
                {exclude.map((rule, idx) => (
                  <RuleRow key={idx} rule={rule} t={t} />
                ))}
              </div>
            </div>
          ) : null}

          {/* Metadata */}
          <div className="rounded-lg border bg-muted/20 p-3 text-xs text-muted-foreground">
            <div className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1">
              <span>Created:</span>
              <span>{formatDateTime(row.created_at, locale, timezone)}</span>
              <span>Updated:</span>
              <span>{formatDateTime(row.updated_at, locale, timezone)}</span>
              <span>Brand ID:</span>
              <span className="font-mono text-[10px]">{row.brand_id ?? "—"}</span>
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between gap-2 border-t bg-background px-6 py-3">
          <div className="flex items-center gap-2 text-xs text-muted-foreground">
            <Send className="size-3" />
            <span>{t("notifications.audiences.view.compose_hint")}</span>
          </div>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={onClose}>
              {t("common.close")}
            </Button>
            {!row.is_system ? (
              <Button size="sm" onClick={onEdit}>
                <Pencil className="mr-1 size-3.5" />
                {t("common.edit")}
              </Button>
            ) : null}
          </div>
        </div>
      </SheetContent>
    </Sheet>
  );
}

/** Human-readable row for one sub-rule in the detail Sheet. */
function RuleRow({
  rule,
  t,
}: {
  rule: {
    type?: string;
    role?: string;
    user_ids?: string[];
    shop_ids?: string[];
    brand_id?: string;
    device_types?: string[];
    branch_id?: string;
    include_members?: boolean;
    include_all_members?: boolean;
    scope?: Record<string, string>;
  };
  t: (k: string, p?: Record<string, string | number>) => string;
}) {
  const label = ruleLabel(rule, t);
  const meta = ruleMeta(rule);

  return (
    <div className="flex items-start gap-3 p-3 text-xs">
      <div className="rounded-md bg-muted/50 p-1.5">{renderRuleIcon(rule.type)}</div>
      <div className="min-w-0 flex-1">
        <p className="font-medium">{label}</p>
        {meta ? <p className="mt-0.5 text-[11px] text-muted-foreground">{meta}</p> : null}
      </div>
    </div>
  );
}

function renderRuleIcon(type?: string) {
  const className = "size-3.5 text-muted-foreground";
  switch (type) {
    case "role":
      return <Tag className={className} />;
    case "user":
      return <UserCircle2 className={className} />;
    case "shop":
      return <Store className={className} />;
    case "brand":
      return <Building2 className={className} />;
    case "device":
      return <MonitorSmartphone className={className} />;
    default:
      return <Users className={className} />;
  }
}

function ruleLabel(
  rule: {
    type?: string;
    role?: string;
    user_ids?: string[];
    shop_ids?: string[];
    brand_id?: string;
    device_types?: string[];
  },
  t: (k: string, p?: Record<string, string | number>) => string
): string {
  switch (rule.type) {
    case "role":
      return t("notifications.audiences.view.rule_role", { role: rule.role ?? "—" });
    case "user":
      return t("notifications.audiences.view.rule_user", { count: rule.user_ids?.length ?? 0 });
    case "shop":
      return t("notifications.audiences.view.rule_shop", { count: rule.shop_ids?.length ?? 0 });
    case "brand":
      return t("notifications.audiences.view.rule_brand");
    case "device":
      return t("notifications.audiences.view.rule_device", {
        types: (rule.device_types ?? []).join(", ") || "—",
      });
    default:
      return rule.type ?? "—";
  }
}

function ruleMeta(rule: {
  type?: string;
  scope?: Record<string, string>;
  include_members?: boolean;
  include_all_members?: boolean;
  branch_id?: string;
}): string | null {
  const parts: string[] = [];
  if (rule.scope) {
    Object.entries(rule.scope).forEach(([k, v]) => parts.push(`${k}: ${v.slice(0, 8)}…`));
  }
  if (rule.include_members) parts.push("includes shop members");
  if (rule.include_all_members) parts.push("includes all brand members");
  if (rule.branch_id) parts.push(`branch: ${rule.branch_id.slice(0, 8)}…`);
  return parts.length ? parts.join(" · ") : null;
}
