"use client";

/**
 * HQ Notification Templates page (plan-012 T2.5).
 *
 * Table list + Sheet editor with ja/en/vi tabs. Each tab holds title +
 * body inputs plus a param-placeholder DropdownMenu populated from
 * `params_schema.required + optional`. Live render preview beside the editor.
 */

import {
  Alert,
  AlertDescription,
  Badge,
  Button,
  Card,
  CardContent,
  Combobox,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Popover,
  PopoverContent,
  PopoverTrigger,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  Skeleton,
  Spinner,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
  TagInput,
  Textarea,
} from "@godxjp/ui";
import {
  AlertCircle,
  Copy,
  Eye,
  FileText,
  HelpCircle,
  Info,
  Pencil,
  Plus,
  ShieldCheck,
  Sparkles,
  Trash2,
} from "lucide-react";
import { useParams } from "next/navigation";
import { useMemo, useState } from "react";
import { toast } from "sonner";

import { PageContent } from "@/components/layout/page-content";
import { PageHeader } from "@/components/layout/page-header";
import { useDebounce } from "@/hooks/use-debounce";
import {
  useCreateTemplate,
  useDeleteTemplate,
  useDuplicateTemplate,
  useTemplateRenderPreview,
  useTemplates,
  useUpdateTemplate,
} from "@/hooks/api/use-templates";
import { useTranslation } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import type { TemplateContent, TemplateRow } from "@/services/notification-template-service";

const EMPTY_CONTENT: TemplateContent = {
  ja: { title: "", body: "" },
  en: { title: "", body: "" },
  vi: { title: "", body: "" },
};

const LOCALES: Array<"ja" | "en" | "vi"> = ["ja", "en", "vi"];

interface EditorState {
  mode: "create" | "edit";
  id: string | null;
  key: string;
  content: TemplateContent;
  paramsSample: Record<string, string>;
  paramsRequired: string[];
  paramsOptional: string[];
}

function emptyEditor(): EditorState {
  return {
    mode: "create",
    id: null,
    key: "",
    content: EMPTY_CONTENT,
    paramsSample: {},
    paramsRequired: [],
    paramsOptional: [],
  };
}

function editorFromRow(row: TemplateRow): EditorState {
  const required = row.params_schema?.required ?? [];
  const optional = row.params_schema?.optional ?? [];
  const sample: Record<string, string> = {};
  [...required, ...optional].forEach((k) => {
    sample[k] = `<${k}>`;
  });
  return {
    mode: "edit",
    id: row.id,
    key: row.key,
    content: row.content ?? EMPTY_CONTENT,
    paramsSample: sample,
    paramsRequired: required,
    paramsOptional: optional,
  };
}

