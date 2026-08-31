// Pure helpers over the Cloud printer-config replica. Kept free of React so the
// role detection and print-error resolution are unit-testable in isolation.

import type { KioskPrinter } from '../types/printer';

/** Backend PrinterRoleEnum value for the customer receipt printer. */
export const RECEIPT_ROLE = 'receipt_printer';

/** The active printer that holds the receipt role, if the branch configured one. */
export function findReceiptPrinter(
  printers: KioskPrinter[],
): KioskPrinter | undefined {
  return printers.find((p) => p.is_active && p.roles?.includes(RECEIPT_ROLE));
}

/**
 * Pick the print-error i18n key to show.
 *
 * `useReceiptPrint` reports `workstation.print_no_workstation` whenever the LAN
 * has no reachable workstation — but that message ("no workstation on this
 * network") is misleading when the real problem is that no receipt printer was
 * ever configured in the admin. Once the Cloud replica has loaded successfully
 * and shows no receipt printer, swap to the config-gap message so staff fix it
 * in admin instead of hunting for a workstation. Only swaps on a SUCCESSFUL
 * load (not on a Cloud-outage fetch error, which says nothing about config).
 */
export function resolvePrintErrorKey(
  errorKey: string | null,
  opts: { hasReceiptPrinter: boolean; printersLoaded: boolean },
): string | null {
  if (
    errorKey === 'workstation.print_no_workstation' &&
    opts.printersLoaded &&
    !opts.hasReceiptPrinter
  ) {
    return 'workstation.print_no_printer_configured';
  }
  return errorKey;
}
