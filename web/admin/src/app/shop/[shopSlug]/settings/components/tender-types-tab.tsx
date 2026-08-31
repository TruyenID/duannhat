"use client";

import { useEffect, useMemo, useState } from "react";
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardDescription,
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
} from "@godxjp/ui";
import {
  CircleIcon,
  CreditCardIcon,
  PencilIcon,
  PlusIcon,
  QrCodeIcon,
  Settings2Icon,
  Trash2Icon,
  WalletIcon,
} from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import {
  useCreateTillTenderType,
  useDeleteTillTenderType,
  useTillTenderTypes,
  useUpdateTillTenderType,
} from "@/hooks/api/use-till-tender-types";
import {
  useCreateTillTenderCategory,
  useDeleteTillTenderCategory,
  useTillTenderCategories,
  useUpdateTillTenderCategory,
} from "@/hooks/api/use-till-tender-categories";
import type { TillTenderType } from "@/services/till-tender-type-service";
import type { TillTenderCategory } from "@/services/till-tender-category-service";

/**
 * Icon resolver. The 3 seeded system categories keep their tuned icons;
 * shop-defined custom categories fall back to a neutral circle so the UI
 * doesn't lie about what kind of payment device they represent.
 */
function categoryIcon(key: string): React.ReactNode {
  switch (key) {
    case "card":
      return <CreditCardIcon className="size-4 text-sky-600" />;
    case "qr":
      return <QrCodeIcon className="size-4 text-violet-600" />;
    case "emoney":
      return <WalletIcon className="size-4 text-amber-600" />;
    default:
      return <CircleIcon className="size-4 text-muted-foreground" />;
  }
}

export interface TenderTypesTabProps {
  shopSlug: string;
}

/**
 * Per-shop tender-types admin. Categories are now CRUD-managed (system
 * seeds + shop overlay) so the list renders dynamically from
 * /tender-categories instead of a hardcoded ["card","qr","emoney"]
 * array. Shop managers can:
 *   - Add a new tender to an existing category (per-section "+ Thêm")
 *   - Create a brand-new category (header "Quản lý nhóm" dialog)
 */
export function TenderTypesTab({ shopSlug }: TenderTypesTabProps) {
  const { t } = useTranslation();
  const categoriesQuery = useTillTenderCategories(shopSlug);
  const typesQuery = useTillTenderTypes(shopSlug);
  const categories: TillTenderCategory[] = categoriesQuery.data?.data ?? [];
  const rows: TillTenderType[] = typesQuery.data?.data ?? [];

  // tender_types grouped by their category key — falls back to a synthetic
  // "_orphan" bucket when a row's category was deleted (shouldn't happen
  // because the backend blocks delete-while-in-use, but defensive UI saves
  // an inconsistent state from disappearing silently).
  const grouped = useMemo(() => {
    const m = new Map<string, TillTenderType[]>();
    for (const cat of categories) m.set(cat.key, []);
    const orphans: TillTenderType[] = [];
    for (const r of rows) {
      const bucket = m.get(r.category);
      if (bucket) bucket.push(r);
      else orphans.push(r);
    }
    for (const list of m.values()) {
      list.sort((a, b) => a.sort_order - b.sort_order);
    }
    return { byCategory: m, orphans };
  }, [categories, rows]);

  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<TillTenderType | null>(null);
  const [defaultCategory, setDefaultCategory] = useState<string>("");
  const [categoriesDialogOpen, setCategoriesDialogOpen] = useState(false);

  useEffect(() => {
    if (!defaultCategory && categories.length > 0) {
      setDefaultCategory(categories[0]!.key);
    }
  }, [defaultCategory, categories]);

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{t("settings.tender_type.title")}</CardTitle>
          <CardDescription>{t("settings.tender_type.description")}</CardDescription>
        </CardHeader>

        <CardContent className="space-y-4">
          <div className="flex flex-wrap items-center justify-end gap-2">
            <Button type="button" variant="outline" onClick={() => setCategoriesDialogOpen(true)}>
              <Settings2Icon className="mr-1.5 size-4" />
              {t("settings.tender_category.manage")}
            </Button>
            <Button
              type="button"
              disabled={categories.length === 0}
              onClick={() => {
                setEditing(null);
                if (categories[0]) setDefaultCategory(categories[0].key);
                setDialogOpen(true);
              }}
            >
              <PlusIcon className="mr-1.5 size-4" />
              {t("settings.tender_type.add")}
            </Button>
          </div>

          {typesQuery.isLoading || categoriesQuery.isLoading ? (
            <div className="flex items-center justify-center py-10">
              <Spinner className="size-5" />
            </div>
          ) : categories.length === 0 ? (
            <div className="rounded-md border border-dashed py-10 text-center text-sm text-muted-foreground">
              {t("settings.tender_category.empty_state")}
            </div>
          ) : (
            <div className="space-y-4">
              {categories.map((cat) => (
                <CategorySection
                  key={cat.id}
                  shopSlug={shopSlug}
                  category={cat}
                  rows={grouped.byCategory.get(cat.key) ?? []}
                  onEdit={(r) => {
                    setEditing(r);
                    setDefaultCategory(r.category);
                    setDialogOpen(true);
                  }}
                  onAdd={() => {
                    setEditing(null);
                    setDefaultCategory(cat.key);
                    setDialogOpen(true);
                  }}
                />
              ))}
              {grouped.orphans.length > 0 && (
                <OrphanSection shopSlug={shopSlug} rows={grouped.orphans} />
              )}
            </div>
          )}
        </CardContent>
      </Card>

      <TenderTypeDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        shopSlug={shopSlug}
        editing={editing}
        defaultCategory={defaultCategory}
        categories={categories}
      />

      <ManageCategoriesDialog
        open={categoriesDialogOpen}
        onOpenChange={setCategoriesDialogOpen}
        shopSlug={shopSlug}
        categories={categories}
      />
    </div>
  );
}

