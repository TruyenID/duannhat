/**
 * Star MC-Print3 (LAN ESC/POS) printer wrapper.
 *
 * Re-exports the shared implementation from `@godxjp/mobile-shared`. The test
 * print page defaults to "TempoFast TMS" so callers don't need to pass options
 * for the common case.
 */
import {
  printReceiptImage,
  testPrinterConnection as sharedTestPrinterConnection,
  type TestPrintOptions,
} from '@godxjp/mobile-shared';

export { printReceiptImage };

export function testPrinterConnection(
  ip: string,
  options: TestPrintOptions = {},
): Promise<void> {
  return sharedTestPrinterConnection(ip, { appName: 'TempoFast TMS', ...options });
}
