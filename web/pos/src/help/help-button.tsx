/**
 * The `?` button, and the drawer it opens.
 *
 * One component for every surface in pos-web — pages, dialogs, modals, panels.
 * Point it at a topic id and it renders the whole guide for the operator's
 * current language:
 *
 *   <HelpButton topic="payment" />
 *
 * ## Why a Sheet (a Radix dialog) and not a hand-rolled overlay
 *
 * Most `?` buttons in this app sit INSIDE another modal — the payment dialog,
 * the void dialog, the settle confirmation. A hand-rolled fixed overlay portaled
 * to `document.body` would be a sibling of the parent dialog's portal, and Radix
 * would read every click inside it as a click OUTSIDE the parent: opening help
 * from the payment dialog would dismiss the payment dialog under it. A Sheet
 * registers on Radix's own dismissable-layer stack, so it is the topmost layer —
 * Escape closes only the help, and clicks inside it are not "outside" anything.
 *
 * (`SheetContent` is fine to take straight from `@godxjp/ui`; the arch test that
 * forbids a raw import guards `DialogContent`, which is the one that carries the
 * per-dialog error boundary.)
 *
 * ## Why `type="button"`
 *
 * Several hosts are `<form>`s (pairing, cash event). A bare `<button>` inside a
 * form submits it, so a cashier asking for help would have fired the form.
 */

import { useState } from "react";
import {
  BookOpenIcon,
  CircleHelpIcon,
  ListOrderedIcon,
  PlugZapIcon,
  TargetIcon,
  TriangleAlertIcon,
} from "lucide-react";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@godxjp/ui";
import { cn } from "@/lib/utils";
import { useOptionalTranslation } from "@/providers/app-provider";
import { getHelpTopic } from ".";
import type { HelpTopicId } from "./types";

export interface HelpButtonProps {
  /** Which guide to show. */
  topic: HelpTopicId;
  /**
   * Extra classes on the trigger. Use it to fit the button into a header row
   * (`ml-auto`, colour overrides on tinted headers) — never to change its size
   * below the 28px touch target.
   */
  className?: string;
  /**
   * Render the label beside the icon instead of icon-only. Used on wide page
   * headers where a bare `?` next to a long title reads as decoration.
   */
  withLabel?: boolean;
}

export function HelpButton({ topic, className, withLabel }: HelpButtonProps) {
  // Optional, not required: this button is dropped into components that are
  // deliberately mountable without an AppProvider (see the hook's docblock).
  // Requiring one here would make adding help to a component a breaking change
  // for that component's own tests.
  const { t, locale } = useOptionalTranslation();
  // Controlled rather than using SheetTrigger: several hosts render the button
  // inside an element that already handles clicks (a dropdown item, a card
  // header), and an explicit handler that calls stopPropagation is the only way
  // to open help without also firing the host's own action.
  const [open, setOpen] = useState(false);
  const content = getHelpTopic(topic, locale);
  const label = t("help.button.label");

  return (
    <>
      <button
        type="button"
        onClick={(event) => {
          event.preventDefault();
          event.stopPropagation();
          setOpen(true);
        }}
        title={label}
        aria-label={label}
        data-slot="help-button"
        data-help-topic={topic}
        className={cn(
          "inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-full",
          "text-muted-foreground transition-colors hover:bg-muted hover:text-foreground",
          "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/50",
          withLabel ? "h-8 px-2.5 text-xs font-medium" : "size-8",
          className,
        )}
      >
        <CircleHelpIcon className="size-4" />
        {withLabel && <span>{label}</span>}
      </button>

      <Sheet open={open} onOpenChange={setOpen}>
        <SheetContent
          side="right"
          className="w-full gap-0 overflow-hidden sm:max-w-lg"
        >
          {/* Explicit padding: `SheetHeader`'s own is `p-[var(--density-sheet)]`,
              and pos-web does not import `@godxjp/ui`'s theme.css, so that token
              is undefined here and the declaration is dropped — the header would
              sit flush against the edge. `pr-12` keeps the title clear of the
              close X the Sheet renders at top-4 right-4. */}
          <SheetHeader className="px-5 pb-4 pr-12 pt-5">
            {content.subtitle && (
              <span className="text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                {content.subtitle}
              </span>
            )}
            <SheetTitle className="text-base leading-snug">
              {content.title}
            </SheetTitle>
            <SheetDescription className="text-xs">
              {t("help.drawer.subtitle")}
            </SheetDescription>
          </SheetHeader>

          <div
            data-slot="help-drawer-body"
            className="flex-1 overflow-y-auto border-t px-5 pb-12 pt-5"
          >
            <div className="flex flex-col gap-6">
              <HelpSection
                icon={<TargetIcon className="size-3.5" />}
                title={t("help.section.purpose")}
              >
                <p className="text-sm leading-relaxed text-muted-foreground">
                  {content.purpose}
                </p>
              </HelpSection>

              {content.setup && content.setup.length > 0 && (
                <HelpSection
                  icon={<PlugZapIcon className="size-3.5" />}
                  title={t("help.section.setup")}
                >
                  <ul className="list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                    {content.setup.map((item) => (
                      <li key={item}>{item}</li>
                    ))}
                  </ul>
                </HelpSection>
              )}

              {content.usage && content.usage.length > 0 && (
                <HelpSection
                  icon={<ListOrderedIcon className="size-3.5" />}
                  title={t("help.section.usage")}
                >
                  <ol className="list-decimal space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground marker:text-xs marker:font-semibold marker:text-foreground">
                    {content.usage.map((step) => (
                      <li key={step}>{step}</li>
                    ))}
                  </ol>
                </HelpSection>
              )}

              {content.checks && content.checks.length > 0 && (
                <HelpSection
                  icon={<TriangleAlertIcon className="size-3.5" />}
                  title={t("help.section.checks")}
                >
                  <ul className="list-disc space-y-2 pl-5 text-sm leading-relaxed text-muted-foreground">
                    {content.checks.map((item) => (
                      <li key={item}>{item}</li>
                    ))}
                  </ul>
                </HelpSection>
              )}

              {content.glossary && content.glossary.length > 0 && (
                <HelpSection
                  icon={<BookOpenIcon className="size-3.5" />}
                  title={t("help.section.glossary")}
                >
                  <dl className="flex flex-col gap-2">
                    {content.glossary.map((entry) => (
                      <div
                        key={entry.term}
                        className="rounded-md bg-muted/60 px-3 py-2"
                      >
                        <dt className="text-sm font-medium text-foreground">
                          {entry.term}
                        </dt>
                        <dd className="mt-0.5 text-sm leading-relaxed text-muted-foreground">
                          {entry.description}
                        </dd>
                      </div>
                    ))}
                  </dl>
                </HelpSection>
              )}

              <p className="border-t pt-4 text-xs leading-relaxed text-muted-foreground">
                {t("help.drawer.footer")}
              </p>
            </div>
          </div>
        </SheetContent>
      </Sheet>
    </>
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
    <section className="flex flex-col gap-2">
      <h3 className="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-foreground">
        <span className="text-muted-foreground">{icon}</span>
        {title}
      </h3>
      {children}
    </section>
  );
}
