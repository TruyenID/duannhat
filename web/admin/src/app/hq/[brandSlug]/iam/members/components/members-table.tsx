"use client";

import Image from "next/image";
import Link from "next/link";
import { Badge } from "@godxjp/ui";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@godxjp/ui";
import { Spinner } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import type { IamMember } from "@/hooks/api/use-iam";

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
//  RoleSummary — compact role list (AWS-style: highest role + +N badge)
// =========================================================================

function RoleSummary({ member }: { member: IamMember }) {
  const { assignments } = member;

  if (assignments.length === 0) {
    return <span className="text-xs text-muted-foreground">—</span>;
  }

  const sorted = [...assignments].sort((a, b) => b.role_level - a.role_level);
  const primary = sorted[0];
  const rest = sorted.length - 1;

  return (
    <div className="flex flex-wrap items-center gap-1">
      <Badge variant="secondary" className="text-xs">
        {primary.role_name}
      </Badge>
      {rest > 0 && <span className="text-xs text-muted-foreground">+{rest}</span>}
    </div>
  );
}

// =========================================================================
//  MembersTable — AWS IAM style: simple list, click name → detail page
// =========================================================================

export interface MembersTableProps {
  brandSlug: string;
  members: IamMember[];
  isLoading: boolean;
}

export function MembersTable({ brandSlug, members, isLoading }: MembersTableProps) {
  const { t } = useTranslation();

  if (isLoading) {
    return (
      <div data-slot="members-table" className="flex items-center justify-center py-12">
        <Spinner className="size-5 text-muted-foreground" />
      </div>
    );
  }

  if (members.length === 0) {
    return (
      <div
        data-slot="members-table"
        className="rounded-lg border border-dashed px-4 py-12 text-center text-sm text-muted-foreground"
      >
        {t("iam.members.empty")}
      </div>
    );
  }

  return (
    <div data-slot="members-table">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="w-10" />
            <TableHead>{t("iam.members.col.name")}</TableHead>
            <TableHead>{t("iam.members.col.roles")}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {members.map((member) => (
            <TableRow key={member.id} className="group/row cursor-pointer">
              <TableCell className="py-2">
                <MemberAvatar name={member.name} avatarUrl={member.avatar_url} />
              </TableCell>

              <TableCell className="py-2">
                <Link href={`/hq/${brandSlug}/iam/members/${member.id}`} className="flex flex-col">
                  <span className="text-sm font-medium text-primary underline-offset-2 group-hover/row:underline">
                    {member.name}
                  </span>
                  <span className="text-xs text-muted-foreground">{member.email}</span>
                </Link>
              </TableCell>

              <TableCell className="py-2">
                <RoleSummary member={member} />
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
