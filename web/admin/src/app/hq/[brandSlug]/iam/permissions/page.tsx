"use client";

import { useParams } from "next/navigation";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { Button } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useIamRoles, useIamPermissions } from "@/hooks/api/use-iam";
import { PermissionMatrix } from "./components/permission-matrix";

export default function IamPermissionsPage() {
  const { t } = useTranslation();
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;

  const {
    data: rolesData,
    isLoading: rolesLoading,
    isError: rolesError,
    refetch: refetchRoles,
    isFetching: rolesFetching,
  } = useIamRoles(brandSlug);
  const {
    data: permsData,
    isLoading: permsLoading,
    isError: permsError,
    refetch: refetchPerms,
    isFetching: permsFetching,
  } = useIamPermissions(brandSlug);

  const roles = rolesData?.data ?? [];
  const permissionGroups = permsData?.data ?? [];
  const isLoading = rolesLoading || permsLoading;
  const isError = rolesError || permsError;
  const isFetching = rolesFetching || permsFetching;

  async function handleRefresh() {
    await Promise.all([refetchRoles(), refetchPerms()]);
  }

  return (
    <>
      <PageHeader
        title={t("iam.permissions.title")}
        onRefresh={handleRefresh}
        isRefreshing={isFetching}
      />

      <PageContent>
        {isError ? (
          <div
            data-slot="iam-permissions-page"
            className="flex flex-col items-center justify-center gap-3 py-16 text-sm text-muted-foreground"
          >
            <p>{t("common.error_loading")}</p>
            <Button variant="outline" size="sm" onClick={handleRefresh}>
              {t("common.retry")}
            </Button>
          </div>
        ) : (
          <div data-slot="iam-permissions-page">
            <PermissionMatrix
              brandSlug={brandSlug}
              roles={roles}
              permissionGroups={permissionGroups}
              isLoading={isLoading}
            />
          </div>
        )}
      </PageContent>
    </>
  );
}
