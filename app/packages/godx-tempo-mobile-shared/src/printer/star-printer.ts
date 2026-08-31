/**
 * Wrapper around `react-native-star-io10` for Star MC-Print3 (LAN ESC/POS).
 *
 * The Star SDK is a native module: it is **not** available in Expo Go or in
 * a web bundle. We `require()` it lazily so importing this file from a screen
 * doesn't crash app startup on those runtimes; the exported functions throw
 * a clear error only when actually invoked. Apps testing on Expo Go can
 * therefore navigate around the printer settings screen without a hard crash.
 *
 * Native module is declared as an **optional** dependency of this package —
 * web-only consumers (or apps that don't print) skip the install entirely.
 */

import { Platform } from 'react-native';

/** Width of the printable area on 80mm thermal paper at 203 DPI (≈ 72mm). */
const PRINTER_DOTS_WIDTH = 576;

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type StarModule = any;

let cached: StarModule | null | undefined;

function loadStar(): StarModule {
  if (cached !== undefined) {
    if (cached === null) throw missingPrinterSdkError();
    return cached;
  }
  if (Platform.OS === 'web') {
    cached = null;
    throw new Error('Star printer SDK does not support web.');
  }
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    cached = require('react-native-star-io10');
    return cached;
  } catch {
    cached = null;
    throw missingPrinterSdkError();
  }
}

function missingPrinterSdkError(): Error {
  return new Error(
    'react-native-star-io10 is not available in this runtime. ' +
      'Use a development build (`npx expo run:ios` / `run:android`) ' +
      'instead of Expo Go.',
  );
}

function createPrinter(ip: string) {
  const Star = loadStar();
  const settings = new Star.StarConnectionSettings();
  settings.interfaceType = Star.InterfaceType.Lan;
  settings.identifier = ip;
  return new Star.StarPrinter(settings);
}

export interface TestPrintOptions {
  /** Header label printed at top — defaults to "TEST PRINT". */
  header?: string;
  /** Sub-line printed under the header — defaults to "GodX". */
  appName?: string;
  /** Locale for date/time formatting — defaults to ja-JP. */
  locale?: string;
}

/**
 * Connects to a Star MC-Print3 on the LAN at `ip` and prints a test page.
 * Throws if the connection fails. The caller is responsible for catching
 * and surfacing the error to the user.
 */
export async function testPrinterConnection(
  ip: string,
  options: TestPrintOptions = {},
): Promise<void> {
  const { header = 'TEST PRINT', appName = 'GodX', locale = 'ja-JP' } = options;
  const Star = loadStar();
  const printer = createPrinter(ip);

  const now = new Date();
  const dateStr = now.toLocaleDateString(locale, {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  });
  const timeStr = now.toLocaleTimeString(locale, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });

  try {
    const builder = new Star.StarXpandCommand.PrinterBuilder()
      .styleAlignment(Star.StarXpandCommand.Printer.Alignment.Center)
      .styleBold(true)
      .styleMagnification(new Star.StarXpandCommand.MagnificationParameter(1, 2))
      .actionPrintText(`${header}\n`)
      .styleMagnification(new Star.StarXpandCommand.MagnificationParameter(1, 1))
      .styleBold(false)
      .actionPrintRuledLine(new Star.StarXpandCommand.Printer.RuledLineParameter(72))
      .actionPrintText(`${appName}\n`)
      .actionPrintText(`IP: ${ip}\n`)
      .actionPrintText(`${dateStr}  ${timeStr}\n`)
      .actionPrintRuledLine(new Star.StarXpandCommand.Printer.RuledLineParameter(72))
      .styleBold(true)
      .actionPrintText('OK  CONNECTED\n')
      .styleBold(false)
      .actionFeedLine(2)
      .actionCut(Star.StarXpandCommand.Printer.CutType.Partial);

    const cmd = new Star.StarXpandCommand.StarXpandCommandBuilder();
    cmd.addDocument(new Star.StarXpandCommand.DocumentBuilder().addPrinter(builder));

    await printer.open();
    await printer.print(await cmd.getCommands());
  } finally {
    await printer.close();
  }
}

/**
 * Sends a base64-encoded PNG to the printer.
 * The image is rendered at 576 dots wide (the printable area on 80mm paper).
 */
export async function printReceiptImage(ip: string, imageBase64: string): Promise<void> {
  const Star = loadStar();
  const printer = createPrinter(ip);

  try {
    const imageParam = new Star.StarXpandCommand.Printer.ImageParameter(
      `data:image/png;base64,${imageBase64}`,
      PRINTER_DOTS_WIDTH,
    )
      .setEffectDiffusion(false)
      .setThreshold(180);

    const builder = new Star.StarXpandCommand.PrinterBuilder()
      .actionPrintImage(imageParam)
      .actionFeedLine(1)
      .actionCut(Star.StarXpandCommand.Printer.CutType.Partial);

    const cmd = new Star.StarXpandCommand.StarXpandCommandBuilder();
    cmd.addDocument(new Star.StarXpandCommand.DocumentBuilder().addPrinter(builder));

    await printer.open();
    await printer.print(await cmd.getCommands());
  } finally {
    await printer.close();
  }
}
