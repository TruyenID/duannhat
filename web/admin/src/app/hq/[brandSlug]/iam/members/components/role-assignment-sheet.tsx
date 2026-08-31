"use client";

import { useEffect, useState } from "react";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from "@godxjp/ui";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from "@godxjp/ui";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import { Button, Badge } from "@godxjp/ui";
import { X } from "lucide-react";
import { Spinner } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type { IamMember, IamAssignment } from "@/hooks/api/use-iam";
import { useIamRoles, useAssignRole, useRevokeRole, useIamBranches } from "@/hooks/api/use-iam";

// =========================================================================
//  RoleAssignmentSheet
// =========================================================================

export interface RoleAssignmentSheetProps {
  brandSlug: string;
  member: IamMember | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Pre-select this role slug in the "Add role" form when opening from a matrix cell. */
  initialRoleSlug?: string;
}

type AssignScope = "org" | "branch";

export function RoleAssignmentSheet({
  brandSlug,
  member,
  open,
  onOpenChange,
  initialRoleSlug,
}: RoleAssignmentSheetProps) {
  const { t } = useTranslation();
  const { data: rolesData, isLoading: rolesLoading } = useIamRoles(brandSlug);
  const { data: branchesData, isLoading: branchesLoading } = useIamBranches(brandSlug);
  const roles = rolesData?.data ?? [];
  const branches = branchesData?.data ?? [];

  const assignRole = useAssignRole(brandSlug);
  const revokeRole = useRevokeRole(brandSlug);

  // Add-role form state — seed from initialRoleSlug each time the sheet opens
  const [selectedRoleSlug, setSelectedRoleSlug] = useState<string>("");
  const [assignScope, setAssignScope] = useState<AssignScope>("org");
  const [selectedBranchId, setSelectedBranchId] = useState<string>("");

  // Reset form whenever the sheet opens, pre-filling role from matrix cell click
  useEffect(() => {
    if (open) {
      setSelectedRoleSlug(initialRoleSlug ?? "");
      setAssignScope("org");
      setSelectedBranchId("");
    }
  }, [open, initialRoleSlug]);

  // Revoke confirm dialog state
  const [revokeTarget, setRevokeTarget] = useState<IamAssignment | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  function openRevokeConfirm(assignment: IamAssignment) {
    setRevokeTarget(assignment);
    setConfirmOpen(true);
  }

  async function handleRevoke() {
    if (!member || !revokeTarget) return;
    await revokeRole.mutateAsync({
      userId: member.id,
      roleSlug: revokeTarget.role_slug,
      branchId: revokeTarget.branch_id,
    });
    setConfirmOpen(false);
    setRevokeTarget(null);
  }

  async function handleAssign() {
    if (!member || !selectedRoleSlug) return;
    if (assignScope === "branch" && !selectedBranchId) return;

    await assignRole.mutateAsync({
      userId: member.id,
      role_slug: selectedRoleSlug,
      branch_id: assignScope === "branch" ? selectedBranchId : null,
    });
    setSelectedRoleSlug("");
    setSelectedBranchId("");
    setAssignScope("org");
  }

  const canAssign =
    !!selectedRoleSlug &&
    (assignScope === "org" || (assignScope === "branch" && !!selectedBranchId));

  return (
    <>
      <Sheet open={open} onOpenChange={onOpenChange}>
        <SheetContent className="w-full sm:max-w-md" data-slot="role-assignment-sheet">
          <SheetHeader>
            <SheetTitle>{t("iam.assign.title")}</SheetTitle>
            {member && (
              <SheetDescription>
                {member.name} · {member.email}
              </SheetDescription>
            )}
          </SheetHeader>

          {member && (
            <div className="mt-6 space-y-6 px-6 pb-6">
              {/* Current assignments */}
              <section>
                <h3 className="mb-2 text-sm font-medium">{t("iam.assign.current")}</h3>
                {member.assignments.length === 0 ? (
                  <p className="text-xs text-muted-foreground">—</p>
                ) : (
                  <div className="flex flex-wrap gap-2">
                    {member.assignments.map((a) => {
                      const branchName = a.branch_id
                        ? (branches.find((b) => b.id === a.branch_id)?.name ?? a.branch_id)
                        : null;
                      return (
                        <Badge
                          key={`${a.role_slug}:${a.branch_id ?? "org"}`}
                          variant="secondary"
                          className="flex items-center gap-1 pr-1"
                        >
                          <span>
                            {a.role_name}
                            <span className="ml-1 text-muted-foreground">
                              · {branchName ?? t("iam.assign.scope_org_badge")}
                            </span>
                          </span>
                          <button
                            type="button"
                            className="ml-1 inline-flex size-4 items-center justify-center rounded-full hover:bg-muted-foreground/20"
                            aria-label={`Remove ${a.role_name}`}
                            onClick={() => openRevokeConfirm(a)}
                          >
                            <X className="size-3" />
                          </button>
                        </Badge>
                      );
                    })}
                  </div>
                )}
              </section>

              {/* Add new role */}
              <section>
                <h3 className="mb-2 text-sm font-medium">{t("iam.assign.add_section")}</h3>
                {rolesLoading ? (
                  <Spinner className="size-4" />
                ) : (
                  <div className="space-y-2">
                    {/* Role select */}
                    <Select value={selectedRoleSlug} onValueChange={setSelectedRoleSlug}>
                      <SelectTrigger className="h-8 w-full text-xs">
                        <SelectValue placeholder={t("iam.assign.select_role")} />
                      </SelectTrigger>
                      <SelectContent>
                        {roles.map((role) => (
                          <SelectItem key={role.slug} value={role.slug} className="text-xs">
                            {role.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>

                    {/* Scope select */}
                    <Select
                      value={assignScope}
                      onValueChange={(v) => {
                        setAssignScope(v as AssignScope);
                        setSelectedBranchId("");
                      }}
                    >
                      <SelectTrigger className="h-8 w-full text-xs">
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

                    {/* Branch select — shown only when scope = branch */}
                    {assignScope === "branch" &&
                      (branchesLoading ? (
                        <Spinner className="size-4" />
                      ) : (
                        <Select value={selectedBranchId} onValueChange={setSelectedBranchId}>
                          <SelectTrigger className="h-8 w-full text-xs">
                            <SelectValue placeholder={t("iam.assign.select_branch")} />
                          </SelectTrigger>
                          <SelectContent>
                            {branches.map((branch) => (
                              <SelectItem key={branch.id} value={branch.id} className="text-xs">
                                {branch.name}
                                {branch.is_headquarters && (
                                  <span className="ml-1 text-muted-foreground">(HQ)</span>
                                )}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      ))}

                    <Button
                      size="sm"
                      className="h-8 w-full text-xs"
                      disabled={!canAssign || assignRole.isPending}
                      onClick={handleAssign}
                    >
                      {assignRole.isPending ? <Spinner className="mr-1 size-3.5" /> : null}
                      {t("iam.assign.confirm_add")}
                    </Button>
                  </div>
                )}
              </section>
            </div>
          )}
        </SheetContent>
      </Sheet>

      {/* Revoke confirm dialog */}
      <Dialog
        open={confirmOpen}
        onOpenChange={(open) => {
          setConfirmOpen(open);
          if (!open) setRevokeTarget(null);
        }}
      >
        <DialogContent data-slot="revoke-confirm-dialog">
          <DialogHeader>
            <DialogTitle>{t("iam.assign.confirm_revoke")}</DialogTitle>
            <DialogDescription>{t("iam.assign.revoke_desc")}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" size="sm" onClick={() => setConfirmOpen(false)}>
              {t("common.cancel")}
            </Button>
            <Button
              variant="destructive"
              size="sm"
              disabled={revokeRole.isPending}
              onClick={handleRevoke}
            >
              {revokeRole.isPending ? <Spinner className="mr-1 size-3.5" /> : null}
              {t("common.delete")}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