function CategorySection({
  shopSlug,
  category,
  rows,
  onEdit,
  onAdd,
}: {
  shopSlug: string;
  category: TillTenderCategory;
  rows: TillTenderType[];
  onEdit: (r: TillTenderType) => void;
  onAdd: () => void;
}) {
  const { t } = useTranslation();
  const remove = useDeleteTillTenderType(shopSlug);

  return (
    <div className="overflow-hidden rounded-md border">
      <div className="flex items-center justify-between gap-2 border-b bg-muted/40 px-3 py-2">
        <div className="flex items-center gap-2 text-[12px] font-semibold tracking-wide text-foreground uppercase">
          <span className="flex size-6 items-center justify-center rounded-full bg-background">
            {categoryIcon(category.key)}
          </span>
          {category.name}
          <span className="text-[11px] font-normal text-muted-foreground">({rows.length})</span>
          {!category.is_system && (
            <Badge variant="secondary" className="text-[10px]">
              {t("settings.tender_category.badge_custom")}
            </Badge>
          )}
        </div>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-7 gap-1 px-2 text-[12px]"
          onClick={onAdd}
        >
          <PlusIcon className="size-3.5" />
          {t("settings.tender_type.add_to_category")}
        </Button>
      </div>

      {rows.length === 0 ? (
        <div className="px-3 py-4 text-center text-[12px] text-muted-foreground">
          {t("settings.tender_type.category_empty")}
        </div>
      ) : (
        <TenderRowsTable rows={rows} onEdit={onEdit} onDelete={(id) => remove.mutate(id)} />
      )}
    </div>
  );
}

/**
 * Renders rows whose `category` key no longer matches any active row in
 * tender_categories — a safety net for inconsistent state. Read-only.
 */
function OrphanSection({ shopSlug, rows }: { shopSlug: string; rows: TillTenderType[] }) {
  const { t } = useTranslation();
  const remove = useDeleteTillTenderType(shopSlug);

  return (
    <div className="overflow-hidden rounded-md border border-dashed">
      <div className="border-b bg-amber-50/50 px-3 py-2 text-[12px] font-semibold text-amber-900 uppercase dark:bg-amber-950/30 dark:text-amber-200">
        {t("settings.tender_type.orphans_header")}
      </div>
      <TenderRowsTable
        rows={rows}
        onEdit={() => {}}
        onDelete={(id) => remove.mutate(id)}
        disableEdit
      />
    </div>
  );
}

