"use client";

import { useState } from "react";
import Image from "next/image";
import Link from "next/link";
import { Button, Badge } from "@godxjp/ui";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";
import { CheckCircle2, Plus, Minus } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import type { IamMember, IamRole, IamBranch } from "@/hooks/api/use-iam";
import { useIamRoles, useIamBranches } from "@/hooks/api/use-iam";
import { RoleAssignmentSheet } from "./role-assignment-sheet";

// =========================================================================
//  MemberAvatar
// =========================================================================

function MemberAvatar({ name, avatarUrl }: { name: string; avatarUrl: string | null }) {
  if (avatarUrl) {
    return (
      <Image
        src={avatarUrl}
        alt={name}
        width={32}
        height={32}
        className="size-8 shrink-0 rounded-full object-cover"
      />
    );
  }
  const initial = name.trim().charAt(0).toUpperCase();
  return (
    <span
      aria-hidden="true"
      className="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-medium text-muted-foreground"
    >
      {initial}
    </span>
  );
}

// =========================================================================
//  RoleLevelColor — consistent with RoleBadge in members-table
// =========================================================================

function roleLevelClass(level: number): string {
  if (level >= 100) return "text-destructive";
  if (level >= 80) return "text-orange-600";
  if (level >= 60) return "text-emerald-600";
  if (level >= 30) return "text-blue-600";
  return "text-muted-foreground";
}

// =========================================================================
//  AssignmentCell — shows assignments for one member+role intersection
// =========================================================================

interface AssignmentCellProps {
  member: IamMember;
  role: IamRole;
  branches: IamBranch[];
  onOpen: (member: IamMember, initialRoleSlug?: string) => void;
}

function AssignmentCell({ member, role, branches, onOpen }: AssignmentCellProps) {
  // All assignments this member has for this specific role
  const matching = member.assignments.filter((a) => a.role_slug === role.slug);
  const hasOrgWide = matching.some((a) => a.branch_id === null);
  const branchAssignments = matching.filter((a) => a.branch_id !== null);

  if (matching.length === 0) {
    // Empty cell — hover reveals "+" to assign
    return (
      <button
        type="button"
        className="group/cell flex size-full min-h-[2.5rem] items-center justify-center rounded p-1 hover:bg-muted/30"
        aria-label={`Assign ${role.name} to ${member.name}`}
        onClick={() => onOpen(member, role.slug)}
      >
        <Plus className="size-3.5 text-transparent group-hover/cell:text-muted-foreground" />
      </button>
    );
  }

  return (
    <button
      type="button"
      className="flex min-h-[2.5rem] w-full flex-col items-center justify-center gap-1 rounded p-1 hover:bg-muted/30"
      aria-label={`Edit ${role.name} for ${member.name}`}
      onClick={() => onOpen(member, role.slug)}
    >
      {/* Org-wide indicator */}
      {hasOrgWide && (
        <CheckCircle2
          className={`size-4 ${roleLevelClass(role.level)}`}
          aria-label="Organization-wide"
        />
      )}

      {/* Branch-scoped badges */}
      {branchAssignments.map((a) => {
        const branchName = branches.find((b) => b.id === a.branch_id)?.name ?? "—";
        return (
          <Badge
            key={a.branch_id}
            variant="secondary"
            className="h-4 px-1 text-[10px] leading-none"
          >
            {branchName}
          </Badge>
        );
      })}
    </button>
  );
}

// =========================================================================
//  MembersMatrix
// =========================================================================

export interface MembersMatrixProps {
  brandSlug: string;
  members: IamMember[];
  isLoading: boolean;
}

export function MembersMatrix({ brandSlug, members, isLoading }: MembersMatrixProps) {
  const { t } = useTranslation();
  const { data: rolesData, isLoading: rolesLoading } = useIamRoles(brandSlug);
  const { data: branchesData } = useIamBranches(brandSlug);

  const roles = rolesData?.data ?? [];
  const branches = branchesData?.data ?? [];
  const sortedRoles = [...roles].sort((a, b) => b.level - a.level);

  const [selectedMember, setSelectedMember] = useState<IamMember | null>(null);
  const [initialRoleSlug, setInitialRoleSlug] = useState<string | undefined>(undefined);
  const [sheetOpen, setSheetOpen] = useState(false);

  function openSheet(member: IamMember, roleSlug?: string) {
    setSelectedMember(member);
    setInitialRoleSlug(roleSlug);
    setSheetOpen(true);
  }

  const tableLoading = isLoading || rolesLoading;

  if (tableLoading) {
    return (
      <div data-slot="members-matrix" className="flex items-center justify-center py-12">
        <Spinner className="size-5 text-muted-foreground" />
      </div>
    );
  }

  if (members.length === 0) {
    return (
      <div
        data-slot="members-matrix"
        className="flex items-center justify-center py-12 text-sm text-muted-foreground"
      >
        {t("iam.members.empty")}
      </div>
    );
  }

  return (
    <div data-slot="members-matrix" className="overflow-x-auto">
      <Table className="border-collapse">
        <TableHeader>
          <TableRow>
            {/* Sticky member column header */}
            <TableHead className="sticky left-0 z-10 min-w-[200px] bg-background">
              {t("iam.members.col.name")}
            </TableHead>

            {/* Role column headers */}
            {sortedRoles.map((role) => (
              <TableHead key={role.slug} className="min-w-[110px] text-center">
                <div className="flex flex-col items-center gap-0.5">
                  <span className={`text-xs ${roleLevelClass(role.level)}`}>{role.name}</span>
                  <span className="text-[10px] font-normal text-muted-foreground">
                    Lv.{role.level}
                  </span>
                </div>
              </TableHead>
            ))}

            {/* Actions */}
            <TableHead className="w-28 text-right">{t("iam.members.col.actions")}</TableHead>
          </TableRow>
        </TableHeader>

        <TableBody>
          {members.map((member) => (
            <TableRow key={member.id} className="group/row">
              {/* Sticky member info cell — name links to detail page */}
              <TableCell className="sticky left-0 z-10 bg-background py-2 group-hover/row:bg-muted/5">
                <Link
                  href={`/hq/${brandSlug}/iam/members/${member.id}`}
                  className="flex items-center gap-2 hover:opacity-80"
                >
                  <MemberAvatar name={member.name} avatarUrl={member.avatar_url} />
                  <div className="flex flex-col">
                    <span className="text-sm leading-snug font-medium underline-offset-2 hover:underline">
                      {member.name}
                    </span>
                    <span className="text-xs text-muted-foreground">{member.email}</span>
                  </div>
                </Link>
              </TableCell>

              {/* Role assignment cells */}
              {sortedRoles.map((role) => (
                <TableCell key={role.slug} className="p-0 text-center align-middle">
                  <AssignmentCell
                    member={member}
                    role={role}
                    branches={branches}
                    onOpen={openSheet}
                  />
                </TableCell>
              ))}

              {/* Full-sheet manage button */}
              <TableCell className="text-right">
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-7 text-xs opacity-0 transition-opacity group-hover/row:opacity-100"
                  onClick={() => openSheet(member)}
                >
                  <Minus className="mr-1 size-3" />
                  {t("iam.members.manage")}
                </Button>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>

      <RoleAssignmentSheet
        brandSlug={brandSlug}
        member={selectedMember}
        open={sheetOpen}
        initialRoleSlug={initialRoleSlug}
        onOpenChange={(open) => {
          setSheetOpen(open);
          if (!open) {
            setSelectedMember(null);
            setInitialRoleSlug(undefined);
          }
        }}
      />
    </div>
  );
}
