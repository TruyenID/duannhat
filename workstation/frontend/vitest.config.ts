import react from "@vitejs/plugin-react";
import { defineConfig } from "vitest/config";

/**
 * #3133 — test runner ĐẦU TIÊN của `workstation/frontend`.
 *
 * App này là app JS duy nhất của monorepo không có gì chạy test: 33 file
 * `.ts`/`.tsx`, 0 file test. Nó lọt vì rào `test:app-gates` chỉ duyệt hai thư
 * mục gõ cứng (`app/`, `web/`) nên không nhìn thấy nó, và vì luật của chính rào
 * ấy là "app CÓ TEST thì phải có cổng" — không có test ⇒ không đòi cổng ⇒ không
 * ai thêm test. Vòng tự khoá.
 *
 * Cấu hình bám khuôn `web/pos/vitest.config.ts` để hai app không đẻ ra hai cách
 * chạy test khác nhau. KHÔNG dùng `vite.config.ts` của app: file đó nạp plugin
 * `@wailsio/runtime` (sinh binding từ cây Go) — thứ không có nghĩa gì trong
 * jsdom và chỉ thêm một đường hỏng không liên quan tới thứ đang đo.
 */
export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    include: ["src/**/*.test.{ts,tsx}"],
    setupFiles: ["./vitest.setup.ts"],
    globals: true,
    // Cùng lý do với web/pos: một lần mount màn hình thật trong jsdom đã gần
    // 1 giây, và pool chạy nhiều file cùng lúc — 5s mặc định của vitest đỏ vì
    // tranh CPU chứ không vì mã sai. Treo thật vẫn đỏ, chỉ muộn hơn.
    testTimeout: 20_000,
    hookTimeout: 20_000,
  },
});
