import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { ShiftHistoryPage } from "./page";

/**
 * #3062 — trang lịch sử ca + nút in lại phiếu 精算.
 *
 * Bài nặng nhất ở đây là bài TỪ CHỐI: nút in lại chỉ được hiện sống cho ca ĐÃ
 * CHỐT. Một ca đang mở chưa có `settlement_snapshot`, nên tờ giấy in ra sẽ nói
 * về một việc chưa xảy ra — cùng lớp lỗi với hoá đơn của đơn chưa đóng (#3040),
 * và cùng lớp với dòng món đã huỷ vẫn nằm trên biên lai (#3044).
 */
const printShiftReport = vi.hoisted(() => vi.fn());
vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: { enabled: true, printShiftReport },
}));

const useTillSessionHistory = vi.hoisted(() => vi.fn());
vi.mock("@/hooks/api/use-till", () => ({
  useTillSessionHistory: (...a: unknown[]) => useTillSessionHistory(...a),
}));

vi.mock("@/hooks/api/use-shop", () => ({
  useShop: () => ({ data: { data: { name: "本郷店" } } }),
}));

vi.mock("@/app/pos/components/pos-header", () => ({
  PosHeader: () => <div data-testid="pos-header" />,
}));

vi.mock("react-router-dom", () => ({
  useParams: () => ({ shopSlug: "hongo" }),
}));

const toastWarning = vi.hoisted(() => vi.fn());
const toastError = vi.hoisted(() => vi.fn());
const toastSuccess = vi.hoisted(() => vi.fn());
vi.mock("sonner", () => ({
  toast: { warning: toastWarning, error: toastError, success: toastSuccess },
}));

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

const session = (over: Record<string, unknown> = {}) => ({
  id: "sess-1",
  session_code: "S-0009",
  status: "settled",
  business_date: "2026-08-16",
  default_currency_code: "JPY",
  opened_at: "2026-08-16T06:36:16Z",
  closed_at: "2026-08-16T12:50:11Z",
  settlement_kind: "final",
  ...over,
});

beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
  printShiftReport.mockReset();
  printShiftReport.mockResolvedValue({ status: "ok" });
  toastWarning.mockReset();
  toastError.mockReset();
  toastSuccess.mockReset();
  useTillSessionHistory.mockReturnValue({ data: [session()], isLoading: false });
});

const reprintButtons = () => screen.getAllByRole("button", { name: /In lại phiếu/ });

describe("ShiftHistoryPage", () => {
  it("liệt kê ca và in lại được ca ĐÃ CHỐT", async () => {
    render(<ShiftHistoryPage />, { wrapper: Wrapper });

    expect(screen.getByText("S-0009")).toBeInTheDocument();
    fireEvent.click(reprintButtons()[0]!);

    await waitFor(() =>
      expect(printShiftReport).toHaveBeenCalledWith({
        shopSlug: "hongo",
        sessionId: "sess-1",
        reportKind: "settlement",
      }),
    );
  });

  // Ca bàn giao phải in ĐÚNG tiêu đề của nó. Một tờ "in lại" mang tiêu đề khác
  // tờ gốc là hai chứng từ khác nhau về cùng một ca.
  it("ca bàn giao in lại với reportKind handover", async () => {
    useTillSessionHistory.mockReturnValue({
      data: [session({ settlement_kind: "handover" })],
      isLoading: false,
    });
    render(<ShiftHistoryPage />, { wrapper: Wrapper });

    fireEvent.click(reprintButtons()[0]!);

    await waitFor(() =>
      expect(printShiftReport).toHaveBeenCalledWith(
        expect.objectContaining({ reportKind: "handover" }),
      ),
    );
  });

  it("ca ĐANG MỞ thì nút in lại bị KHOÁ", () => {
    useTillSessionHistory.mockReturnValue({
      data: [session({ status: "open", closed_at: null })],
      isLoading: false,
    });
    render(<ShiftHistoryPage />, { wrapper: Wrapper });

    expect(reprintButtons()[0]!).toBeDisabled();
  });

  // Máy in offline ⇒ 503 `no_printer`. Nút phải NÓI RA, không nuốt — nếu không
  // thu ngân tưởng đã in và đi tìm tờ giấy không tồn tại.
  it("máy in offline thì cảnh báo, không im lặng", async () => {
    printShiftReport.mockResolvedValue({ status: "no_printer" });
    render(<ShiftHistoryPage />, { wrapper: Wrapper });

    fireEvent.click(reprintButtons()[0]!);

    await waitFor(() => expect(toastWarning).toHaveBeenCalled());
    expect(toastSuccess).not.toHaveBeenCalled();
  });

  // `opened_at` khai nullable ở `till-service.ts`, và `new Date(null)` KHÔNG ném
  // lỗi — nó ra `Invalid Date` rồi in thẳng lên màn hình. Hỏng kiểu đó không đỏ
  // ở đâu cả; nó chỉ hiện ra khi thu ngân đọc phải một dòng vô nghĩa.
  it("ca thiếu giờ mở thì hiện gạch, không hiện Invalid Date", () => {
    useTillSessionHistory.mockReturnValue({
      data: [session({ opened_at: null, closed_at: null })],
      isLoading: false,
    });
    render(<ShiftHistoryPage />, { wrapper: Wrapper });

    expect(screen.queryByText(/Invalid Date/)).not.toBeInTheDocument();
    expect(screen.getByText("—")).toBeInTheDocument();
  });

  it("danh sách rỗng thì nói rõ, không hiện bảng trống", () => {
    useTillSessionHistory.mockReturnValue({ data: [], isLoading: false });
    render(<ShiftHistoryPage />, { wrapper: Wrapper });

    expect(screen.getByText("Chưa có ca nào.")).toBeInTheDocument();
  });
});
