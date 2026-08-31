/**
 * #1170 tầng 1 — tuỳ chọn service worker, tách khỏi `vite.config.ts` để test
 * được.
 *
 * Vì sao tách: bật/tắt SW theo mode **không nhìn thấy được** từ danh sách
 * plugin — `VitePWA({ disable: true })` vẫn đăng ký đúng 5 plugin cùng tên như
 * khi bật. Đo bằng tên plugin sẽ ra một test luôn xanh ở cả hai bản. Đưa quyết
 * định ra một hàm thuần thì kiểm được thẳng vào chính quyết định đó.
 *
 * Bản build thật vẫn là bằng chứng cuối (`dist/sw.js` có ở cloud, không có ở
 * workstation) — xem thân PR #1170.
 */
import type { VitePWAOptions } from "vite-plugin-pwa";

/** `--mode workstation` = bundle nhúng trong workstation; còn lại là bản cloud. */
export const WORKSTATION_MODE = "workstation";

export function offlineShellOptions(mode: string): Partial<VitePWAOptions> {
  return {
    // Bản workstation KHÔNG có service worker: nó được phục vụ same-origin từ
    // ổ đĩa máy tại quán và đã có cơ chế version riêng
    // (`pos-bundle-version.json`). Thêm SW vào đó là hai lớp cache cùng quản
    // một bundle với hai ý kiến khác nhau về "bản mới nhất" — đúng lớp lệch
    // version mà plan-052 P-19 nói tới.
    disable: mode === WORKSTATION_MODE,

    // HỎI, không tự nạp lại: đây là máy tính tiền, reload giữa lúc thu ngân
    // đang gõ đơn là mất việc đang làm.
    registerType: "prompt",

    // BẮT BUỘC: index.html khai `script-src 'self'` (không 'unsafe-inline'),
    // nên script đăng ký inline do plugin chèn sẽ bị CSP chặn — im lặng, và
    // triệu chứng chỉ lộ ra khi đã mất mạng. App tự đăng ký trong
    // `components/offline-shell.tsx`, đi theo bundle nên hợp lệ CSP.
    injectRegister: null,

    // SW trong dev làm nhiễu HMR và giữ asset cũ giữa các lần sửa.
    // Thử tay bằng `pnpm build:cloud && pnpm preview`.
    devOptions: { enabled: false },

    includeAssets: ["favicon.svg", "icons.svg"],

    manifest: {
      name: "godx POS",
      short_name: "POS",
      description: "TempoFast POS terminal",
      lang: "vi",
      display: "standalone",
      orientation: "landscape",
      background_color: "#ffffff",
      theme_color: "#ffffff",
      start_url: "/",
      // Chỉ có SVG vì repo chưa có icon PNG 192/512. Đủ để chạy offline; riêng
      // lời mời "cài lên màn hình chính" thì một số nền tảng đòi đúng hai cỡ
      // PNG đó. Bổ sung asset là việc riêng, không chặn tầng này.
      icons: [
        {
          src: "/favicon.svg",
          sizes: "any",
          type: "image/svg+xml",
          purpose: "any",
        },
      ],
    },

    workbox: {
      globPatterns: ["**/*.{js,css,html,svg,woff,woff2}"],

      // Trần kích thước MỘT file được precache. Mặc định của workbox là 2 MiB,
      // và bundle chính của POS đã vượt: `dev` build ra 2.099 MB, tức hơn trần
      // đúng **2,4 kB**, và `vite build` THOÁT MÃ 1 (vite-plugin-pwa nâng cảnh
      // báo của workbox thành lỗi ở `closeBundle`). `origin/main` ở 2.071 MB
      // nên còn qua — nghĩa là cổng này đang được giữ bởi 26 kB may mắn, không
      // phải bởi một quyết định nào.
      //
      // Đây KHÔNG phải làm im một cảnh báo. Con số này quyết định tầng 1 có
      // thật hay không:
      //
      //   Vượt trần → workbox BỎ file khỏi precache manifest. Mất mạng thì
      //   `navigateFallback` vẫn trả được `index.html` từ cache, nhưng thẻ
      //   <script> của nó trỏ tới `assets/index-*.js` — file không nằm trong
      //   precache, `destination === "script"` nên cũng không khớp luật
      //   runtime nào ở dưới (chỉ font/style), thế là ra mạng và hỏng. Kết quả
      //   đúng bằng thứ #1170 sinh ra để chặn: MÀN HÌNH TRẮNG.
      //
      // Vì sao 4 MiB chứ không phải "vừa đủ": một trần bám sát bundle là cái
      // bẫy vừa nổ — nó gãy vì 2,4 kB, và gãy bằng một thông báo nói về
      // workbox chứ không nói về bundle, nên người đọc đi sửa nhầm chỗ. Việc
      // canh bundle phình là việc của một rào NÓI ĐÚNG TÊN nó, không phải của
      // hằng số này. 4 MiB ≈ 1,8× bundle hôm nay (2,29 MB), gzip vẫn nằm dưới
      // ~1,1 MB — một lần tải về cho máy tính tiền là chấp nhận được.
      //
      // Ngược lại, đừng nâng vô hạn: chạm 4 MiB thì đó là vấn đề bundle thật
      // (xem `docs`/CLAUDE.md — `@godxjp/ui` kéo cả TipTap vào dù pos-web
      // không dùng, và recharts chỉ phục vụ trang doanh thu), phải tách chunk
      // chứ không phải nâng số.
      maximumFileSizeToCacheInBytes: 4 * 1024 * 1024,
      // SPA: điều hướng khi offline rơi về index đã precache…
      navigateFallback: "/index.html",
      // …trừ `/api/**`. Trả shell HTML cho một lời gọi API là biến "mất mạng"
      // thành "parse lỗi" ở tầng trên — lỗi mạng phải nổi lên để app xử lý.
      navigateFallbackDenylist: [/^\/api\//],
      runtimeCaching: [
        {
          // Tiền và đơn hàng KHÔNG bao giờ đọc từ cache ở tầng SW (#1092:
          // offline-sales đáng tin là vai của workstation, không phải browser).
          urlPattern: /\/api\//,
          handler: "NetworkOnly",
        },
        {
          urlPattern: ({ request }: { request: Request }) =>
            request.destination === "font" || request.destination === "style",
          handler: "CacheFirst",
          options: {
            cacheName: "godx-pos-fonts",
            expiration: { maxEntries: 30, maxAgeSeconds: 60 * 60 * 24 * 30 },
          },
        },
      ],
    },
  };
}
