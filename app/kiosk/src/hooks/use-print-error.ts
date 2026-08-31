// src/hooks/use-print-error.ts
//
// Resolves the human-readable print-error detail for the success screens,
// distinguishing "no workstation on the LAN" from "no receipt printer configured
// in Cloud" using the printer replica. Shared so all four success screens
// (dine-in / custom / split / split-by-items) render the same message logic.

import { useTranslation } from '../providers/app-provider';
import { resolvePrintErrorKey } from '../lib/printer-utils';
import { useKioskPrinters } from './use-kiosk-printers';
import type { UseReceiptPrintReturn } from './use-receipt-print';

export function usePrintErrorDetail(
  printer: UseReceiptPrintReturn,
  autoPrintReason: string | null,
): string | null {
  const { t } = useTranslation();
  const { hasReceiptPrinter, loaded } = useKioskPrinters();

  const key = resolvePrintErrorKey(printer.errorKey, {
    hasReceiptPrinter,
    printersLoaded: loaded,
  });
  if (key) return t(key);
  return printer.error ?? autoPrintReason;
}
