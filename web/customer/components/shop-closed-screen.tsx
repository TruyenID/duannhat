"use client";

import { Clock } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import Link from "next/link";

import MenuStateCard from "@/components/menu-state-card";
import { buttonVariants } from "@/components/ui/button";
import { useCurrentBranchOpenState, useNextOpeningDayLabel } from "@/hooks/use-branch-open-state";

interface ShopClosedScreenProps {
  /** Shop name, so the customer knows WHICH branch is shut. */
  branchName: string;
}

/**
 * #1167 — what a take-away customer gets INSTEAD of the menu while the shop is
 * closed. Not a banner over a browsable menu: the menu is gone, because an
 * orderable-looking list at a shut shop is the confusion this ticket is about.
 *
 * Deliberately shaped like the "menu outside its schedule window" card next to
 * it in menu-page.tsx — same clock badge, same "next opening" row, same
 * "choose another store" escape hatch — so the two closed states read as one
 * thing to the customer. #1750 moved that shape into MenuStateCard so the
 * likeness is structural rather than a copy someone has to remember to keep
 * in sync.
 *
 * Dine-in keeps its advisory banner instead (see shop-closed-notice.tsx): that
 * customer is sitting at the table and must still be able to order.
 */
export default function ShopClosedScreen({ branchName }: ShopClosedScreenProps) {
  const t = useTranslations("shop");
  const tMenu = useTranslations("menu");
  const locale = useLocale();
  const { nextOpening } = useCurrentBranchOpenState();
  const reopenDay = useNextOpeningDayLabel(nextOpening);

  return (
    <MenuStateCard
      icon={Clock}
      tone="amber"
      titleId="shop-closed-title"
      title={t("closedScreenTitle")}
      description={
        nextOpening
          ? t("closedScreenDescription", { branch: branchName })
          : t("closedScreenDescriptionNoSchedule", { branch: branchName })
      }
      details={
        nextOpening && (
          <dl className="mt-5 divide-y rounded-xl border bg-neutral-50 px-4 text-left text-sm">
            <div className="flex justify-between gap-4 py-3">
              <dt className="text-neutral-500">{t("closedScreenReopenLabel")}</dt>
              <dd className="text-right font-semibold text-neutral-900">
                {reopenDay}
                <br />
                {nextOpening.time}
              </dd>
            </div>
          </dl>
        )
      }
      actions={
        <Link
          href={`/${locale}/select-branch?next=takeaway`}
          className={buttonVariants({ className: "w-full sm:w-auto" })}
        >
          {tMenu("chooseAnotherStore")}
        </Link>
      }
    />
  );
}
