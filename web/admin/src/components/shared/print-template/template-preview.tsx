"use client";

/**
 * Slip preview panel — plan-053 M5 (#1171), T4.3.
 *
 * The image comes from the SERVER, drawn by the same renderer that drives the
 * printer (`App\Services\Print\Renderer\SvgRenderer` over `SlipComposer`).
 * Until M5 this panel redrew the slip in TypeScript, which meant two
 * implementations of one set of layout rules — and the copy that never touched
 * a printer was the one a brand approved from. There is no client-side
 * renderer here any more, and adding one back is the bug, not the fallback.
 *
 * The SVG is shown through an `<img>` rather than inlined. Inlining would let
 * anything inside the document execute in the admin's origin; an `<img>` cannot
 * run script or fetch a remote resource, whatever the bytes say. The server
 * already escapes brand-authored text and ships a `sandbox` CSP — this is the
 * second lock on the same door, because brand copy is user input.
 */

import { useMemo } from "react";
import {
  Button,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
  Spinner,
} from "@godxjp/ui";
import { TriangleAlert } from "lucide-react";
import { useTranslation } from "@/providers/app-provider";
import { usePrintTemplatePreview } from "@/hooks/api/use-print-templates";
import {
  PRINT_LOCALES,
  PRINT_PAPERS,
  toI18nMap,
  type PaperSize,
  type PrintLocale,
  type PrintTemplateDefinition,
} from "@/types/models/PrintTemplate";

export interface TemplatePreviewProps {
  /** Whose surface this is — it picks the endpoint and its permission. */
  scope: "brand" | "shop";
  /** Brand slug for `scope="brand"`, shop slug for `scope="shop"`. */
  slug: string;
  kind: string;
  /** The editor's CURRENT state, unsaved edits included. */
  definition: PrintTemplateDefinition;
  paper: PaperSize;
  onPaperChange: (paper: PaperSize) => void;
  locale: PrintLocale;
  onLocaleChange: (locale: PrintLocale) => void;
}

/**
 * Which authored blocks have no copy in the chosen locale.
 *
 * This is NOT a second renderer and must never become one: it measures nothing,
 * lays nothing out and decides no column. It reads the definition the author is
 * looking at and reports "you have not written this in Vietnamese" — a fact
 * about the document, which the server cannot tell the client through an image.
 * The moment it computes a width or a line break it is the duplicate this file
 * exists to have deleted.
 */
function localeFallbackBlocks(
  definition: PrintTemplateDefinition,
  locale: PrintLocale
): string[] {
  return definition.blocks
    .filter((block) => {
      if (block.enabled === false) return false;
      const map = toI18nMap(block.i18n);
      // A block with no copy at all is a structural block, not a gap.
      const authored = PRINT_LOCALES.some((l) => map[l].trim() !== "");
      return authored && map[locale].trim() === "";
    })
    .map((block) => block.id);
}

export function TemplatePreview({
  scope,
  slug,
  kind,
  definition,
  paper,
  onPaperChange,
  locale,
  onLocaleChange,
}: TemplatePreviewProps) {
  const { t } = useTranslation();

  const { data, isPending, isError, refetch, isFetching } = usePrintTemplatePreview(
    scope,
    slug,
    kind,
    { definition, paper, locale }
  );

  /*
   * A data URL, not `URL.createObjectURL`. An object URL has to be revoked by
   * hand, and the one thing that changes here is the definition — a URL leaked
   * per edit would pin every version of the slip in memory for the life of the
   * tab. A data URL is garbage-collected with the string.
   */
  const src = useMemo(
    () => (data ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(data)}` : null),
    [data]
  );

  const fellBack = localeFallbackBlocks(definition, locale);

  return (
    <div data-slot="template-preview" className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center gap-2">
        <Select value={paper} onValueChange={(v) => onPaperChange(v as PaperSize)}>
          <SelectTrigger className="h-8 w-28 text-xs" data-testid="preview-paper">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PRINT_PAPERS.map((p) => (
              <SelectItem key={p} value={p}>
                {p}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select value={locale} onValueChange={(v) => onLocaleChange(v as PrintLocale)}>
          <SelectTrigger className="h-8 w-28 text-xs" data-testid="preview-locale">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {PRINT_LOCALES.map((l) => (
              <SelectItem key={l} value={l}>
                {t(`print_templates.locale.${l}`)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {isFetching && <Spinner className="size-3.5 text-muted-foreground" />}
      </div>

      <p className="text-[11px] text-muted-foreground">{t("print_templates.preview.note")}</p>

      {fellBack.length > 0 && (
        <div className="flex items-start gap-1.5 rounded-md border border-amber-300 bg-amber-50 px-2 py-1.5 text-[11px] text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
          <TriangleAlert className="mt-0.5 size-3 shrink-0" />
          <span>
            {t("print_templates.preview.locale_fallback", { blocks: fellBack.join(", ") })}
          </span>
        </div>
      )}

      <div className="flex justify-center overflow-x-auto rounded-md bg-muted/40 p-4">
        {isError ? (
          <div
            data-testid="preview-error"
            className="flex flex-col items-center gap-2 py-6 text-xs text-muted-foreground"
          >
            <p>{t("print_templates.preview.failed")}</p>
            <Button variant="outline" size="sm" onClick={() => void refetch()}>
              {t("common.retry")}
            </Button>
          </div>
        ) : isPending || !src ? (
          <div className="flex items-center gap-2 py-6 text-xs text-muted-foreground">
            <Spinner className="size-3.5" />
            {t("common.loading")}
          </div>
        ) : (
          /*
           * `next/image` is wrong here on both counts: the source is a data URL
           * the optimizer cannot fetch, and a receipt preview must render at
           * its exact geometry — re-encoding it is the one thing this panel
           * must not do.
           */
          // eslint-disable-next-line @next/next/no-img-element
          <img
            data-testid="preview-paper-sheet"
            src={src}
            alt={t("print_templates.preview.alt")}
            className="max-w-full rounded-sm bg-white shadow-sm"
          />
        )}
      </div>
    </div>
  );
}
