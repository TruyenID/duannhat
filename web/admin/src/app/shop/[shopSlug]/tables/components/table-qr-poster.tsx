"use client";

/**
 * TableQrPoster — a single 74mm × 105mm (= ISO A7) printable poster that
 * reproduces the restaurant's "QRコードセルフ注文 / PLEASE SCAN QR CODE FOR
 * ORDERING" table sticker and drops the table's own QR into the white card.
 *
 * Everything is sized in millimetres via inline styles (not Tailwind) so the
 * artefact prints at exact physical dimensions regardless of screen DPI — the
 * `<PrintQrPostersDialog>` lays these out 4-up on A4 and cuts them apart.
 *
 * Dynamic per table:  `tableCode` + the QR built from `qrToken`.
 * Template chrome (title, 3 steps, wifi/feedback footer) is static branding,
 * with `brandLabel` / `feedbackUrl` overridable so the poster is not welded to
 * a single shop.
 */

import { QRCodeSVG } from "qrcode.react";
import { ArrowRight, Smartphone, Soup, Wallet, Wifi } from "lucide-react";
import { useRuntimeConfig } from "@/providers/runtime-config-provider";
import { buildDineInUrl } from "./dine-in-url";

// A7 — the physical poster size the restaurant prints and sticks on each table.
export const POSTER_WIDTH_MM = 74;
export const POSTER_HEIGHT_MM = 105;

// Template palette (matches the reference artwork).
const GREEN = "#17984a";
const RED = "#e23c3c";
const YELLOW = "#f5d20e";
const INK = "#1b1b1b";

export interface TableQrPosterProps {
  /** Shop slug — used to build the customer-web dine-in URL. */
  shopSlug: string;
  /** Raw QR token stored on the Table record. */
  qrToken: string;
  /** Human table code printed under the QR (e.g. "A-1"). */
  tableCode: string;
  /** Footer brand label under the WIFI box. Defaults to "betoya-pho". */
  brandLabel?: string;
  /** Optional URL for the static feedback QR shown in the footer. */
  feedbackUrl?: string;
  /** Optional className passed to the outer element (e.g. cut-guide border). */
  className?: string;
}

/** One of the ①②③ ordering steps. */
function Step({
  n,
  icon,
  label,
}: {
  n: number;
  icon: React.ReactNode;
  label: string;
}) {
  return (
    <div style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: "1mm" }}>
      <div style={{ display: "flex", alignItems: "center", gap: "1mm" }}>
        <span
          style={{
            width: "4.5mm",
            height: "4.5mm",
            borderRadius: "50%",
            background: YELLOW,
            color: INK,
            fontSize: "2.8mm",
            fontWeight: 800,
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            flexShrink: 0,
          }}
        >
          {n}
        </span>
        <span style={{ color: "#fff", display: "flex" }}>{icon}</span>
      </div>
      <span
        style={{
          background: YELLOW,
          color: INK,
          fontSize: "2mm",
          fontWeight: 700,
          borderRadius: "2mm",
          padding: "0.6mm 1.6mm",
          whiteSpace: "nowrap",
          lineHeight: 1.2,
        }}
      >
        {label}
      </span>
    </div>
  );
}

