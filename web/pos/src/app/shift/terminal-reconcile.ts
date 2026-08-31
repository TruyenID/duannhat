/**
 * Payment-terminal (決済端末) device reconciliation math for the shift close
 * screen.
 *
 * #1156 — a branch may run SEVERAL physical terminals (Stera + StarPay, …),
 * each with its own end-of-shift batch slip. Tenders are grouped into one
 * section per terminal via the device's `accepts` (see ./tender-terminals),
 * and each section carries its own declared-vs-system compare +
 * `terminal_batch_total`. A shop with no registered terminal data keeps the
 * pre-#1156 behaviour: ONE generic section covering every non-cash tender.
 *
 * The backend (`TillSessionService::close` → `persistSettlementDetails`)
 * still gates per anchor row (card/credit) and per rolled-up category
 * (qr/emoney), so a section-level reason must be stamped onto exactly the
 * tenders that gate needs it on. `reasonCarrierKeys` is that set — extracted
 * here so it can be unit-tested independently of the close-page component.
 * `computeSectionReconciles` partitions the SAME global carrier set across
 * sections, so the union over sections always equals the single-terminal
 * result (the backend gate never sees a difference).
 */
import type { TillTenderType } from "@/services/till-service";
import type { TerminalSection } from "./tender-terminals";

function num(s: string): number {
  const n = Number(s);
  return Number.isFinite(n) && n >= 0 ? n : 0;
}

const round2 = (n: number): number => Math.round(n * 100) / 100;

export interface TerminalReconcileInput {
  /** All active tender types for the branch. */
  tenderTypes: TillTenderType[];
  /** Cashier's per-tender entry (gross/cancel as raw input strings). */
  tenders: Record<string, { gross: string; cancel: string }>;
  /** Σ(gross − cancel) the cashier declared, keyed by category. */
  categoryDeclared: Record<string, number>;
  /** System expected, keyed by category (from the reconciliation payload). */
  categoryExpected: Record<string, number>;
  /** Per-anchor expected (cash/credit carry a row-level expected). */
  expectedByTender: Record<string, number | null>;
  /** Non-cash category keys visible in the reconciliation UI. */
  visibleCategoryKeys: string[];
  /** Till variance tolerance (abs). */
  tolerance: number;
}

export interface TerminalReconcileResult {
  /** Σ of the cashier's entries across every non-cash category. */
  declaredTotal: number;
  /** Σ expected across every non-cash category (the device's system revenue). */
  systemTotal: number;
  /** declaredTotal − systemTotal. */
  variance: number;
  /**
   * Tenders that must carry the single device-level `variance_reason` so the
   * backend gate passes: every out-of-tolerance anchor row, plus ONE carrier
   * per out-of-tolerance non-cash category (a touched row if any, else the
   * first row so an untouched-but-diverging category still gets a reason).
   */
  reasonCarrierKeys: Set<string>;
  /**
   * #2616 — CÙNG tập với `reasonCarrierKeys`, nhưng mang theo CON SỐ.
   *
   * Trước bản này số lệch được tính rồi vứt ngay sau phép so sánh, chỉ giữ lại
   * key. Hệ quả trên sàn: hai tổng hiển thị đều `±0` (vì hai dòng lệch ngược
   * dấu triệt tiêu nhau), ô nhập lý do hiện ra, nút bị khoá — và **không đâu nói
   * dòng nào lệch**. Thu ngân đứng trước một màn hình toàn số 0 và một lỗi
   * chung chung, giữa lúc kết ca.
   *
   * Giữ `reasonCarrierKeys` để chỗ gọi cũ không phải đổi; nó dẫn xuất từ đây.
   */
  reasonCarriers: ReasonCarrier[];
}

/** Một dòng/nhóm đang vượt ngưỡng, kèm lý do vượt. */
export interface ReasonCarrier {
  tenderKey: string;
  /** Nhãn thô của tender (`name`); chỗ hiển thị tự chọn locale. */
  name: Record<string, string> | string;
  declared: number;
  expected: number;
  /** `declared - expected`. Dương = dư, âm = thiếu. */
  variance: number;
  /**
   * `anchor` = luật per-row (thẻ/credit so riêng dòng mình).
   * `category` = luật gộp nhóm (qr/emoney so tổng nhóm).
   * Hai luật khác nhau nên thông điệp cho thu ngân cũng phải khác.
   */
  rule: "anchor" | "category";
}

