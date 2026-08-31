import { defineConfig } from "vitest/config";
import path from "path";
import { fileURLToPath } from "node:url";

const dirname =
  typeof __dirname !== "undefined"
    ? __dirname
    : path.dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  resolve: {
    alias: {
      "@": path.resolve(dirname, "./src"),
    },
  },
  test: {
    environment: "jsdom",
    include: ["src/**/*.test.{ts,tsx}"],
    setupFiles: ["./vitest.setup.ts"],
    globals: true,
    // #1183 added component tests that mount whole money screens (shift
    // open/close, payment + void dialogs) in jsdom. A single such render is
    // ~1s alone, but the pool runs many files at once, so on a loaded machine
    // any of them — including the pre-existing hook tests — can blow past
    // vitest's 5s default and fail for no reason but contention. Real hangs
    // still fail, just 20s later.
    testTimeout: 20_000,
    hookTimeout: 20_000,
    coverage: {
      provider: "v8",
      reporter: ["text", "html"],
      reportOnFailure: true,
      include: [
        "src/lib/**",
        "src/services/**",
        "src/providers/**",
      ],
      exclude: ["**/*.test.{ts,tsx}", "**/*.d.ts"],
      /*
       * #284 — "Coverage ≥ 60% cho lib/ + services/ + providers/auth-provider"
       * là Định-nghĩa-done của epic. Trước đây nó chỉ là một câu trong issue:
       * chạy `test:coverage` rồi tự đọc bảng. Đo lại khi đóng epic thì
       * `src/services/**` ở **60.06%** — vượt ngưỡng đúng 0.06 điểm, tức một
       * test bị xoá là DoD gãy mà CI vẫn xanh.
       *
       * Các số dưới đây là BÁNH CÓC: chúng đặt ngay dưới mức đo được, và chỉ
       * được phép tăng. Thêm một file không test thì gate đỏ — đó là ý đồ, vì
       * pos-web xử lý tiền mặt.
       *
       * Ngưỡng theo TỪNG THƯ MỤC chứ không chỉ một số tổng: `lib/` đang 88% có
       * thể che cho `services/` tụt xuống 40% mà số tổng vẫn qua.
       */
      thresholds: {
        statements: 75,
        branches: 67,
        functions: 73,
        lines: 80,
        "src/lib/**": {
          statements: 88,
          branches: 85,
          functions: 92,
          lines: 92,
        },
        "src/services/**": {
          statements: 66,
          branches: 56,
          functions: 66,
          lines: 73,
        },
        "src/providers/**": {
          statements: 75,
          branches: 50,
          functions: 73,
          lines: 76,
        },
      },
    },
  },
});
