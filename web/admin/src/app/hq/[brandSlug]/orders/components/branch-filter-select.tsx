"use client";
import { useShops } from "@/hooks/api/use-shops";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";
export interface BranchFilterSelectProps {
  brandSlug: string;
  value: string;
  onValueChange: (value: string) => void;
}
export function BranchFilterSelect({ brandSlug, value, onValueChange }: BranchFilterSelectProps) {
  const { t } = useTranslation();
  const { data } = useShops(brandSlug);
  const shops = data?.data ?? [];
  return (
    <Select value={value} onValueChange={onValueChange}>
      <SelectTrigger data-slot="branch-filter-select" className="h-8 w-48 text-sm">
        <SelectValue placeholder={t("hq.orders.filter.all_branches")} />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">{t("hq.orders.filter.all_branches")}</SelectItem>
        {shops.map((s) => (
          <SelectItem key={s.id} value={s.id}>
            {s.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