export function computeTerminalReconcile(
  input: TerminalReconcileInput,
): TerminalReconcileResult {
  const {
    tenderTypes,
    tenders,
    categoryDeclared,
    categoryExpected,
    expectedByTender,
    visibleCategoryKeys,
    tolerance,
  } = input;

  const systemTotal = visibleCategoryKeys.reduce(
    (sum, k) => sum + (categoryExpected[k] ?? 0),
    0,
  );
  const declaredTotal = visibleCategoryKeys.reduce(
    (sum, k) => sum + (categoryDeclared[k] ?? 0),
    0,
  );
  const variance = round2(declaredTotal - systemTotal);

  const grouped: Record<string, TillTenderType[]> = {};
  for (const tt of tenderTypes) {
    (grouped[tt.category] ??= []).push(tt);
  }

  const reasonCarriers: ReasonCarrier[] = [];

  // Anchor rows (card/credit) — row-level expected vs declared.
  for (const tt of tenderTypes) {
    if (tt.category === "cash" || !tt.is_expected_anchor) continue;
    const exp = expectedByTender[tt.tender_key];
    if (exp === null || exp === undefined) continue;
    const ti = tenders[tt.tender_key];
    const net = ti ? num(ti.gross) - num(ti.cancel) : 0;
    if (Math.abs(net - exp) > tolerance) {
      reasonCarriers.push({
        tenderKey: tt.tender_key,
        name: tt.name,
        declared: net,
        expected: exp,
        variance: round2(net - exp),
        rule: "anchor",
      });
    }
  }

  // One carrier per out-of-tolerance non-cash category.
  for (const k of visibleCategoryKeys) {
    const declared = categoryDeclared[k] ?? 0;
    const exp = categoryExpected[k] ?? 0;
    if (Math.abs(round2(declared - exp)) <= tolerance) continue;
    const rows = grouped[k] ?? [];
    const touched = rows.find((tt) => {
      const ti = tenders[tt.tender_key];
      return !!ti && (ti.gross.trim() !== "" || ti.cancel.trim() !== "");
    });
    const carrier = touched ?? rows[0];
    if (carrier) {
      // Số báo cho thu ngân là chênh lệch của CẢ NHÓM, không phải của riêng
      // dòng mang lý do — luật B so tổng nhóm, nên hiện số của một dòng sẽ nói
      // sai chuyện đang xảy ra.
      reasonCarriers.push({
        tenderKey: carrier.tender_key,
        name: carrier.name,
        declared,
        expected: exp,
        variance: round2(declared - exp),
        rule: "category",
      });
    }
  }

  // Một tender có thể bị CẢ HAI luật gắn cờ: luật A vì dòng của nó lệch, luật B
  // vì cả nhóm nó thuộc về cũng lệch. `reasonCarrierKeys` là Set nên xưa nay
  // gộp im lặng; mảng thì phơi ra, và nếu để nguyên thì UI hiện cùng một tender
  // hai dòng với hai con số — trông như hai lỗi khác nhau.
  //
  // Giữ bản `anchor`: nó là sự thật ở mức DÒNG của chính tender đó. Luật B chỉ
  // cần MỘT dòng nào đó trong nhóm đứng ra nhận lý do, mà dòng ấy đã có mặt rồi.
  const deduped: ReasonCarrier[] = [];
  const seen = new Set<string>();
  for (const c of reasonCarriers) {
    if (seen.has(c.tenderKey)) continue;
    seen.add(c.tenderKey);
    deduped.push(c);
  }

  return {
    declaredTotal,
    systemTotal,
    variance,
    reasonCarrierKeys: new Set(deduped.map((c) => c.tenderKey)),
    reasonCarriers: deduped,
  };
}

/** Per-terminal-section reconcile result (#1156). */
export interface SectionReconcile {
  /** The section this row describes (device id or generic). */
  section: TerminalSection;
  /** Σ(gross − cancel) the cashier entered across this section's tenders. */
  declaredTotal: number;
  /**
   * Σ category-level expected over the categories ATTRIBUTED to this section
   * (see TerminalSection.ownedCategoryKeys — per-brand expected from
   * `order_payments.tender_key` is a backend follow-up).
   */
  systemTotal: number;
  /** declaredTotal − systemTotal. */
  variance: number;
  /**
   * The subset of the GLOBAL reason-carrier set living in this section — the
   * tenders this section's reason must be stamped onto at submit.
   */
  reasonCarrierKeys: Set<string>;
  /** #2616 — cùng tập, kèm con số, để UI nói được dòng nào lệch bao nhiêu. */
  reasonCarriers: ReasonCarrier[];
}

/**
 * Split the single-terminal reconcile into per-section rows. Invariants:
 *  - Σ declaredTotal over sections   = global declaredTotal
 *  - Σ systemTotal over sections    = global systemTotal
 *  - ∪ reasonCarrierKeys over sections = global reasonCarrierKeys
 * (the last one is what keeps the backend variance-reason gate satisfied
 * exactly as before). A carrier that belongs to no section — a tender of a
 * category hidden from the section builder — is attached to the LAST section
 * so its reason is still collected somewhere.
 */
export function computeSectionReconciles(
  input: TerminalReconcileInput,
  sections: TerminalSection[],
): SectionReconcile[] {
  if (sections.length === 0) return [];

  const { reasonCarrierKeys: globalCarriers, reasonCarriers: globalCarrierDetails } =
    computeTerminalReconcile(input);
  const detailByKey = new Map(globalCarrierDetails.map((c) => [c.tenderKey, c]));

  const sectionOfTender = new Map<string, number>();
  sections.forEach((s, i) => {
    for (const key of s.tenderKeys) {
      if (!sectionOfTender.has(key)) sectionOfTender.set(key, i);
    }
  });

  const results: SectionReconcile[] = sections.map((section) => {
    const declaredTotal = section.tenderKeys.reduce((sum, key) => {
      const ti = input.tenders[key];
      return sum + (ti ? num(ti.gross) - num(ti.cancel) : 0);
    }, 0);
    const systemTotal = section.ownedCategoryKeys.reduce(
      (sum, cat) => sum + (input.categoryExpected[cat] ?? 0),
      0,
    );
    return {
      section,
      declaredTotal: round2(declaredTotal),
      systemTotal: round2(systemTotal),
      variance: round2(declaredTotal - systemTotal),
      reasonCarrierKeys: new Set<string>(),
      reasonCarriers: [],
    };
  });

  for (const key of globalCarriers) {
    const idx = sectionOfTender.get(key) ?? results.length - 1;
    results[idx].reasonCarrierKeys.add(key);
    const detail = detailByKey.get(key);
    if (detail) results[idx].reasonCarriers.push(detail);
  }

  return results;
}
