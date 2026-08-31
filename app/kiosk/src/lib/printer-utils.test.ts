import { describe, it, expect } from 'vitest';
import { findReceiptPrinter, resolvePrintErrorKey, RECEIPT_ROLE } from './printer-utils';
import type { KioskPrinter } from '../types/printer';

function printer(over: Partial<KioskPrinter>): KioskPrinter {
  return {
    id: 'p1',
    name: 'P',
    roles: [],
    connection_type: 'lan',
    address: '192.168.1.50',
    paper_width: 80,
    cut_type: 'partial',
    encoding: 'utf-8',
    is_active: true,
    ...over,
  };
}

describe('findReceiptPrinter', () => {
  it('finds the active printer holding the receipt role', () => {
    const kitchen = printer({ id: 'k', roles: ['kitchen_printer'] });
    const receipt = printer({ id: 'r', roles: ['hall_printer', RECEIPT_ROLE] });
    expect(findReceiptPrinter([kitchen, receipt])?.id).toBe('r');
  });

  it('returns undefined when no printer has the receipt role', () => {
    expect(findReceiptPrinter([printer({ roles: ['kitchen_printer'] })])).toBeUndefined();
  });

  it('ignores an inactive receipt printer', () => {
    expect(
      findReceiptPrinter([printer({ roles: [RECEIPT_ROLE], is_active: false })]),
    ).toBeUndefined();
  });

  it('is safe on an empty list', () => {
    expect(findReceiptPrinter([])).toBeUndefined();
  });
});

describe('resolvePrintErrorKey', () => {
  const NO_WS = 'workstation.print_no_workstation';
  const NO_PRINTER = 'workstation.print_no_printer_configured';

  it('swaps no-workstation → no-printer when Cloud loaded and no receipt printer', () => {
    expect(
      resolvePrintErrorKey(NO_WS, { hasReceiptPrinter: false, printersLoaded: true }),
    ).toBe(NO_PRINTER);
  });

  it('keeps no-workstation when a receipt printer IS configured', () => {
    expect(
      resolvePrintErrorKey(NO_WS, { hasReceiptPrinter: true, printersLoaded: true }),
    ).toBe(NO_WS);
  });

  it('keeps no-workstation when the Cloud config has not loaded (e.g. outage)', () => {
    // An unloaded/errored replica says nothing about config — do not claim
    // "no printer configured" when we simply could not read it.
    expect(
      resolvePrintErrorKey(NO_WS, { hasReceiptPrinter: false, printersLoaded: false }),
    ).toBe(NO_WS);
  });

  it('passes other error keys through untouched', () => {
    expect(
      resolvePrintErrorKey('some.other.key', { hasReceiptPrinter: false, printersLoaded: true }),
    ).toBe('some.other.key');
    expect(
      resolvePrintErrorKey(null, { hasReceiptPrinter: false, printersLoaded: true }),
    ).toBeNull();
  });
});
