"use client";

/**
 * Visual rule builder for notification audiences (plan-012 T1.5).
 *
 * Produces the rule JSON shape consumed by AudienceResolverService on the
 * backend. Pickers (MultiCombobox / Select) load real data from the IAM
 * endpoints — admins pick humans, not paste UUIDs.
 */

import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  MultiCombobox,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@godxjp/ui";
import { Plus, Trash2 } from "lucide-react";
import { useParams } from "next/navigation";
import { useMemo } from "react";

import { useIamBranches, useIamMembers, useIamRoles } from "@/hooks/api/use-iam";
import { useTranslation } from "@/providers/app-provider";
import type {
  AudienceRule,
  AudienceRuleType,
  AudienceSubRule,
} from "@/services/notification-audience-service";

export interface RuleBuilderProps {
  value: AudienceRule;
  onChange: (rule: AudienceRule) => void;
}

const RULE_TYPES: AudienceRuleType[] = ["role", "user", "shop", "brand", "device"];
const DEVICE_TYPES = ["workstation", "tms", "kitchen-display"];

function blankSubRule(type: AudienceRuleType): AudienceSubRule {
  switch (type) {
    case "role":
      return { type: "role", role: "" };
    case "user":
      return { type: "user", user_ids: [] };
    case "shop":
      return { type: "shop", shop_ids: [], include_members: true };
    case "brand":
      return { type: "brand", include_all_members: true };
    case "device":
      return { type: "device", device_types: [] };
  }
}

