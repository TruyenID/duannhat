"use client";

import { useEffect, useState } from "react";
import Image from "next/image";
import { notFound, useParams } from "next/navigation";
import Link from "next/link";
import { ApiError } from "@/lib/api";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  Spinner,
  Button,
  Badge,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@godxjp/ui";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@godxjp/ui";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@godxjp/ui";
import {
  ChevronLeft,
  Trash2,
  Plus,
  Store,
  Ban,
  CircleCheck,
  KeyRound,
  History,
  Check,
} from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import { formatDateTime } from "@/lib/date";
import type { IamAssignment, IamMemberPermissionEntry } from "@/hooks/api/use-iam";
import {
  useIamMember,
  useIamMemberPermissions,
  useIamMemberAudit,
  useIamRoles,
  useIamBranches,
  useAssignRole,
  useRevokeRole,
  useSetMemberActive,
  useResetMemberPassword,
} from "@/hooks/api/use-iam";

// =========================================================================
//  RoleLevelBadge — colored by level
// =========================================================================

function RoleLevelBadge({ level, name }: { level: number; name: string }) {
  if (level >= 100) return <Badge variant="destructive">{name}</Badge>;
  if (level >= 80)
    return <Badge className="bg-orange-100 text-orange-700 hover:bg-orange-100">{name}</Badge>;
  if (level >= 60)
    return <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100">{name}</Badge>;
  if (level >= 30)
    return <Badge className="bg-blue-100 text-blue-700 hover:bg-blue-100">{name}</Badge>;
  return <Badge variant="secondary">{name}</Badge>;
}

// =========================================================================
//  PermissionsTab — effective permissions grouped by role assignment
// =========================================================================

