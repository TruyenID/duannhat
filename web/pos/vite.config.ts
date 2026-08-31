import { execSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { VitePWA } from "vite-plugin-pwa";
import { offlineShellOptions } from "./src/lib/pwa-options";

const __dirname = path.dirname(fileURLToPath(import.meta.url));

// One version, read from package.json (which `VERSION` at the repo root keeps
// in lockstep). Baked in at BUILD time so pairing device_info and the
// X-App-Version heartbeat header report the running bundle — before this,
// pairing fell back to a hardcoded "0.1.0" (VITE_APP_VERSION was never set
// anywhere) and the admin devices page showed every POS pinned at the
// pair-time number forever (measured 2026-08-18, shop/tsukiji/devices).
const pkgVersion = (
  JSON.parse(readFileSync(path.join(__dirname, "package.json"), "utf8")) as {
    version: string;
  }
).version;

// #1169 — stamp the workstation bundle with an identity so the embedding
// workstation can expose it at GET /api/lan/pos-bundle/version. This is the
// hook v2 (auto-pull a newer bundle from Cloud) reads to decide "am I stale?".
// Emitted ONLY for `--mode workstation`; the Amplify (cloud) build never carries it.
const emitBundleVersion = () => ({
  name: "emit-pos-bundle-version",
  apply: "build" as const,
  generateBundle() {
    let commit = "unknown";
    try {
      commit = execSync("git rev-parse --short HEAD", { cwd: __dirname })
        .toString()
        .trim();
    } catch {
      // No git (e.g. building from a tarball) — identity degrades to the version.
    }
    const pkg = JSON.parse(
      readFileSync(path.join(__dirname, "package.json"), "utf8"),
    );
    // @ts-expect-error — `this` is the Rollup plugin context at build time.
    this.emitFile({
      type: "asset",
      fileName: "pos-bundle-version.json",
      source:
        JSON.stringify(
          {
            bundle: "pos-web",
            version: pkg.version,
            commit,
            builtAt: new Date().toISOString(),
          },
          null,
          2,
        ) + "\n",
    });
  },
});

// Dev-only: strip the production CSP meta from index.html. Vite injects
// inline <script> tags for react-refresh + HMR which `script-src 'self'`
// blocks, causing a blank screen. Production HTML keeps the strict CSP.
const stripCspInDev = () => ({
  name: "strip-csp-in-dev",
  apply: "serve" as const,
  transformIndexHtml(html: string) {
    return html.replace(
      /<meta\s+http-equiv="Content-Security-Policy"[^>]*>/i,
      "",
    );
  },
});

// #1170 tầng 1 — offline app shell, CHỈ cho bản cloud (Amplify).
// Quyết định + lý do nằm trong `src/lib/pwa-options.ts` (tách ra để test được:
// bật/tắt theo mode không nhìn thấy từ danh sách plugin — `disable: true` vẫn
// đăng ký đúng 5 plugin cùng tên).
const offlineShell = (mode: string) => VitePWA(offlineShellOptions(mode));

// plan-052 T3.5 (#1169) — MỘT codebase, HAI cách phân phối:
//   `vite build`                      → base `/`      (Amplify, cloud-first)
//   `vite build --mode workstation`   → base `/pos/`  (nhúng vào workstation)
// `base` là thứ duy nhất khác nhau ở tầng build; phần hành vi nằm trong
// base-url-resolver, đọc lại chính `import.meta.env.BASE_URL` này.
export default defineConfig(({ mode }) => ({
  base: mode === "workstation" ? "/pos/" : "/",
  define: {
    "import.meta.env.VITE_APP_VERSION": JSON.stringify(pkgVersion),
  },
  plugins: [
    react(),
    tailwindcss(),
    stripCspInDev(),
    offlineShell(mode),
    ...(mode === "workstation" ? [emitBundleVersion()] : []),
  ],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  server: {
    host: process.env.HOST ?? true,
    port: Number(process.env.HOST_PORT_POS ?? 5440),
    strictPort: true,
    // HMR qua tunnel cần wss trên 443 — bật bằng `TUNNEL=1 pnpm dev` để dev
    // localhost thường vẫn dùng HMR transport mặc định. Dùng tunnel nào thì
    // thêm host của nó vào `allowedHosts`.
    ...(process.env.TUNNEL ? { hmr: { clientPort: 443, protocol: "wss" } } : {}),
  },
}));
