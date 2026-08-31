import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import wails from "@wailsio/runtime/plugins/vite";

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss(), wails("./bindings")],
  resolve: {
    preserveSymlinks: false,
    dedupe: ["react", "react-dom"],
  },
  optimizeDeps: {
    // Force Vite to pre-bundle the linked package and its CJS transitive deps.
    // Without this, Vite treats linked packages as source code and skips
    // pre-bundling, leaving __require("react") calls unresolved at runtime.
    include: [
      "@godxjp/ui",
      "react-hook-form",
      "use-sync-external-store",
      "use-sync-external-store/shim",
      "use-sync-external-store/shim/with-selector",
    ],
  },
  build: {
    commonjsOptions: {
      // Include both node_modules AND the linked @godxjp/ui package so
      // Rollup's commonjs plugin processes __require / __commonJS shims.
      include: [/node_modules/, /@godxjp\/ui/],
      // Critical: transform files that mix ES imports with CJS require() calls.
      // This is exactly what tsup produces when it inlines CJS deps into ESM output.
      transformMixedEsModules: true,
    },
  },
});
