/**
 * IAM Hooks — React-Query wrappers for the IAM API.
 *
 * All mutations toast on success and failure, and invalidate the relevant
 * IAM query keys so the UI stays in sync without a page reload.
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch } from "@/lib/api";
import { iamKeys } from "./query-keys";

// =========================================================================
//  Types
// =========================================================================

export interface IamAssignment {
  role_slug: string;
  role_name: string;
  role_level: number;
  branch_id: string | null;
}

export interface IamMember {
  id: string;
  name: string;
  email: string;
  avatar_url: string | null;
  is_active: boolean;
  assignments: IamAssignment[];
}

export interface IamRole {
  id: string;
  slug: string;
  name: string;
  level: number;
  description: string | null;
  permissions: string[]; // array of permission slugs
  is_system: boolean; // pristine global template (not yet forked by this org)
  is_customized: boolean; // org-scoped fork shadowing a system template
  is_editable: boolean; // may be edited (editing a system template forks it server-side)
}

export interface IamPermissionGroup {
  group: string;
  permissions: { slug: string; name: string }[];
}

/** One role assignment with its effective permission list. */
export interface IamMemberPermissionEntry {
  role_slug: string;
  role_name: string;
  role_level: number;
  branch_id: string | null;
  permissions: string[];
}

export interface IamBranch {
  id: string;
  name: string;
  slug: string;
  is_headquarters: boolean;
  is_active: boolean;
}

/** One IAM audit-trail entry for a member (role/status/password events). */
export interface IamAuditEntry {
  id: string;
  action: string;
  actor_name: string | null;
  metadata: Record<string, unknown> | null;
  created_at: string | null;
}

// =========================================================================
//  Queries
// =========================================================================

export function useIamMembers(brandSlug: string) {
  return useQuery({
    queryKey: iamKeys.members(brandSlug),
    queryFn: () => apiFetch<{ data: IamMember[] }>(`/api/v1/hq/${brandSlug}/iam/members`),
    enabled: !!brandSlug,
  });
}

export function useIamMember(brandSlug: string, userId: string) {
  return useQuery({
    queryKey: iamKeys.member(brandSlug, userId),
    queryFn: () => apiFetch<{ data: IamMember }>(`/api/v1/hq/${brandSlug}/iam/members/${userId}`),
    enabled: !!brandSlug && !!userId,
  });
}

export function useIamMemberPermissions(brandSlug: string, userId: string) {
  return useQuery({
    queryKey: iamKeys.memberPermissions(brandSlug, userId),
    queryFn: () =>
      apiFetch<{ data: IamMemberPermissionEntry[] }>(
        `/api/v1/hq/${brandSlug}/iam/members/${userId}/permissions`
      ),
    enabled: !!brandSlug && !!userId,
  });
}

export function useIamRoles(brandSlug: string) {
  return useQuery({
    queryKey: iamKeys.roles(brandSlug),
    queryFn: () => apiFetch<{ data: IamRole[] }>(`/api/v1/hq/${brandSlug}/iam/roles`),
    enabled: !!brandSlug,
  });
}

export function useIamPermissions(brandSlug: string) {
  return useQuery({
    queryKey: iamKeys.permissions(brandSlug),
    queryFn: () =>
      apiFetch<{ data: IamPermissionGroup[] }>(`/api/v1/hq/${brandSlug}/iam/permissions`),
    enabled: !!brandSlug,
  });
}

export function useIamMemberAudit(brandSlug: string, userId: string) {
  return useQuery({
    queryKey: iamKeys.memberAudit(brandSlug, userId),
    queryFn: () =>
      apiFetch<{ data: IamAuditEntry[] }>(`/api/v1/hq/${brandSlug}/iam/members/${userId}/audit`),
    enabled: !!brandSlug && !!userId,
  });
}

export function useIamBranches(brandSlug: string) {
  return useQuery({
    queryKey: iamKeys.branches(brandSlug),
    queryFn: () => apiFetch<{ data: IamBranch[] }>(`/api/v1/hq/${brandSlug}/iam/branches`),
    enabled: !!brandSlug,
    staleTime: 5 * 60 * 1000,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useAssignRole(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      userId,
      role_slug,
      branch_id,
    }: {
      userId: string;
      role_slug: string;
      branch_id?: string | null;
    }) =>
      apiFetch<{ data: IamMember }>(`/api/v1/hq/${brandSlug}/iam/members/${userId}/assign`, {
        method: "POST",
        body: JSON.stringify({ role_slug, branch_id: branch_id ?? null }),
      }),
    onSuccess: (_, { userId }) => {
      toast.success("Role assigned.");
      qc.invalidateQueries({ queryKey: iamKeys.members(brandSlug) });
      qc.invalidateQueries({
        queryKey: iamKeys.member(brandSlug, userId),
      });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to assign role."),
  });
}

export function useRevokeRole(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      userId,
      roleSlug,
      branchId,
    }: {
      userId: string;
      roleSlug: string;
      branchId?: string | null;
    }) => {
      const params = branchId ? `?branch_id=${branchId}` : "";
      return apiFetch<void>(
        `/api/v1/hq/${brandSlug}/iam/members/${userId}/roles/${roleSlug}${params}`,
        { method: "DELETE" }
      );
    },
    onSuccess: (_, { userId }) => {
      toast.success("Role revoked.");
      qc.invalidateQueries({ queryKey: iamKeys.members(brandSlug) });
      qc.invalidateQueries({
        queryKey: iamKeys.member(brandSlug, userId),
      });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to revoke role."),
  });
}

export function useResetMemberPassword(brandSlug: string) {
  return useMutation({
    mutationFn: (userId: string) =>
      apiFetch<{ message: string }>(
        `/api/v1/hq/${brandSlug}/iam/members/${userId}/reset-password`,
        { method: "POST" }
      ),
    onSuccess: () => toast.success("Password-reset email sent."),
    onError: (e: Error) => toast.error(e.message || "Failed to send reset email."),
  });
}

export function useSetMemberActive(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ userId, active }: { userId: string; active: boolean }) =>
      apiFetch<{ data: IamMember }>(
        `/api/v1/hq/${brandSlug}/iam/members/${userId}/${active ? "activate" : "deactivate"}`,
        { method: "PATCH" }
      ),
    onSuccess: (_, { userId, active }) => {
      toast.success(active ? "Member activated." : "Member deactivated.");
      qc.invalidateQueries({ queryKey: iamKeys.members(brandSlug) });
      qc.invalidateQueries({ queryKey: iamKeys.member(brandSlug, userId) });
    },
    onError: (e: Error) => toast.error(e.message || "Failed to update member."),
  });
}

export function useUpdateRolePermissions(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ roleId, permission_slugs }: { roleId: string; permission_slugs: string[] }) =>
      apiFetch<void>(`/api/v1/hq/${brandSlug}/iam/roles/${roleId}`, {
        method: "PUT",
        body: JSON.stringify({ permission_slugs }),
      }),
    // Editing a system template forks it (copy-on-write, new role id, same slug), so the
    // refetch is what surfaces the org-scoped copy. Toasts + 403/422 branching live in the
    // caller (permission-matrix handleSave) where translations are available.
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: iamKeys.roles(brandSlug) });
    },
  });
}