function TenderRowsTable({
  rows,
  onEdit,
  onDelete,
  disableEdit = false,
}: {
  rows: TillTenderType[];
  onEdit: (r: TillTenderType) => void;
  onDelete: (id: string) => void;
  disableEdit?: boolean;
}) {
  const { t } = useTranslation();
  return (
    <table className="w-full text-sm">
      <thead className="bg-muted/20 text-[11px] tracking-wide text-muted-foreground uppercase">
        <tr>
          <th className="px-3 py-2 text-left font-medium">{t("settings.tender_type.col_name")}</th>
          <th className="px-3 py-2 text-left font-medium">{t("settings.tender_type.col_key")}</th>
          <th className="px-3 py-2 text-left font-medium">
            {t("settings.tender_type.col_source")}
          </th>
          <th className="px-3 py-2 text-right font-medium">
            {t("settings.tender_type.col_actions")}
          </th>
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => {
          const isGlobal = r.branch_id === null;
          return (
            <tr key={r.id} className="border-t">
              <td className="px-3 py-2.5 font-medium">{r.name}</td>
              <td className="px-3 py-2.5 font-mono text-[12px] text-muted-foreground">
                {r.tender_key}
              </td>
              <td className="px-3 py-2.5">
                {isGlobal ? (
                  <Badge variant="secondary">{t("settings.tender_type.source_global")}</Badge>
                ) : (
                  <Badge>{t("settings.tender_type.source_shop")}</Badge>
                )}
              </td>
              <td className="px-3 py-2.5 text-right">
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="size-8 px-0"
                  disabled={isGlobal || disableEdit}
                  title={
                    isGlobal
                      ? t("settings.tender_type.global_readonly_tip")
                      : t("settings.tender_type.edit")
                  }
                  onClick={() => onEdit(r)}
                >
                  <PencilIcon className="size-4" />
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="size-8 px-0 text-destructive hover:text-destructive"
                  disabled={isGlobal}
                  title={
                    isGlobal
                      ? t("settings.tender_type.global_readonly_tip")
                      : t("settings.tender_type.delete")
                  }
                  onClick={() => {
                    if (
                      window.confirm(t("settings.tender_type.delete_confirm", { name: r.name }))
                    ) {
                      onDelete(r.id);
                    }
                  }}
                >
                  <Trash2Icon className="size-4" />
                </Button>
              </td>
            </tr>
          );
        })}
      </tbody>
    </table>
  );
}

