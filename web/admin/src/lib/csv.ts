/**
 * CSV building + download for accounting exports (#1157).
 *
 * Deliberately dumb: it escapes, joins, and hands the browser a Blob. It does
 * NOT format money. Amounts belong in an export as the raw integer the server
 * sent plus the currency code in its own column — a localized "¥1,234" would
 * arrive in Excel as text, and a locale-formatted "1.234" is ambiguous between
 * one thousand two hundred thirty-four and one point two three four.
 */

/** RFC-4180 field escaping. */
export function csvCell(value: string | number | null | undefined): string {
  if (value === null || value === undefined) return "";
  const s = String(value);
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

/**
 * Join a header row and body rows into a CSV document.
 *
 * The leading BOM is what makes Excel open a UTF-8 file as UTF-8 — without it,
 * Japanese and Vietnamese column headers arrive as mojibake.
 */
export function buildCsv(
  header: Array<string | number | null | undefined>,
  rows: Array<Array<string | number | null | undefined>>
): string {
  const lines = [header.map(csvCell).join(","), ...rows.map((r) => r.map(csvCell).join(","))];
  return "﻿" + lines.join("\n");
}

/** Trigger a browser download for CSV text. No-op outside the browser. */
export function downloadCsv(filename: string, csv: string): void {
  if (typeof document === "undefined") return;
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
