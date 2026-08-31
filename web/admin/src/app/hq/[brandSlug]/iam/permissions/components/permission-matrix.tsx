"use client";

import { useCallback, useEffect, useState } from "react";
import { Search } from "lucide-react";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Checkbox,
  Input,
  Spinner,
} from "@godxjp/ui";
import { toast } from "sonner";
import { useTranslation } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import type { IamRole, IamPermissionGroup } from "@/hooks/api/use-iam";
import { useUpdateRolePermissions } from "@/hooks/api/use-iam";
import { UnsavedWarningBar } from "./unsaved-warning-bar";

export interface PermissionMatrixProps {
  brandSlug: string;
  roles: IamRole[];
  permissionGroups: IamPermissionGroup[];
  isLoading: boolean;
}

export function PermissionMatrix({
  brandSlug,
  roles,
  permissionGroups,
  isLoading,
}: PermissionMatrixProps) {
  const { t } = useTranslation();
  const updatePermissions = useUpdateRolePermissions(brandSlug);

  // Roles ordered by level desc (highest authority first)
  const sortedRoles = [...roles].sort((a, b) => b.level - a.level);

  // dirty state: Map<roleSlug, Set<permissionSlug>>
  const [dirty, setDirty] = useState<Map<string, Set<string>>>(new Map());
  const [isSaving, setIsSaving] = useState(false);

  // Column hover state for visual column highlight
  const [hoveredRoleSlug, setHoveredRoleSlug] = useState<string | null>(null);

  // Client-side row filter (TC-IAMP-106) — matches permission name or slug.
  // Filtering only affects display; dirty/save state stays over the full set.
  const [search, setSearch] = useState("");

  // Discard confirmation dialog (TC-IAMP-108) — guard the destructive reset.
  const [confirmDiscard, setConfirmDiscard] = useState(false);

  // Initialize dirty state from roles data
  useEffect(() => {
    if (roles.length === 0) return;
    const initial = new Map<string, Set<string>>();
    for (const role of roles) {
      initial.set(role.slug, new Set(role.permissions));
    }
    setDirty(initial);
  }, [roles]);

  // isDirty: compare dirty to original roles.permissions
  const isDirty = useCallback((): boolean => {
    for (const role of roles) {
      const original = new Set(role.permissions);
      const current = dirty.get(role.slug) ?? new Set<string>();
      if (original.size !== current.size) return true;
      for (const p of original) {
        if (!current.has(p)) return true;
      }
    }
    return false;
  }, [dirty, roles]);

  // Warn on browser-level navigation (refresh / close / external link) while
  // there are unsaved permission changes (TC-IAMP-108).
  useEffect(() => {
    if (!isDirty() || isSaving) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      // Legacy Chrome/Edge: returnValue must be set to trigger the prompt.
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [isDirty, isSaving]);

  function togglePermission(roleSlug: string, permSlug: string) {
    setDirty((prev) => {
      const next = new Map(prev);
      const set = new Set(next.get(roleSlug) ?? []);
      if (set.has(permSlug)) {
        set.delete(permSlug);
      } else {
        set.add(permSlug);
      }
      next.set(roleSlug, set);
      return next;
    });
  }

  // Toggle ALL permissions in a group for a specific role
  function toggleGroupRole(roleSlug: string, groupPermSlugs: string[], targetChecked: boolean) {
    setDirty((prev) => {
      const next = new Map(prev);
      const set = new Set(next.get(roleSlug) ?? []);
      if (targetChecked) {
        groupPermSlugs.forEach((p) => set.add(p));
      } else {
        groupPermSlugs.forEach((p) => set.delete(p));
      }
      next.set(roleSlug, set);
      return next;
    });
  }

  // Check if a specific role's permissions differ from the server state.
  function isRoleDirty(role: IamRole): boolean {
    const original = new Set(role.permissions);
    const current = dirty.get(role.slug) ?? new Set<string>();
    if (original.size !== current.size) return true;
    for (const p of original) {
      if (!current.has(p)) return true;
    }
    return false;
  }

  async function handleSave() {
    setIsSaving(true);
    const updates = roles
      .filter((role) => role.is_editable && isRoleDirty(role))
      .map((role) => ({
        roleId: role.id,
        permission_slugs: Array.from(dirty.get(role.slug) ?? []),
      }));

    try {
      await Promise.all(updates.map((u) => updatePermissions.mutateAsync(u)));
      handleDiscard();
      toast.success(t("iam.permissions.saved"));
    } catch (e) {
      // Editing a system template forks it server-side (copy-on-write); the only
      // expected failures are the self-lockout guard (422) and a permission gate (403).
      if (
        e instanceof ApiError &&
        e.status === 422 &&
        (e.body as { code?: string }).code === "IAM_LAST_ADMIN_LOCKOUT"
      ) {
        toast.error(t("iam.permissions.lockout_error"));
      } else if (e instanceof ApiError && e.status === 403) {
        toast.error(t("iam.permissions.forbidden"));
      } else {
        toast.error(t("iam.permissions.save_error"));
      }
    } finally {
      setIsSaving(false);
    }
  }

  function handleDiscard() {
    const reset = new Map<string, Set<string>>();
    for (const role of roles) {
      reset.set(role.slug, new Set(role.permissions));
    }
    setDirty(reset);
  }

  if (isLoading) {
    return (
      <div data-slot="permission-matrix" className="flex items-center justify-center py-16">
        <Spinner className="size-6 text-muted-foreground" />
      </div>
    );
  }

  if (roles.length === 0 || permissionGroups.length === 0) {
    return (
      <div
        data-slot="permission-matrix"
        className="flex items-center justify-center py-16 text-sm text-muted-foreground"
      >
        {t("common.no_data")}
      </div>
    );
  }

  const dirtyFlag = isDirty();

  // Filter permission rows by name / slug; drop groups left empty.
  const query = search.trim().toLowerCase();
  const filteredGroups = query
    ? permissionGroups
        .map((group) => ({
          ...group,
          permissions: group.permissions.filter(
            (p) => p.name.toLowerCase().includes(query) || p.slug.toLowerCase().includes(query)
          ),
        }))
        .filter((group) => group.permissions.length > 0)
    : permissionGroups;

  return (
    <>
      <div className="relative mb-3 max-w-sm">
        <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          className="pl-8"
          placeholder={t("iam.permissions.search_placeholder")}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      <div data-slot="permission-matrix" className="overflow-x-auto pb-20">
        {filteredGroups.length === 0 ? (
          <div className="py-16 text-center text-sm text-muted-foreground">
            {t("common.no_results")}
          </div>
        ) : (
          <table className="w-full border-collapse text-sm">
            <thead className="sticky top-0 z-20 bg-background shadow-[0_1px_0_0_hsl(var(--border))]">
              <tr>
                {/* sticky header for group/permission column */}
                <th className="sticky left-0 z-30 bg-background py-2 pr-4 text-left text-xs font-semibold text-muted-foreground">
                  {t("iam.members.col.roles")}
                </th>
                {sortedRoles.map((role) => (
                  <th
                    key={role.slug}
                    className="min-w-[120px] px-3 py-2 text-center text-xs font-semibold"
                  >
                    <div className="flex flex-col items-center gap-0.5">
                      <span>{role.name}</span>
                      {role.is_customized && (
                        <span className="text-[10px] text-primary">
                          {t("iam.permissions.badge_custom")}
                        </span>
                      )}
                      {role.is_system && (
                        <span className="text-[10px] text-muted-foreground">
                          {t("iam.permissions.badge_system")}
                        </span>
                      )}
                      {!role.is_editable && (
                        <span className="text-[10px] text-muted-foreground">
                          {t("iam.permissions.locked")}
                        </span>
                      )}
                    </div>
                  </th>
                ))}
              </tr>
            </thead>
            {filteredGroups.map((group) => {
              const groupPermSlugs = group.permissions.map((p) => p.slug);

              return (
                <tbody key={group.group}>
                  {/* Group header row with per-role select-all checkboxes */}
                  <tr className="border-b bg-muted/40">
                    <td className="sticky left-0 z-10 bg-muted/40 py-1.5 pl-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                      {group.group}
                    </td>
                    {sortedRoles.map((role) => {
                      const locked = !role.is_editable;
                      const rolePerms = dirty.get(role.slug) ?? new Set<string>();
                      const checkedCount = groupPermSlugs.filter((p) => rolePerms.has(p)).length;
                      const allChecked = checkedCount === groupPermSlugs.length;
                      const someChecked = checkedCount > 0 && checkedCount < groupPermSlugs.length;

                      return (
                        <td key={role.slug} className="px-3 py-1.5 text-center">
                          <Checkbox
                            checked={
                              allChecked ? true : someChecked ? "indeterminate" : false
                            }
                            disabled={locked}
                            aria-label={`${role.name} — ${group.group} all`}
                            onCheckedChange={(checked) => {
                              if (locked) return;
                              toggleGroupRole(role.slug, groupPermSlugs, checked === true);
                            }}
                          />
                        </td>
                      );
                    })}
                  </tr>

                  {/* Permission rows */}
                  {group.permissions.map((perm) => (
                    <tr key={perm.slug} className="border-b hover:bg-muted/20">
                      {/* Sticky left cell — permission name + slug */}
                      <td className="sticky left-0 z-10 bg-background py-2 pr-4 text-xs">
                        {perm.name}
                        <span className="ml-2 font-mono text-[10px] text-muted-foreground">
                          {perm.slug}
                        </span>
                      </td>

                      {sortedRoles.map((role) => {
                        const locked = !role.is_editable;
                        const checked = dirty.get(role.slug)?.has(perm.slug) ?? false;
                        const isHovered = hoveredRoleSlug === role.slug;

                        return (
                          <td
                            key={role.slug}
                            className={`px-3 py-2 text-center transition-colors ${isHovered && !locked ? "bg-primary/5" : ""}`}
                            onMouseEnter={() => !locked && setHoveredRoleSlug(role.slug)}
                            onMouseLeave={() => setHoveredRoleSlug(null)}
                          >
                            <Checkbox
                              checked={checked}
                              disabled={locked}
                              onCheckedChange={() =>
                                !locked && togglePermission(role.slug, perm.slug)
                              }
                              aria-label={`${role.name} - ${perm.name}`}
                            />
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              );
            })}
          </table>
        )}
      </div>

      <UnsavedWarningBar
        isDirty={dirtyFlag}
        isSaving={isSaving}
        onSave={handleSave}
        onDiscard={() => setConfirmDiscard(true)}
      />

      {/* Confirm before discarding unsaved permission changes (TC-IAMP-108). */}
      <AlertDialog open={confirmDiscard} onOpenChange={setConfirmDiscard}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("iam.permissions.discard_confirm_title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("iam.permissions.discard_confirm_desc")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={isSaving}>
              {t("iam.permissions.continue_editing")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                handleDiscard();
                setConfirmDiscard(false);
              }}
            >
              {t("iam.permissions.discard")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
