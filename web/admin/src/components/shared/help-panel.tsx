"use client";

import { BookOpen, CircleQuestionMark, ListOrdered, Target, TriangleAlert } from "lucide-react";
import {
  Button,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@godxjp/ui";
import { useTranslation } from "@/providers/app-provider";

export interface HelpPanelGlossaryEntry {
  term: string;
  description: string;
}

export interface HelpPanelSections {
  /** One paragraph: what this screen is for and who uses it. */
  purpose?: string;
  /** The operating flow, in the order performed. Rendered as a numbered list. */
  usage?: string[];
  /** Constraints, cut-offs, irreversible actions, silent failures. */
  checks?: string[];
  /** Column / metric definitions — especially any two that look addable but are not. */
  glossary?: HelpPanelGlossaryEntry[];
}

export interface HelpPanelProps extends HelpPanelSections {
  /** Screen name — pass the SAME i18n key the PageHeader title uses. */
  title: string;
  /** Optional second-language name of the screen, shown as an eyebrow. */
  subtitle?: string;
}

/**
 * Right-hand drawer explaining one screen: purpose, flow, gotchas, glossary.
 *
 * Every string is passed in from the caller's `t()` — this component holds no
 * copy of its own beyond the shared chrome (`help.panel.*`), so a screen's help
 * text lives next to the screen that renders it.
 *
 * Layout notes specific to `@godxjp/ui`'s Sheet (they differ from other Omnify
 * projects — this build has no `SheetBody` and no `width` prop):
 * - `SheetContent` defaults to `w-3/4 sm:max-w-sm`; help text needs more room,
 *   so the width is widened here and still caps at the viewport on mobile.
 * - `SheetHeader` carries `p-[var(--density-sheet)]`; the scroll body below it
 *   repeats that padding so both align on the same left edge.
 * - The close button is absolutely positioned at `top-4 right-4`, so the header
 *   keeps right padding to stop the title running underneath it.
 */
export function HelpPanel({ title, subtitle, purpose, usage, checks, glossary }: HelpPanelProps) {
  const { t } = useTranslation();

  const hasUsage = Boolean(usage?.length);
  const hasChecks = Boolean(checks?.length);
  const hasGlossary = Boolean(glossary?.length);

  return (
    <Sheet>
      <SheetTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="size-7 text-muted-foreground"
          title={t("help.panel.action")}
          aria-label={t("help.panel.action")}
        >
          <CircleQuestionMark className="size-3.5" />
        </Button>
      </SheetTrigger>

      {/* Never pass `data-slot` to SheetContent / Button — both set their own,
          and `...props` spreads after it, so ours would erase theirs. This
          component's slot goes on the scroll body below, which it owns. */}
      <SheetContent side="right" className="w-full gap-0 sm:max-w-md">
        <SheetHeader className="pr-12">
          {subtitle && (
            <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
              {subtitle}
            </span>
          )}
          <SheetTitle className="text-base">{t("help.panel.title", { title })}</SheetTitle>
          <SheetDescription>{t("help.panel.subtitle")}</SheetDescription>
        </SheetHeader>

        <div
          data-slot="help-panel"
          className="flex-1 overflow-y-auto border-t px-[var(--density-sheet)] pt-5 pb-10"
        >
          <div className="flex flex-col gap-6">
            {purpose && (
              <HelpSection
                icon={<Target className="size-3.5" />}
                title={t("help.panel.section.purpose")}
              >
                <p className="text-sm leading-relaxed text-muted-foreground">{purpose}</p>
              </HelpSection>
            )}

            {hasUsage && (
              <HelpSection
                icon={<ListOrdered className="size-3.5" />}
                title={t("help.panel.section.usage")}
              >
                <ol className="list-decimal space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground marker:text-xs marker:font-medium marker:text-foreground">
                  {usage?.map((step, i) => (
                    <li key={i}>{step}</li>
                  ))}
                </ol>
              </HelpSection>
            )}

            {hasChecks && (
              <HelpSection
                icon={<TriangleAlert className="size-3.5" />}
                title={t("help.panel.section.checks")}
              >
                <ul className="list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                  {checks?.map((item, i) => (
                    <li key={i}>{item}</li>
                  ))}
                </ul>
              </HelpSection>
            )}

            {hasGlossary && (
              <HelpSection
                icon={<BookOpen className="size-3.5" />}
                title={t("help.panel.section.glossary")}
              >
                <dl className="flex flex-col gap-2">
                  {glossary?.map((entry, i) => (
                    <div key={i} className="rounded-md bg-muted/60 px-3 py-2">
                      <dt className="text-sm font-medium text-foreground">{entry.term}</dt>
                      <dd className="mt-0.5 text-sm leading-relaxed text-muted-foreground">
                        {entry.description}
                      </dd>
                    </div>
                  ))}
                </dl>
              </HelpSection>
            )}
          </div>
        </div>
      </SheetContent>
    </Sheet>
  );
}

function HelpSection({
  icon,
  title,
  children,
}: {
  icon: React.ReactNode;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section>
      <h3 className="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-foreground uppercase">
        <span className="text-muted-foreground">{icon}</span>
        {title}
      </h3>
      {children}
    </section>
  );
}
