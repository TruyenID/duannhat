"use client";

/**
 * Block-form template editor — plan-053 M4 (#1171), T4.1 / T4.2.
 *
 * A FORM, not a WYSIWYG (DESIGN §8 puts drag-and-drop in a later plan). Each
 * block shows its type and its mutability, and the form physically cannot
 * author what the definition may not carry:
 *
 *   locked      no controls at all — the engine builds the content (money, tax,
 *               再発行 marker). Position comes from the system default.
 *   toggleable  the `enabled` switch only (登録番号, #1152).
 *   free        the props the catalog delegates — text i18n ja/en/vi, align,
 *               source, param fields, item columns.
 *
 * In SHOP mode a second gate applies on top: anything outside the brand's
 * `shop_editable` allow-list is disabled with the reason spelled out (TR-03).
 * The disabled input is a courtesy — publish is the real boundary.
 */

import {
  Badge,
  Checkbox,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Switch,
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from "@godxjp/ui";
import { Lock, ToggleRight } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import {
  editablePropsOf,
  PRINT_LOCALES,
  toI18nMap,
  type PrintBlock,
  type PrintTemplateCatalog,
  type PrintTemplateDefinition,
} from "@/types/models/PrintTemplate";

export interface TemplateBlockEditorProps {
  definition: PrintTemplateDefinition;
  catalog: PrintTemplateCatalog;
  onChange: (definition: PrintTemplateDefinition) => void;
  /** `shop` adds the allow-list gate on top of the catalog mutability. */
  mode: "brand" | "shop";
  /** Brand's `shop_editable` — required in shop mode. */
  allowedPaths?: string[];
  /** Brand mode only: which blocks are delegated to shops. */
  shopEditable?: string[];
  onShopEditableChange?: (paths: string[]) => void;
  disabled?: boolean;
}

const ALIGNMENTS = ["left", "center", "right"] as const;

function isPathAllowed(allowed: string[], blockId: string, prop: string): boolean {
  return allowed.some((path) => path === blockId || path === `${blockId}.${prop}`);
}

export function TemplateBlockEditor({
  definition,
  catalog,
  onChange,
  mode,
  allowedPaths = [],
  shopEditable,
  onShopEditableChange,
  disabled = false,
}: TemplateBlockEditorProps) {
  const { t } = useTranslation();

  const patchBlock = (blockId: string, patch: Partial<PrintBlock>) => {
    onChange({
      ...definition,
      blocks: definition.blocks.map((block) =>
        block.id === blockId ? { ...block, ...patch } : block
      ),
    });
  };

  /** Why a control is dead, or `null` when it is live. */
  const lockReason = (blockId: string, prop: string): string | null => {
    if (disabled) return t("print_templates.reason.read_only");
    if (!editablePropsOf(catalog, blockId).includes(prop)) {
      return catalog.mutability[blockId] === "toggleable"
        ? t("print_templates.reason.toggle_only")
        : t("print_templates.reason.locked");
    }
    if (mode === "shop" && !isPathAllowed(allowedPaths, blockId, prop)) {
      return t("print_templates.reason.not_delegated");
    }
    return null;
  };

  const toggleDelegation = (blockId: string, on: boolean) => {
    if (!onShopEditableChange) return;
    const current = shopEditable ?? [];
    onShopEditableChange(
      on
        ? Array.from(new Set([...current, blockId]))
        : current.filter((path) => path !== blockId && !path.startsWith(`${blockId}.`))
    );
  };

  return (
    <TooltipProvider>
      <div data-slot="template-block-editor" className="flex flex-col gap-2">
        {definition.blocks.map((block) => {
          // Fail CLOSED on a block the catalog does not describe: offering a
          // control the backend would reject is the failure mode this whole
          // form exists to avoid. (Before #2043 the fallback was "free", which
          // fails open — it only never bit because the client-side mirror
          // happened to answer for every block it was asked about.)
          const mutability = catalog.mutability[block.id] ?? "locked";
          const required = catalog.required.includes(block.id);
          const editable = editablePropsOf(catalog, block.id);
          const delegated = (shopEditable ?? []).some(
            (path) => path === block.id || path.startsWith(`${block.id}.`)
          );

          return (
            <section
              key={block.id}
              data-testid={`block-${block.id}`}
              data-mutability={mutability}
              className="rounded-md border bg-card px-3 py-2.5"
            >
              <header className="flex flex-wrap items-center gap-1.5">
                <span className="font-mono text-xs font-medium">{block.id}</span>
                <span className="text-[11px] text-muted-foreground">
                  {t(`print_templates.block.${block.id}`)}
                </span>
                <Badge variant="outline" className="text-[10px]">
                  {block.type ?? "locked"}
                </Badge>
                {mutability === "locked" && (
                  <Badge color="destructive" variant="soft" className="gap-1 text-[10px]">
                    <Lock className="size-3" />
                    {t("print_templates.mutability.locked")}
                  </Badge>
                )}
                {mutability === "toggleable" && (
                  <Badge color="warning" variant="soft" className="gap-1 text-[10px]">
                    <ToggleRight className="size-3" />
                    {t("print_templates.mutability.toggleable")}
                  </Badge>
                )}
                {mutability === "free" && (
                  <Badge color="success" variant="soft" className="text-[10px]">
                    {t("print_templates.mutability.free")}
                  </Badge>
                )}
                {required && (
                  <Badge color="info" variant="soft" className="text-[10px]">
                    {t("print_templates.required")}
                  </Badge>
                )}

                <div className="ml-auto flex items-center gap-3">
                  {mode === "brand" && onShopEditableChange && mutability !== "locked" && (
                    <label className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                      <Checkbox
                        checked={delegated}
                        disabled={disabled}
                        data-testid={`delegate-${block.id}`}
                        onCheckedChange={(checked) => toggleDelegation(block.id, checked === true)}
                      />
                      {t("print_templates.delegate_to_shop")}
                    </label>
                  )}
                  {editable.includes("enabled") && (
                    <GatedControl reason={lockReason(block.id, "enabled")}>
                      <div className="flex items-center gap-1.5">
                        <Switch
                          checked={block.enabled !== false}
                          disabled={lockReason(block.id, "enabled") !== null}
                          data-testid={`enabled-${block.id}`}
                          onCheckedChange={(checked) => patchBlock(block.id, { enabled: checked })}
                        />
                        <span className="text-[11px] text-muted-foreground">
                          {block.enabled !== false
                            ? t("print_templates.on")
                            : t("print_templates.off")}
                        </span>
                      </div>
                    </GatedControl>
                  )}
                </div>
              </header>

              {editable.length === 0 && (
                <p className="mt-1 text-[11px] text-muted-foreground">
                  {t("print_templates.engine_owned")}
                </p>
              )}

              {/* ── free-text i18n (ja / en / vi) ───────────────────────── */}
              {editable.includes("i18n") && (
                <div className="mt-2 grid gap-2 sm:grid-cols-3">
                  {PRINT_LOCALES.map((locale) => {
                    const reason = lockReason(block.id, "i18n");
                    return (
                      <GatedControl key={locale} reason={reason}>
                        <div className="flex flex-col gap-1">
                          <Label className="text-[11px] text-muted-foreground">
                            {t(`print_templates.locale.${locale}`)}
                          </Label>
                          <Input
                            className="h-8 text-xs"
                            disabled={reason !== null}
                            data-testid={`i18n-${block.id}-${locale}`}
                            value={toI18nMap(block.i18n)[locale]}
                            onChange={(event) =>
                              patchBlock(block.id, {
                                i18n: { ...toI18nMap(block.i18n), [locale]: event.target.value },
                              })
                            }
                          />
                        </div>
                      </GatedControl>
                    );
                  })}
                </div>
              )}

              {/* ── scalar props ────────────────────────────────────────── */}
              <div className="mt-2 flex flex-wrap items-end gap-3">
                {editable.includes("align") && (
                  <GatedControl reason={lockReason(block.id, "align")}>
                    <div className="flex flex-col gap-1">
                      <Label className="text-[11px] text-muted-foreground">
                        {t("print_templates.prop.align")}
                      </Label>
                      <Select
                        value={block.align ?? "left"}
                        disabled={lockReason(block.id, "align") !== null}
                        onValueChange={(value) =>
                          patchBlock(block.id, { align: value as PrintBlock["align"] })
                        }
                      >
                        <SelectTrigger
                          className="h-8 w-28 text-xs"
                          data-testid={`align-${block.id}`}
                        >
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {ALIGNMENTS.map((value) => (
                            <SelectItem key={value} value={value}>
                              {t(`print_templates.align.${value}`)}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </GatedControl>
                )}

                {editable.includes("source") && (
                  <GatedControl reason={lockReason(block.id, "source")}>
                    <div className="flex flex-col gap-1">
                      <Label className="text-[11px] text-muted-foreground">
                        {t("print_templates.prop.source")}
                      </Label>
                      <Select
                        value={block.source ?? catalog.sources[0]}
                        disabled={lockReason(block.id, "source") !== null}
                        onValueChange={(value) => patchBlock(block.id, { source: value })}
                      >
                        <SelectTrigger
                          className="h-8 w-44 text-xs"
                          data-testid={`source-${block.id}`}
                        >
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {catalog.sources.map((source) => (
                            <SelectItem key={source} value={source}>
                              {source}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </GatedControl>
                )}

                {editable.includes("max_width_dots") && (
                  <GatedControl reason={lockReason(block.id, "max_width_dots")}>
                    <div className="flex flex-col gap-1">
                      <Label className="text-[11px] text-muted-foreground">
                        {t("print_templates.prop.max_width_dots")}
                      </Label>
                      <Input
                        type="number"
                        className="h-8 w-28 text-xs"
                        disabled={lockReason(block.id, "max_width_dots") !== null}
                        value={
                          block.max_width_dots === undefined ? "" : String(block.max_width_dots)
                        }
                        onChange={(event) =>
                          patchBlock(block.id, {
                            max_width_dots: event.target.value
                              ? Number(event.target.value)
                              : undefined,
                          })
                        }
                      />
                    </div>
                  </GatedControl>
                )}

                {editable.includes("bold") && (
                  <GatedControl reason={lockReason(block.id, "bold")}>
                    <label className="flex items-center gap-1.5 text-[11px]">
                      <Checkbox
                        checked={block.bold === true}
                        disabled={lockReason(block.id, "bold") !== null}
                        onCheckedChange={(checked) =>
                          patchBlock(block.id, { bold: checked === true })
                        }
                      />
                      {t("print_templates.prop.bold")}
                    </label>
                  </GatedControl>
                )}

                {editable.includes("fallback") && (
                  <GatedControl reason={lockReason(block.id, "fallback")}>
                    <label className="flex items-center gap-1.5 text-[11px]">
                      <Checkbox
                        checked={block.fallback === true}
                        disabled={lockReason(block.id, "fallback") !== null}
                        data-testid={`fallback-${block.id}`}
                        onCheckedChange={(checked) =>
                          patchBlock(block.id, { fallback: checked === true })
                        }
                      />
                      {t("print_templates.prop.fallback")}
                    </label>
                  </GatedControl>
                )}
              </div>

              {/* ── list props ──────────────────────────────────────────── */}
              {editable.includes("fields") && (
                <ChipList
                  label={t("print_templates.prop.fields")}
                  options={catalog.param_fields}
                  selected={block.fields ?? []}
                  reason={lockReason(block.id, "fields")}
                  testId={`fields-${block.id}`}
                  onToggle={(value, on) =>
                    patchBlock(block.id, {
                      fields: on
                        ? [...(block.fields ?? []), value]
                        : (block.fields ?? []).filter((field) => field !== value),
                    })
                  }
                />
              )}

              {editable.includes("columns") && (
                <ChipList
                  label={t("print_templates.prop.columns")}
                  options={catalog.prop_enums[block.id]?.columns ?? []}
                  selected={block.columns ?? []}
                  reason={lockReason(block.id, "columns")}
                  testId={`columns-${block.id}`}
                  onToggle={(value, on) =>
                    patchBlock(block.id, {
                      columns: on
                        ? [...(block.columns ?? []), value]
                        : (block.columns ?? []).filter((column) => column !== value),
                    })
                  }
                />
              )}
            </section>
          );
        })}
      </div>
    </TooltipProvider>
  );
}

/** Wraps a control so a disabled one always explains itself (TR-03). */
function GatedControl({ reason, children }: { reason: string | null; children: React.ReactNode }) {
  if (!reason) return <>{children}</>;
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span className="inline-block cursor-not-allowed opacity-60" tabIndex={0}>
          {children}
        </span>
      </TooltipTrigger>
      <TooltipContent className="max-w-64 text-xs">{reason}</TooltipContent>
    </Tooltip>
  );
}

function ChipList({
  label,
  options,
  selected,
  reason,
  testId,
  onToggle,
}: {
  label: string;
  options: string[];
  selected: string[];
  reason: string | null;
  testId: string;
  onToggle: (value: string, on: boolean) => void;
}) {
  return (
    <div className="mt-2" data-testid={testId}>
      <Label className="text-[11px] text-muted-foreground">{label}</Label>
      <GatedControl reason={reason}>
        <div className="mt-1 flex flex-wrap gap-1.5">
          {options.map((option) => {
            const on = selected.includes(option);
            return (
              <label
                key={option}
                className={`flex items-center gap-1 rounded-md border px-1.5 py-0.5 font-mono text-[10px] ${
                  on ? "border-primary/50 bg-primary/5" : "border-border"
                }`}
              >
                <Checkbox
                  checked={on}
                  disabled={reason !== null}
                  onCheckedChange={(checked) => onToggle(option, checked === true)}
                />
                {option}
              </label>
            );
          })}
        </div>
      </GatedControl>
    </div>
  );
}
