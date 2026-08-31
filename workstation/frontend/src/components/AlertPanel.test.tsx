import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router";
import { afterEach, describe, expect, it, vi } from "vitest";

import { AlertPanel } from "./AlertPanel";
import { AppProvider } from "../providers/app-provider";
import type { WorkstationAlert } from "../lib/api";

/**
 * #3133 — bài test ĐẦU TIÊN của `workstation/frontend`, nhắm đúng chỗ đã trả giá.
 *
 * #2848 ship một màn hình **chạm tiền** ở app này (xác nhận cảnh báo lệch tiền,
 * fail-closed khi không có ca, xác nhận hai bước) mà **không một dòng nào có
 * rào** — vì app không có test runner nào để chạy rào cả. Ba điều được ghim ở
 * đây là ba điều đã hỏng hoặc suýt hỏng trên máy thật:
 *
 * 1. **Nút ack đi theo cờ `ack_required` do máy trạm gửi.** Bản trước gõ cứng
 *    `kind === "cash_retained"`, nên `cloud_money_overwrite` hiện ra mà KHÔNG
 *    có nút nào và ba dòng ở 本郷店/人形町店 treo từ 2026-08-13.
 * 2. **Fail-closed khi máy trả bản cũ không có `ack_actor`.** Mặc định "không
 *    biết ai xác nhận" phải CHẶN, không mở — ghi một tác nhân giả vào sổ kiểm
 *    toán tiền còn tệ hơn không ghi.
 * 3. **Nút "cả đợt" chỉ hiện khi kind đó có ≥2 dòng.** Một dòng thì nút hàng
 *    loạt chỉ là nút đơn lẻ với nhiều rủi ro hơn.
 *
 * Bài này đi qua `fetch` chứ không mock `../lib/api`: luật fail-closed
 * (`?? NO_ACK_ACTOR`) sống trong `listAlerts()`, nên mock ở tầng ấy sẽ đo chính
 * cái mock. Và nó cho phép hỏi câu thứ hai mà #2848 đặt ra — *bấm vào có gọi
 * đúng endpoint không*.
 */

type FetchCall = { url: string; method: string; body: unknown };

function alert(over: Partial<WorkstationAlert> = {}): WorkstationAlert {
  return {
    id: "a1",
    kind: "cloud_money_overwrite",
    subject: "till-1",
    severity: "critical",
    audience: "shop",
    title: "Lệch tiền",
    detail: null,
    first_seen_at: "2026-08-17T01:00:00Z",
    last_seen_at: "2026-08-17T01:00:00Z",
    count: 1,
    ack_required: true,
    ...over,
  };
}

/** Máy trạm trả gì cho `GET /api/alerts` — nguyên văn, kể cả khoá bị THIẾU. */
function serve(payload: unknown): FetchCall[] {
  const calls: FetchCall[] = [];

  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: unknown, init?: RequestInit) => {
      const url = String(input);
      calls.push({
        url,
        method: init?.method ?? "GET",
        body: init?.body === undefined ? undefined : JSON.parse(String(init.body)),
      });

      const data = url.endsWith("/api/alerts") ? payload : { acked: 1 };
      return { ok: true, status: 200, json: async () => data } as unknown as Response;
    }),
  );

  return calls;
}

function mount() {
  return render(
    <MemoryRouter>
      <AppProvider>
        <AlertPanel />
      </AppProvider>
    </MemoryRouter>,
  );
}

/** Ca đang mở và có tên người — điều kiện để nút ack bấm được. */
const OPEN_SHIFT = { shift_in_progress: true, session_code: "S-01", name: "田中" };

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("AlertPanel — nút xác nhận", () => {
  it("chỉ vẽ nút cho alert có ack_required (#2848: KHÔNG theo danh sách kind gõ cứng)", async () => {
    serve({
      alerts: [
        alert({ id: "money", kind: "cloud_money_overwrite", title: "Cloud ghi đè", ack_required: true }),
        alert({ id: "printer", kind: "printer_offline", title: "Máy in mất kết nối", ack_required: false }),
      ],
      ack_actor: OPEN_SHIFT,
    });

    mount();

    // Cả hai dòng đều hiện — cờ chỉ quyết định NÚT, không quyết định hiển thị.
    expect(await screen.findByText("Cloud ghi đè")).toBeInTheDocument();
    expect(screen.getByText("Máy in mất kết nối")).toBeInTheDocument();

    // ĐÚNG MỘT nút ack. Bản gõ cứng `cash_retained` cho ra 0 nút ở đây.
    expect(screen.getAllByRole("button", { name: "確認済みにする" })).toHaveLength(1);
  });

  it("bấm ack gọi đúng endpoint của dòng đó", async () => {
    const calls = serve({ alerts: [alert({ id: "money-42" })], ack_actor: OPEN_SHIFT });

    mount();

    const button = await screen.findByRole("button", { name: "確認済みにする" });
    fireEvent.click(button);

    await waitFor(() => {
      expect(calls.some((c) => c.method === "POST" && c.url.endsWith("/api/alerts/money-42/ack"))).toBe(
        true,
      );
    });
  });
});