export default function TemplatesPage() {
  const { brandSlug } = useParams<{ brandSlug: string }>();
  const { t } = useTranslation();

  const { data, isLoading, isError, refetch } = useTemplates(brandSlug);

  const [editor, setEditor] = useState<EditorState | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<TemplateRow | null>(null);

  const createMutation = useCreateTemplate(brandSlug);
  const updateMutation = useUpdateTemplate(brandSlug, editor?.id ?? "");
  const deleteMutation = useDeleteTemplate(brandSlug);
  const duplicateMutation = useDuplicateTemplate(brandSlug);

  const [duplicatingId, setDuplicatingId] = useState<string | null>(null);

  async function save() {
    if (!editor) return;
    const hasParams = editor.paramsRequired.length > 0 || editor.paramsOptional.length > 0;
    const paramsSchema = hasParams
      ? { required: editor.paramsRequired, optional: editor.paramsOptional }
      : null;
    if (editor.mode === "create") {
      await createMutation.mutateAsync({
        key: editor.key,
        content: editor.content,
        params_schema: paramsSchema,
      });
    } else {
      await updateMutation.mutateAsync({
        content: editor.content,
        params_schema: paramsSchema,
      });
    }
    setEditor(null);
  }

  async function doDelete() {
    if (!confirmDelete) return;
    try {
      await deleteMutation.mutateAsync(confirmDelete.id);
      setConfirmDelete(null);
    } catch (err) {
      // Backend blocks deleting a template still referenced by a rule
      // (TC-NOTIF-TPL05).
      const code = err instanceof ApiError ? (err.body?.error as string | undefined) : undefined;
      toast.error(
        code === "IN_USE"
          ? t("notifications.templates.delete_in_use")
          : t("notifications.templates.delete_error")
      );
      setConfirmDelete(null);
    }
  }

  async function doDuplicate(row: TemplateRow) {
    setDuplicatingId(row.id);
    try {
      const copy = await duplicateMutation.mutateAsync(row.id);
      toast.success(t("notifications.templates.duplicate_success", { key: copy.key }));
    } catch {
      toast.error(t("notifications.templates.duplicate_error"));
    } finally {
      setDuplicatingId(null);
    }
  }

  const rows = data?.data ?? [];
  const systemCount = rows.filter((r) => r.is_system).length;

  return (
    <>
      <PageHeader
        title={t("notifications.templates.title")}
        description={t("notifications.templates.subtitle")}
      >
        <Button size="sm" onClick={() => setEditor(emptyEditor())}>
          <Plus className="mr-1 size-3.5" />
          {t("notifications.templates.create")}
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

        {!isLoading && rows.length > 0 ? (
          <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatTile
              icon={<FileText className="size-4" />}
              label={t("notifications.templates.stat_total")}
              value={rows.length}
              tone="primary"
            />
            <StatTile
              icon={<ShieldCheck className="size-4" />}
              label={t("notifications.templates.stat_system")}
              value={systemCount}
              tone="neutral"
            />
            <StatTile
              icon={<Pencil className="size-4" />}
              label={t("notifications.templates.stat_custom")}
              value={rows.length - systemCount}
              tone="info"
            />
          </div>
        ) : null}

        {isLoading ? (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {[0, 1, 2, 3].map((i) => (
              <Skeleton key={i} className="h-32 w-full" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <EmptyState onCreate={() => setEditor(emptyEditor())} />
        ) : (
          <TemplateGrid
            rows={rows}
            onEdit={(row) => setEditor(editorFromRow(row))}
            onDelete={setConfirmDelete}
            onDuplicate={doDuplicate}
            duplicatingId={duplicatingId}
          />
        )}

        {editor && (
          <TemplateEditor
            brandSlug={brandSlug}
            editor={editor}
            setEditor={setEditor}
            onCancel={() => setEditor(null)}
            onSave={save}
            isSaving={createMutation.isPending || updateMutation.isPending}
            existingKeys={rows.map((r) => r.key)}
          />
        )}

        <Dialog open={confirmDelete !== null} onOpenChange={(v) => !v && setConfirmDelete(null)}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <Trash2 className="size-4 text-destructive" />
                {t("notifications.templates.delete_title")}
              </DialogTitle>
              <DialogDescription>
                {t("notifications.templates.delete_description", {
                  key: confirmDelete?.key ?? "",
                })}
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setConfirmDelete(null)}>
                {t("common.cancel")}
              </Button>
              <Button variant="destructive" onClick={doDelete} disabled={deleteMutation.isPending}>
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
          <FileText className="size-8 text-primary" />
        </div>
        <div className="space-y-1">
          <p className="text-base font-semibold">{t("notifications.templates.empty_title")}</p>
          <p className="max-w-sm text-sm text-muted-foreground">
            {t("notifications.templates.empty_description")}
          </p>
        </div>
        <Button size="sm" onClick={onCreate}>
          <Plus className="mr-1 size-3.5" />
          {t("notifications.templates.create")}
        </Button>
      </CardContent>
    </Card>
  );
}

function TemplateGrid({
  rows,
  onEdit,
  onDelete,
  onDuplicate,
  duplicatingId,
}: {
  rows: TemplateRow[];
  onEdit: (row: TemplateRow) => void;
  onDelete: (row: TemplateRow) => void;
  onDuplicate: (row: TemplateRow) => void;
  duplicatingId: string | null;
}) {
  const { t } = useTranslation();
  return (
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
      {rows.map((row) => {
        const jaTitle = row.content?.ja?.title ?? "";
        const locales = LOCALES.filter((l) => row.content?.[l]?.title || row.content?.[l]?.body);
        return (
          <Card key={row.id} className="group transition hover:border-primary/40 hover:shadow-sm">
            <CardContent className="space-y-3 p-4">
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <FileText className="size-3.5 shrink-0 text-muted-foreground" />
                    <code className="truncate text-xs font-semibold">{row.key}</code>
                    {row.is_system ? (
                      <Badge variant="secondary" className="shrink-0 text-[10px]">
                        <ShieldCheck className="mr-1 size-3" />
                        {t("notifications.templates.system_badge")}
                      </Badge>
                    ) : null}
                  </div>
                  {jaTitle ? (
                    <p className="mt-1 line-clamp-2 text-sm">{jaTitle}</p>
                  ) : (
                    <p className="mt-1 text-xs text-muted-foreground italic">
                      {t("notifications.templates.no_title")}
                    </p>
                  )}
                </div>
              </div>

              <div className="flex flex-wrap gap-1">
                {locales.map((l) => (
                  <Badge key={l} variant="outline" className="text-[10px] uppercase">
                    {l}
                  </Badge>
                ))}
              </div>

              <div className="flex justify-end gap-1 border-t pt-3">
                <Button variant="ghost" size="sm" onClick={() => onEdit(row)}>
                  <Pencil className="mr-1 size-3.5" />
                  {t("common.edit")}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => onDuplicate(row)}
                  disabled={duplicatingId === row.id}
                >
                  {duplicatingId === row.id ? (
                    <Spinner className="mr-1 size-3.5" />
                  ) : (
                    <Copy className="mr-1 size-3.5" />
                  )}
                  {t("notifications.templates.duplicate")}
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => onDelete(row)}
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

/**
 * Known notification types emitters use. Admin picks from this list;
 * "Create custom type" unlocks a free-text input for broadcast-only keys.
 */
const KNOWN_TYPES: Array<{ value: string; label: string; emitter: boolean }> = [
  { value: "stock.alert.low", label: "Stock alert — low", emitter: true },
  { value: "stock.alert.out", label: "Stock alert — out of stock", emitter: true },
  { value: "recipe.approved", label: "Recipe approved", emitter: true },
  { value: "recipe.rejected", label: "Recipe rejected", emitter: true },
  { value: "order.paid", label: "Order paid", emitter: true },
  { value: "order.status_changed", label: "Order status changed", emitter: true },
  { value: "system.critical", label: "System critical alert", emitter: true },
  { value: "promo.welcome", label: "Promo — welcome campaign", emitter: false },
  { value: "brand.announcement", label: "Brand announcement", emitter: false },
  { value: "ops.daily_summary", label: "Daily ops summary", emitter: false },
  { value: "maintenance.scheduled", label: "Scheduled maintenance", emitter: false },
];

function TemplateEditor({
  brandSlug,
  editor,
  setEditor,
  onCancel,
  onSave,
  isSaving,
  existingKeys,
}: {
  brandSlug: string;
  editor: EditorState;
  setEditor: (state: EditorState) => void;
  onCancel: () => void;
  onSave: () => void;
  isSaving: boolean;
  existingKeys: string[];
}) {
  const { t } = useTranslation();
  const [activeLocale, setActiveLocale] = useState<"ja" | "en" | "vi">("ja");
  const [customMode, setCustomMode] = useState(
    editor.mode === "edit" && !KNOWN_TYPES.some((k) => k.value === editor.key)
  );

  const debouncedContent = useDebounce(editor.content, 400);
  const previewContent = useMemo<TemplateContent | null>(
    () => debouncedContent ?? null,
    [debouncedContent]
  );
  const preview = useTemplateRenderPreview(brandSlug, previewContent, editor.paramsSample);

  function patchContent(
    locale: "ja" | "en" | "vi",
    patch: Partial<{ title: string; body: string }>
  ) {
    setEditor({
      ...editor,
      content: {
        ...editor.content,
        [locale]: { ...editor.content[locale], ...patch },
      },
    });
  }

  function insertToken(token: string) {
    const active = editor.content[activeLocale];
    setEditor({
      ...editor,
      content: {
        ...editor.content,
        [activeLocale]: {
          ...active,
          body: `${active.body}{{${token}}}`,
        },
      },
    });
  }

  function updateParams(bucket: "required" | "optional", tags: string[]) {
    // Normalize: lowercase, snake_case + dot only. Drops empties + dedupes
    // within bucket; the OTHER bucket can still contain the same key
    // (we dedupe across buckets at render time via Set).
    const cleaned = Array.from(
      new Set(
        tags
          .map((t) =>
            t
              .trim()
              .toLowerCase()
              .replace(/[^a-z0-9_.]/g, "_")
          )
          .filter((t) => t.length > 0)
      )
    );
    const next = {
      paramsRequired: bucket === "required" ? cleaned : editor.paramsRequired,
      paramsOptional: bucket === "optional" ? cleaned : editor.paramsOptional,
    };
    // Rebuild sample so preview substitutes <param_name>.
    const sample: Record<string, string> = {};
    [...next.paramsRequired, ...next.paramsOptional].forEach((k) => {
      sample[k] = `<${k}>`;
    });
    setEditor({ ...editor, ...next, paramsSample: sample });
  }

  // Dedup across buckets — required wins if a key is in both.
  const paramKeys = Array.from(new Set([...editor.paramsRequired, ...editor.paramsOptional]));

  // Build combobox options from known types, excluding those already templated
  // (unless we're editing that existing one).
  const typeOptions = useMemo(() => {
    return KNOWN_TYPES.filter((k) => editor.mode === "edit" || !existingKeys.includes(k.value)).map(
      (k) => ({
        value: k.value,
        label: k.emitter ? `${k.label} · emitter` : k.label,
      })
    );
  }, [existingKeys, editor.mode]);

  const selectedKnown = KNOWN_TYPES.find((k) => k.value === editor.key);
  const keyInvalid =
    editor.key.trim() === "" || (customMode && !/^[a-z][a-z0-9._-]*$/.test(editor.key));
  const missingLocale = LOCALES.some(
    (l) => !editor.content[l]?.title?.trim() || !editor.content[l]?.body?.trim()
  );

  return (
    <Sheet open onOpenChange={(v) => !v && onCancel()}>
      <SheetContent side="right" className="flex w-full flex-col gap-0 p-0 sm:max-w-3xl">
        {/* Header */}
        <div className="flex items-start gap-3 border-b bg-muted/20 px-6 py-4">
          <div className="rounded-lg bg-primary/10 p-2.5">
            <FileText className="size-5 text-primary" />
          </div>
          <div className="flex-1">
            <SheetHeader className="space-y-1 p-0 text-left">
              <SheetTitle className="text-base font-semibold">
                {editor.mode === "create"
                  ? t("notifications.templates.create_title")
                  : t("notifications.templates.edit_title")}
              </SheetTitle>
              <SheetDescription className="text-xs">
                {t("notifications.templates.editor_description")}
              </SheetDescription>
            </SheetHeader>
          </div>
        </div>

        {/* Body — scrollable, sectioned */}
        <div className="flex-1 overflow-y-auto">
          {/* Section 1 — Type key */}
          <TplSection
            title={t("notifications.templates.section_identity")}
            description={t("notifications.templates.section_identity_hint")}
          >
            <TplFormField
              label={t("notifications.templates.key_label")}
              required
              helpText={t("notifications.templates.help_key")}
            >
              {editor.mode === "edit" ? (
                <Input value={editor.key} disabled className="h-10 font-mono text-xs" />
              ) : customMode ? (
                <div className="flex gap-2">
                  <Input
                    value={editor.key}
                    onChange={(e) => setEditor({ ...editor, key: e.target.value.toLowerCase() })}
                    placeholder="custom.my_type"
                    className="h-10 font-mono text-xs"
                  />
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setCustomMode(false);
                      setEditor({ ...editor, key: "" });
                    }}
                  >
                    {t("common.cancel")}
                  </Button>
                </div>
              ) : (
                <div className="flex gap-2">
                  <div className="flex-1">
                    <Combobox
                      options={typeOptions}
                      value={editor.key}
                      onChange={(v) => setEditor({ ...editor, key: v })}
                      placeholder={t("notifications.templates.key_picker_placeholder")}
                      searchPlaceholder={t("notifications.templates.key_picker_search")}
                    />
                  </div>
                  <Button variant="outline" size="sm" onClick={() => setCustomMode(true)}>
                    <Plus className="mr-1 size-3.5" />
                    {t("notifications.templates.custom_key")}
                  </Button>
                </div>
              )}
            </TplFormField>

            {selectedKnown ? (
              <div
                className={
                  selectedKnown.emitter
                    ? "mt-2 flex items-start gap-2 rounded-md border border-primary/30 bg-primary/5 p-3 text-xs"
                    : "mt-2 flex items-start gap-2 rounded-md border bg-muted/20 p-3 text-xs"
                }
              >
                <Info className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                <div>
                  {selectedKnown.emitter ? (
                    <>
                      <p className="font-medium">
                        {t("notifications.templates.type_emitter_title")}
                      </p>
                      <p className="mt-0.5 text-muted-foreground">
                        {t("notifications.templates.type_emitter_hint", {
                          key: selectedKnown.value,
                        })}
                      </p>
                    </>
                  ) : (
                    <>
                      <p className="font-medium">
                        {t("notifications.templates.type_broadcast_title")}
                      </p>
                      <p className="mt-0.5 text-muted-foreground">
                        {t("notifications.templates.type_broadcast_hint")}
                      </p>
                    </>
                  )}
                </div>
              </div>
            ) : customMode ? (
              <p className="mt-2 text-[11px] text-muted-foreground">
                {t("notifications.templates.custom_hint")}
              </p>
            ) : null}
          </TplSection>

          {/* Section 2 — Params schema (required + optional) */}
          <TplSection
            title={t("notifications.templates.section_params")}
            description={t("notifications.templates.section_params_hint")}
          >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <TplFormField
                label={t("notifications.templates.params_required_label")}
                helpText={t("notifications.templates.help_params_required")}
              >
                <TagInput
                  value={editor.paramsRequired}
                  onChange={(tags) => updateParams("required", tags)}
                  placeholder={t("notifications.templates.params_required_placeholder")}
                />
              </TplFormField>
              <TplFormField
                label={t("notifications.templates.params_optional_label")}
                helpText={t("notifications.templates.help_params_optional")}
              >
                <TagInput
                  value={editor.paramsOptional}
                  onChange={(tags) => updateParams("optional", tags)}
                  placeholder={t("notifications.templates.params_optional_placeholder")}
                />
              </TplFormField>
            </div>
          </TplSection>

          {/* Section 3 — Content (1-box Tabs + live preview side) */}
          <TplSection
            title={t("notifications.templates.section_content")}
            description={t("notifications.templates.section_content_hint")}
          >
            {/* Param chips */}
            {paramKeys.length > 0 ? (
              <div className="mb-4 rounded-md border bg-muted/10 p-3">
                <p className="mb-2 flex items-center gap-1 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                  {t("notifications.templates.params_helper")}
                  <FieldHelp text={t("notifications.templates.help_params")} />
                </p>
                <div className="flex flex-wrap gap-1.5">
                  {paramKeys.map((key) => (
                    <button
                      key={key}
                      type="button"
                      onClick={() => insertToken(key)}
                      className="inline-flex items-center gap-1 rounded-full border bg-background px-2.5 py-1 font-mono text-[11px] hover:border-primary/50 hover:bg-primary/5"
                      title={t("notifications.templates.click_to_insert")}
                    >
                      <Plus className="size-3 text-muted-foreground" />
                      {`{{${key}}}`}
                    </button>
                  ))}
                </div>
              </div>
            ) : null}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1fr]">
              {/* Left — tab-switched locale editor */}
              <div className="rounded-lg border bg-background p-3">
                <Tabs
                  value={activeLocale}
                  onValueChange={(v) => setActiveLocale(v as "ja" | "en" | "vi")}
                >
                  <TabsList className="grid w-full grid-cols-3">
                    {LOCALES.map((l) => {
                      const filled =
                        editor.content[l]?.title?.trim() && editor.content[l]?.body?.trim();
                      return (
                        <TabsTrigger key={l} value={l} className="gap-1.5 font-mono uppercase">
                          <span
                            aria-hidden
                            className={
                              filled
                                ? "size-1.5 rounded-full bg-primary"
                                : "size-1.5 rounded-full bg-destructive/70"
                            }
                          />
                          {l}
                        </TabsTrigger>
                      );
                    })}
                  </TabsList>

                  {LOCALES.map((l) => {
                    const filled =
                      editor.content[l]?.title?.trim() && editor.content[l]?.body?.trim();
                    return (
                      <TabsContent key={l} value={l} className="mt-3 space-y-3">
                        <div className="flex items-center gap-2">
                          <Badge
                            variant={filled ? "default" : "outline"}
                            className="font-mono text-[10px] uppercase"
                          >
                            {l}
                          </Badge>
                          {!filled ? (
                            <span className="text-[10px] text-destructive">
                              {t("notifications.templates.locale_incomplete")}
                            </span>
                          ) : null}
                        </div>
                        <div className="space-y-1">
                          <label className="flex items-center gap-1 text-[10px] font-medium text-muted-foreground">
                            {t("notifications.templates.title_field_label")}
                            <FieldHelp text={t("notifications.templates.help_title")} />
                          </label>
                          <Input
                            value={editor.content[l].title}
                            onChange={(e) => patchContent(l, { title: e.target.value })}
                            placeholder={t("notifications.templates.title_placeholder")}
                            className="h-9 text-sm"
                          />
                        </div>
                        <div className="space-y-1">
                          <label className="flex items-center gap-1 text-[10px] font-medium text-muted-foreground">
                            {t("notifications.templates.body_field_label")}
                            <FieldHelp text={t("notifications.templates.help_body")} />
                          </label>
                          <Textarea
                            value={editor.content[l].body}
                            onChange={(e) => patchContent(l, { body: e.target.value })}
                            placeholder={t("notifications.templates.body_placeholder")}
                            rows={5}
                            maxLength={1000}
                            className="field-sizing-fixed text-sm"
                          />
                        </div>
                      </TabsContent>
                    );
                  })}
                </Tabs>
              </div>

              {/* Right — sticky live preview */}
              <div className="lg:sticky lg:top-0 lg:self-start">
                <div className="rounded-lg border bg-muted/20">
                  <div className="flex items-center justify-between border-b bg-muted/30 px-3 py-2">
                    <div className="flex items-center gap-2">
                      <Eye className="size-3.5 text-muted-foreground" />
                      <span className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                        {t("notifications.templates.preview")}
                      </span>
                      <Badge variant="outline" className="font-mono text-[10px] uppercase">
                        {activeLocale}
                      </Badge>
                    </div>
                    {preview.isFetching ? <Spinner className="size-3" /> : null}
                  </div>
                  <div className="p-4">
                    {preview.data?.[activeLocale] ? (
                      <div className="space-y-2 rounded-md bg-background p-3 shadow-sm">
                        <div className="flex items-start gap-2">
                          <Sparkles className="mt-0.5 size-3.5 shrink-0 text-primary" />
                          <p className="text-sm font-semibold">
                            {preview.data[activeLocale]?.title || (
                              <span className="text-muted-foreground italic">
                                {t("notifications.templates.no_title")}
                              </span>
                            )}
                          </p>
                        </div>
                        <p className="text-xs whitespace-pre-wrap text-muted-foreground">
                          {preview.data[activeLocale]?.body}
                        </p>
                      </div>
                    ) : (
                      <p className="text-center text-xs text-muted-foreground italic">
                        {t("notifications.templates.preview_hint")}
                      </p>
                    )}
                  </div>
                </div>
              </div>
            </div>
          </TplSection>
        </div>

        {/* Sticky footer with validation */}
        <div className="flex items-center justify-between gap-2 border-t bg-background px-6 py-3">
          <div className="text-xs text-muted-foreground">
            {keyInvalid ? (
              <span className="text-destructive">
                {t("notifications.templates.validation.key_required")}
              </span>
            ) : missingLocale ? (
              <span className="text-amber-600 dark:text-amber-400">
                {t("notifications.templates.validation.locales_incomplete")}
              </span>
            ) : (
              <span>{t("notifications.templates.validation.ready")}</span>
            )}
          </div>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={onCancel}>
              {t("common.cancel")}
            </Button>
            <Button size="sm" onClick={onSave} disabled={isSaving || keyInvalid || missingLocale}>
              {isSaving ? <Spinner className="mr-2 size-3.5" /> : null}
              {t("common.save")}
            </Button>
          </div>
        </div>
      </SheetContent>
    </Sheet>
  );
}

function TplSection({
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

function TplFormField({
  label,
  required,
  helpText,
  children,
}: {
  label: string;
  required?: boolean;
  helpText?: string;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <label className="flex items-center gap-1 text-xs font-medium">
        {label}
        {required ? <span className="text-destructive">*</span> : null}
        {helpText ? <FieldHelp text={helpText} /> : null}
      </label>
      {children}
    </div>
  );
}

function FieldHelp({ text }: { text: string }) {
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          tabIndex={-1}
          aria-label="Help"
          onClick={(e) => e.stopPropagation()}
          className="ml-0.5 inline-flex items-center justify-center rounded-full text-muted-foreground/70 hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
        >
          <HelpCircle className="size-3.5" />
        </button>
      </PopoverTrigger>
      <PopoverContent
        side="top"
        align="start"
        className="max-w-sm text-xs leading-relaxed whitespace-pre-line"
      >
        {text}
      </PopoverContent>
    </Popover>
  );
}