function PermissionsTab({ brandSlug, userId }: { brandSlug: string; userId: string }) {
  const { t } = useTranslation();
  const { data: branchesData } = useIamBranches(brandSlug);
  const branches = branchesData?.data ?? [];

  const { data, isLoading, isError } = useIamMemberPermissions(brandSlug, userId);
  const entries: IamMemberPermissionEntry[] = data?.data ?? [];

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-16">
        <Spinner className="size-5 text-muted-foreground" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="py-10 text-center text-sm text-muted-foreground">
        {t("common.error_loading")}
      </div>
    );
  }

  if (entries.length === 0) {
    return (
      <div className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
        {t("iam.members.detail.no_assignments")}
      </div>
    );
  }

  const allPermissions = Array.from(new Set(entries.flatMap((e) => e.permissions))).sort();

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">{t("iam.members.detail.permissions_desc")}</p>

      <div className="overflow-x-auto rounded-lg border">
        <Table>
          <TableHeader>
            {/* Row 1 — role badges */}
            <TableRow className="border-b-0">
              <TableHead className="min-w-[220px] pb-1">
                {t("iam.members.detail.col.permission")}
              </TableHead>
              {entries.map((entry) => (
                <TableHead
                  key={`role:${entry.role_slug}:${entry.branch_id ?? "org"}`}
                  className="pb-1 text-center whitespace-nowrap"
                >
                  <RoleLevelBadge level={entry.role_level} name={entry.role_name} />
                </TableHead>
              ))}
            </TableRow>
            {/* Row 2 — scope labels */}
            <TableRow>
              <TableHead />
              {entries.map((entry) => {
                const scopeLabel = entry.branch_id
                  ? (branches.find((b) => b.id === entry.branch_id)?.name ?? entry.branch_id)
                  : t("iam.assign.scope_org_badge");
                return (
                  <TableHead
                    key={`scope:${entry.role_slug}:${entry.branch_id ?? "org"}`}
                    className="pt-0 text-center text-[11px] font-normal whitespace-nowrap text-muted-foreground"
                  >
                    {scopeLabel}
                  </TableHead>
                );
              })}
            </TableRow>
          </TableHeader>
          <TableBody>
            {allPermissions.map((slug) => (
              <TableRow key={slug}>
                <TableCell className="font-mono text-xs">{slug}</TableCell>
                {entries.map((entry) => (
                  <TableCell
                    key={`${entry.role_slug}:${entry.branch_id ?? "org"}`}
                    className="text-center"
                  >
                    {entry.permissions.includes(slug) ? (
                      <Check className="mx-auto h-4 w-4 text-emerald-600" aria-label="granted" />
                    ) : (
                      <span className="text-muted-foreground/30">—</span>
                    )}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}

// =========================================================================
//  RolesTab — AWS Permissions tab equivalent
// =========================================================================

type AssignScope = "org" | "branch";

function RolesTab({
  brandSlug,
  userId,
  assignments,
}: {
  brandSlug: string;
  userId: string;
  assignments: IamAssignment[];
}) {
  const { t } = useTranslation();
  const { data: rolesData } = useIamRoles(brandSlug);
  const { data: branchesData } = useIamBranches(brandSlug);

  const roles = rolesData?.data ?? [];
  const branches = branchesData?.data ?? [];
  const sortedRoles = [...roles].sort((a, b) => b.level - a.level);

  const assignRole = useAssignRole(brandSlug);
  const revokeRole = useRevokeRole(brandSlug);

  // Add-role form
  const [showForm, setShowForm] = useState(false);
  const [selectedRoleSlug, setSelectedRoleSlug] = useState("");
  const [assignScope, setAssignScope] = useState<AssignScope>("org");
  const [selectedBranchId, setSelectedBranchId] = useState("");

  // Revoke confirm
  const [revokeTarget, setRevokeTarget] = useState<IamAssignment | null>(null);

  // Unsaved-changes guard for the add-role form (TC-MEM-DET8). The form is
  // "dirty" once the admin has picked anything beyond the default org scope.
  const [confirmCancel, setConfirmCancel] = useState(false);
  const isFormDirty =
    showForm && (selectedRoleSlug !== "" || selectedBranchId !== "" || assignScope !== "org");

  // Warn on browser-level navigation (refresh / close / external link) while
  // there is an unsaved role selection.
  useEffect(() => {
    if (!isFormDirty) return;
    const handler = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [isFormDirty]);

  function resetForm() {
    setSelectedRoleSlug("");
    setSelectedBranchId("");
    setAssignScope("org");
    setShowForm(false);
  }

  // Close the form, confirming first if there is an unsaved selection.
  function requestCloseForm() {
    if (isFormDirty) {
      setConfirmCancel(true);
    } else {
      resetForm();
    }
  }

  async function handleAssign() {
    if (!selectedRoleSlug) return;
    if (assignScope === "branch" && !selectedBranchId) return;
    await assignRole.mutateAsync({
      userId,
      role_slug: selectedRoleSlug,
      branch_id: assignScope === "branch" ? selectedBranchId : null,
    });
    setSelectedRoleSlug("");
    setSelectedBranchId("");
    setAssignScope("org");
    setShowForm(false);
  }

  async function handleRevoke() {
    if (!revokeTarget) return;
    await revokeRole.mutateAsync({
      userId,
      roleSlug: revokeTarget.role_slug,
      branchId: revokeTarget.branch_id,
    });
    setRevokeTarget(null);
  }

  const canAssign = !!selectedRoleSlug && (assignScope === "org" || !!selectedBranchId);

  return (
    <div className="space-y-4">
      {/* Header row: section title + Add button */}
      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">{t("iam.members.detail.assignments")}</p>
        <Button
          size="sm"
          variant="outline"
          className="h-7 gap-1 text-xs"
          onClick={() => (showForm ? requestCloseForm() : setShowForm(true))}
        >
          <Plus className="size-3" />
          {t("iam.members.detail.add_role")}
        </Button>
      </div>

      {/* Inline add-role form (collapsed by default) */}
      {showForm && (
        <div className="rounded-lg border bg-muted/30 p-4">
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <Select value={selectedRoleSlug} onValueChange={setSelectedRoleSlug}>
              <SelectTrigger className="h-8 text-xs">
                <SelectValue placeholder={t("iam.assign.select_role")} />
              </SelectTrigger>
              <SelectContent>
                {sortedRoles.map((r) => (
                  <SelectItem key={r.slug} value={r.slug} className="text-xs">
                    {r.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Select
              value={assignScope}
              onValueChange={(v) => {
                setAssignScope(v as AssignScope);
                setSelectedBranchId("");
              }}
            >
              <SelectTrigger className="h-8 text-xs">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="org" className="text-xs">
                  {t("iam.assign.scope_org")}
                </SelectItem>
                <SelectItem value="branch" className="text-xs">
                  {t("iam.assign.scope_branch")}
                </SelectItem>
              </SelectContent>
            </Select>

            {assignScope === "branch" && (
              <Select value={selectedBranchId} onValueChange={setSelectedBranchId}>
                <SelectTrigger className="h-8 text-xs sm:col-span-2">
                  <SelectValue placeholder={t("iam.assign.select_branch")} />
                </SelectTrigger>
                <SelectContent>
                  {branches.map((b) => (
                    <SelectItem key={b.id} value={b.id} className="text-xs">
                      {b.name}
                      {b.is_headquarters && (
                        <span className="ml-1 text-muted-foreground">(HQ)</span>
                      )}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          </div>

          <div className="mt-3 flex gap-2">
            <Button
              size="sm"
              className="h-8 text-xs"
              disabled={!canAssign || assignRole.isPending}
              onClick={handleAssign}
            >
              {assignRole.isPending ? <Spinner className="mr-1 size-3.5" /> : null}
              {t("iam.assign.confirm_add")}
            </Button>
            <Button size="sm" variant="ghost" className="h-8 text-xs" onClick={requestCloseForm}>
              {t("common.cancel")}
            </Button>
          </div>
        </div>
      )}

      {/* Assignments table */}
      {assignments.length === 0 ? (
        <div className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
          {t("iam.members.detail.no_assignments")}
        </div>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t("iam.members.detail.col.role")}</TableHead>
              <TableHead>{t("iam.members.detail.col.scope")}</TableHead>
              <TableHead>{t("iam.members.detail.col.type")}</TableHead>
              <TableHead className="w-24 text-right">
                {t("iam.members.detail.col.actions")}
              </TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {assignments.map((a) => {
              const branchName = a.branch_id
                ? (branches.find((b) => b.id === a.branch_id)?.name ?? a.branch_id)
                : null;

              return (
                <TableRow key={`${a.role_slug}:${a.branch_id ?? "org"}`}>
                  <TableCell>
                    <RoleLevelBadge level={a.role_level} name={a.role_name} />
                  </TableCell>
                  <TableCell className="text-sm">
                    {branchName ?? (
                      <span className="text-muted-foreground">
                        {t("iam.assign.scope_org_badge")}
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="text-xs text-muted-foreground">
                    {a.branch_id
                      ? t("iam.members.detail.type_branch")
                      : t("iam.members.detail.type_org")}
                  </TableCell>
                  <TableCell className="text-right">
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-7 gap-1 text-xs text-destructive hover:bg-destructive/10 hover:text-destructive"
                      onClick={() => setRevokeTarget(a)}
                    >
                      <Trash2 className="size-3" />
                      {t("iam.members.detail.revoke")}
                    </Button>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      )}

      {/* Revoke confirm dialog */}
      <Dialog
        open={!!revokeTarget}
        onOpenChange={(open) => {
          if (!open) setRevokeTarget(null);
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t("iam.assign.confirm_revoke")}</DialogTitle>
            <DialogDescription>{t("iam.assign.revoke_desc")}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setRevokeTarget(null)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              disabled={revokeRole.isPending}
              onClick={handleRevoke}
            >
              {revokeRole.isPending ? <Spinner className="mr-1 size-3.5" /> : null}
              {t("iam.members.detail.revoke")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Unsaved add-role form guard (TC-MEM-DET8) */}
      <AlertDialog open={confirmCancel} onOpenChange={setConfirmCancel}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("iam.members.detail.discard_form_title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("iam.members.detail.discard_form_desc")}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t("iam.members.detail.keep_editing")}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                resetForm();
                setConfirmCancel(false);
              }}
            >
              {t("iam.members.detail.discard")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

// =========================================================================
//  ShopsTab — shops (branches) the member is assigned to (TC-MEM-DET3)
// =========================================================================

function ShopsTab({ brandSlug, assignments }: { brandSlug: string; assignments: IamAssignment[] }) {
  const { t } = useTranslation();
  const { data: branchesData } = useIamBranches(brandSlug);
  const branches = branchesData?.data ?? [];

  // Org-wide roles grant access to every shop; branch-scoped roles limit the
  // member to specific shops. Group the branch-scoped ones by shop.
  const orgWideRoles = assignments.filter((a) => a.branch_id === null);
  const byBranch = new Map<string, IamAssignment[]>();
  for (const a of assignments) {
    if (!a.branch_id) continue;
    byBranch.set(a.branch_id, [...(byBranch.get(a.branch_id) ?? []), a]);
  }

  const hasOrgWide = orgWideRoles.length > 0;
  const hasBranchScoped = byBranch.size > 0;

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">{t("iam.members.detail.shops_desc")}</p>

      {hasOrgWide && (
        <div className="flex items-start gap-2 rounded-lg border bg-muted/30 p-3 text-sm">
          <Store className="mt-0.5 size-4 text-muted-foreground" />
          <div>
            <p className="font-medium">{t("iam.members.detail.shops_all_access")}</p>
            <p className="text-xs text-muted-foreground">
              {orgWideRoles.map((r) => r.role_name).join(", ")}
            </p>
          </div>
        </div>
      )}

      {hasBranchScoped ? (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>{t("iam.members.detail.col.shop")}</TableHead>
              <TableHead>{t("iam.members.detail.col.role")}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {Array.from(byBranch.entries()).map(([branchId, roles]) => {
              const branch = branches.find((b) => b.id === branchId);
              return (
                <TableRow key={branchId}>
                  <TableCell className="text-sm font-medium">
                    {branch?.name ?? branchId}
                    {branch?.is_headquarters && (
                      <span className="ml-1 text-xs text-muted-foreground">(HQ)</span>
                    )}
                  </TableCell>
                  <TableCell>
                    <div className="flex flex-wrap gap-1">
                      {roles.map((r) => (
                        <RoleLevelBadge key={r.role_slug} level={r.role_level} name={r.role_name} />
                      ))}
                    </div>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      ) : !hasOrgWide ? (
        <div className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
          {t("iam.members.detail.shops_empty")}
        </div>
      ) : null}
    </div>
  );
}

// =========================================================================
//  AuditTab — IAM action history for the member (TC-MEM-DET7)
// =========================================================================

function AuditTab({ brandSlug, userId }: { brandSlug: string; userId: string }) {
  const { t, locale } = useTranslation();
  const { data: branchesData } = useIamBranches(brandSlug);
  const branches = branchesData?.data ?? [];

  const { data, isLoading, isError } = useIamMemberAudit(brandSlug, userId);
  const entries = data?.data ?? [];

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-16">
        <Spinner className="size-5 text-muted-foreground" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="py-10 text-center text-sm text-muted-foreground">
        {t("common.error_loading")}
      </div>
    );
  }

  if (entries.length === 0) {
    return (
      <div className="rounded-lg border border-dashed px-4 py-10 text-center text-sm text-muted-foreground">
        {t("iam.members.detail.audit_empty")}
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">{t("iam.members.detail.audit_desc")}</p>
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>{t("iam.members.detail.audit_col.action")}</TableHead>
            <TableHead>{t("iam.members.detail.audit_col.detail")}</TableHead>
            <TableHead>{t("iam.members.detail.audit_col.actor")}</TableHead>
            <TableHead className="text-right">{t("iam.members.detail.audit_col.time")}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {entries.map((e) => {
            const roleName =
              typeof e.metadata?.role_name === "string" ? e.metadata.role_name : null;
            const branchId =
              typeof e.metadata?.branch_id === "string" ? e.metadata.branch_id : null;
            const branchName = branchId
              ? (branches.find((b) => b.id === branchId)?.name ?? branchId)
              : null;
            const detail = [roleName, branchName].filter(Boolean).join(" · ");
            // action arrives as "iam.role_assigned" → key suffix "role_assigned".
            const actionKey = e.action.replace(/^iam\./, "");

            return (
              <TableRow key={e.id}>
                <TableCell className="text-sm font-medium">
                  {t(`iam.members.detail.audit_action.${actionKey}`)}
                </TableCell>
                <TableCell className="text-sm text-muted-foreground">{detail || "—"}</TableCell>
                <TableCell className="text-sm">{e.actor_name ?? "—"}</TableCell>
                <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                  {formatDateTime(e.created_at, locale)}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </div>
  );
}

// =========================================================================
//  MemberDetailPage
// =========================================================================

export default function MemberDetailPage() {
  const { t } = useTranslation();
  const params = useParams<{ brandSlug: string; userId: string }>();
  const { brandSlug, userId } = params;

  const {
    data: memberData,
    isLoading,
    isError,
    error,
    isFetching,
    refetch,
  } = useIamMember(brandSlug, userId);

  const setActive = useSetMemberActive(brandSlug);
  const resetPassword = useResetMemberPassword(brandSlug);
  const [confirmDeactivate, setConfirmDeactivate] = useState(false);
  const [confirmReset, setConfirmReset] = useState(false);

  const member = memberData?.data ?? null;
  const initial = member?.name.trim().charAt(0).toUpperCase() ?? "?";

  if (isLoading) {
    return (
      <>
        <PageHeader title="..." onRefresh={refetch} isRefreshing={isFetching} />
        <PageContent>
          <div className="flex items-center justify-center py-16">
            <Spinner className="size-6 text-muted-foreground" />
          </div>
        </PageContent>
      </>
    );
  }

  if (isError || !member) {
    // A non-existent user id, or a user who isn't a member of this org, comes
    // back 404 (403 for forbidden) — render the not-found boundary instead of a
    // generic error so a bad URL doesn't crash or look broken (TC-MEM-DET6).
    const status = error instanceof ApiError ? error.status : undefined;
    if (status === 404 || status === 403) notFound();

    return (
      <>
        <PageHeader title="—" />
        <PageContent>
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-sm text-muted-foreground">
            <p>{t("common.error_loading")}</p>
            <Button variant="outline" size="sm" onClick={() => refetch()}>
              {t("common.retry")}
            </Button>
          </div>
        </PageContent>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title={member.name}
        description={member.email}
        onRefresh={refetch}
        isRefreshing={isFetching}
      />

      <PageContent>
        <div data-slot="member-detail-page" className="space-y-6">
          {/* Back breadcrumb */}
          <Link
            href={`/hq/${brandSlug}/iam/members`}
            className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
          >
            <ChevronLeft className="size-3" />
            {t("iam.members.detail.back")}
          </Link>

          {/* Profile summary card */}
          <div className="flex items-center gap-4 rounded-lg border p-4">
            {member.avatar_url ? (
              <Image
                src={member.avatar_url}
                alt={member.name}
                width={56}
                height={56}
                className="size-14 rounded-full object-cover"
              />
            ) : (
              <span className="inline-flex size-14 items-center justify-center rounded-full bg-muted text-xl font-semibold text-muted-foreground">
                {initial}
              </span>
            )}
            <div className="space-y-0.5">
              <div className="flex items-center gap-2">
                <p className="text-base font-semibold">{member.name}</p>
                {member.is_active ? (
                  <Badge className="bg-emerald-100 text-emerald-700 hover:bg-emerald-100">
                    {t("iam.members.detail.status_active")}
                  </Badge>
                ) : (
                  <Badge variant="secondary">{t("iam.members.detail.status_inactive")}</Badge>
                )}
              </div>
              <p className="text-sm text-muted-foreground">{member.email}</p>
              <p className="text-xs text-muted-foreground">
                {member.assignments.length > 0
                  ? t("iam.members.total", { count: member.assignments.length })
                  : t("iam.members.detail.no_assignments")}
              </p>
            </div>

            {/* Member actions (TC-MEM-DET4 / DET5) */}
            <div className="ml-auto flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                className="gap-1"
                disabled={resetPassword.isPending}
                onClick={() => setConfirmReset(true)}
              >
                {resetPassword.isPending ? (
                  <Spinner className="size-3.5" />
                ) : (
                  <KeyRound className="size-3.5" />
                )}
                {t("iam.members.detail.reset_password")}
              </Button>
              {member.is_active ? (
                <Button
                  variant="outline"
                  size="sm"
                  className="gap-1 text-destructive hover:bg-destructive/10 hover:text-destructive"
                  disabled={setActive.isPending}
                  onClick={() => setConfirmDeactivate(true)}
                >
                  <Ban className="size-3.5" />
                  {t("iam.members.detail.deactivate")}
                </Button>
              ) : (
                <Button
                  variant="outline"
                  size="sm"
                  className="gap-1"
                  disabled={setActive.isPending}
                  onClick={() => setActive.mutate({ userId, active: true })}
                >
                  {setActive.isPending ? (
                    <Spinner className="size-3.5" />
                  ) : (
                    <CircleCheck className="size-3.5" />
                  )}
                  {t("iam.members.detail.activate")}
                </Button>
              )}
            </div>
          </div>

          {/* Tabs */}
          <Tabs defaultValue="roles">
            <TabsList>
              <TabsTrigger value="roles">{t("iam.members.detail.tab_roles")}</TabsTrigger>
              <TabsTrigger value="shops">{t("iam.members.detail.tab_shops")}</TabsTrigger>
              <TabsTrigger value="permissions">
                {t("iam.members.detail.tab_permissions")}
              </TabsTrigger>
              <TabsTrigger value="audit" className="gap-1">
                <History className="size-3.5" />
                {t("iam.members.detail.tab_audit")}
              </TabsTrigger>
            </TabsList>

            <TabsContent value="roles" className="mt-4">
              <RolesTab brandSlug={brandSlug} userId={userId} assignments={member.assignments} />
            </TabsContent>

            <TabsContent value="shops" className="mt-4">
              <ShopsTab brandSlug={brandSlug} assignments={member.assignments} />
            </TabsContent>

            <TabsContent value="permissions" className="mt-4">
              <PermissionsTab brandSlug={brandSlug} userId={userId} />
            </TabsContent>

            <TabsContent value="audit" className="mt-4">
              <AuditTab brandSlug={brandSlug} userId={userId} />
            </TabsContent>
          </Tabs>
        </div>
      </PageContent>

      {/* Reset-password confirmation (TC-MEM-DET4) */}
      <AlertDialog open={confirmReset} onOpenChange={setConfirmReset}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {t("iam.members.detail.reset_password_confirm_title")}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {t("iam.members.detail.reset_password_confirm_desc", { email: member.email })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={resetPassword.isPending}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                resetPassword.mutate(userId);
                setConfirmReset(false);
              }}
            >
              {t("iam.members.detail.reset_password")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Deactivate confirmation (TC-MEM-DET5) */}
      <AlertDialog open={confirmDeactivate} onOpenChange={setConfirmDeactivate}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t("iam.members.detail.deactivate_confirm_title")}</AlertDialogTitle>
            <AlertDialogDescription>
              {t("iam.members.detail.deactivate_confirm_desc", { name: member.name })}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={setActive.isPending}>
              {t("common.cancel")}
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                setActive.mutate({ userId, active: false });
                setConfirmDeactivate(false);
              }}
            >
              {t("iam.members.detail.deactivate")}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