function TenderTypeDialog({
  open,
  onOpenChange,
  shopSlug,
  editing,
  defaultCategory,
  categories,
}: {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  shopSlug: string;
  editing: TillTenderType | null;
  defaultCategory: string;
  categories: TillTenderCategory[];
}) {
  const { t } = useTranslation();
  const create = useCreateTillTenderType(shopSlug);
  const update = useUpdateTillTenderType(shopSlug);

  const [name, setName] = useState("");
  const [tenderKey, setTenderKey] = useState("");
  const [category, setCategory] = useState<string>(defaultCategory);
  const [sortOrder, setSortOrder] = useState<string>("100");

  useEffect(() => {
    if (open) {
      setName(editing?.name ?? "");
      setTenderKey(editing?.tender_key ?? "");
      setCategory(editing?.category ?? defaultCategory);
      setSortOrder(editing ? String(editing.sort_order) : "100");
    }
  }, [open, editing, defaultCategory]);

  const isEdit = !!editing;
  const valid =
    name.trim().length > 0 && category.length > 0 && (isEdit || /^[a-zA-Z0-9_]+$/.test(tenderKey));
  const submitting = create.isPending || update.isPending;

  async function onSubmit() {
    try {
      if (isEdit && editing) {
        await update.mutateAsync({
          id: editing.id,
          data: {
            name: name.trim(),
            category,
            sort_order: Number(sortOrder) || 100,
          },
        });
      } else {
        await create.mutateAsync({
          tender_key: tenderKey.trim(),
          name: name.trim(),
          category,
          sort_order: Number(sortOrder) || 100,
        });
      }
      onOpenChange(false);
    } catch {
      /* toast handled by hook */
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>
            {isEdit
              ? t("settings.tender_type.dialog.title_edit")
              : t("settings.tender_type.dialog.title_create")}
          </DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="space-y-1.5">
            <Label htmlFor="tender-name" className="text-sm">
              {t("settings.tender_type.name_label")}
            </Label>
            <Input
              id="tender-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t("settings.tender_type.name_placeholder")}
              autoFocus
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="tender-key" className="text-sm">
              {t("settings.tender_type.key_label")}
            </Label>
            <Input
              id="tender-key"
              value={tenderKey}
              onChange={(e) => setTenderKey(e.target.value)}
              disabled={isEdit}
              placeholder="rakuten_pay"
              className="font-mono text-[13px]"
            />
            <p className="text-xs text-muted-foreground">
              {isEdit
                ? t("settings.tender_type.key_locked_hint")
                : t("settings.tender_type.key_hint")}
            </p>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="tender-category" className="text-sm">
              {t("settings.tender_type.category_label")}
            </Label>
            <Select value={category} onValueChange={(v) => setCategory(v)}>
              <SelectTrigger id="tender-category">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {categories.map((c) => (
                  <SelectItem key={c.id} value={c.key}>
                    {c.name}
                    {!c.is_system && (
                      <span className="ml-2 text-[10px] text-muted-foreground">
                        ({t("settings.tender_category.badge_custom")})
                      </span>
                    )}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              {t("settings.tender_type.category_hint")}
            </p>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="tender-sort" className="text-sm">
              {t("settings.tender_type.sort_label")}
            </Label>
            <Input
              id="tender-sort"
              type="number"
              inputMode="numeric"
              min="0"
              value={sortOrder}
              onChange={(e) => setSortOrder(e.target.value)}
            />
            <p className="text-xs text-muted-foreground">{t("settings.tender_type.sort_hint")}</p>
          </div>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
          >
            {t("common.cancel")}
          </Button>
          <Button type="button" onClick={onSubmit} disabled={!valid || submitting}>
            {submitting && <Spinner className="mr-2 size-3.5" />}
            {isEdit ? t("common.save") : t("common.add")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * "Quản lý nhóm" — CRUD surface for shop-defined categories. System rows
 * (is_system=true) are listed read-only at the top so shop managers see
 * they exist but can't edit/delete them.
 */
function ManageCategoriesDialog({
  open,
  onOpenChange,
  shopSlug,
  categories,
}: {
  open: boolean;
  onOpenChange: (v: boolean) => void;
  shopSlug: string;
  categories: TillTenderCategory[];
}) {
  const { t } = useTranslation();
  const create = useCreateTillTenderCategory(shopSlug);
  const update = useUpdateTillTenderCategory(shopSlug);
  const remove = useDeleteTillTenderCategory(shopSlug);

  const [newKey, setNewKey] = useState("");
  const [newName, setNewName] = useState("");
  const [newSort, setNewSort] = useState("100");

  const [editingId, setEditingId] = useState<string | null>(null);
  const [editName, setEditName] = useState("");
  const [editSort, setEditSort] = useState("100");

  useEffect(() => {
    if (!open) {
      setNewKey("");
      setNewName("");
      setNewSort("100");
      setEditingId(null);
    }
  }, [open]);

  const validNew = newName.trim().length > 0 && /^[a-z0-9_]+$/.test(newKey.trim());

  async function onCreate() {
    try {
      await create.mutateAsync({
        key: newKey.trim().toLowerCase(),
        name: newName.trim(),
        sort_order: Number(newSort) || 100,
      });
      setNewKey("");
      setNewName("");
      setNewSort("100");
    } catch {
      /* toast handled by hook */
    }
  }

  function beginEdit(c: TillTenderCategory) {
    setEditingId(c.id);
    setEditName(c.name);
    setEditSort(String(c.sort_order));
  }

  async function commitEdit(id: string) {
    try {
      await update.mutateAsync({
        id,
        data: {
          name: editName.trim(),
          sort_order: Number(editSort) || 100,
        },
      });
      setEditingId(null);
    } catch {
      /* toast handled by hook */
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{t("settings.tender_category.manage")}</DialogTitle>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="rounded-md border">
            <table className="w-full text-sm">
              <thead className="bg-muted/30 text-[11px] tracking-wide text-muted-foreground uppercase">
                <tr>
                  <th className="px-3 py-2 text-left font-medium">
                    {t("settings.tender_category.col_name")}
                  </th>
                  <th className="px-3 py-2 text-left font-medium">
                    {t("settings.tender_category.col_key")}
                  </th>
                  <th className="px-3 py-2 text-left font-medium">
                    {t("settings.tender_category.col_sort")}
                  </th>
                  <th className="px-3 py-2 text-right font-medium">
                    {t("settings.tender_category.col_actions")}
                  </th>
                </tr>
              </thead>
              <tbody>
                {categories.map((c) => {
                  const isEditing = editingId === c.id;
                  const readOnly = c.is_system;
                  return (
                    <tr key={c.id} className="border-t">
                      <td className="px-3 py-2 font-medium">
                        {isEditing ? (
                          <Input
                            value={editName}
                            onChange={(e) => setEditName(e.target.value)}
                            className="h-8"
                          />
                        ) : (
                          <span className="flex items-center gap-2">
                            {c.name}
                            {readOnly && (
                              <Badge variant="secondary" className="text-[10px]">
                                {t("settings.tender_category.badge_system")}
                              </Badge>
                            )}
                          </span>
                        )}
                      </td>
                      <td className="px-3 py-2 font-mono text-[12px] text-muted-foreground">
                        {c.key}
                      </td>
                      <td className="px-3 py-2">
                        {isEditing ? (
                          <Input
                            type="number"
                            min="0"
                            value={editSort}
                            onChange={(e) => setEditSort(e.target.value)}
                            className="h-8 w-20"
                          />
                        ) : (
                          c.sort_order
                        )}
                      </td>
                      <td className="px-3 py-2 text-right">
                        {isEditing ? (
                          <>
                            <Button
                              size="sm"
                              variant="ghost"
                              className="h-8"
                              onClick={() => setEditingId(null)}
                              disabled={update.isPending}
                            >
                              {t("common.cancel")}
                            </Button>
                            <Button
                              size="sm"
                              className="ml-1 h-8"
                              onClick={() => commitEdit(c.id)}
                              disabled={!editName.trim() || update.isPending}
                            >
                              {update.isPending && <Spinner className="mr-1.5 size-3" />}
                              {t("common.save")}
                            </Button>
                          </>
                        ) : (
                          <>
                            <Button
                              size="sm"
                              variant="ghost"
                              className="size-8 px-0"
                              disabled={readOnly}
                              title={
                                readOnly
                                  ? t("settings.tender_category.readonly_tip")
                                  : t("common.edit")
                              }
                              onClick={() => beginEdit(c)}
                            >
                              <PencilIcon className="size-4" />
                            </Button>
                            <Button
                              size="sm"
                              variant="ghost"
                              className="size-8 px-0 text-destructive hover:text-destructive"
                              disabled={readOnly || remove.isPending}
                              title={
                                readOnly
                                  ? t("settings.tender_category.readonly_tip")
                                  : t("common.delete")
                              }
                              onClick={() => {
                                if (
                                  window.confirm(
                                    t("settings.tender_category.delete_confirm", {
                                      name: c.name,
                                    })
                                  )
                                ) {
                                  remove.mutate(c.id);
                                }
                              }}
                            >
                              <Trash2Icon className="size-4" />
                            </Button>
                          </>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          <div className="rounded-md border bg-muted/20 p-3">
            <p className="mb-2 text-[12px] font-semibold tracking-wide text-muted-foreground uppercase">
              {t("settings.tender_category.add_new_section")}
            </p>
            <div className="grid grid-cols-12 gap-2">
              <div className="col-span-12 sm:col-span-5">
                <Label htmlFor="new-cat-name" className="text-[11px]">
                  {t("settings.tender_category.name_label")}
                </Label>
                <Input
                  id="new-cat-name"
                  value={newName}
                  onChange={(e) => setNewName(e.target.value)}
                  placeholder={t("settings.tender_category.name_placeholder")}
                  className="h-9"
                />
              </div>
              <div className="col-span-7 sm:col-span-4">
                <Label htmlFor="new-cat-key" className="text-[11px]">
                  {t("settings.tender_category.key_label")}
                </Label>
                <Input
                  id="new-cat-key"
                  value={newKey}
                  onChange={(e) =>
                    setNewKey(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ""))
                  }
                  placeholder="voucher"
                  className="h-9 font-mono text-[13px]"
                />
              </div>
              <div className="col-span-5 sm:col-span-3">
                <Label htmlFor="new-cat-sort" className="text-[11px]">
                  {t("settings.tender_category.sort_label")}
                </Label>
                <Input
                  id="new-cat-sort"
                  type="number"
                  min="0"
                  value={newSort}
                  onChange={(e) => setNewSort(e.target.value)}
                  className="h-9"
                />
              </div>
            </div>
            <p className="mt-2 text-[11px] text-muted-foreground">
              {t("settings.tender_category.add_hint")}
            </p>
            <div className="mt-2 flex justify-end">
              <Button
                type="button"
                size="sm"
                onClick={onCreate}
                disabled={!validNew || create.isPending}
              >
                {create.isPending && <Spinner className="mr-1.5 size-3" />}
                <PlusIcon className="mr-1.5 size-4" />
                {t("settings.tender_category.add_button")}
              </Button>
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {t("common.close")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
