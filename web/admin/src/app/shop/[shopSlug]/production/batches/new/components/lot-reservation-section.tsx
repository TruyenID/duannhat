"use client";

import { Badge, Input, Switch } from "@godxjp/ui";
import { useTranslation, useTimezone } from "@/providers/app-provider";
import { formatDate } from "@/lib/date";
import type { MaterialLot } from "@/services/material-lot-service";

// =========================================================================
//  Types
// =========================================================================

export interface LotReservation {
  lot_id: string;
  qty: number;
}

export interface LotReservationSectionProps {
  lots: MaterialLot[];
  isLoading: boolean;
  unit: string;
  enabled: boolean;
  onEnabledChange: (enabled: boolean) => void;
  /** lotId → the string shown in that row's input (already merged: override ?? FEFO). */
  values: Record<string, string>;
  /** lotId → the FEFO suggestion, rendered as a hint under the input. */
  suggestions: Record<string, number>;
  onQtyChange: (lotId: string, raw: string) => void;
}

// =========================================================================
//  FEFO helper — fill from earliest-expiry lots until planned qty is met
// =========================================================================

export function suggestFefoReservations(
  lots: MaterialLot[],
  plannedQty: number
): LotReservation[] {
  const sorted = [...lots].sort((a, b) => {
    if (!a.expiry_date && !b.expiry_date) return 0;
    if (!a.expiry_date) return 1;
    if (!b.expiry_date) return -1;
    return a.expiry_date.localeCompare(b.expiry_date);
  });

  let remaining = plannedQty;
  const result: LotReservation[] = [];

  for (const lot of sorted) {
    if (remaining <= 0) break;
    const available = Number(lot.qty_on_hand);
    if (available <= 0) continue;
    const qty = Math.min(available, remaining);
    result.push({ lot_id: lot.id, qty });
    remaining -= qty;
  }

  return result;
}

// =========================================================================
//  Component
// =========================================================================

/**
 * #3077 → #3112. This section used to collect the user's picks and drop them
 * into `console.info`. It is back because the write path exists now:
 * `POST /shops/{shopSlug}/material-lot-reservations`, gated by the `*AtShop`
 * abilities, which the warehouse staff who use this screen actually hold.
 *
 * Presentational on purpose — the lots query, the FEFO math and the resulting
 * reservation list all live in the page, because the page is what has to POST
 * them once the batch has an id. Keeping the state here would have meant
 * pushing it upward from an effect, which is the shape React 19 warns about
 * and which the previous version silenced with an exhaustive-deps disable.
 */
export function LotReservationSection({
  lots,
  isLoading,
  unit,
  enabled,
  onEnabledChange,
  values,
  suggestions,
  onQtyChange,
}: LotReservationSectionProps) {
  const { t, locale } = useTranslation();
  const { timezone } = useTimezone();

  return (
    <div data-slot="lot-reservation-section" className="mb-4 max-w-2xl space-y-3">
      {/* Toggle */}
      <div className="flex items-center gap-3">
        <Switch id="reserve-lots-toggle" checked={enabled} onCheckedChange={onEnabledChange} />
        <label htmlFor="reserve-lots-toggle" className="text-sm font-medium">
          {t("shop.production.batches.form.reserve_lots")}
        </label>
      </div>

      {enabled && (
        <p className="text-xs text-muted-foreground">
          {t("shop.production.batches.form.reserve_lots_help")}
        </p>
      )}

      {/* Lot list */}
      {enabled && (
        <>
          {lots.length === 0 && !isLoading && (
            <p className="text-xs text-muted-foreground">
              {t("shop.production.batches.form.no_lots_available")}
            </p>
          )}

          {lots.length > 0 && (
            <div className="overflow-x-auto rounded-md border">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/40 text-xs text-muted-foreground">
                    <th className="px-3 py-2 text-left font-medium">
                      {t("shop.production.batches.form.reserve_lot_code")}
                    </th>
                    <th className="px-3 py-2 text-right font-medium">
                      {t("shop.production.batches.form.reserve_available")}
                    </th>
                    <th className="px-3 py-2 text-left font-medium">
                      {t("shop.production.batches.form.reserve_expiry")}
                    </th>
                    <th className="px-3 py-2 text-right font-medium">
                      {t("shop.production.batches.form.reserve_qty")}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {lots.map((lot) => {
                    const suggestedQty = suggestions[lot.id] ?? 0;
                    const available = Number(lot.qty_on_hand);

                    return (
                      <tr key={lot.id} className="border-b last:border-b-0">
                        <td className="px-3 py-2">
                          <code className="text-xs">{lot.lot_code}</code>
                        </td>
                        <td className="px-3 py-2 text-right tabular-nums">
                          {available.toLocaleString()} {unit}
                        </td>
                        <td className="px-3 py-2">
                          {lot.expiry_date ? (
                            <Badge variant="outline" className="text-xs">
                              {formatDate(lot.expiry_date, locale, timezone)}
                            </Badge>
                          ) : (
                            <span className="text-xs text-muted-foreground">—</span>
                          )}
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex flex-col items-end gap-0.5">
                            <Input
                              type="number"
                              min={0}
                              max={available}
                              step="0.01"
                              value={values[lot.id] ?? ""}
                              onChange={(e) => onQtyChange(lot.id, e.target.value)}
                              placeholder="0"
                              className="h-8 w-24 text-right text-sm"
                            />
                            {suggestedQty > 0 && (
                              <span className="text-[10px] text-muted-foreground">
                                {t("shop.production.batches.form.reserve_suggested", {
                                  qty: suggestedQty.toLocaleString(),
                                })}
                              </span>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  );
}
