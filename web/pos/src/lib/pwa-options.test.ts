import { describe, expect, it } from "vitest";
import { offlineShellOptions, WORKSTATION_MODE } from "./pwa-options";

/**
 * #1170 tầng 1. NĂM quyết định dưới đây đều hỏng theo kiểu **im lặng** — app
 * vẫn chạy, chỉ sai khi mất mạng hoặc khi có bản mới. Nên ghim.
 *
 * Quyết định thứ năm (`maximumFileSizeToCacheInBytes`) là ngoại lệ một nửa: khi
 * nó SAI, `vite build` thoát mã 1 nên có kêu — nhưng kêu bằng một thông báo nói
 * về workbox, không nói về bundle, và triệu chứng thật (bundle chính rơi khỏi
 * precache ⇒ mất mạng ra màn hình trắng) thì không kêu gì cả.
 *
 * Không kiểm được ở đây: `dist/sw.js` có thật hay không. Danh sách plugin giống
 * hệt nhau ở cả hai mode (`disable: true` vẫn nạp đủ 5 plugin cùng tên), nên
 * bằng chứng đó phải lấy từ build thật — xem thân PR #1170.
 */
describe("offlineShellOptions", () => {
  it("tắt service worker ở bản workstation", () => {
    // Workstation phục vụ bundle same-origin từ ổ đĩa và đã có version riêng.
    // Thêm SW là hai lớp cache cùng quản một bundle (plan-052 P-19).
    expect(offlineShellOptions(WORKSTATION_MODE).disable).toBe(true);
  });

  it("bật service worker ở bản cloud", () => {
    expect(offlineShellOptions("production").disable).toBe(false);
    expect(offlineShellOptions("development").disable).toBe(false);
  });

  it("không tự đăng ký bằng script inline — CSP của index.html chặn", () => {
    // `script-src 'self'` không có 'unsafe-inline'. Nếu đổi cái này thành
    // "auto"/"inline" thì SW không bao giờ đăng ký được, và chỉ lộ ra khi
    // người dùng đã mất mạng.
    expect(offlineShellOptions("production").injectRegister).toBeNull();
  });

  it("hỏi trước khi nạp lại, không tự nạp", () => {
    // Máy tính tiền: reload giữa lúc đang gõ đơn là mất việc đang làm.
    expect(offlineShellOptions("production").registerType).toBe("prompt");
  });

  it("không bao giờ phục vụ /api/ từ cache", () => {
    const { workbox } = offlineShellOptions("production");
    const apiRule = workbox?.runtimeCaching?.find(
      (r) => r.urlPattern instanceof RegExp && r.urlPattern.source.includes("api"),
    );
    expect(apiRule?.handler).toBe("NetworkOnly");
  });

  it("precache đủ chỗ cho bundle chính — nếu không, offline ra màn hình trắng", () => {
    const { workbox } = offlineShellOptions("production");
    const limit = workbox?.maximumFileSizeToCacheInBytes;

    // Phải khai TƯỜNG MINH. Bỏ dòng này đi là quay về mặc định 2 MiB của
    // workbox, mà bundle của POS đã vượt từ nhánh `dev` (2.099 MB) — build
    // gãy, và nếu ai đó "sửa" bằng cách nới trần ở chỗ khác thì bundle rơi
    // khỏi precache trong im lặng.
    expect(limit).toBeTypeOf("number");

    // Cận DƯỚI: phải trên mặc định 2 MiB, nếu không thì việc khai ra vô nghĩa.
    expect(limit!).toBeGreaterThan(2 * 1024 * 1024);

    // Cận dưới thật: bundle đo được lúc viết rào này là 2,29 MB. Đặt sàn ở
    // 3 MiB để một lần "dọn dẹp" hạ trần xuống sát bundle không tái lập đúng
    // cái bẫy 2,4 kB vừa gãy.
    expect(limit!).toBeGreaterThanOrEqual(3 * 1024 * 1024);

    // Cận TRÊN: trần này không phải chỗ để nuốt một bundle phình mãi. Chạm
    // 8 MiB nghĩa là phải tách chunk, không phải nâng số.
    expect(limit!).toBeLessThanOrEqual(8 * 1024 * 1024);
  });

  it("navigation fallback không nuốt lời gọi API", () => {
    // Trả shell HTML cho một request API biến "mất mạng" thành "parse lỗi"
    // ở tầng trên — lỗi mạng phải nổi lên để app xử lý.
    const { workbox } = offlineShellOptions("production");
    expect(workbox?.navigateFallback).toBe("/index.html");
    const denied = workbox?.navigateFallbackDenylist ?? [];
    expect(denied.some((re) => re.test("/api/v1/orders"))).toBe(true);
    expect(denied.some((re) => re.test("/orders"))).toBe(false);
  });
});