export function RuleBuilder({ value, onChange }: RuleBuilderProps) {
  const { t } = useTranslation();
  const { brandSlug } = useParams<{ brandSlug: string }>();

  const { data: membersResp } = useIamMembers(brandSlug);
  const { data: rolesResp } = useIamRoles(brandSlug);
  const { data: branchesResp } = useIamBranches(brandSlug);

  const memberOptions = useMemo(
    () =>
      (membersResp?.data ?? []).map((m) => ({
        value: m.id,
        label: `${m.name} · ${m.email}`,
      })),
    [membersResp]
  );
  const roleOptions = useMemo(
    () =>
      (rolesResp?.data ?? []).map((r) => ({
        value: r.slug,
        label: `${r.name} (${r.slug})`,
      })),
    [rolesResp]
  );
  const branchOptions = useMemo(
    () =>
      (branchesResp?.data ?? []).map((b) => ({
        value: b.id,
        label: b.is_headquarters ? `${b.name} (HQ)` : b.name,
      })),
    [branchesResp]
  );
  const deviceOptions = useMemo(() => DEVICE_TYPES.map((d) => ({ value: d, label: d })), []);

  const rules = value.rules ?? [];
  const exclude = value.exclude ?? [];

  function setRules(next: AudienceSubRule[]) {
    onChange({ ...value, rules: next });
  }

  function setExclude(next: AudienceSubRule[]) {
    const { exclude: _omit, ...rest } = value;
    onChange(next.length === 0 ? rest : { ...rest, exclude: next });
  }

  function addRule(type: AudienceRuleType) {
    setRules([...rules, blankSubRule(type)]);
  }

  function removeRule(idx: number) {
    setRules(rules.filter((_, i) => i !== idx));
  }

  function patchRule(idx: number, patch: Partial<AudienceSubRule>) {
    setRules(rules.map((r, i) => (i === idx ? { ...r, ...patch } : r)));
  }

  function addExcludeUser() {
    setExclude([...exclude, { type: "user", user_ids: [] }]);
  }

  function removeExclude(idx: number) {
    setExclude(exclude.filter((_, i) => i !== idx));
  }

  function patchExclude(idx: number, patch: Partial<AudienceSubRule>) {
    setExclude(exclude.map((r, i) => (i === idx ? { ...r, ...patch } : r)));
  }

  return (
    <div className="flex flex-col gap-4" data-slot="audience-rule-builder">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium">{t("notifications.audiences.combinator")}</span>
        <Select
          value={value.combinator ?? "or"}
          onValueChange={(v) => onChange({ ...value, combinator: v as "or" | "and" })}
        >
          <SelectTrigger className="w-28">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="or">{t("notifications.audiences.combinator_or")}</SelectItem>
            <SelectItem value="and">{t("notifications.audiences.combinator_and")}</SelectItem>
          </SelectContent>
        </Select>
        <span className="text-xs text-muted-foreground">
          {t("notifications.audiences.combinator_hint")}
        </span>
      </div>

      <div className="flex flex-col gap-3">
        {rules.map((rule, idx) => (
          <Card key={idx} data-slot="audience-rule-card">
            <CardHeader className="flex-row items-center justify-between">
              <CardTitle className="text-sm">
                <Badge variant="secondary">{t(`notifications.audiences.type_${rule.type}`)}</Badge>
              </CardTitle>
              <Button variant="ghost" size="sm" onClick={() => removeRule(idx)}>
                <Trash2 className="size-4" />
              </Button>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
              <SubRuleBody
                rule={rule}
                memberOptions={memberOptions}
                roleOptions={roleOptions}
                branchOptions={branchOptions}
                deviceOptions={deviceOptions}
                onChange={(patch) => patchRule(idx, patch)}
              />
            </CardContent>
          </Card>
        ))}

        {rules.length === 0 && (
          <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
            {t("notifications.audiences.empty_rules")}
          </p>
        )}
      </div>

      <div className="flex flex-wrap gap-2">
        {RULE_TYPES.map((type) => (
          <Button key={type} variant="outline" size="sm" onClick={() => addRule(type)}>
            <Plus className="mr-1 size-4" />
            {t(`notifications.audiences.add_${type}`)}
          </Button>
        ))}
      </div>

      <div className="flex flex-col gap-2 border-t pt-4">
        <div className="flex items-center justify-between">
          <div>
            <span className="text-sm font-medium">{t("notifications.audiences.exclude")}</span>
            <p className="text-xs text-muted-foreground">
              {t("notifications.audiences.exclude_hint")}
            </p>
          </div>
          <Button variant="outline" size="sm" onClick={addExcludeUser}>
            <Plus className="mr-1 size-4" />
            {t("notifications.audiences.add_exclude")}
          </Button>
        </div>
        {exclude.map((rule, idx) => (
          <Card key={idx} data-slot="audience-exclude-card">
            <CardContent className="flex flex-row items-center gap-2 p-3">
              <span className="shrink-0 text-xs text-muted-foreground">
                {t("notifications.audiences.exclude_users")}
              </span>
              <div className="flex-1">
                <MultiCombobox
                  options={memberOptions}
                  value={rule.user_ids ?? []}
                  onChange={(v) => patchExclude(idx, { user_ids: v })}
                  placeholder={t("notifications.audiences.user_picker_placeholder")}
                  searchPlaceholder={t("notifications.audiences.user_search_placeholder")}
                  className="h-9 w-full"
                />
              </div>
              <Button variant="ghost" size="sm" onClick={() => removeExclude(idx)}>
                <Trash2 className="size-4" />
              </Button>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}

interface SubRuleBodyProps {
  rule: AudienceSubRule;
  memberOptions: { value: string; label: string }[];
  roleOptions: { value: string; label: string }[];
  branchOptions: { value: string; label: string }[];
  deviceOptions: { value: string; label: string }[];
  onChange: (patch: Partial<AudienceSubRule>) => void;
}

function SubRuleBody({
  rule,
  memberOptions,
  roleOptions,
  branchOptions,
  deviceOptions,
  onChange,
}: SubRuleBodyProps) {
  const { t } = useTranslation();

  switch (rule.type) {
    case "role":
      return (
        <div className="flex flex-col gap-2">
          <label className="text-xs text-muted-foreground">
            {t("notifications.audiences.role_label")}
          </label>
          <Select value={rule.role ?? ""} onValueChange={(v) => onChange({ role: v })}>
            <SelectTrigger>
              <SelectValue placeholder={t("notifications.audiences.role_picker_placeholder")} />
            </SelectTrigger>
            <SelectContent>
              {roleOptions.map((r) => (
                <SelectItem key={r.value} value={r.value}>
                  {r.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <label className="mt-2 text-xs text-muted-foreground">
            {t("notifications.audiences.role_scope_label")}
          </label>
          <Select
            value={Object.values(rule.scope ?? {})[0] ?? "__none__"}
            onValueChange={(v) => {
              if (v === "__none__") {
                onChange({ scope: undefined });
                return;
              }
              const key =
                rule.role === "warehouse_manager"
                  ? "warehouse_id"
                  : rule.role === "shop_manager"
                    ? "shop_id"
                    : "brand_id";
              onChange({ scope: { [key]: v } });
            }}
          >
            <SelectTrigger>
              <SelectValue placeholder={t("notifications.audiences.role_scope_placeholder")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__none__">
                {t("notifications.audiences.role_scope_none")}
              </SelectItem>
              {branchOptions.map((b) => (
                <SelectItem key={b.value} value={b.value}>
                  {b.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      );

    case "user":
      return (
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground">
            {t("notifications.audiences.user_label")}
          </label>
          <MultiCombobox
            options={memberOptions}
            value={rule.user_ids ?? []}
            onChange={(v) => onChange({ user_ids: v })}
            placeholder={t("notifications.audiences.user_picker_placeholder")}
            searchPlaceholder={t("notifications.audiences.user_search_placeholder")}
            className="h-9 w-full"
          />
        </div>
      );

    case "shop":
      return (
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground">
            {t("notifications.audiences.shop_label")}
          </label>
          <MultiCombobox
            options={branchOptions}
            value={rule.shop_ids ?? []}
            onChange={(v) => onChange({ shop_ids: v })}
            placeholder={t("notifications.audiences.shop_picker_placeholder")}
            searchPlaceholder={t("notifications.audiences.shop_search_placeholder")}
            className="h-9 w-full"
          />
          <label className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
            <input
              type="checkbox"
              checked={rule.include_members ?? true}
              onChange={(e) => onChange({ include_members: e.target.checked })}
            />
            {t("notifications.audiences.shop_include_members")}
          </label>
        </div>
      );

    case "brand":
      return (
        <div className="flex flex-col gap-1">
          <p className="text-xs text-muted-foreground">{t("notifications.audiences.brand_hint")}</p>
          <label className="flex items-center gap-2 text-xs text-muted-foreground">
            <input
              type="checkbox"
              checked={rule.include_all_members ?? true}
              onChange={(e) => onChange({ include_all_members: e.target.checked })}
            />
            {t("notifications.audiences.brand_include_all")}
          </label>
        </div>
      );

    case "device":
      return (
        <div className="flex flex-col gap-2">
          <label className="text-xs text-muted-foreground">
            {t("notifications.audiences.device_types_label")}
          </label>
          <MultiCombobox
            options={deviceOptions}
            value={rule.device_types ?? []}
            onChange={(v) => onChange({ device_types: v })}
            placeholder={t("notifications.audiences.device_picker_placeholder")}
            searchPlaceholder={t("notifications.audiences.device_search_placeholder")}
            className="h-9 w-full"
          />
          <label className="mt-1 text-xs text-muted-foreground">
            {t("notifications.audiences.device_branch_label")}
          </label>
          <Select
            value={rule.branch_id ?? "__none__"}
            onValueChange={(v) => onChange({ branch_id: v === "__none__" ? undefined : v })}
          >
            <SelectTrigger>
              <SelectValue placeholder={t("notifications.audiences.device_branch_placeholder")} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__none__">
                {t("notifications.audiences.device_branch_none")}
              </SelectItem>
              {branchOptions.map((b) => (
                <SelectItem key={b.value} value={b.value}>
                  {b.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      );
  }
}
