"use client";

import { cn } from "@/lib/utils";
import { formatMinor, isUnknownCurrency } from "@/lib/money-minor";
import { useTranslation } from "@/providers/app-provider";

export interface MinorAmountProps {
  /** Integer in the currency's smallest unit, exactly as the API sent it. */
  minor: number | string | null | undefined;
  /** The currency of THIS row — never the UI locale's currency. */
  currency: string | null | undefined;
  className?: string;
  /** Render zero and null in muted grey (fee columns are mostly zero). */
  muteZero?: boolean;
}

/**
 * A single money figure from the settlement API (#1157).
 *
 * The currency comes from the row because a brand can hold connections in more
 * than one currency, and the scaling rule differs between them: JPY and VND are
 * zero-decimal, so their minor unit IS the yen / dong. See `lib/money-minor.ts`
 * for why a blanket `/100` is the bug this whole path is built around.
 *
 * When the currency is unrecognised the amount renders as raw minor units with
 * a label instead of a guessed money value — deliberately conspicuous.
 */
export function MinorAmount({ minor, currency, className, muteZero }: MinorAmountProps) {
  const { t, locale } = useTranslation();

  const unknown = isUnknownCurrency(currency);
  const text = formatMinor(minor, locale, currency, {
    unknownCurrencyLabel: t("hq.settlements.money.minor_units"),
  });
  const isZero = Number(minor ?? 0) === 0;

  return (
    <span
      data-slot="minor-amount"
      title={unknown ? t("hq.settlements.money.unknown_currency_hint") : undefined}
      className={cn(
        "tabular-nums",
        unknown && "text-muted-foreground italic",
        muteZero && isZero && "text-muted-foreground",
        className
      )}
    >
      {text}
    </span>
  );
}
