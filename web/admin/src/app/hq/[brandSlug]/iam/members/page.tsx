"use client";

import { useEffect, useMemo, useState } from "react";
import { useParams } from "next/navigation";
import { PageHeader } from "@/components/layout/page-header";
import { PageContent } from "@/components/layout/page-content";
import { ListPageToolbar } from "@/components/shared/list-page-toolbar";
import {
  Spinner,
  Button,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
import { useIamMembers } from "@/hooks/api/use-iam";
import { useSearchFilters } from "@/hooks/use-search-filters";
import { useDebounce } from "@/hooks/use-debounce";
import { MembersTable } from "./components/members-table";

const FILTER_DEFAULTS = {
  search: "",
  role: "all",
};

export default function IamMembersPage() {
  const { t } = useTranslation();
  const params = useParams<{ brandSlug: string }>();
  const brandSlug = params.brandSlug;

  // Filters synced to the URL (reload keeps search + role).
  const { filters: urlFilters, setFilter, resetFilters } = useSearchFilters(FILTER_DEFAULTS);
  const [search, setSearch] = useState(urlFilters.search);
  const debouncedSearch = useDebounce(search, 300);

  useEffect(() => {
    if (debouncedSearch !== urlFilters.search) {
      setFilter("search", debouncedSearch);
    }
  }, [debouncedSearch]);

  const roleFilter = urlFilters.role;

  const hasActiveFilters = Object.entries(urlFilters).some(
    ([key, value]) => value !== FILTER_DEFAULTS[key as keyof typeof FILTER_DEFAULTS]
  );

  const { data, isLoading, isError, isFetching, refetch } = useIamMembers(brandSlug);
  const members = useMemo(() => data?.data ?? [], [data]);

  // Distinct roles present among the loaded members, highest level first.
  const roleOptions = useMemo(() => {
    const map = new Map<string, { slug: string; name: string; level: number }>();
    for (const m of members) {
      for (const a of m.assignments) {
        if (!map.has(a.role_slug)) {
          map.set(a.role_slug, { slug: a.role_slug, name: a.role_name, level: a.role_level });
        }
      }
    }
    return [...map.values()].sort((a, b) => b.level - a.level);
  }, [members]);

  const filtered = useMemo(() => {
    const q = debouncedSearch.trim().toLowerCase();
    return members.filter((m) => {
      const matchesSearch =
        !q || m.name.toLowerCase().includes(q) || m.email.toLowerCase().includes(q);
      const matchesRole =
        roleFilter === "all" || m.assignments.some((a) => a.role_slug === roleFilter);
      return matchesSearch && matchesRole;
    });
  }, [members, debouncedSearch, roleFilter]);

  return (
    <>
      <PageHeader
        title={t("iam.members.title")}
        description={
          isLoading || isError ? undefined : t("iam.members.total", { count: members.length })
        }
        onRefresh={refetch}
        isRefreshing={isFetching}
      />

      <PageContent>
        {isLoading ? (
          <div data-slot="iam-members-page" className="flex items-center justify-center py-16">
            <Spinner className="size-6 text-muted-foreground" />
          </div>
        ) : isError ? (
          <div
            data-slot="iam-members-page"
            className="flex flex-col items-center justify-center gap-3 py-16 text-sm text-muted-foreground"
          >
            <p>{t("common.error_loading")}</p>
            <Button variant="outline" size="sm" onClick={() => refetch()}>
              {t("common.retry")}
            </Button>
          </div>
        ) : (
          <div data-slot="iam-members-page" className="space-y-3">
            <ListPageToolbar
              search={search}
              onSearchChange={setSearch}
              searchPlaceholder={t("iam.members.search_placeholder")}
              hasActiveFilters={hasActiveFilters}
              onClearFilters={() => {
                resetFilters();
                setSearch("");
              }}
            >
              <Select value={roleFilter} onValueChange={(v) => setFilter("role", v)}>
                <SelectTrigger className="h-8 w-44 text-xs">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{t("iam.members.filter.all_roles")}</SelectItem>
                  {roleOptions.map((r) => (
                    <SelectItem key={r.slug} value={r.slug}>
                      {r.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </ListPageToolbar>

            <MembersTable brandSlug={brandSlug} members={filtered} isLoading={false} />
          </div>
        )}
      </PageContent>
    </>
  );
}