describe("AlertPanel — fail-closed khi máy trả bản cũ", () => {
  it("thiếu ack_actor ⇒ không ack được, và nói ra vì sao", async () => {
    // Máy trạm cũ hơn frontend: khoá `ack_actor` KHÔNG có trong payload.
    // `listAlerts()` phải rơi về NO_ACK_ACTOR (`shift_in_progress: false`),
    // chứ không phải coi như đang có ca.
    const calls = serve({ alerts: [alert({ id: "money" })] });

    mount();

    expect(
      await screen.findByText(
        "レジ（精算）が開いていないため確認できません。先にレジ開けを行ってください。",
      ),
    ).toBeInTheDocument();

    const button = screen.getByRole("button", { name: "確認済みにする" });
    expect(button).toBeDisabled();

    fireEvent.click(button);
    expect(calls.filter((c) => c.method === "POST")).toHaveLength(0);
  });
});

describe("AlertPanel — xác nhận cả đợt", () => {
  it("kind có ≥2 dòng thì có nút cả đợt; kind một dòng thì không", async () => {
    serve({
      alerts: [
        alert({ id: "m1", kind: "cloud_money_overwrite" }),
        alert({ id: "m2", kind: "cloud_money_overwrite" }),
        alert({ id: "c1", kind: "cash_retained" }),
      ],
      ack_actor: OPEN_SHIFT,
    });

    mount();

    expect(
      await screen.findByRole("button", {
        name: "「クラウドによる金額の上書き」2 件をまとめて確認",
      }),
    ).toBeInTheDocument();

    // `cash_retained` chỉ có MỘT dòng ⇒ không có nút cả đợt cho nó.
    expect(
      screen.queryByRole("button", { name: /「釣銭機に現金が残留」.*まとめて確認/ }),
    ).not.toBeInTheDocument();
  });

  it("dòng không cần ack KHÔNG được tính vào số lượng của đợt", async () => {
    // Hai dòng cùng kind nhưng chỉ một dòng cần ack ⇒ không phải một "đợt".
    // Đếm nhầm ở đây sẽ hứa đóng 2 dòng rồi đóng 1, ngay trên màn hình tiền.
    serve({
      alerts: [
        alert({ id: "m1", kind: "cloud_money_overwrite", ack_required: true }),
        alert({ id: "m2", kind: "cloud_money_overwrite", ack_required: false }),
      ],
      ack_actor: OPEN_SHIFT,
    });

    mount();

    expect(await screen.findByRole("button", { name: "確認済みにする" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /まとめて確認/ })).not.toBeInTheDocument();
  });

  it("nút cả đợt HAI BƯỚC: bấm lần một chỉ hỏi, chưa đóng gì", async () => {
    const calls = serve({
      alerts: [
        alert({ id: "m1", kind: "cloud_money_overwrite" }),
        alert({ id: "m2", kind: "cloud_money_overwrite" }),
      ],
      ack_actor: OPEN_SHIFT,
    });

    mount();

    fireEvent.click(
      await screen.findByRole("button", {
        name: "「クラウドによる金額の上書き」2 件をまとめて確認",
      }),
    );

    // Lời hỏi phải nói rõ SỐ DÒNG và TÊN NGƯỜI sẽ vào sổ.
    expect(
      screen.getByText("「クラウドによる金額の上書き」の 2 件を確認済みにします。確認者は 田中 です。"),
    ).toBeInTheDocument();
    expect(calls.filter((c) => c.method === "POST")).toHaveLength(0);

    fireEvent.click(screen.getByRole("button", { name: "2 件を確認済みにする" }));

    await waitFor(() => {
      const post = calls.find((c) => c.method === "POST");
      expect(post?.url.endsWith("/api/alerts/ack-kind")).toBe(true);
      expect(post?.body).toMatchObject({ kind: "cloud_money_overwrite" });
    });
  });
});
