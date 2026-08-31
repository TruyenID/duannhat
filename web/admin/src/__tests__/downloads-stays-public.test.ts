import { describe, expect, it } from "vitest";
import { NextRequest } from "next/server";
import { proxy } from "../proxy";

/**
 * #3088 — trang tải bản workstation phải mở cho người CHƯA đăng nhập.
 *
 * Người đi cài máy ở quán không có tài khoản admin. Bắt họ đăng nhập để tải
 * trình cài là đóng đúng cánh cửa trang này sinh ra để mở, và chủ dự án đã chốt
 * ("làm ở next ko cần auth", 2026-08-17).
 *
 * Vì sao cần một bài test cho MỘT phần tử trong mảng: nó đã hỏng đúng kiểu đó.
 * Trang ship từ 17/08 nhưng `/downloads` không có trong `PUBLIC_PATHS`, nên mọi
 * lượt truy cập bị 307 về `/login?redirect=%2Fdownloads` rồi đá tiếp sang SSO.
 * Không test nào đỏ, và nhìn từ ngoài nó giống hệt "trang chưa deploy" — tôi đã
 * mất một vòng đi tìm ở tầng AWS trước khi nhận ra nó nằm trong repo.
 *
 * Bài này cũng ghim VẾ NGƯỢC LẠI. Một danh sách công khai chỉ nới ra được, nên
 * thứ đáng canh ngang với "downloads mở" là "trang quản trị vẫn đóng".
 */
function visit(path: string) {
  return proxy(new NextRequest(new URL(path, "https://tempo.godx.jp")));
}

describe("#3088 — cổng đăng nhập của admin-web", () => {
  it("để /downloads đi qua khi CHƯA đăng nhập", () => {
    const res = visit("/downloads");
    expect(res.status, "/downloads bị chặn — người cài máy không có tài khoản admin").not.toBe(307);
    expect(res.headers.get("location")).toBeNull();
  });

  it("để cả đường con của /downloads đi qua", () => {
    expect(visit("/downloads/workstation").status).not.toBe(307);
  });

  it("VẪN chặn trang quản trị khi chưa đăng nhập", () => {
    // Vế ngược lại. Không có nó, một bản vá mở toang mọi thứ cũng qua bài trên.
    const res = visit("/hq/betoya/products");
    expect(res.status).toBe(307);
    expect(res.headers.get("location")).toContain("/login?redirect=%2Fhq%2Fbetoya%2Fproducts");
  });

  it("KHÔNG mở nhầm một đường chỉ TRÙNG TIỀN TỐ", () => {
    // `startsWith(p + "/")` là đúng; `startsWith(p)` thì `/downloads-secret`
    // cũng lọt. Ghim để không ai đơn giản hoá nó.
    expect(visit("/downloads-secret").status).toBe(307);
  });
});

/**
 * Lớp thứ hai: `matcher` phải loại `/downloads` để proxy KHÔNG CHẠY cho nó.
 *
 * `PUBLIC_PATHS` cho trang đi qua sau khi proxy đã chạy; `matcher` khiến proxy
 * không được gọi ngay từ đầu. Chủ dự án yêu cầu vế thứ hai — trang tải không
 * nên phụ thuộc vào một nhánh `if` bên trong hàm auth còn đúng hay không.
 */
describe("#3088 — matcher loại /downloads khỏi proxy", () => {
  it("regex của matcher KHÔNG khớp /downloads", async () => {
    const { config } = await import("../proxy");
    const pattern = config.matcher[0];
    const re = new RegExp(`^${pattern}$`);

    expect(re.test("/downloads"), "matcher vẫn bắt /downloads — proxy sẽ chạy cho trang công khai").toBe(false);
    expect(re.test("/downloads/workstation")).toBe(false);
  });

  it("matcher VẪN bắt trang quản trị", () => {
    // Vế ngược lại: một regex nới quá tay sẽ tắt auth cho cả app.
    const re = new RegExp(`^/((?!_next/static|_next/image|favicon.ico|api|s3/|downloads).*)$`);
    expect(re.test("/hq/betoya/products")).toBe(true);
    expect(re.test("/login")).toBe(true);
  });
});