export function TableQrPoster({
  shopSlug,
  qrToken,
  tableCode,
  brandLabel = "betoya-pho",
  feedbackUrl,
  className,
}: TableQrPosterProps) {
  const { customerWebUrl } = useRuntimeConfig();
  const iconStyle = { width: "5.5mm", height: "5.5mm" } as const;
  const arrow = (
    <ArrowRight style={{ width: "4mm", height: "4mm", color: "#fff", flexShrink: 0 }} strokeWidth={3} />
  );

  return (
    <div
      data-slot="table-qr-poster"
      className={className}
      style={{
        width: `${POSTER_WIDTH_MM}mm`,
        height: `${POSTER_HEIGHT_MM}mm`,
        boxSizing: "border-box",
        background: GREEN,
        color: "#fff",
        padding: "3.5mm 4mm 3mm",
        display: "flex",
        flexDirection: "column",
        overflow: "hidden",
        // Force the green to print (browsers strip backgrounds by default).
        WebkitPrintColorAdjust: "exact",
        printColorAdjust: "exact",
      }}
    >
      {/* Header */}
      <div style={{ textAlign: "center" }}>
        <div style={{ fontSize: "6mm", fontWeight: 900, lineHeight: 1.05, letterSpacing: "-0.2mm" }}>
          QRコードセルフ注文
        </div>
        <div style={{ fontSize: "2.6mm", fontWeight: 800, lineHeight: 1.15, marginTop: "0.5mm" }}>
          PLEASE SCAN QR CODE
          <br />
          FOR ORDERING
        </div>
      </div>

      {/* White QR card */}
      <div
        style={{
          position: "relative",
          background: "#fff",
          borderRadius: "3mm",
          margin: "2mm 0",
          padding: "3.5mm 3mm 5mm",
          display: "flex",
          flexDirection: "column",
          alignItems: "center",
        }}
      >
        <QRCodeSVG
          value={buildDineInUrl(shopSlug, qrToken, customerWebUrl)}
          size={256}
          level="M"
          marginSize={0}
          bgColor="#ffffff"
          fgColor="#000000"
          style={{ width: "33mm", height: "33mm", display: "block" }}
        />
        <div style={{ color: INK, fontSize: "2.8mm", fontWeight: 700, marginTop: "1.2mm" }}>
          {tableCode}
        </div>
        {/* Red "dine-in only" pill overlapping the card bottom edge */}
        <div
          style={{
            position: "absolute",
            bottom: "-3mm",
            left: "50%",
            transform: "translateX(-50%)",
            background: RED,
            color: "#fff",
            fontSize: "2.6mm",
            fontWeight: 800,
            borderRadius: "3mm",
            padding: "1mm 3mm",
            whiteSpace: "nowrap",
            WebkitPrintColorAdjust: "exact",
            printColorAdjust: "exact",
          }}
        >
          店内のご利用のみとなります
        </div>
      </div>

      {/* Steps */}
      <div
        style={{
          borderTop: "0.3mm solid rgba(255,255,255,0.55)",
          paddingTop: "2mm",
          marginTop: "1mm",
          display: "flex",
          alignItems: "flex-start",
          justifyContent: "center",
          gap: "1.5mm",
        }}
      >
        <Step n={1} icon={<Smartphone style={iconStyle} />} label="QRコード読み取り" />
        {arrow}
        <Step n={2} icon={<Soup style={iconStyle} />} label="メニュー選択" />
        {arrow}
        <Step n={3} icon={<Wallet style={iconStyle} />} label="決済" />
      </div>

      {/* Footer: WIFI + feedback */}
      <div
        style={{
          borderTop: "0.3mm solid rgba(255,255,255,0.55)",
          paddingTop: "2mm",
          marginTop: "auto",
          display: "flex",
          alignItems: "center",
          gap: "2.5mm",
        }}
      >
        <div style={{ display: "flex", flexDirection: "column", alignItems: "center", flexShrink: 0 }}>
          <div
            style={{
              background: "rgba(255,255,255,0.16)",
              borderRadius: "1.5mm",
              padding: "1.5mm 2mm",
              display: "flex",
              flexDirection: "column",
              alignItems: "center",
              WebkitPrintColorAdjust: "exact",
              printColorAdjust: "exact",
            }}
          >
            <Wifi style={{ width: "5.5mm", height: "5.5mm", color: "#fff" }} />
            <span style={{ fontSize: "2mm", fontWeight: 800, marginTop: "0.5mm" }}>WIFI FREE</span>
          </div>
          <span style={{ fontSize: "2mm", fontWeight: 700, marginTop: "1mm" }}>{brandLabel}</span>
        </div>

        {feedbackUrl ? (
          <div style={{ background: "#fff", borderRadius: "1mm", padding: "1mm", flexShrink: 0 }}>
            <QRCodeSVG
              value={feedbackUrl}
              size={128}
              level="M"
              marginSize={0}
              bgColor="#ffffff"
              fgColor="#000000"
              style={{ width: "14mm", height: "14mm", display: "block" }}
            />
          </div>
        ) : null}

        <p style={{ fontSize: "1.9mm", lineHeight: 1.35, margin: 0, color: "#fff" }}>
          いつもベト屋をご利用いただきありがとうございます。当店は、サービス改善・向上のため、お客様のご意見・ご要望フォームを設置しております。
          {feedbackUrl ? "左側のコードをスキャンして、ぜひご投稿下さい。" : ""}
        </p>
      </div>
    </div>
  );
}
